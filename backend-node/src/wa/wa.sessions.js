import path from 'path'
import fs from 'fs/promises'
import { existsSync } from 'fs'
import { createHash, randomUUID } from 'crypto'
import { fileURLToPath } from 'url'
import pino from 'pino'
import makeWASocket, { Browsers, DisconnectReason, useMultiFileAuthState, fetchLatestBaileysVersion, downloadMediaMessage } from 'baileys'
import { isValidTenantId } from '../middleware/auth.js'

const logger = pino({ level: process.env.LOG_LEVEL || 'silent' })

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const DATA_DIR = process.env.DATA_DIR || path.resolve(__dirname, '../../data')
const TENANTS_DIR = path.join(DATA_DIR, 'tenants')
// Pre-tenancy layout. Anything still here on boot is quarantined, never served.
const LEGACY_SESSIONS_DIR = path.join(DATA_DIR, 'sessions')
const QUARANTINE_TENANT = '_unassigned'
const META_FILE = 'meta.json'
const MEDIA_DIR = 'media'

// Tenant isolation is structural, not check-based. Both the on-disk path and
// the in-memory key are derived from the *authenticated* tenant id, so one
// tenant cannot name another tenant's session — the address does not exist for
// them. There is deliberately no lookup that takes a sessionId on its own.
function tenantSessionsDir(tenantId) {
  return path.join(TENANTS_DIR, tenantId, 'sessions')
}

function sessionPathFor(tenantId, sessionId) {
  return path.join(tenantSessionsDir(tenantId), sessionId)
}

function sessionPath(state) {
  return sessionPathFor(state.tenantId, state.sessionId)
}

function sessionKey(tenantId, sessionId) {
  return `${tenantId}:${sessionId}`
}

const sessions = new Map()
const MAX_MESSAGES_PER_CHAT = 5000
const MAX_QR_RETRIES = 5
const BASE_RETRY_DELAY_MS = 3000
const LOGOUT_TIMEOUT_MS = 5000

let cachedVersion = null
let versionFetchedAt = 0
const VERSION_CACHE_MS = 60 * 60 * 1000 // 1 hour

async function getWaVersion() {
  if (cachedVersion && Date.now() - versionFetchedAt < VERSION_CACHE_MS) {
    return cachedVersion
  }
  try {
    const { version } = await fetchLatestBaileysVersion()
    cachedVersion = version
    versionFetchedAt = Date.now()
    console.log('Fetched latest WA version:', version.join('.'))
    return version
  } catch (err) {
    console.error('Failed to fetch WA version, using default:', err.message)
    return undefined
  }
}

const mediaCacheQueue = new Set()

function getMediaPath(sessionPath, messageId, mime) {
  const ext = (mime || '').split('/')[1]?.split(';')[0] || 'bin'
  return path.join(sessionPath, MEDIA_DIR, `${messageId}.${ext}`)
}

async function cacheMedia(sessionState, entry) {
  if (!entry.rawMessage || !entry.id) return
  const cacheKey = `${sessionKey(sessionState.tenantId, sessionState.sessionId)}:${entry.id}`
  if (mediaCacheQueue.has(cacheKey)) return
  mediaCacheQueue.add(cacheKey)

  const dir = sessionPath(sessionState)
  const mediaDir = path.join(dir, MEDIA_DIR)
  const filePath = getMediaPath(dir, entry.id, entry.mediaMime)

  try {
    await fs.mkdir(mediaDir, { recursive: true })
    if (existsSync(filePath)) return

    const mediaType = entry.mediaType
    const typeMap = { image: 'imageMessage', video: 'videoMessage', audio: 'audioMessage', voice: 'audioMessage', document: 'documentMessage', sticker: 'stickerMessage' }
    const msgKey = typeMap[mediaType]
    if (!msgKey || !entry.rawMessage[msgKey]) return

    const buffer = await downloadMediaMessage(
      { key: { remoteJid: entry.chatId, fromMe: entry.fromMe, id: entry.id }, message: entry.rawMessage },
      'buffer',
      {},
      { logger, reuploadRequest: sessionState.sock?.updateMediaMessage }
    )
    await fs.writeFile(filePath, buffer)
  } catch (err) {
    // silent fail - media will use live download as fallback
  } finally {
    mediaCacheQueue.delete(cacheKey)
  }
}

async function getMediaFromCache(tenantId, sessionId, messageId, mime) {
  const filePath = getMediaPath(sessionPathFor(tenantId, sessionId), messageId, mime)
  try {
    if (!existsSync(filePath)) return null
    const buffer = await fs.readFile(filePath)
    return buffer
  } catch {
    return null
  }
}

async function ensureTenantDir(tenantId) {
  await fs.mkdir(tenantSessionsDir(tenantId), { recursive: true })
}

function newSessionId() {
  return randomUUID()
}

// Session ids are always server-generated UUIDs. Every filesystem path is built
// from one, so validate the shape before it can ever reach fs — defence in depth
// against path traversal even though callers currently gate on the in-memory Map.
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function isValidSessionId(id) {
  return typeof id === 'string' && UUID_RE.test(id)
}

