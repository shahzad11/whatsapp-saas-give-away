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
// The endpoint stops taking new work at 30 seconds, so this only fires if the
// frontend has stopped answering altogether.
const REMINDER_TIMEOUT_MS = 45_000
// The first tick runs almost immediately rather than a minute after boot. A
// deploy is precisely when reminders have been piling up, and waiting out the
// full interval turns every restart into a minute of avoidable lateness. The
// short delay lets the frontend container finish coming up first; if it has not,
// the failure is logged and the next tick is a minute away.
const REMINDER_FIRST_TICK_MS = 5_000
const FRONTEND_URL = (process.env.FRONTEND_URL || 'http://frontend').replace(/\/+$/, '')

function startReminderScheduler() {
  if (!process.env.BACKEND_API_KEY) return

  // Ticks never overlap. The endpoint is safe either way — every reminder is
  // claimed before it is sent — but a frontend slow enough to run over a minute
  // does not need a second request piled on top of the first.
  let inFlight = false
  let consecutiveFailures = 0

  const tick = async () => {
    if (inFlight) {
      console.warn('Appointment reminders: previous tick still running, skipping this one')
      return
    }
    inFlight = true
    try {
      const res = await fetch(`${FRONTEND_URL}/internal/appointment-reminders.php`, {
        method: 'POST',
        headers: { 'X-Api-Key': process.env.BACKEND_API_KEY },
        signal: AbortSignal.timeout(REMINDER_TIMEOUT_MS)
      })
      if (res.status === 401) {
        console.error('Reminder scheduler rejected: BACKEND_API_KEY mismatch with the frontend')
        return
      }
      if (!res.ok) {
        consecutiveFailures++
        console.error(`Appointment reminders: frontend answered ${res.status} (${consecutiveFailures} in a row)`)
        return
      }

      const body = await res.json().catch(() => null)
      if (consecutiveFailures > 0) {
        console.log(`Appointment reminders: recovered after ${consecutiveFailures} failed tick(s)`)
        consecutiveFailures = 0
      }

      // Only speak when something happened: a log line a minute would bury
      // everything else. But "nothing was sent" is not the same as "nothing
      // happened" — a reminder recovered from a killed tick, one abandoned
      // because its appointment came first, or a queue that is not draining are
      // all things somebody needs to be able to find afterwards.
      if (body?.sent || body?.failed || body?.retrying || body?.missed) {
        console.log(
          `Appointment reminders: sent ${body.sent}, failed ${body.failed}, ` +
          `retrying ${body.retrying ?? 0}, missed ${body.missed ?? 0}`
        )
      }
      if (body?.recovered) {
        console.warn(`Appointment reminders: recovered ${body.recovered} interrupted send(s)`)
      }
      if (body?.deferred) {
        console.warn(`Appointment reminders: ${body.deferred} due reminder(s) deferred — the batch ran out of time`)
      }
      const overdue = body?.health?.overdue ?? 0
      if (overdue > 0) {
        console.warn(`Appointment reminders: ${overdue} reminder(s) more than 15 minutes overdue`)
      }
    } catch (err) {
      consecutiveFailures++
      const what = err?.name === 'TimeoutError' || err?.name === 'AbortError'
        ? `no answer within ${REMINDER_TIMEOUT_MS / 1000}s`
        : err.message
      // Timeouts used to be swallowed entirely, which meant a frontend that had
      // stopped answering looked exactly like a stack with nothing to send.
      console.error(`Reminder scheduler tick failed: ${what} (${consecutiveFailures} in a row)`)
    } finally {
      inFlight = false
    }
  }

  setTimeout(tick, REMINDER_FIRST_TICK_MS).unref?.()
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
