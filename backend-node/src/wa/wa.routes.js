import express from 'express'
import { createSession, listSessions, getQr, getStatus, logoutSession, relinkSession, getChats, getMessages, downloadMedia, sendMessage, sendMedia, sendEvent, markChatRead, transcodeAudio } from './wa.controller.js'
import { rateLimit } from '../middleware/rate-limit.js'

export const waRouter = express.Router()

// Limits are sized from what the frontend actually does, then given headroom,
// so a normal tenant never sees a 429:
//
//   link.php polls the QR every 3s          -> 20 req/min
//   chats.php polls messages every 5s       -> 12 req/min per open thread
//   accounts.php "Sync all" fires one       -> one /status per account, per click
//     /status per account in a single click
//
// Several browser tabs multiply that, hence a shared poll budget of 240/min
// (4/s) rather than something tight. The point is a ceiling, not a throttle.
const poll = rateLimit('poll', 240, 60_000)

// Sending is metered by the plan's monthly quota already; this bounds the
// *rate*, which the quota does not. Chatbot auto-replies go through the same
// endpoint, so it has to absorb a burst of inbound messages without stalling.
const send = rateLimit('send', 60, 60_000)

// Uploads are the expensive path: up to MAX_UPLOAD_BYTES each, base64-inflated,
// parsed into memory. A far lower ceiling is appropriate.
const upload = rateLimit('upload', 10, 60_000)

// Transcoding spends ffmpeg CPU on whatever bytes it is handed, once per
// inbound voice note. Burstier than uploads but still bounded.
const transcode = rateLimit('transcode', 30, 60_000)

// Creating a session spawns a Baileys socket and writes auth state to disk.
// Repeated calls are how you exhaust file descriptors, so this is the tightest
// bucket on the router.
const createLimit = rateLimit('create', 10, 5 * 60_000)

// Read receipts (#37). Its own bucket rather than the send one: every chatbot
// reply now makes both calls, and sharing a budget would mean a busy hour spent
// the send allowance twice as fast — throttling the actual replies to protect a
// receipt. Cheap enough to be generous with.
const receipt = rateLimit('receipt', 120, 60_000)

// Everything else: cheap, but not free.
const general = rateLimit('general', 60, 60_000)

waRouter.get('/sessions', general, listSessions)
waRouter.post('/sessions', createLimit, createSession)
waRouter.get('/sessions/:sessionId/status', poll, getStatus)
waRouter.get('/sessions/:sessionId/qr', poll, getQr)
waRouter.post('/sessions/:sessionId/logout', general, logoutSession)
// Relink spawns a socket exactly like create does, so it shares create's tight
// bucket rather than the general one.
waRouter.post('/sessions/:sessionId/relink', createLimit, relinkSession)
waRouter.get('/sessions/:sessionId/chats', poll, getChats)
waRouter.get('/sessions/:sessionId/chats/:chatId/messages', poll, getMessages)
waRouter.get('/sessions/:sessionId/messages/:messageId/media', general, downloadMedia)
waRouter.post('/sessions/:sessionId/chats/:chatId/messages', send, sendMessage)
waRouter.post('/sessions/:sessionId/chats/:chatId/media', upload, sendMedia)
waRouter.post('/audio/transcode', transcode, transcodeAudio)
// The appointment event card goes out in the same burst pattern as the .ics it
// replaces, so it shares the upload bucket rather than competing with chat.
waRouter.post('/sessions/:sessionId/chats/:chatId/event', upload, sendEvent)
waRouter.post('/sessions/:sessionId/chats/:chatId/read', receipt, markChatRead)