function toSnapshot(state) {
  let totalMessages = 0
  for (const msgs of state.messages.values()) {
    totalMessages += msgs.length
  }
  return {
    sessionId: state.sessionId,
    label: state.label || null,
    status: state.status,
    qr: state.qr || null,
    connectedAt: state.connectedAt || null,
    lastDisconnectAt: state.lastDisconnectAt || null,
    lastDisconnectReason: state.lastDisconnectReason || null,
    user: state.user || null,
    retries: state.retries || 0,
    syncInfo: {
      totalChats: state.chats.size,
      totalMessages,
      syncBatches: state.syncBatches || 0,
      syncing: state.syncing || false
    }
  }
}

async function writeMeta(sessionPath, meta) {
  await fs.writeFile(path.join(sessionPath, META_FILE), JSON.stringify(meta, null, 2))
}

async function readMeta(sessionPath) {
  try {
    const raw = await fs.readFile(path.join(sessionPath, META_FILE), 'utf-8')
    return JSON.parse(raw)
  } catch {
    return {}
  }
}

const CHATS_FILE = 'chats.json'
const MESSAGES_FILE = 'messages.json'
const MESSAGES_DIR = 'messages'
const chatSaveTimers = new Map()
const msgSaveTimers = new Map()

// Write to a temp file then rename, so a crash mid-write can never leave a
// truncated/corrupt JSON file behind.
//
// The temp name includes a per-call sequence number: a debounced save can fire
// again while the previous write is still awaiting, and two overlapping writes
// sharing one temp path would clobber each other and orphan the file.
let writeSeq = 0

async function writeJsonAtomic(filePath, value) {
  const tmp = `${filePath}.${process.pid}.${++writeSeq}.tmp`
  try {
    await fs.writeFile(tmp, JSON.stringify(value))
    await fs.rename(tmp, filePath)
  } catch (err) {
    await fs.rm(tmp, { force: true }).catch(() => {})
    throw err
  }
}

async function writeChats(sessionPath, chatsMap) {
  const arr = Array.from(chatsMap.values())
  await writeJsonAtomic(path.join(sessionPath, CHATS_FILE), arr)
}

async function readChats(sessionPath) {
  try {
    const raw = await fs.readFile(path.join(sessionPath, CHATS_FILE), 'utf-8')
    const arr = JSON.parse(raw)
    const map = new Map()
    for (const c of arr) {
      map.set(c.id, c)
    }
    return map
  } catch {
    return new Map()
  }
}

function scheduleChatSave(sessionState) {
  const key = sessionKey(sessionState.tenantId, sessionState.sessionId)
  if (chatSaveTimers.has(key)) return
  chatSaveTimers.set(key, setTimeout(async () => {
    chatSaveTimers.delete(key)
    try {
      await writeChats(sessionPath(sessionState), sessionState.chats)
    } catch (_) {}
  }, 3000))
}

// chatId is not filesystem-safe (contains '@', and is attacker-influenced), so
// shard files are named by a hash of it.
function chatShardName(chatId) {
  return createHash('sha1').update(chatId).digest('hex') + '.json'
}

function stripRaw(msgs) {
  return msgs.slice(-MAX_MESSAGES_PER_CHAT).map(m => {
    if (!m.rawMessage) return m
    const { rawMessage, ...rest } = m
    return rest
  })
}

// Persist only the chats that actually changed. Previously every save rewrote a
// single messages.json containing the whole session (10MB+ in production) on a
// 5s debounce, which does not scale and risked truncation on crash.
async function writeDirtyMessages(sessionPath, messagesMap, dirtyChats) {
  if (!dirtyChats || dirtyChats.size === 0) return
  const dir = path.join(sessionPath, MESSAGES_DIR)
  await fs.mkdir(dir, { recursive: true })

  const chatIds = Array.from(dirtyChats)
  dirtyChats.clear()

  for (const chatId of chatIds) {
    const msgs = messagesMap.get(chatId)
    if (!msgs) continue
    try {
      await writeJsonAtomic(path.join(dir, chatShardName(chatId)), { chatId, msgs: stripRaw(msgs) })
    } catch (_) {
      dirtyChats.add(chatId) // retry on the next flush
    }
  }
}

async function readMessages(sessionPath) {
  const map = new Map()
  const dir = path.join(sessionPath, MESSAGES_DIR)

  let files = []
  try {
    files = await fs.readdir(dir)
  } catch {
    files = []
  }

  for (const file of files) {
    if (!file.endsWith('.json')) continue
    try {
      const raw = await fs.readFile(path.join(dir, file), 'utf-8')
      const shard = JSON.parse(raw)
      if (shard?.chatId && Array.isArray(shard.msgs)) map.set(shard.chatId, shard.msgs)
    } catch (_) {
      // skip unreadable/corrupt shard rather than failing the whole restore
    }
  }

  // One-time migration off the legacy monolithic messages.json.
  const legacy = path.join(sessionPath, MESSAGES_FILE)
  if (existsSync(legacy)) {
    try {
      const obj = JSON.parse(await fs.readFile(legacy, 'utf-8'))
      await fs.mkdir(dir, { recursive: true })
      let migrated = 0
      for (const [chatId, msgs] of Object.entries(obj)) {
        if (map.has(chatId) || !Array.isArray(msgs)) continue
        map.set(chatId, msgs)
        await writeJsonAtomic(path.join(dir, chatShardName(chatId)), { chatId, msgs })
        migrated++
      }
      await fs.rename(legacy, legacy + '.migrated')
      console.log(`Migrated ${migrated} chats out of legacy messages.json`)
    } catch (err) {
      console.error('Legacy messages.json migration failed:', err.message)
    }
  }

  return map
}

