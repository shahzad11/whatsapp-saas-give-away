// Per-tenant rate limiting.
//
// The API key only proves the caller is the PHP frontend; X-Tenant-Id says who
// it is acting for. Before this, an authenticated tenant faced no ceiling at
// all, so one could spam sends, hammer the QR/status poll, or push media
// uploads until the box gave out — and the blast radius was every other tenant
// on the instance, because Baileys sockets and this process are shared.
//
// Hand-rolled rather than express-rate-limit: this repo carries no framework
// middleware beyond express itself, and the whole mechanism is a token bucket.
// Adding a dependency (and its transitive tree) to avoid forty lines is a worse
// trade than owning them.
//
// In-memory, so limits are per process. That is exactly right for what is being
// protected — this process's CPU, sockets and event loop — and it means no Redis
// to run. If the backend is ever scaled past one replica, the WhatsApp sessions
// pin a tenant to a process anyway, so the bucket stays where the load lands.

const buckets = new Map()

// Full buckets carry no information: a tenant at capacity is indistinguishable
// from one that has never called. Dropping them keeps the map proportional to
// *active* tenants rather than to every tenant that has ever connected.
const SWEEP_INTERVAL_MS = 5 * 60 * 1000
const IDLE_MS = 10 * 60 * 1000

function sweep(now = Date.now()) {
  for (const [key, bucket] of buckets) {
    if (now - bucket.updatedAt > IDLE_MS) buckets.delete(key)
  }
}

// unref() so a quiet backend can still exit; a live timer would hold the event
// loop open and make the container hang on shutdown.
const sweeper = setInterval(sweep, SWEEP_INTERVAL_MS)
if (typeof sweeper.unref === 'function') sweeper.unref()

// Continuous refill rather than fixed windows. A fixed window lets a caller
// spend a full quota at 0:59 and another at 1:01, so the real burst is double
// the configured limit; a bucket that drips cannot be gamed that way.
//
// Returns 0 when the request may proceed, otherwise the milliseconds until it
// could.
function consume(key, capacity, refillPerMs) {
  const now = Date.now()
  let bucket = buckets.get(key)
  if (!bucket) {
    bucket = { tokens: capacity, updatedAt: now }
    buckets.set(key, bucket)
  }

  bucket.tokens = Math.min(capacity, bucket.tokens + (now - bucket.updatedAt) * refillPerMs)
  bucket.updatedAt = now

  if (bucket.tokens < 1) return Math.ceil((1 - bucket.tokens) / refillPerMs)
  bucket.tokens -= 1
  return 0
}

// `name` scopes the bucket, so an exhausted upload quota cannot also block
// polling. Routes sharing a name share a budget deliberately — the poll
// endpoints do, because they are the same behaviour from the server's side.
export function rateLimit(name, limit, windowMs) {
  const refillPerMs = limit / windowMs
  return function rateLimiter(req, res, next) {
    // Tenant-scoped routes key on the tenant. Routes mounted without
    // requireTenant — the system stats endpoint — share a single global slot,
    // so they are still bounded rather than free.
    const who = req.tenantId || '_global'
    const waitMs = consume(`${who}|${name}`, limit, refillPerMs)
    if (waitMs === 0) return next()

    const retryAfter = Math.max(1, Math.ceil(waitMs / 1000))
    res.set('Retry-After', String(retryAfter))
    return res.status(429).json({
      ok: false,
      error: `Too many requests. Try again in ${retryAfter}s.`
    })
  }
}

// Exported for tests and for a future admin/debug view.
export function _resetRateLimits() {
  buckets.clear()
}
