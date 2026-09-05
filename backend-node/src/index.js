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
let reminderTimer

// Appointment reminders need something that runs continuously, and this process
// is the only such thing in the stack. It does not decide anything: it pokes a
// PHP endpoint that owns the schedule, the quota and the sending. There is no
// cron in the frontend image, and adding one would mean two schedulers.
//
// Once a minute is enough granularity for "24 hours before" and "1 hour before",
// and the endpoint is idempotent, so a missed or doubled tick is harmless.
const REMINDER_TICK_MS = 60_000
const FRONTEND_URL = (process.env.FRONTEND_URL || 'http://frontend').replace(/\/+$/, '')

function startReminderScheduler() {
  if (!process.env.BACKEND_API_KEY) return

  const tick = async () => {
    try {
      const res = await fetch(`${FRONTEND_URL}/internal/appointment-reminders.php`, {
        method: 'POST',
        headers: { 'X-Api-Key': process.env.BACKEND_API_KEY },
        signal: AbortSignal.timeout(45_000)
      })
      if (res.status === 401) {
        console.error('Reminder scheduler rejected: BACKEND_API_KEY mismatch with the frontend')
        return
      }
      const body = await res.json().catch(() => null)
      // Only speak when something happened: a log line a minute would bury
      // everything else.
      if (body?.sent || body?.failed) {
        console.log(`Appointment reminders: sent ${body.sent}, failed ${body.failed}`)
      }
    } catch (err) {
      if (err?.name !== 'TimeoutError' && err?.name !== 'AbortError') {
        console.error('Reminder scheduler tick failed:', err.message)
      }
    }
  }

  reminderTimer = setInterval(tick, REMINDER_TICK_MS)
  // Do not hold the process open on this alone.
  reminderTimer.unref?.()
}

async function start() {
  await restoreAllSessions()
  server = app.listen(port, host, () => {
    console.log(`Backend listening on http://${host}:${port}`)
  })
  startReminderScheduler()
}

let shuttingDown = false

async function shutdown(signal) {
  if (shuttingDown) return
  shuttingDown = true
  console.log(`Received ${signal} — flushing pending writes before exit`)

  if (reminderTimer) clearInterval(reminderTimer)
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