function scheduleMessageSave(sessionState, chatId) {
  if (chatId) sessionState.dirtyChats.add(chatId)

  const key = sessionKey(sessionState.tenantId, sessionState.sessionId)
  if (msgSaveTimers.has(key)) return
  msgSaveTimers.set(key, setTimeout(async () => {
    msgSaveTimers.delete(key)
    try {
      await writeDirtyMessages(sessionPath(sessionState), sessionState.messages, sessionState.dirtyChats)
    } catch (_) {}
  }, 5000))
}

function detectMediaType(message) {
  if (!message) return { type: 'text', mime: null, filename: null }
  if (message.imageMessage) return { type: 'image', mime: message.imageMessage.mimetype || 'image/jpeg', filename: null }
  if (message.videoMessage) return { type: 'video', mime: message.videoMessage.mimetype || 'video/mp4', filename: null }
  if (message.audioMessage) {
    const isVoice = message.audioMessage.ptt === true
    return { type: isVoice ? 'voice' : 'audio', mime: message.audioMessage.mimetype || 'audio/ogg', filename: null }
  }
  if (message.documentMessage) return { type: 'document', mime: message.documentMessage.mimetype || 'application/octet-stream', filename: message.documentMessage.fileName || null }
  if (message.stickerMessage) return { type: 'sticker', mime: message.stickerMessage.mimetype || 'image/webp', filename: null }
  if (message.contactMessage || message.contactsArrayMessage) return { type: 'contact', mime: null, filename: null }
  if (message.locationMessage || message.liveLocationMessage) return { type: 'location', mime: null, filename: null }
  return { type: 'text', mime: null, filename: null }
}

function extractText(message) {
  if (!message) return ''
  return message.conversation ||
    message.extendedTextMessage?.text ||
    message.imageMessage?.caption ||
    message.videoMessage?.caption ||
    message.documentMessage?.caption ||
    ''
}

function extractContactInfo(message) {
  if (!message) return null
  if (message.contactMessage) {
    return [{ displayName: message.contactMessage.displayName || '', vcard: message.contactMessage.vcard || '' }]
  }
  if (message.contactsArrayMessage?.contacts) {
    return message.contactsArrayMessage.contacts.map(c => ({
      displayName: c.displayName || '', vcard: c.vcard || ''
    }))
  }
  return null
}

function extractLocationInfo(message) {
  if (!message) return null
  const loc = message.locationMessage || message.liveLocationMessage
  if (!loc) return null
  return {
    latitude: loc.degreesLatitude || 0,
    longitude: loc.degreesLongitude || 0,
    name: loc.name || '',
    address: loc.address || '',
    url: loc.url || ''
  }
}

function storeMessage(sessionState, msg) {
  if (!msg.message) return
  if (msg.key?.remoteJid === 'status@broadcast') return

  const chatId = msg.key.remoteJid
  const fromMe = msg.key.fromMe || false
  const media = detectMediaType(msg.message)
  const text = extractText(msg.message)
  const timestamp = msg.messageTimestamp
    ? new Date(Number(msg.messageTimestamp) * 1000).toISOString()
    : new Date().toISOString()

  const contactInfo = extractContactInfo(msg.message)
  const locationInfo = extractLocationInfo(msg.message)

  const entry = {
    id: msg.key.id,
    chatId,
    fromMe,
    text,
    mediaType: media.type,
    mediaMime: media.mime,
    mediaFilename: media.filename,
    senderName: msg.pushName || null,
    time: timestamp,
    contactInfo: contactInfo || undefined,
    locationInfo: locationInfo || undefined,
    rawMessage: (media.type !== 'text') ? msg.message : undefined
  }

  if (!sessionState.messages.has(chatId)) {
    sessionState.messages.set(chatId, [])
  }
  const arr = sessionState.messages.get(chatId)

  // Dedup: if message already exists, upgrade it with rawMessage if available
  const existingIdx = arr.findIndex(m => m.id === entry.id)
  if (existingIdx !== -1) {
    if (entry.rawMessage && !arr[existingIdx].rawMessage) {
      arr[existingIdx].rawMessage = entry.rawMessage
      arr[existingIdx].contactInfo = entry.contactInfo || arr[existingIdx].contactInfo
      arr[existingIdx].locationInfo = entry.locationInfo || arr[existingIdx].locationInfo
    }
  } else {
    arr.push(entry)
    if (arr.length > MAX_MESSAGES_PER_CHAT) {
      arr.splice(0, arr.length - MAX_MESSAGES_PER_CHAT)
    }
  }

  // Async media caching to disk
  if (entry.rawMessage && media.type !== 'text' && media.type !== 'contact' && media.type !== 'location') {
    cacheMedia(sessionState, entry)
  }

  let preview = text
  if (!preview && media.type !== 'text') {
    const labels = { image: '📷 Photo', video: '🎥 Video', audio: '🎵 Audio', voice: '🎤 Voice message', document: '📄 Document', sticker: '🏷️ Sticker', contact: '👤 Contact', location: '📍 Location' }
    preview = labels[media.type] || media.type
  } else if (preview && media.type !== 'text') {
    const icons = { image: '📷', video: '🎥', audio: '🎵', voice: '🎤', document: '📄', sticker: '🏷️', contact: '👤', location: '📍' }
    preview = (icons[media.type] || '') + ' ' + preview
  }

  const isGroup = chatId.endsWith('@g.us')
  const existing = sessionState.chats.get(chatId)
  let chatName = existing?.name || chatId.split('@')[0]
  if (!fromMe && !isGroup && msg.pushName) {
    chatName = msg.pushName
  }

  const existingPhone = sessionState.chats.get(chatId)?.phone || sessionState.phoneNumbers.get(chatId) || null
  sessionState.chats.set(chatId, {
    id: chatId,
    name: chatName,
    lastMessage: preview,
    lastTime: timestamp,
    isGroup,
    phone: existingPhone
  })

  scheduleChatSave(sessionState)
  scheduleMessageSave(sessionState, chatId)
}

