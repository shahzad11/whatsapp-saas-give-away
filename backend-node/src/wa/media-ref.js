// Persisted media descriptor (#15).
//
// rawMessage is stripped before a message hits a shard, which used to mean a
// media message restored from disk could never be re-downloaded — only an
// eagerly cached file could be shown. mediaRef is the small JSON-safe part of
// the media sub-message that downloadMediaMessage needs to fetch the bytes
// again, so a shard entry alone is enough to recover media after a restart.

const MEDIA_KEYS = ['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage']

// Buffers arrive from Baileys as Buffer/Uint8Array, and come back from a JSON
// round-trip as { type: 'Buffer', data: [...] }. Anything else is not a byte
// field worth keeping.
function toB64(v) {
  if (v == null) return null
  if (Buffer.isBuffer(v) || v instanceof Uint8Array) {
    return Buffer.from(v).toString('base64')
  }
  if (v.type === 'Buffer' && Array.isArray(v.data)) {
    return Buffer.from(v.data).toString('base64')
  }
  return null
}

function fromB64(v) {
  return typeof v === 'string' ? Buffer.from(v, 'base64') : undefined
}

// fileLength is a protobuf long — Baileys may hand it over as a number, a
// decimal string, or a { low, high } Long object depending on the code path.
function toNum(v) {
  if (v == null) return null
  const n = Number(v)
  if (Number.isFinite(n)) return n
  if (typeof v === 'object' && Number.isFinite(Number(v.low))) {
    return Number(v.low) + Number(v.high || 0) * 0x100000000
  }
  return null
}

// The JSON-safe descriptor for a message's media sub-message, or null when the
// message carries no usable download coordinates (mediaKey + directPath are
// the minimum a live download needs).
export function mediaRefFromContent(content) {
  if (!content || typeof content !== 'object') return null
  for (const key of MEDIA_KEYS) {
    const m = content[key]
    if (!m) continue
    if (!m.mediaKey || !m.directPath) return null
    return {
      key,
      mediaKey: toB64(m.mediaKey),
      directPath: m.directPath || null,
      url: m.url || null,
      mimetype: m.mimetype || null,
      fileLength: toNum(m.fileLength),
      fileSha256: toB64(m.fileSha256),
      fileEncSha256: toB64(m.fileEncSha256)
    }
  }
  return null
}

// Rebuilds the { <type>Message: {...} } shape downloadMediaMessage expects as
// `message`, with the byte fields restored to Buffers.
export function contentFromMediaRef(ref) {
  if (!ref || !ref.key || !MEDIA_KEYS.includes(ref.key)) return null
  return {
    [ref.key]: {
      url: ref.url ?? undefined,
      directPath: ref.directPath ?? undefined,
      mediaKey: fromB64(ref.mediaKey),
      mimetype: ref.mimetype ?? undefined,
      fileLength: ref.fileLength ?? undefined,
      fileSha256: fromB64(ref.fileSha256),
      fileEncSha256: fromB64(ref.fileEncSha256)
    }
  }
}
