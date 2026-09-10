import path from 'path'
import fs from 'fs/promises'
import { existsSync } from 'fs'
import { createHash, randomUUID } from 'crypto'
import { spawn } from 'child_process'
import { tmpdir } from 'os'
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

// Every message array is kept sorted oldest → newest. Baileys delivers history
// in arbitrary order across (and within) batches, so an append-only array ends
// up shuffled — which made the chat list order look random and, worse, made the
// trim below drop the *newest* messages because it discards from the front.
function msgTime(m) {
  const t = Date.parse(m?.time)
  return Number.isNaN(t) ? 0 : t
}

function sortMessages(msgs) {
  return msgs.sort((a, b) => msgTime(a) - msgTime(b))
}

// Mirrors the no-content check in storeMessage(), but for an already-normalised
// entry. Used to evict protocol/system messages persisted by earlier versions.
function hasRenderableContent(m) {
  return !!(m?.text || (m?.mediaType && m.mediaType !== 'text') || m?.contactInfo || m?.locationInfo)
}

// Binary insert: a bulk history dump is mostly out of order, so re-sorting the
// whole chat on every message would be O(n² log n) over a sync of thousands.
function insertMessageSorted(arr, entry) {
  const t = msgTime(entry)
  let lo = 0
  let hi = arr.length
  while (lo < hi) {
    const mid = (lo + hi) >> 1
    if (msgTime(arr[mid]) <= t) lo = mid + 1
    else hi = mid
  }
  arr.splice(lo, 0, entry)
}

// Keeping the newest MAX_MESSAGES_PER_CHAT is only correct because the array is
// sorted; see insertMessageSorted.
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
      // Sort on load: shards written before the ordering fix are shuffled, and
      // the sorted-array invariant has to hold before anything is inserted.
      // Filter too: earlier versions persisted content-less protocol messages.
      if (shard?.chatId && Array.isArray(shard.msgs)) {
        map.set(shard.chatId, sortMessages(shard.msgs.filter(hasRenderableContent)))
      }
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
        sortMessages(msgs)
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

const MEDIA_LABELS = { image: '📷 Photo', video: '🎥 Video', audio: '🎵 Audio', voice: '🎤 Voice message', document: '📄 Document', sticker: '🏷️ Sticker', contact: '👤 Contact', location: '📍 Location' }
const MEDIA_ICONS = { image: '📷', video: '🎥', audio: '🎵', voice: '🎤', document: '📄', sticker: '🏷️', contact: '👤', location: '📍' }

// The chat-list preview for a stored message: caption prefixed with a type
// icon, or a bare label when there is no caption.
function previewFor(entry) {
  const type = entry.mediaType || 'text'
  if (type === 'text') return entry.text || ''
  if (!entry.text) return MEDIA_LABELS[type] || type
  return `${MEDIA_ICONS[type] || ''} ${entry.text}`.trim()
}