function getContactName(contact) {
  return contact.name || contact.notify || contact.verifiedName || null
}

function isNumericName(name) {
  if (!name) return true
  return /^[\d@.]+$/.test(name) || name.includes('@')
}

function processContact(sessionState, contact) {
  if (!contact || !contact.id) return
  const id = contact.id
  const bestName = getContactName(contact)

  // Store name in contactNames map for both JID formats
  if (bestName) {
    sessionState.contactNames.set(id, bestName)
  }

  // If id is phone-based (@s.whatsapp.net), extract the phone number
  if (id.endsWith('@s.whatsapp.net')) {
    const phone = id.split('@')[0]
    sessionState.phoneNumbers.set(id, phone)

    // If contact also has a LID, propagate phone AND name to the LID
    if (contact.lid) {
      const lidJid = contact.lid.endsWith('@lid') ? contact.lid : contact.lid + '@lid'
      sessionState.phoneNumbers.set(lidJid, phone)
      if (bestName) sessionState.contactNames.set(lidJid, bestName)

      const lidChat = sessionState.chats.get(lidJid)
      if (lidChat) {
        if (!lidChat.phone) lidChat.phone = phone
        if (bestName && isNumericName(lidChat.name)) lidChat.name = bestName
      }
    }

    // Update own phone-based chat entry name
    const phoneChat = sessionState.chats.get(id)
    if (phoneChat && bestName && isNumericName(phoneChat.name)) phoneChat.name = bestName
  }

  // If id is LID-based (@lid)
  if (id.endsWith('@lid')) {
    if (contact.phone) {
      const phone = String(contact.phone).replace(/[^0-9]/g, '')
      if (phone) sessionState.phoneNumbers.set(id, phone)
    }
    const lidChat = sessionState.chats.get(id)
    if (lidChat && bestName && isNumericName(lidChat.name)) lidChat.name = bestName
  }
}

function crossReferenceLidNames(sessionState) {
  // Build phone→name map from @s.whatsapp.net chat entries
  const phoneToName = new Map()
  for (const [chatId, chat] of sessionState.chats) {
    if (chatId.endsWith('@s.whatsapp.net')) {
      const phone = chatId.split('@')[0]
      sessionState.phoneNumbers.set(chatId, phone)
      if (!isNumericName(chat.name)) {
        phoneToName.set(phone, chat.name)
        sessionState.contactNames.set(chatId, chat.name)
      }
    }
  }

  // Apply names to @lid chat entries that have a phone mapping
  let resolved = 0
  for (const [chatId, chat] of sessionState.chats) {
    if (chatId.endsWith('@lid') && chat.phone) {
      sessionState.phoneNumbers.set(chatId, chat.phone)
      const name = phoneToName.get(chat.phone)
      if (name && isNumericName(chat.name)) {
        chat.name = name
        sessionState.contactNames.set(chatId, name)
        resolved++
      }
    }
  }

  if (resolved > 0) {
    console.log(`Cross-referenced ${resolved} LID chat names from phone-based entries`)
    scheduleChatSave(sessionState)
  }
}

function resolveNumericNames(sessionState) {
  let resolved = 0
  for (const [chatId, chat] of sessionState.chats) {
    if (isNumericName(chat.name)) {
      const mappedName = sessionState.contactNames.get(chatId)
      if (mappedName) {
        chat.name = mappedName
        resolved++
      }
    }
  }
  if (resolved > 0) {
    console.log(`Resolved ${resolved} numeric chat names from contact data`)
  }
}

// Registers a timeout against the session so it can be cancelled on logout.
// Without this, callbacks keep firing against deleted session state and hold
// the whole sessionState (including message buffers) alive.
function trackTimeout(sessionState, fn, ms) {
  const t = setTimeout(() => {
    sessionState.timers.delete(t)
    fn()
  }, ms)
  sessionState.timers.add(t)
  return t
}

function markSyncComplete(sessionState) {
  if (sessionState.syncing) {
    sessionState.syncing = false
    console.log(`Sync timeout — marking sync complete for session ${sessionState.sessionId}`)
  }
}

