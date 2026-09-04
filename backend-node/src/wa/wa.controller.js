import QRCode from 'qrcode'
import { createNewSession, getTenantSessionSnapshots, getSessionSnapshot, getSessionChats, getSessionMessages, getMediaBuffer, sendSessionMessage, logoutAndDeleteSession } from './wa.sessions.js'

export async function createSession(req, res, next) {
  try {
    const { label } = req.body || {}
    const session = await createNewSession(req.tenantId, label)
    res.status(201).json({ ok: true, sessionId: session.sessionId, label: session.label })
  } catch (e) {
    next(e)
  }
}

export async function listSessions(req, res, next) {
  try {
    const list = getTenantSessionSnapshots(req.tenantId)
    return res.json({ ok: true, count: list.length, sessions: list })
  } catch (e) {
    next(e)
  }
}

export async function getStatus(req, res, next) {
  try {
    const { sessionId } = req.params
    const snap = getSessionSnapshot(req.tenantId, sessionId)
    if (!snap) {
      return res.status(404).json({ ok: false, error: 'Session not found' })
    }

    return res.json({ ok: true, ...snap })
  } catch (e) {
    next(e)
  }
}

export async function getQr(req, res, next) {
  try {
    const { sessionId } = req.params
    const snap = getSessionSnapshot(req.tenantId, sessionId)
    if (!snap) {
      return res.status(404).json({ ok: false, error: 'Session not found' })
    }

    if (snap.status === 'connected') {
      return res.json({ ok: true, status: 'connected' })
    }

    if (!snap.qr) {
      return res.status(409).json({ ok: false, error: 'QR not available yet', status: snap.status })
    }

    const qrImageBase64 = await QRCode.toDataURL(snap.qr)

    return res.json({
      ok: true,
      status: snap.status,
      qr: snap.qr,
      qrImageBase64
    })
  } catch (e) {
    next(e)
  }
}

export async function logoutSession(req, res, next) {
  try {
    const { sessionId } = req.params
    const existed = await logoutAndDeleteSession(req.tenantId, sessionId)
    if (!existed) {
      return res.status(404).json({ ok: false, error: 'Session not found' })
    }

    return res.json({ ok: true })
  } catch (e) {
    next(e)
  }
}

export async function getChats(req, res, next) {
  try {
    const { sessionId } = req.params
    const chats = getSessionChats(req.tenantId, sessionId)
    if (chats === null) {
      return res.status(404).json({ ok: false, error: 'Session not found' })
    }
    return res.json({ ok: true, chats })
  } catch (e) {
    next(e)
  }
}

export async function getMessages(req, res, next) {
  try {
    const { sessionId, chatId } = req.params
    const messages = getSessionMessages(req.tenantId, sessionId, chatId)
    if (messages === null) {
      return res.status(404).json({ ok: false, error: 'Session not found' })
    }
    return res.json({ ok: true, messages })
  } catch (e) {
    next(e)
  }
}

export async function downloadMedia(req, res, next) {
  try {
    const { sessionId, messageId } = req.params
    const result = await getMediaBuffer(req.tenantId, sessionId, messageId)
    if (!result.ok) {
      const status = result.error.includes('not found') ? 404 : 400
      return res.status(status).json({ ok: false, error: result.error })
    }

    res.set('Content-Type', result.mime)
    if (result.filename) {
      res.set('Content-Disposition', `inline; filename="${result.filename}"`)
    }
    res.send(result.buffer)
  } catch (e) {
    next(e)
  }
}

export async function sendMessage(req, res, next) {
  try {
    const { sessionId, chatId } = req.params
    const { text } = req.body || {}

    if (!text || !text.trim()) {
      return res.status(400).json({ ok: false, error: 'Text is required' })
    }

    const result = await sendSessionMessage(req.tenantId, sessionId, chatId, text.trim())
    if (!result.ok) {
      return res.status(400).json(result)
    }

    return res.json(result)
  } catch (e) {
    next(e)
  }
}
