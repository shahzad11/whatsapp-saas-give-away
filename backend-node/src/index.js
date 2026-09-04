import dotenv from 'dotenv'

dotenv.config()

import { createApp } from './server.js'
import { initAuth } from './middleware/auth.js'
import { restoreAllSessions, flushAllPendingWrites } from './wa/wa.sessions.js'

const port = Number(process.env.PORT || 3001)
// Under Docker this is 0.0.0.0 — but the container port is never published, so
// the service is only reachable from inside the compose network. Network
// position is no longer the only control: every /api route also requires the
// shared API key and a tenant header (see middleware/auth.js).
const host = process.env.BIND_HOST || '127.0.0.1'

// Throws — and therefore refuses to boot — if BACKEND_API_KEY is missing.
initAuth()

const app = createApp()

let server

async function start() {
  await restoreAllSessions()
  server = app.listen(port, host, () => {
    console.log(`Backend listening on http://${host}:${port}`)
  })
}

let shuttingDown = false

async function shutdown(signal) {
  if (shuttingDown) return
  shuttingDown = true
  console.log(`Received ${signal} — flushing pending writes before exit`)

  if (server) server.close()

  try {
    await flushAllPendingWrites()
    console.log('Flush complete')
  } catch (err) {
    console.error('Flush failed:', err)
  }

  process.exit(0)
}

process.on('SIGTERM', () => shutdown('SIGTERM'))
process.on('SIGINT', () => shutdown('SIGINT'))

process.on('unhandledRejection', (reason) => {
  console.error('Unhandled promise rejection:', reason)
})

start().catch((err) => {
  console.error('Failed to start:', err)
  process.exit(1)
})