function attachSocketEvents(sessionState, sock, saveCreds) {
  sock.ev.on('creds.update', saveCreds)

  const hasExistingData = sessionState.messages.size > 0
  sessionState.syncing = true
  sessionState.syncBatches = 0

  if (sessionState.syncTimeout) clearTimeout(sessionState.syncTimeout)
  sessionState.syncTimeout = trackTimeout(
    sessionState,
    () => markSyncComplete(sessionState),
    hasExistingData ? 15000 : 60000
  )

  sock.ev.on('messaging-history.set', ({ chats: syncedChats, messages: syncedMessages, contacts: syncedContacts, isLatest }) => {
    sessionState.syncBatches = (sessionState.syncBatches || 0) + 1

    clearTimeout(sessionState.syncTimeout)
    sessionState.timers.delete(sessionState.syncTimeout)
    sessionState.syncTimeout = trackTimeout(sessionState, () => markSyncComplete(sessionState), 30000)

    if (syncedContacts) {
      // Log a sample of contact data for debugging
      if (sessionState.syncBatches === 1 && syncedContacts.length > 0) {
        const samples = syncedContacts.slice(0, 5).map(c => ({
          id: c.id, lid: c.lid, name: c.name, notify: c.notify,
          verifiedName: c.verifiedName, phone: c.phone,
          keys: Object.keys(c).filter(k => c[k] != null)
        }))
        console.log('Contact data samples:', JSON.stringify(samples, null, 2))
      }

      for (const contact of syncedContacts) {
        if (contact.id && contact.id !== 'status@broadcast') {
          processContact(sessionState, contact)
          const bestName = getContactName(contact)
          const existing = sessionState.chats.get(contact.id)
          if (existing) {
            if (bestName && isNumericName(existing.name)) existing.name = bestName
            else if (bestName) existing.name = bestName
            existing.phone = sessionState.phoneNumbers.get(contact.id) || existing.phone || null
          }
        }
      }
    }

    if (syncedChats) {
      for (const chat of syncedChats) {
        if (chat.id === 'status@broadcast') continue
        if (!sessionState.chats.has(chat.id)) {
          const mappedName = sessionState.contactNames.get(chat.id)
          sessionState.chats.set(chat.id, {
            id: chat.id,
            name: mappedName || chat.name || chat.id.split('@')[0],
            lastMessage: '',
            lastTime: chat.conversationTimestamp
              ? new Date(Number(chat.conversationTimestamp) * 1000).toISOString()
              : null,
            isGroup: chat.id.endsWith('@g.us'),
            phone: sessionState.phoneNumbers.get(chat.id) || null
          })
        }
      }
    }

    if (syncedMessages) {
      for (const msg of syncedMessages) {
        storeMessage(sessionState, msg)
      }
    }

    let totalMsgs = 0
    for (const msgs of sessionState.messages.values()) { totalMsgs += msgs.length }

    console.log(`History sync batch #${sessionState.syncBatches}: +${syncedChats?.length || 0} chats, +${syncedMessages?.length || 0} msgs, +${syncedContacts?.length || 0} contacts (total: ${sessionState.chats.size} chats, ${totalMsgs} msgs)${isLatest ? ' [COMPLETE]' : ''}`)

    if (isLatest) {
      sessionState.syncing = false
      console.log(`Full history sync complete for session ${sessionState.sessionId}`)
      resolveNumericNames(sessionState)
    }

    scheduleChatSave(sessionState)
  })

  sock.ev.on('contacts.upsert', (contacts) => {
    for (const contact of contacts) {
      if (contact.id && contact.id !== 'status@broadcast') {
        processContact(sessionState, contact)
        const bestName = getContactName(contact)
        const phone = sessionState.phoneNumbers.get(contact.id) || null
        const existing = sessionState.chats.get(contact.id)
        if (existing) {
          if (bestName && isNumericName(existing.name)) existing.name = bestName
          else if (bestName) existing.name = bestName
          existing.phone = phone || existing.phone || null
        } else {
          sessionState.chats.set(contact.id, {
            id: contact.id,
            name: bestName || contact.id.split('@')[0],
            lastMessage: '',
            lastTime: null,
            phone
          })
        }
      }
    }
    resolveNumericNames(sessionState)
    scheduleChatSave(sessionState)
  })

  sock.ev.on('contacts.update', (updates) => {
    for (const update of updates) {
      if (!update.id || update.id === 'status@broadcast') continue
      const bestName = getContactName(update)
      if (bestName) sessionState.contactNames.set(update.id, bestName)
      processContact(sessionState, update)
      const existing = sessionState.chats.get(update.id)
      if (existing) {
        if (bestName) existing.name = bestName
        const phone = sessionState.phoneNumbers.get(update.id)
        if (phone) existing.phone = phone
      }
    }
    resolveNumericNames(sessionState)
    scheduleChatSave(sessionState)
  })

  sock.ev.on('messages.upsert', ({ messages: msgs }) => {
    for (const msg of msgs) {
      storeMessage(sessionState, msg)
    }
  })

  sock.ev.on('connection.update', async (update) => {
    const { connection, qr, lastDisconnect } = update

    if (qr) {
      sessionState.qr = qr
      sessionState.status = 'qr_required'
    }

    if (connection === 'open') {
      sessionState.status = 'connected'
      sessionState.connectedAt = new Date().toISOString()
      sessionState.qr = null
      sessionState.retries = 0
      sessionState.user = sock?.user || null

      await writeMeta(sessionPath(sessionState), {
        tenantId: sessionState.tenantId,
        label: sessionState.label,
        user: sessionState.user,
        connectedAt: sessionState.connectedAt
      })

      trackTimeout(sessionState, async () => {
        if (!sessions.has(sessionKey(sessionState.tenantId, sessionState.sessionId))) return
        try {
          const groups = await sock.groupFetchAllParticipating()
          let added = 0
          for (const [gid, meta] of Object.entries(groups)) {
            if (!sessionState.chats.has(gid)) {
              sessionState.chats.set(gid, {
                id: gid,
                name: meta.subject || gid,
                lastMessage: '',
                lastTime: meta.subjectTime
                  ? new Date(meta.subjectTime * 1000).toISOString()
                  : null,
                phone: null
              })
              added++
            }
          }
          if (added > 0) {
            console.log(`Auto-fetched ${added} groups for session ${sessionState.sessionId}`)
            scheduleChatSave(sessionState)
          }
        } catch (err) {
          console.error('Group fetch failed:', err.message)
        }
      }, 5000)
    }

    if (connection === 'close') {
      sessionState.lastDisconnectAt = new Date().toISOString()

      const statusCode = lastDisconnect?.error?.output?.statusCode
      sessionState.lastDisconnectReason = statusCode || null

      const errMsg = lastDisconnect?.error?.message || lastDisconnect?.error?.toString?.() || null
      if (statusCode || errMsg) {
        console.error(`Session ${sessionState.sessionId} connection closed:`, { statusCode, errMsg })
      }

      if (statusCode === DisconnectReason.loggedOut) {
        sessionState.status = 'logged_out'
      } else {
        sessionState.retries = (sessionState.retries || 0) + 1
        if (sessionState.retries > MAX_QR_RETRIES && !sessionState.user) {
          console.error(`Session ${sessionState.sessionId} exceeded ${MAX_QR_RETRIES} retries without connecting — giving up`)
          sessionState.status = 'failed'
        } else {
          sessionState.status = 'disconnected'
          const delay = Math.min(BASE_RETRY_DELAY_MS * Math.pow(2, sessionState.retries - 1), 60000)
          console.log(`Session ${sessionState.sessionId} will retry in ${delay}ms (attempt ${sessionState.retries})`)
          trackTimeout(sessionState, () => reconnectSession(sessionState.tenantId, sessionState.sessionId), delay)
        }
      }
    }
  })
}

