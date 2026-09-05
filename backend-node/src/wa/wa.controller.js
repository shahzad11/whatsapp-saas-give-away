import QRCode from 'qrcode'
import { createNewSession, getTenantSessionSnapshots, getSessionSnapshot, getSessionChats, getSessionMessages, getMedia, sendSessionMessage, sendSessionMedia, logoutAndDeleteSession, UPLOAD_KINDS, MAX_UPLOAD_BYTES } from './wa.sessions.js'

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
    const result = await getMedia(req.tenantId, sessionId, messageId)
    if (!result.ok) {
      const status = result.error.includes('not found') ? 404 : 400
      return res.status(status).json({ ok: false, error: result.error })
    }

    const headers = { 'Content-Type': result.mime }
    if (result.filename) {
      // Quotes and CR/LF would let a remote-supplied filename inject a header.
      headers['Content-Disposition'] = `inline; filename="${sanitizeFilename(result.filename)}"`
    }

    // Streamed from disk in the normal case: res.sendFile handles Range (so a
    // long video can be seeked instead of downloaded whole), Content-Length and
    // conditional requests. A buffer only happens when the cache write failed.
    if (result.path) {
      res.set(headers)
      return res.sendFile(result.path, { headers, acceptRanges: true }, err => {
        if (err && !res.headersSent) next(err)
      })
    }

    res.set(headers)
    res.send(result.buffer)
  } catch (e) {
    next(e)
  }
}

// A document's filename comes from the sender, i.e. from outside.
function sanitizeFilename(name) {
  return String(name).replace(/[\r\n"\\]/g, '_').slice(0, 200)
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

// Attachments arrive base64-encoded inside JSON rather than as multipart. The
// frontend is a PHP process that already speaks JSON to this API over an
// authenticated internal hop, so this keeps one transport and one auth path
// instead of adding a multipart parser to the backend.
export async function sendMedia(req, res, next) {
  try {
    const { sessionId, chatId } = req.params
    const { kind, data, mimetype, fileName, caption } = req.body || {}

    if (!UPLOAD_KINDS.includes(kind)) {
      return res.status(400).json({ ok: false, error: 'Unsupported attachment type' })
    }
    if (typeof data !== 'string' || data.length === 0) {
      return res.status(400).json({ ok: false, error: 'Attachment data is required' })
    }

    // Buffer.from ignores anything that is not base64, so a garbage payload
    // decodes to a short buffer rather than failing. Length is the check.
    const buffer = Buffer.from(data, 'base64')
    if (buffer.length === 0) {
      return res.status(400).json({ ok: false, error: 'Attachment could not be decoded' })
    }
    if (buffer.length > MAX_UPLOAD_BYTES) {
      return res.status(413).json({
        ok: false,
        error: `Attachment exceeds the ${Math.round(MAX_UPLOAD_BYTES / 1048576)} MB limit`
      })
    }

    const result = await sendSessionMedia(req.tenantId, sessionId, chatId, {
      kind,
      buffer,
      mime: typeof mimetype === 'string' ? mimetype : null,
      filename: typeof fileName === 'string' ? fileName : null,
      caption: typeof caption === 'string' ? caption.trim() : ''
    })

    if (!result.ok) {
      return res.status(400).json(result)
    }

    return res.json(result)
  } catch (e) {
    next(e)
  }
}
