import { createHash, timingSafeEqual } from 'crypto'

// Tenant ids are `t<userId>` where userId is the MySQL users.id owned by the
// PHP frontend. They are used to build filesystem paths, so the shape is
// validated before it can ever reach fs.
const TENANT_RE = /^t[0-9]{1,19}$/

const MIN_KEY_LENGTH = 32

let apiKeyDigest = null

export function isValidTenantId(id) {
  return typeof id === 'string' && TENANT_RE.test(id)
}

// Fail closed at boot rather than at first request: a backend running without
// a key configured would be an open door, and an open door that only reveals
// itself under traffic is worse than one that refuses to start.
export function initAuth() {
  const key = process.env.BACKEND_API_KEY
  if (!key || key.length < MIN_KEY_LENGTH) {
    throw new Error(`BACKEND_API_KEY must be set and at least ${MIN_KEY_LENGTH} characters`)
  }
  apiKeyDigest = createHash('sha256').update(key).digest()
}

// Compare digests, not the raw strings. Digests are always 32 bytes, so this is
// constant time and — unlike a length check followed by timingSafeEqual — does
// not leak the length of the configured key.
function keyMatches(provided) {
  if (typeof provided !== 'string' || provided.length === 0) return false
  const given = createHash('sha256').update(provided).digest()
  return timingSafeEqual(given, apiKeyDigest)
}

export function requireApiKey(req, res, next) {
  if (!apiKeyDigest) {
    return res.status(500).json({ ok: false, error: 'Auth not initialised' })
  }
  if (!keyMatches(req.get('x-api-key'))) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' })
  }
  next()
}

// Every tenant-scoped route reads req.tenantId. Nothing downstream ever takes a
// tenant id from the body, the query string or a path parameter — it comes from
// this header only, so a caller cannot address another tenant's data.
export function requireTenant(req, res, next) {
  const tenantId = req.get('x-tenant-id')
  if (!isValidTenantId(tenantId)) {
    return res.status(400).json({ ok: false, error: 'Missing or invalid X-Tenant-Id' })
  }
  req.tenantId = tenantId
  next()
}