async function reconnectSession(tenantId, sessionId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return
  if (s.status === 'logged_out') return

  try {
    const { state, saveCreds } = await useMultiFileAuthState(sessionPath(s))
    const version = await getWaVersion()
    const sock = makeWASocket({
      auth: state,
      version,
      browser: Browsers.macOS('Safari'),
      logger,
      syncFullHistory: true,
      generateHighQualityLinkPreview: false,
      connectTimeoutMs: 30000
    })
    s.sock = sock
    s.status = 'reconnecting'
    attachSocketEvents(s, sock, saveCreds)
  } catch (err) {
    console.error(`Failed to reconnect session ${sessionId}:`, err.message)
  }
}

export function getSessionSnapshot(tenantId, sessionId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return null
  return toSnapshot(s)
}

// Scoped to one tenant by design. There is no "list every session" export —
// the previous global version was the single worst cross-tenant leak in the app.
export function getTenantSessionSnapshots(tenantId) {
  const list = []
  for (const s of sessions.values()) {
    if (s.tenantId === tenantId) list.push(toSnapshot(s))
  }
  return list
}

export async function createNewSession(tenantId, label) {
  if (!isValidTenantId(tenantId)) throw new Error('Invalid tenant')
  await ensureTenantDir(tenantId)

  const sessionId = newSessionId()
  const dir = sessionPathFor(tenantId, sessionId)
  await fs.mkdir(dir, { recursive: true })

  await writeMeta(dir, { tenantId, label: label || null })

  const { state, saveCreds } = await useMultiFileAuthState(dir)

  const sessionState = {
    tenantId,
    sessionId,
    label: label || null,
    status: 'qr_required',
    qr: null,
    connectedAt: null,
    lastDisconnectAt: null,
    lastDisconnectReason: null,
    user: null,
    sock: null,
    retries: 0,
    chats: new Map(),
    messages: new Map(),
    dirtyChats: new Set(),
    phoneNumbers: new Map(),
    contactNames: new Map(),
    timers: new Set()
  }

  const version = await getWaVersion()
  const sock = makeWASocket({
    auth: state,
    version,
    browser: Browsers.macOS('Safari'),
    logger,
    syncFullHistory: true,
    generateHighQualityLinkPreview: false,
    connectTimeoutMs: 30000
  })

  sessionState.sock = sock
  sessions.set(sessionKey(tenantId, sessionId), sessionState)

  attachSocketEvents(sessionState, sock, saveCreds)

  return sessionState
}

