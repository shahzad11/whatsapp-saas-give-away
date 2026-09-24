import { test, before } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { createHash } from 'node:crypto'

// Regression for the #16 data loss: memory is bounded to 200, but trimming to
// that bound at *insert* time drops entries before the debounced flush can
// merge them into the shard. The trim is legal only after the merge, inside
// writeDirtyMessages.
//
// DATA_DIR must point at the temp dir before the module is imported — it is
// captured there at load time.
const DATA = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-flush-'))
process.env.DATA_DIR = DATA

let storeMessage, writeDirtyMessages
before(async () => {
  const mod = await import('../src/wa/wa.sessions.js')
  storeMessage = mod.storeMessage
  writeDirtyMessages = mod.writeDirtyMessages
})

const SID = '11111111-1222-4321-8321-444444444444'
const CHAT = '15550001@s.whatsapp.net'
const sessionDir = path.join(DATA, 'tenants', 't1', 'sessions', SID)
const shardFile = path.join(sessionDir, 'messages', createHash('sha1').update(CHAT).digest('hex') + '.json')

function newSessionState() {
  return {
    tenantId: 't1',
    sessionId: SID,
    chats: new Map(),
    messages: new Map(),
    dirtyChats: new Set(),
    chatTouchedAt: new Map(),
    phoneNumbers: new Map(),
    contactNames: new Map(),
    timers: new Set(),
    sock: null
  }
}

const msg = (i) => ({
  key: { remoteJid: CHAT, id: 'id-' + String(i).padStart(6, '0'), fromMe: false },
  message: { conversation: 'm' + i },
  messageTimestamp: 1700000000 + i
})

function shardMsgs() {
  return JSON.parse(fs.readFileSync(shardFile, 'utf-8')).msgs
}

test('a 1000-message history batch survives the flush intact', async () => {
  const s = newSessionState()
  const order = Array.from({ length: 1000 }, (_, i) => i)
  // Shuffle: Baileys delivers history in arbitrary order.
  for (let i = order.length - 1; i > 0; i--) {
    const j = (i * 37 + 11) % (i + 1);
    [order[i], order[j]] = [order[j], order[i]]
  }
  for (const i of order) storeMessage(s, msg(i))

  await writeDirtyMessages(sessionDir, s.messages, s.dirtyChats)

  const msgs = shardMsgs()
  assert.equal(msgs.length, 1000)
  for (let i = 1; i < msgs.length; i++) {
    assert.ok(Date.parse(msgs[i].time) >= Date.parse(msgs[i - 1].time), 'shard is sorted oldest→newest')
  }
  assert.ok(s.messages.get(CHAT).length <= 200, `memory trimmed to bound, got ${s.messages.get(CHAT).length}`)
})

test('a message older than the loaded tail still merges into the shard', async () => {
  // A 300-message shard already on disk; memory will lazily hold its tail of 200.
  const disk = Array.from({ length: 300 }, (_, i) => ({
    id: 'd' + String(i).padStart(4, '0'),
    chatId: CHAT,
    text: 'd' + i,
    time: new Date(1600000000000 + i * 1000).toISOString()
  }))
  fs.mkdirSync(path.dirname(shardFile), { recursive: true })
  fs.writeFileSync(shardFile, JSON.stringify({ chatId: CHAT, msgs: disk }))

  const s = newSessionState()
  // Older than everything on disk: goes in at the front of the loaded tail.
  storeMessage(s, {
    key: { remoteJid: CHAT, id: 'oldest', fromMe: false },
    message: { conversation: 'older than all' },
    messageTimestamp: 1500000000
  })

  await writeDirtyMessages(sessionDir, s.messages, s.dirtyChats)

  const msgs = shardMsgs()
  assert.equal(msgs.length, 301)
  assert.equal(msgs[0].id, 'oldest')
})
