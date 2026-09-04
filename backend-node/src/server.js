import express from 'express'
import { waRouter } from './wa/wa.routes.js'
import { requireApiKey, requireTenant } from './middleware/auth.js'

export function createApp() {
  const app = express()

  app.disable('x-powered-by')
  app.use(express.json({ limit: '1mb' }))

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