// Sessions created before tenancy existed sit in the flat data/sessions/ dir
// and there is no way to attribute them to a tenant from inside this process —
// the ownership mapping lives in the frontend's MySQL. Rather than guess, move
// them somewhere that fails the tenant-id regex, so they can never be addressed
// or served. They stay on disk for forensics until an operator deals with them.
async function quarantineLegacySessions() {
  if (!existsSync(LEGACY_SESSIONS_DIR)) return

  let entries = []
  try {
    entries = await fs.readdir(LEGACY_SESSIONS_DIR, { withFileTypes: true })
  } catch {
    return
  }

  const dest = tenantSessionsDir(QUARANTINE_TENANT)
  let moved = 0
  for (const entry of entries) {
    if (!entry.isDirectory()) continue
    try {
      await fs.mkdir(dest, { recursive: true })
      await fs.rename(path.join(LEGACY_SESSIONS_DIR, entry.name), path.join(dest, entry.name))
      moved++
    } catch (err) {
      console.error(`Failed to quarantine legacy session ${entry.name}:`, err.message)
    }
  }

  if (moved > 0) {
    console.warn(`Quarantined ${moved} pre-tenancy session(s) to ${dest}. They are NOT served to any tenant and must be re-linked.`)
  }
  await fs.rmdir(LEGACY_SESSIONS_DIR).catch(() => {})
}

export async function restoreAllSessions() {
  await fs.mkdir(TENANTS_DIR, { recursive: true })
  await quarantineLegacySessions()

  let tenantDirs = []
  try {
    tenantDirs = await fs.readdir(TENANTS_DIR, { withFileTypes: true })
  } catch {
    return
  }

  for (const tenantDir of tenantDirs) {
    if (!tenantDir.isDirectory()) continue
    const tenantId = tenantDir.name
    // Skips the quarantine bucket for free: '_unassigned' fails the regex.
    if (!isValidTenantId(tenantId)) continue

    await restoreTenantSessions(tenantId)
  }
}

async function restoreTenantSessions(tenantId) {
  let entries
  try {
    entries = await fs.readdir(tenantSessionsDir(tenantId), { withFileTypes: true })
  } catch {
    return
  }

  for (const entry of entries) {
    if (!entry.isDirectory()) continue
    const sessionId = entry.name
    if (!isValidSessionId(sessionId)) continue
    const dir = sessionPathFor(tenantId, sessionId)

    const credsPath = path.join(dir, 'creds.json')
    if (!existsSync(credsPath)) continue

    const meta = await readMeta(dir)

    const { state, saveCreds } = await useMultiFileAuthState(dir)

    const restoredChats = await readChats(dir)
    const restoredMessages = await readMessages(dir)

    const sessionState = {
      tenantId,
      sessionId,
      label: meta.label || null,
      status: 'reconnecting',
      qr: null,
      connectedAt: meta.connectedAt || null,
      lastDisconnectAt: null,
      lastDisconnectReason: null,
      user: meta.user || null,
      sock: null,
      chats: restoredChats,
      messages: restoredMessages,
      dirtyChats: new Set(),
      phoneNumbers: new Map(),
      contactNames: new Map(),
      timers: new Set()
    }

    const version = await getWaVersion()
    const sock = makeWASocket({
      auth: state,
      version,
      browser: Browsers.macOS('Safari'),
      logger,
      syncFullHistory: true,
      generateHighQualityLinkPreview: false,
      connectTimeoutMs: 30000
    })

    sessionState.sock = sock
    sessions.set(sessionKey(tenantId, sessionId), sessionState)

    crossReferenceLidNames(sessionState)
    attachSocketEvents(sessionState, sock, saveCreds)

    console.log(`Restored session ${sessionId} for tenant ${tenantId} (label: ${meta.label || 'none'})`)
  }
}

export async function logoutAndDeleteSession(tenantId, sessionId) {
  if (!isValidTenantId(tenantId)) return false
  if (!isValidSessionId(sessionId)) return false

  const key = sessionKey(tenantId, sessionId)
  const s = sessions.get(key)

  // A session whose QR was never scanned has no creds.json, so it is not
  // restored into memory on boot — but its directory is still on disk. Clean
  // that orphan up instead of leaving it behind forever. The path is built from
  // the caller's own tenant id, so this cannot reach another tenant's orphan.
  if (!s) {
    const orphanPath = sessionPathFor(tenantId, sessionId)
    if (existsSync(orphanPath)) {
      await fs.rm(orphanPath, { recursive: true, force: true })
      console.log(`Removed orphaned session directory ${sessionId} for tenant ${tenantId}`)
      return true
    }
    return false
  }

  // Cancel every pending timer for this session before tearing it down, so no
  // callback fires against deleted state and nothing rewrites the files we are
  // about to remove.
  for (const t of s.timers) clearTimeout(t)
  s.timers.clear()
  if (s.syncTimeout) clearTimeout(s.syncTimeout)

  const chatTimer = chatSaveTimers.get(key)
  if (chatTimer) {
    clearTimeout(chatTimer)
    chatSaveTimers.delete(key)
  }
  const msgTimer = msgSaveTimers.get(key)
  if (msgTimer) {
    clearTimeout(msgTimer)
    msgSaveTimers.delete(key)
  }

  // sock.logout() waits on a reply from WhatsApp. On an already-dead or
  // never-connected socket that reply never arrives, and an unbounded await
  // here means the directory below is never deleted — leaving an orphaned
  // session on disk and in memory. Cap it and continue regardless.
  try {
    if (s.sock) {
      await Promise.race([
        s.sock.logout(),
        new Promise((resolve) => setTimeout(resolve, LOGOUT_TIMEOUT_MS))
      ])
    }
  } catch (_) {
    // ignore — we tear the socket down regardless
  }

  // Detach listeners and close the WebSocket. Without this the socket stays
  // connected and its creds.update handler can recreate the directory we are
  // about to delete.
  try {
    s.sock?.ev?.removeAllListeners?.()
    s.sock?.end?.(undefined)
  } catch (_) {
    // ignore
  }
  s.sock = null

  sessions.delete(key)

  await fs.rm(sessionPathFor(tenantId, sessionId), { recursive: true, force: true })

  return true
}