// A chat whose lastMessage/lastTime came from a protocol message that has since
// been filtered out stays wrongly pinned to the top, because the summary is
// only ever allowed to move forwards in time. Re-derive it from the newest
// surviving message. Chats with no stored messages are left alone: their
// lastTime legitimately comes from WhatsApp's conversationTimestamp.
function repairChatSummaries(chats, messages) {
  let repaired = 0
  for (const chat of chats.values()) {
    const msgs = messages.get(chat.id)
    if (!msgs || msgs.length === 0) continue

    const newest = msgs[msgs.length - 1]
    if (!chat.lastTime || msgTime(newest) >= Date.parse(chat.lastTime)) continue

    chat.lastTime = newest.time
    chat.lastMessage = previewFor(newest)
    repaired++
  }
  return repaired
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

// WhatsApp nests real content inside these envelopes. Without unwrapping, a
// disappearing message or a view-once photo looks like an empty message and
// would be discarded by the no-content check in storeMessage().
const CONTENT_WRAPPERS = [
  'ephemeralMessage',
  'viewOnceMessage',
  'viewOnceMessageV2',
  'viewOnceMessageV2Extension',
  'documentWithCaptionMessage',
  'deviceSentMessage',
  'editedMessage'
]

function unwrapMessage(message) {
  let m = message
  // Envelopes nest (a view-once inside an ephemeral, say). The depth cap keeps
  // a malformed or hostile payload from spinning here.
  for (let depth = 0; m && depth < 5; depth++) {
    const key = CONTENT_WRAPPERS.find(k => m[k]?.message)
    if (!key) break
    m = m[key].message
  }
  return m
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
    // Types we do not render specially. Pulling their text out means they are
    // still stored and previewed rather than being dropped as "no content".
    message.pollCreationMessage?.name ||
    message.pollCreationMessageV2?.name ||
    message.pollCreationMessageV3?.name ||
    message.groupInviteMessage?.caption ||
    message.buttonsMessage?.contentText ||
    message.listMessage?.description ||
    message.templateMessage?.hydratedTemplate?.hydratedContentText ||
    message.interactiveMessage?.body?.text ||
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
  const content = unwrapMessage(msg.message)
  if (!content) return

  const media = detectMediaType(content)
  const text = extractText(content)
  const contactInfo = extractContactInfo(content)
  const locationInfo = extractLocationInfo(content)

  // Nothing a user could ever see: sender-key distribution, app-state sync,
  // protocol acks, reactions, poll votes. WhatsApp exchanges a burst of these
  // with the linked account's *own* number during QR pairing, which is why that
  // chat used to jump to the top of the list with a blank preview.
  if (media.type === 'text' && !text && !contactInfo && !locationInfo) return

  const timestamp = msg.messageTimestamp
    ? new Date(Number(msg.messageTimestamp) * 1000).toISOString()
    : new Date().toISOString()

  const isGroupChat = chatId.endsWith('@g.us')

  // In a group, key.remoteJid is the group, and key.participant is who actually
  // spoke. Without it every incoming group message is attributed to the group
  // itself, which is why group threads could not show per-sender names.
  const senderJid = fromMe
    ? null
    : (msg.key?.participant || msg.participant || (isGroupChat ? null : chatId))

  // pushName is the sender's self-chosen display name and is the best signal for
  // a group participant we have no contact entry for. A saved contact name still
  // wins, matching WhatsApp.
  const identity = senderJid ? resolveIdentity(sessionState, senderJid) : { name: null, phone: null }
  const senderName = identity.name
    || msg.pushName
    || (identity.phone ? '+' + identity.phone : null)

  const entry = {
    id: msg.key.id,
    chatId,
    fromMe,
    text,
    mediaType: media.type,
    mediaMime: media.mime,
    mediaFilename: media.filename,
    senderName,
    // Kept so the name can be re-resolved later: contact sync often arrives
    // after the messages it would have named.
    senderJid: senderJid || undefined,
    time: timestamp,
    contactInfo: contactInfo || undefined,
    locationInfo: locationInfo || undefined,
    // The unwrapped content, not msg.message: cacheMedia/getMediaBuffer look up
    // rawMessage['imageMessage'] etc., which is not present on the envelope.
    rawMessage: (media.type !== 'text') ? content : undefined
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
    insertMessageSorted(arr, entry)
    // Safe to drop from the front only because the array is sorted oldest-first.
    if (arr.length > MAX_MESSAGES_PER_CHAT) {
      arr.splice(0, arr.length - MAX_MESSAGES_PER_CHAT)
    }
  }

  // Async media caching to disk
  if (entry.rawMessage && media.type !== 'text' && media.type !== 'contact' && media.type !== 'location') {
    cacheMedia(sessionState, entry)
  }

  const preview = previewFor(entry)

  const isGroup = isGroupChat
  const existing = sessionState.chats.get(chatId)

  // A history dump replays old messages. Whichever one happened to be processed
  // last used to define the chat's lastMessage/lastTime, so the chat list — which
  // sorts on lastTime — came out in effectively random order. Only ever advance
  // the summary forwards in time.
  const isNewest = !existing?.lastTime || msgTime(entry) >= Date.parse(existing.lastTime)

  let chatName = existing?.name || chatId.split('@')[0]
  // pushName from an old message is stale; only trust it from the newest one.
  if (isNewest && !fromMe && !isGroup && msg.pushName) {
    chatName = msg.pushName
  }

  const existingPhone = existing?.phone || sessionState.phoneNumbers.get(chatId) || null
  sessionState.chats.set(chatId, {
    id: chatId,
    name: chatName,
    lastMessage: isNewest ? preview : (existing.lastMessage || ''),
    lastTime: isNewest ? timestamp : existing.lastTime,
    isGroup,
    phone: existingPhone,
    // Carried forward, never reset here. A new message does not un-archive a
    // chat in WhatsApp, and this function must not decide archive state.
    archived: existing?.archived || false
  })

  scheduleChatSave(sessionState)
  scheduleMessageSave(sessionState, chatId)
}

function getContactName(contact) {
  return contact.name || contact.notify || contact.verifiedName || null
}

// A "numeric name" is an identifier masquerading as a name: a JID, or the
// digits of one. Legacy group JIDs are `<phone>-<timestamp>`, so the hyphen has
// to be in the class or every such group passes as a real subject.
export function isNumericName(name) {
  if (!name) return true
  return /^[\d@.\-]+$/.test(name) || name.includes('@')
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

// The single place that decides what a JID is *called*.
//
// A raw JID must never reach the UI. Two kinds are unusable to a human:
// `<digits>@s.whatsapp.net` is at least a phone number, but `<opaque>@lid` is an
// internal identifier that means nothing at all — showing its digits is worse
// than showing nothing, because it looks like a phone number and is not one.
//
// Order: known contact name → the chat's own resolved name → the phone number
// in international form → null. Callers render null as "Unknown", never as the
// JID.
// Exported for unit testing: pure, and the single point where "what is this
// JID called" is decided, so it is worth pinning down directly.
export function resolveIdentity(sessionState, jid) {
  if (!jid) return { name: null, phone: null }

  const phone = sessionState.phoneNumbers.get(jid)
    || (jid.endsWith('@s.whatsapp.net') ? jid.split('@')[0] : null)

  let name = sessionState.contactNames.get(jid) || null
  if (isNumericName(name)) name = null

  if (!name) {
    const chat = sessionState.chats.get(jid)
    if (chat && !isNumericName(chat.name)) name = chat.name
  }

  return { name: name || null, phone: phone || null }
}

// Baileys has used both `archived` and `archive` across versions, and the value
// arrives as a boolean, a number, or absent. Absent must mean "unknown", not
// "not archived", or an app-state patch that omits the field would silently
// un-archive the chat.
export function readArchiveFlag(chat) {
  const raw = chat?.archived ?? chat?.archive
  if (raw === undefined || raw === null) return undefined
  return raw === true || raw === 1 || raw === '1' || raw === 'true'
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

// --- Chatbot hand-off -------------------------------------------------------
//
// The backend does not decide anything about the chatbot. It cannot: the plan,
// the tenant's configuration, the quota and the audit log all live in MySQL,
// which PHP owns. So this hands the message over and forgets about it. Every
// rule — including "never in a group, never in an archived chat" — is enforced
// on the PHP side, where it exists once instead of twice.
const FRONTEND_URL = (process.env.FRONTEND_URL || 'http://frontend').replace(/\/+$/, '')
const CHATBOT_HOOK_TIMEOUT_MS = 5000

// Fire and forget. The reply itself takes seconds (a model call plus a send) and
// nothing here waits for it: blocking the socket's event loop on an HTTP request
// would stall message ingest for every chat.
function notifyChatbot(sessionState, msg) {
    if (!process.env.BACKEND_API_KEY) return
    if (msg?.key?.fromMe) return
    if (msg?.key?.remoteJid === 'status@broadcast') return

    const content = unwrapMessage(msg.message)
    if (!content) return

    const media = detectMediaType(content)
    const text = extractText(content)
    // Nothing to answer: a reaction, a receipt, a poll vote.
    if (media.type === 'text' && !text) return

    const chatId = msg.key.remoteJid
    const chat = sessionState.chats.get(chatId)
    // The linked number's own chat. Answering it would have the bot talking to
    // itself, forever.
    const selfJid = sessionState.sock?.user?.id || ''
    const selfPhone = selfJid.split(':')[0].split('@')[0]
    const isSelfChat = !!selfPhone && chatId.startsWith(selfPhone + '@')

    const payload = JSON.stringify({
      sessionId: sessionState.sessionId,
      chatId,
      messageId: msg.key.id,
      text,
      mediaType: media.type,
      fromMe: false,
      isGroup: chatId.endsWith('@g.us'),
      // Read from the chat state the backend already keeps current through
      // chats.update, so an archived conversation is known as such immediately.
      archived: !!chat?.archived,
      isSelfChat
    })

    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), CHATBOT_HOOK_TIMEOUT_MS)

    fetch(`${FRONTEND_URL}/internal/chatbot-inbound.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Key': process.env.BACKEND_API_KEY,
        'X-Tenant-Id': sessionState.tenantId
      },
      body: payload,
      signal: controller.signal
    })
      .then(res => {
        // A 401 here means the two halves disagree about the shared secret —
        // silent chatbots are the hardest thing to debug, so say it loudly.
        if (res.status === 401) console.error('Chatbot hook rejected: BACKEND_API_KEY mismatch with the frontend')
      })
      .catch(err => {
        // An abort is expected: PHP keeps working after we stop listening
        // (ignore_user_abort), so a slow reply is not a failure.
        if (err?.name !== 'AbortError') console.error('Chatbot hook failed:', err.message)
      })
      .finally(() => clearTimeout(timer))
}

function attachSocketEvents(sessionState, sock, saveCreds) {
  sock.ev.on('creds.update', saveCreds)

  // markOnlineOnConnect: false is necessary but not sufficient on baileys
  // 7.0.0-rc14: Socket/socket.js announces the push name with a bare
  // <presence name="..."/> node on the first creds.update that carries it, and
  // a presence node with no type means 'available'. That fires during QR
  // pairing, so the phone goes quiet right after linking — which is exactly
  // when the user noticed it. Re-assert 'unavailable' to undo it.
  const assertOffline = async () => {
    if (!sessions.has(sessionKey(sessionState.tenantId, sessionState.sessionId))) return
    try {
      await sock.sendPresenceUpdate('unavailable')
    } catch (_) {
      // Best effort: presence must never take the session down.
    }
  }
  sessionState.assertOffline = assertOffline

  sock.ev.on('creds.update', (update) => {
    // Only the push-name announcement marks us available; ignore key rotations.
    if (update?.me?.name) trackTimeout(sessionState, assertOffline, 1000)
  })

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
        // conversationTimestamp is WhatsApp's own "last activity" for the chat,
        // so it is the authoritative sort key — better than the timestamp of
        // whichever message we happen to have received. Apply it even when the
        // chat already exists, but never move the chat backwards in time.
        const convTime = chat.conversationTimestamp
          ? new Date(Number(chat.conversationTimestamp) * 1000).toISOString()
          : null
        const existing = sessionState.chats.get(chat.id)

        const archived = readArchiveFlag(chat)

        if (existing) {
          if (convTime && (!existing.lastTime || convTime > existing.lastTime)) {
            existing.lastTime = convTime
          }
          if (chat.name && isNumericName(existing.name)) existing.name = chat.name
          // undefined means the payload did not say, which must not be read as
          // "not archived".
          if (archived !== undefined) existing.archived = archived
        } else {
          const mappedName = sessionState.contactNames.get(chat.id)
          sessionState.chats.set(chat.id, {
            id: chat.id,
            // Groups carry their subject in chat.name; prefer it over a contact
            // map entry, which for a group would only ever be the raw JID.
            name: chat.id.endsWith('@g.us')
              ? (chat.name || chat.subject || chat.id)
              : (mappedName || chat.name || chat.id.split('@')[0]),
            lastMessage: '',
            lastTime: convTime,
            isGroup: chat.id.endsWith('@g.us'),
            phone: sessionState.phoneNumbers.get(chat.id) || null,
            archived: archived === true
          })
        }
      }
    }

    if (syncedMessages) {
      // Process oldest → newest. Baileys hands the batch over in arbitrary
      // order; ingesting it as-is left message timelines shuffled and made each
      // chat's summary depend on iteration order rather than on recency.
      const ordered = [...syncedMessages].sort(
        (a, b) => Number(a.messageTimestamp || 0) - Number(b.messageTimestamp || 0)
      )
      for (const msg of ordered) {
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

  // Archiving is app-state data: it arrives as a patch after the initial sync,
  // and toggling it on the phone emits nothing else. Without this handler the
  // archive flag would only ever be as fresh as the last full history sync.
  sock.ev.on('chats.update', (updates) => {
    let touched = 0
    for (const update of updates || []) {
      if (!update?.id) continue
      const chat = sessionState.chats.get(update.id)
      if (!chat) continue

      const archived = readArchiveFlag(update)
      if (archived !== undefined && chat.archived !== archived) {
        chat.archived = archived
        touched++
      }
      // A renamed group announces itself here too.
      const newName = update.name || update.subject
      if (newName && !isNumericName(newName) && newName !== chat.name) {
        chat.name = newName
        touched++
      }
    }
    if (touched > 0) scheduleChatSave(sessionState)
  })

  // Group renames and membership changes.
  sock.ev.on('groups.update', (updates) => {
    let touched = 0
    for (const update of updates || []) {
      if (!update?.id || !update.subject) continue
      const chat = sessionState.chats.get(update.id)
      if (chat && chat.name !== update.subject) {
        chat.name = update.subject
        chat.isGroup = true
        touched++
      }
    }
    if (touched > 0) scheduleChatSave(sessionState)
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

  sock.ev.on('messages.upsert', ({ messages: msgs, type }) => {
    for (const msg of msgs) {
      storeMessage(sessionState, msg)
      // 'notify' means this arrived now. 'append' is backfill, and
      // messaging-history.set does not come through here at all — which is the
      // whole point: a 9,000-message history sync must never wake the chatbot.
      if (type === 'notify') notifyChatbot(sessionState, msg)
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

      // Re-assert at intervals: the push-name presence node races the initial
      // sync, so a single call at 'open' can be overridden moments later.
      assertOffline()
      trackTimeout(sessionState, assertOffline, 5000)
      trackTimeout(sessionState, assertOffline, 30000)

      trackTimeout(sessionState, async () => {
        if (!sessions.has(sessionKey(sessionState.tenantId, sessionState.sessionId))) return
        try {
          const groups = await sock.groupFetchAllParticipating()
          let added = 0
          let renamed = 0
          for (const [gid, meta] of Object.entries(groups)) {
            const existing = sessionState.chats.get(gid)
            if (!existing) {
              sessionState.chats.set(gid, {
                id: gid,
                name: meta.subject || gid,
                lastMessage: '',
                lastTime: meta.subjectTime
                  ? new Date(meta.subjectTime * 1000).toISOString()
                  : null,
                // This was missing, so every group discovered here was stored
                // with isGroup undefined and rendered as a 1:1 chat: no group
                // icon, and the thread showed no per-sender names.
                isGroup: true,
                phone: null,
                archived: false
              })
              added++
            } else {
              // The chat may already exist from a message, in which case its
              // name is the raw JID. The subject is authoritative for a group.
              existing.isGroup = true
              if (meta.subject && isNumericName(existing.name)) {
                existing.name = meta.subject
                renamed++
              }
            }
          }
          if (added > 0 || renamed > 0) {
            console.log(`Groups: ${added} added, ${renamed} named from subject (session ${sessionState.sessionId})`)
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
      // Baileys defaults this to true, which announces the client as
      // 'available'. WhatsApp then treats this as an active desktop session and
      // stops pushing notifications to the paired phone. See keepPhoneNotified.
      markOnlineOnConnect: false,
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
    // See the note in reconnectSession: keeps phone push notifications alive.
    markOnlineOnConnect: false,
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

    const repaired = repairChatSummaries(restoredChats, restoredMessages)
    if (repaired > 0) {
      console.log(`Session ${sessionId}: re-derived ${repaired} chat summary/ies that pointed at filtered protocol messages`)
    }

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
      // Baileys defaults this to true, which announces the client as
      // 'available'. WhatsApp then treats this as an active desktop session and
      // stops pushing notifications to the paired phone. See keepPhoneNotified.
      markOnlineOnConnect: false,
      generateHighQualityLinkPreview: false,
      connectTimeoutMs: 30000
    })

    sessionState.sock = sock
    sessions.set(sessionKey(tenantId, sessionId), sessionState)

    // Persist the repair so the corrected ordering survives even if this
    // process exits before the chat list changes again.
    if (repaired > 0) scheduleChatSave(sessionState)

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

// Re-authenticate an existing session without losing its history.
//
// A session that WhatsApp logged out, or that gave up after MAX_QR_RETRIES, is
// unusable and cannot recover on its own: the creds on disk are dead and no QR
// is being produced. The only previous way out was Remove + Link again, which
// mints a *new* sessionId — orphaning every chat, message and media file the old
// one owned, and every DB row keyed by it.
//
// So this keeps the sessionId and the history and throws away only the auth
// state, which is what is actually broken. The socket is rebuilt from an empty
// keystore, so Baileys emits a fresh QR and the same session pairs to a phone
// again.
//
// Only files are removed, never directories: messages/ and media/ are the
// history. Two files inside the session dir are also not auth state — meta.json
// (tenant, label) and chats.json — so they are kept by name. Everything else at
// the top level belongs to useMultiFileAuthState (creds.json, pre-key-*,
// session-*, sender-key-*, app-state-sync-*), and that set is open-ended enough
// that a keep-list is safer than a delete-list.
const NON_AUTH_FILES = new Set([META_FILE, CHATS_FILE])

async function clearAuthState(dir) {
  let entries = []
  try {
    entries = await fs.readdir(dir, { withFileTypes: true })
  } catch {
    return
  }
  for (const entry of entries) {
    if (!entry.isFile()) continue
    if (NON_AUTH_FILES.has(entry.name)) continue
    await fs.rm(path.join(dir, entry.name), { force: true }).catch(() => {})
  }
}

export async function relinkSession(tenantId, sessionId) {
  if (!isValidTenantId(tenantId)) return false
  if (!isValidSessionId(sessionId)) return false

  const dir = sessionPathFor(tenantId, sessionId)
  if (!existsSync(dir)) return false

  const key = sessionKey(tenantId, sessionId)
  const existing = sessions.get(key)

  // Pending history writes are flushed rather than cancelled — unlike logout,
  // this session's chats and messages are being kept, so discarding up to 5s of
  // them here would lose data the tenant still owns.
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

  if (existing) {
    // Cancel the reconnect/sync timers first. A pending reconnectSession would
    // otherwise fire against the keystore we are about to empty.
    for (const t of existing.timers) clearTimeout(t)
    existing.timers.clear()
    if (existing.syncTimeout) clearTimeout(existing.syncTimeout)
    existing.syncTimeout = null

    try {
      await writeChats(dir, existing.chats)
      await writeDirtyMessages(dir, existing.messages, existing.dirtyChats)
    } catch (err) {
      console.error(`Relink flush failed for session ${sessionId}:`, err.message)
    }

    // No sock.logout() here: on a logged-out or failed session the reply never
    // comes, and telling WhatsApp to unlink a device it already unlinked buys
    // nothing. Detaching the listeners is what matters — a live creds.update
    // handler would rewrite creds.json straight after we delete it.
    try {
      existing.sock?.ev?.removeAllListeners?.()
      existing.sock?.end?.(undefined)
    } catch (_) {
      // ignore — the socket is going away either way
    }
    existing.sock = null
  }

  await clearAuthState(dir)

  const meta = await readMeta(dir)
  // Drop the paired identity from meta: it belongs to the phone that just went
  // away. Leaving it would make a backend restart mid-relink report a user and a
  // connectedAt for a session that has never paired.
  await writeMeta(dir, { tenantId, label: meta.label || null })

  const { state, saveCreds } = await useMultiFileAuthState(dir)

  const sessionState = existing || {
    tenantId,
    sessionId,
    label: meta.label || null,
    chats: await readChats(dir),
    messages: await readMessages(dir),
    dirtyChats: new Set(),
    phoneNumbers: new Map(),
    contactNames: new Map(),
    timers: new Set()
  }

  sessionState.status = 'qr_required'
  sessionState.qr = null
  sessionState.user = null
  sessionState.connectedAt = null
  sessionState.lastDisconnectAt = null
  sessionState.lastDisconnectReason = null
  sessionState.retries = 0

  const version = await getWaVersion()
  const sock = makeWASocket({
    auth: state,
    version,
    browser: Browsers.macOS('Safari'),
    logger,
    syncFullHistory: true,
    // See the note in reconnectSession: keeps phone push notifications alive.
    markOnlineOnConnect: false,
    generateHighQualityLinkPreview: false,
    connectTimeoutMs: 30000
  })

  sessionState.sock = sock
  sessions.set(key, sessionState)

  attachSocketEvents(sessionState, sock, saveCreds)

  console.log(`Relinked session ${sessionId} for tenant ${tenantId} — auth state cleared, history kept`)
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
    // displayName is resolved at read time, not at ingest: contact sync usually
    // lands after the chats it would have named, so a name baked in at ingest
    // stays stale. null means "we genuinely do not know" — the caller must
    // render that as Unknown rather than falling back to the JID.
    const identity = resolveIdentity(s, chat.id)
    const displayName = chat.isGroup
      ? (isNumericName(chat.name) ? null : chat.name)
      : (identity.name || (identity.phone ? '+' + identity.phone : null))

    chatList.push({
      ...chat,
      archived: chat.archived === true,
      phone: chat.phone || identity.phone || null,
      displayName
    })
  }
  // Most recent first, chats that have never had activity last. Compared as
  // instants rather than strings so a malformed/legacy lastTime cannot wedge a
  // chat at the top of the list.
  chatList.sort((a, b) => {
    const ta = a.lastTime ? Date.parse(a.lastTime) : 0
    const tb = b.lastTime ? Date.parse(b.lastTime) : 0
    return (Number.isNaN(tb) ? 0 : tb) - (Number.isNaN(ta) ? 0 : ta)
  })
  return chatList
}

// `since` (epoch ms, exclusive) returns only messages newer than that instant.
// Callers that omit it still get the whole thread, so the chatbot history fetch
// and any existing client are unaffected.
//
// The caller polls every 5s; without this it received — and re-wrote — every
// message in the chat each time, up to MAX_MESSAGES_PER_CHAT.
export function getSessionMessages(tenantId, sessionId, chatId, since = null) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return null

  let msgs = s.messages.get(chatId) || []

  // Binary search, not filter: the array is kept sorted oldest → newest (see
  // insertMessageSorted), so the cut point is findable in log n instead of
  // walking thousands of entries on every poll.
  if (since !== null && msgs.length > 0) {
    let lo = 0
    let hi = msgs.length
    while (lo < hi) {
      const mid = (lo + hi) >> 1
      if (msgTime(msgs[mid]) <= since) lo = mid + 1
      else hi = mid
    }
    msgs = msgs.slice(lo)
  }

  if (!chatId.endsWith('@g.us')) return msgs

  // Group threads show who spoke. Re-resolve per read for the same reason as
  // the chat list: a participant's contact entry usually arrives after their
  // messages, so the name stored at ingest is often just their pushName or
  // nothing at all.
  return msgs.map(m => {
    if (m.fromMe || !m.senderJid) return m
    const identity = resolveIdentity(s, m.senderJid)
    const better = identity.name
      || m.senderName
      || (identity.phone ? '+' + identity.phone : null)
    return better === m.senderName ? m : { ...m, senderName: better }
  })
}

// Resolves a message's media to a **file on disk** wherever possible, so the
// response can be streamed instead of held in memory. A 166 MB video in the
// cache was enough to kill a 256 MB PHP worker on every request for it; holding
// the same bytes in Node is no better, just quieter.
//
// Returns { path } for anything cached (the normal case) and only falls back to
// { buffer } when a live download could not be written to disk.
export async function getMedia(tenantId, sessionId, messageId) {
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
  const filePath = getMediaPath(sessionPathFor(tenantId, sessionId), messageId, mime)

  // 1. Disk cache (persists across restarts). Hand back the path, not the bytes.
  if (existsSync(filePath)) {
    try {
      const st = await fs.stat(filePath)
      if (st.size > 0) return { ok: true, path: filePath, size: st.size, mime, filename: found.mediaFilename }
    } catch (_) {
      // fall through to a live download
    }
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

    // Cache to disk, then serve from there — awaited rather than fire-and-forget
    // so this request streams too. Only a failed write falls back to the buffer.
    try {
      await fs.mkdir(path.join(sessionPath(s), MEDIA_DIR), { recursive: true })
      await fs.writeFile(filePath, buffer)
      return { ok: true, path: filePath, size: buffer.length, mime, filename: found.mediaFilename }
    } catch (_) {
      return { ok: true, buffer, mime, filename: found.mediaFilename }
    }
  } catch (err) {
    console.error(`Media download failed for ${messageId}:`, err.message)
    return { ok: false, error: 'Failed to download media: ' + err.message }
  }
}

// The three guards every send has to pass. Returned as a value rather than
// thrown so both send paths answer with the same shapes.
function sendableSession(tenantId, sessionId) {
  const s = sessions.get(sessionKey(tenantId, sessionId))
  if (!s) return { error: 'Session not found' }
  if (!s.sock) return { error: 'Socket not available' }
  if (s.status !== 'connected') return { error: 'Session not connected' }
  return { session: s }
}

// Files a message we just sent into the in-memory thread and moves the chat
// summary forward. Shared by text and media sends so an attachment cannot end
// up half-recorded (in the thread but not in the chat list, say).
function recordOutgoing(s, entry) {
  const { chatId } = entry
  if (!s.messages.has(chatId)) {
    s.messages.set(chatId, [])
  }
  insertMessageSorted(s.messages.get(chatId), entry)

  const existing = s.chats.get(chatId)
  s.chats.set(chatId, {
    ...existing,
    id: chatId,
    name: existing?.name || chatId.split('@')[0],
    lastMessage: previewFor(entry),
    lastTime: entry.time,
    isGroup: chatId.endsWith('@g.us'),
    phone: existing?.phone || null,
    // Replying does not un-archive a chat.
    archived: existing?.archived || false
  })

  scheduleChatSave(s)
  scheduleMessageSave(s, chatId)
}

export async function sendSessionMessage(tenantId, sessionId, chatId, text) {
  const { session: s, error } = sendableSession(tenantId, sessionId)
  if (error) return { ok: false, error }

  try {
    const sent = await s.sock.sendMessage(chatId, { text })

    const entry = {
      id: sent.key.id,
      chatId,
      fromMe: true,
      text,
      time: new Date().toISOString()
    }

    recordOutgoing(s, entry)

    return { ok: true, messageId: sent.key.id }
  } catch (err) {
    return { ok: false, error: err.message }
  }
}

// Marks a chat as read on WhatsApp (#37).
//
// Two different things, both of which a person means by "read": the receipt
// that turns the sender's ticks blue, and the unread badge on the tenant's own
// phone. readMessages() does the first; chatModify() does the second, and it is
// best-effort — a Baileys build or a chat state that will not take it must not
// fail the call, because the reply it follows has already been sent.
//
// Only inbound messages get a receipt, and only ones newer than the last
// receipt this process sent for the chat: re-acknowledging the whole thread on
// every reply is a burst of traffic WhatsApp reads as automation.
export async function markSessionChatRead(tenantId, sessionId, chatId) {
  const { session: s, error } = sendableSession(tenantId, sessionId)
  if (error) return { ok: false, error }

  if (!s.readUpTo) s.readUpTo = new Map()
  const since = s.readUpTo.get(chatId) || 0

  // participant identifies who spoke inside a group, where remoteJid is the
  // group itself. In a one-to-one chat storeMessage() records the sender as the
  // chat, so passing it here would set participant to the same jid as remoteJid
  // — which is not what a receipt for a direct message looks like.
  const isGroup = chatId.endsWith('@g.us')

  const msgs = s.messages.get(chatId) || []
  const keys = []
  let newest = since
  // Newest first, and never more than a page of them: the receipt only has to
  // reach the last unacknowledged messages, not the entire history.
  for (let i = msgs.length - 1; i >= 0 && keys.length < 20; i--) {
    const m = msgs[i]
    const t = msgTime(m)
    if (t <= since) break
    if (m.fromMe) continue
    keys.push({
      remoteJid: chatId,
      id: m.id,
      participant: isGroup ? (m.senderJid || undefined) : undefined
    })
    if (t > newest) newest = t
  }

  if (keys.length === 0) return { ok: true, marked: 0 }

  try {
    await s.sock.readMessages(keys)
  } catch (err) {
    return { ok: false, error: err.message }
  }
  s.readUpTo.set(chatId, newest)

  try {
    const last = msgs[msgs.length - 1]
    await s.sock.chatModify(
      { markRead: true, lastMessages: [{ key: { remoteJid: chatId, fromMe: !!last.fromMe, id: last.id }, messageTimestamp: Math.floor(msgTime(last) / 1000) }] },
      chatId
    )
  } catch (_) {
    // The receipt is what the customer sees; the badge is housekeeping.
  }

  return { ok: true, marked: keys.length }
}

// What the composer may send, and what WhatsApp content each maps to. 'voice'
// is not a WhatsApp type — it is an audioMessage with ptt set, which is the one
// flag that makes a recipient's client render a waveform instead of a file.
export const UPLOAD_KINDS = ['image', 'video', 'audio', 'voice', 'document']

// 16 MB, matching WhatsApp Web's own ceiling for photos and video. Enforced in
// four places on purpose (browser, PHP upload, JSON body parser, here): the
// browser check is UX, the rest are the actual limit.
export const MAX_UPLOAD_BYTES = 16 * 1024 * 1024

// A voice note has to be ogg/opus. MediaRecorder produces webm/opus in Chrome,
// ogg/opus in Firefox and mp4/aac in Safari, so anything that is not already
// ogg is transcoded rather than passed through — WhatsApp clients render a
// mislabelled voice note as a broken attachment.
//
// Temp files rather than pipes because Safari's mp4 needs a seekable input, and
// ffmpeg cannot seek a pipe.
async function transcodeToOpus(buffer) {
  const base = path.join(tmpdir(), `wa-voice-${randomUUID()}`)
  const inPath = base + '.in'
  const outPath = base + '.ogg'

  try {
    await fs.writeFile(inPath, buffer)
    await new Promise((resolve, reject) => {
      const ff = spawn('ffmpeg', [
        '-hide_banner', '-loglevel', 'error', '-y',
        '-i', inPath,
        '-vn', '-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-ac', '1',
        '-f', 'ogg', outPath
      ])
      let stderr = ''
      ff.stderr.on('data', d => { stderr += d.toString().slice(0, 500) })
      ff.on('error', err => reject(new Error(
        err.code === 'ENOENT' ? 'Voice notes need ffmpeg, which is not installed' : err.message
      )))
      ff.on('close', code => code === 0
        ? resolve()
        : reject(new Error('Audio conversion failed' + (stderr ? ': ' + stderr.trim() : ''))))
    })
    return await fs.readFile(outPath)
  } finally {
    await Promise.all([fs.rm(inPath, { force: true }), fs.rm(outPath, { force: true })])
  }
}

// The WhatsApp content object for an attachment. Kept separate from the send so
// the mapping is testable without a live socket — it is the part that decides
// what a recipient's phone actually renders:
//   ptt: true             — a voice message with a waveform, not an audio file
//   fileName on documents — what the recipient sees and saves it as
//   caption: undefined    — omitted rather than empty, which WhatsApp treats as
//                           a real (blank) caption on some clients
export function buildMediaContent(kind, buffer, mime, filename, caption) {
  switch (kind) {
    case 'image': return { image: buffer, mimetype: mime, caption: caption || undefined }
    case 'video': return { video: buffer, mimetype: mime, caption: caption || undefined }
    case 'audio': return { audio: buffer, mimetype: mime, ptt: false }
    case 'voice': return { audio: buffer, mimetype: mime, ptt: true }
    case 'document': return {
      document: buffer,
      mimetype: mime,
      fileName: filename || 'file',
      caption: caption || undefined
    }
    default: return null
  }
}

export async function sendSessionMedia(tenantId, sessionId, chatId, upload) {
  const { session: s, error } = sendableSession(tenantId, sessionId)
  if (error) return { ok: false, error }

  const { kind, filename, caption = '' } = upload
  if (!UPLOAD_KINDS.includes(kind)) return { ok: false, error: 'Unsupported attachment type' }
  if (!Buffer.isBuffer(upload.buffer) || upload.buffer.length === 0) {
    return { ok: false, error: 'Attachment is empty' }
  }
  if (upload.buffer.length > MAX_UPLOAD_BYTES) {
    return { ok: false, error: `Attachment exceeds the ${Math.round(MAX_UPLOAD_BYTES / 1048576)} MB limit` }
  }

  let buffer = upload.buffer
  let mime = upload.mime || 'application/octet-stream'

  if (kind === 'voice') {
    if (!mime.startsWith('audio/ogg')) {
      try {
        buffer = await transcodeToOpus(buffer)
      } catch (err) {
        // Fail closed: sending the original would arrive as an unplayable file.
        return { ok: false, error: err.message }
      }
    }
    mime = 'audio/ogg; codecs=opus'
  }

  const content = buildMediaContent(kind, buffer, mime, filename, caption)

  try {
    const sent = await s.sock.sendMessage(chatId, content)

    const entry = {
      id: sent.key.id,
      chatId,
      fromMe: true,
      text: kind === 'audio' || kind === 'voice' ? '' : caption,
      mediaType: kind,
      mediaMime: mime,
      mediaFilename: kind === 'document' ? (filename || 'file') : null,
      time: new Date().toISOString(),
      // The unwrapped content, matching storeMessage(), so a later cache miss
      // can still re-download this message's media.
      rawMessage: sent.message ? unwrapMessage(sent.message) : undefined
    }

    // Seed the disk cache from what we already hold in memory. Without this the
    // thread would have to fetch our own attachment back out of WhatsApp to
    // render it, which costs a round trip and fails while offline.
    const dir = sessionPath(s)
    try {
      await fs.mkdir(path.join(dir, MEDIA_DIR), { recursive: true })
      await fs.writeFile(getMediaPath(dir, entry.id, mime), buffer)
    } catch (_) {
      // Cache-only failure. The message is sent; getMediaBuffer falls back to a
      // live download.
    }

    recordOutgoing(s, entry)

    return { ok: true, messageId: sent.key.id, mediaType: kind }
  } catch (err) {
    return { ok: false, error: err.message }
  }
}
