import path from 'path'

// Sender-controlled values become filesystem paths (#6).
//
// Both halves of `media/<messageId>.<ext>` come from the sender's WhatsApp
// client: the message id and the declared MIME type. Nothing upstream of us is
// guaranteed to have cleaned them, so they are sanitised here — the one place
// every media path is built — and the result is verified to still be inside
// the session's media directory before it is ever handed to fs.

// Real WhatsApp message ids are hex/alnum. Anything outside this shape is a
// modified client; callers should skip caching for it entirely.
const SAFE_ID_RE = /^[A-Za-z0-9_-]{1,128}$/

export function isSafeMessageId(id) {
  return typeof id === 'string' && SAFE_ID_RE.test(id)
}

// Turns the untrusted pair into a filename that cannot leave `media/`.
// Defence is layered on purpose: the regexes make traversal impossible, and
// the resolve+prefix assertion catches the day a change here reopens it.
export function getMediaPath(sessionPath, messageId, mime) {
  const safeId = String(messageId ?? '').replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 128) || '_'

  // Extension from the mime subtype only: lowercased, parameters dropped, and
  // reduced to [a-z0-9] so `application/../../x` becomes a word, not a path.
  const subtype = String(mime || '').split('/')[1] || ''
  const safeExt = subtype.split(';')[0].toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 10) || 'bin'

  const mediaDir = path.resolve(sessionPath, 'media')
  const resolved = path.resolve(mediaDir, `${safeId}.${safeExt}`)
  if (!resolved.startsWith(mediaDir + path.sep)) {
    throw new Error(`media path escapes the session media dir: ${String(messageId).slice(0, 64)}`)
  }
  return resolved
}

// The same allow-list the PHP frontend applies (#7), kept in step with
// mediaResponseHeaders(): anything a browser could render as active content
// (html, svg, text/*, application/*) becomes an attachment with a neutral
// type. Defence in depth — the frontend proxy rebuilds these anyway, but this
// endpoint must not depend on that to be safe.
const MEDIA_INLINE_TYPES = new Set([
  'image/jpeg', 'image/png', 'image/gif', 'image/webp',
  'video/mp4', 'video/3gpp', 'video/webm',
  'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/amr',
])

export function mediaDownloadHeaders(mime, filename) {
  const base = String(mime || '').split(';')[0].trim().toLowerCase()
  const inline = MEDIA_INLINE_TYPES.has(base)

  const safe = String(filename || '')
    .split(/[\\/]/).pop()                 // basename
    .replace(/[^\w.\-]+/g, '_')
    .slice(0, 100) || 'media'

  return {
    'Content-Type': inline ? base : 'application/octet-stream',
    'Content-Disposition': `${inline ? 'inline' : 'attachment'}; filename="${safe === '..' ? 'media' : safe}"`,
    'X-Content-Type-Options': 'nosniff',
    'Content-Security-Policy': "sandbox; default-src 'none'",
  }
}