// Flush all pending debounced writes. Called on SIGTERM/SIGINT so a restart
// does not silently discard up to 5s of chat/message state.
export async function flushAllPendingWrites() {
  for (const [id, timer] of chatSaveTimers) {
    clearTimeout(timer)
    chatSaveTimers.delete(id)
  }
  for (const [id, timer] of msgSaveTimers) {
    clearTimeout(timer)
    msgSaveTimers.delete(id)
  }

  for (const s of sessions.values()) {
    const dir = sessionPath(s)
    try {
      await writeChats(dir, s.chats)
      await writeDirtyMessages(dir, s.messages, s.dirtyChats)
    } catch (err) {
      console.error(`Flush failed for session ${s.sessionId}:`, err.message)
    }
  }
}

export function getSessionChats(tenantId, sessionId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return null

  const chatList = []
  for (const chat of s.chats.values()) {
    chatList.push(chat)
  }
  chatList.sort((a, b) => (b.lastTime || '').localeCompare(a.lastTime || ''))
  return chatList
}

export function getSessionMessages(tenantId, sessionId, chatId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return null

  return s.messages.get(chatId) || []
}

export async function getMediaBuffer(tenantId, sessionId, messageId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return { ok: false, error: 'Session not found' }

  // Find the message across all chats, prefer the copy with rawMessage
  let found = null
  for (const [, msgs] of s.messages) {
    for (let i = msgs.length - 1; i >= 0; i--) {
      if (msgs[i].id === messageId) {
        if (msgs[i].rawMessage) { found = msgs[i]; break }
        if (!found) found = msgs[i]
      }
    }
    if (found?.rawMessage) break
  }

  if (!found) return { ok: false, error: 'Message not found' }

  const mime = found.mediaMime || 'application/octet-stream'

  // 1. Try disk cache first (persists across restarts)
  const cached = await getMediaFromCache(tenantId, sessionId, messageId, mime)
  if (cached) {
    return { ok: true, buffer: cached, mime, filename: found.mediaFilename }
  }

  // 2. Try live download if rawMessage is available
  if (!found.rawMessage) return { ok: false, error: 'Media not available (cached media expired)' }

  const mediaType = found.mediaType
  const typeMap = { image: 'imageMessage', video: 'videoMessage', audio: 'audioMessage', voice: 'audioMessage', document: 'documentMessage', sticker: 'stickerMessage' }
  const msgKey = typeMap[mediaType]
  if (!msgKey || !found.rawMessage[msgKey]) return { ok: false, error: 'Unsupported media type for download' }

  try {
    const buffer = await downloadMediaMessage(
      { key: { remoteJid: found.chatId, fromMe: found.fromMe, id: found.id }, message: found.rawMessage },
      'buffer',
      {},
      { logger, reuploadRequest: s.sock?.updateMediaMessage }
    )

    // Cache to disk for future requests
    const dir = sessionPath(s)
    const filePath = getMediaPath(dir, messageId, mime)
    fs.mkdir(path.join(dir, MEDIA_DIR), { recursive: true })
      .then(() => fs.writeFile(filePath, buffer))
      .catch(() => {})

    return { ok: true, buffer, mime, filename: found.mediaFilename }
  } catch (err) {
    console.error(`Media download failed for ${messageId}:`, err.message)
    return { ok: false, error: 'Failed to download media: ' + err.message }
  }
}

export async function sendSessionMessage(tenantId, sessionId, chatId, text) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return { ok: false, error: 'Session not found' }
  if (!s.sock) return { ok: false, error: 'Socket not available' }
  if (s.status !== 'connected') return { ok: false, error: 'Session not connected' }

  try {
    const sent = await s.sock.sendMessage(chatId, { text })

    const entry = {
      id: sent.key.id,
      chatId,
      fromMe: true,
      text,
      time: new Date().toISOString()
    }

    if (!s.messages.has(chatId)) {
      s.messages.set(chatId, [])
    }
    s.messages.get(chatId).push(entry)

    s.chats.set(chatId, {
      id: chatId,
      name: s.chats.get(chatId)?.name || chatId.split('@')[0],
      lastMessage: text,
      lastTime: entry.time,
      isGroup: chatId.endsWith('@g.us'),
      phone: s.chats.get(chatId)?.phone || null
    })

    scheduleChatSave(s)
    scheduleMessageSave(s, chatId)

    return { ok: true, messageId: sent.key.id }
  } catch (err) {
    return { ok: false, error: err.message }
  }
}
