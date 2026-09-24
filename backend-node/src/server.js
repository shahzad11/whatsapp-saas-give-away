import express from 'express'
import { waRouter } from './wa/wa.routes.js'
import { MAX_UPLOAD_BYTES } from './wa/wa.sessions.js'
import { requireApiKey, requireTenant } from './middleware/auth.js'

// base64 inflates a payload by a third; the slack covers the JSON envelope.
const UPLOAD_BODY_LIMIT = Math.ceil(MAX_UPLOAD_BYTES * 4 / 3) + 64 * 1024

export function createApp() {
  const app = express()

  app.disable('x-powered-by')

  // Only the attachment endpoint gets the large body limit. Raising it globally
  // would let any endpoint be handed 20 MB to parse, which is a cheap way to
  // make the backend spend memory.
  const jsonDefault = express.json({ limit: '1mb' })
  const jsonUpload = express.json({ limit: UPLOAD_BODY_LIMIT })
  app.use((req, res, next) => {
    // req.path here is the full path — this middleware is mounted on the app,
    // before any router, so the /api/v1/wa prefix is still on it.
    const isUpload = req.method === 'POST'
      && (req.path.endsWith('/media') || req.path === '/api/v1/wa/audio/transcode')
    const parser = isUpload ? jsonUpload : jsonDefault
    return parser(req, res, next)
  })

  // Unauthenticated on purpose: the container healthcheck runs before any
  // secret is available to it, and this leaks nothing.
  app.get('/health', (req, res) => {
    res.json({ ok: true })
  })

  // Order matters. The API key proves the caller is the PHP frontend; the
  // tenant header says which tenant it is acting for. Both are mandatory for
  // every /api route, so a new endpoint cannot be added without them.
  app.use('/api/v1/wa', requireApiKey, requireTenant, waRouter)

  app.use((err, req, res, next) => {
    const status = err?.statusCode || err?.status || 500
    res.status(status).json({
      ok: false,
      error: err?.message || 'Internal Server Error'
    })
  })

  return app
}
