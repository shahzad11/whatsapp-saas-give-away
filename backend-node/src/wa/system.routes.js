import express from 'express'
import { statfs } from 'fs/promises'
import { DATA_DIR, backendStatsSnapshot } from './wa.sessions.js'
import { getMediaSweepSummary } from './media-sweeper.js'
import { rateLimit } from '../middleware/rate-limit.js'

export const systemRouter = express.Router()

// Mounted with requireApiKey only (see server.js): there is no tenant in scope
// for process-wide stats, and requireTenant would reject the call. The shared
// 'general' bucket style applies — keyed on a fixed slot since no tenant id
// is present.
const general = rateLimit('general', 60, 60_000)

// Process-level resource numbers for the admin System page. Counts and byte
// totals only — no message content, no per-chat data.
systemRouter.get('/stats', general, async (req, res) => {
  const mem = process.memoryUsage()

  let disk = { freeBytes: null, totalBytes: null }
  try {
    const st = await statfs(DATA_DIR)
    disk = {
      freeBytes: Number(st.bavail) * Number(st.bsize),
      totalBytes: Number(st.blocks) * Number(st.bsize)
    }
  } catch (_) {
    // nulls say "unknown" without failing the whole stats call.
  }

  const snap = backendStatsSnapshot()
  res.json({
    ok: true,
    memory: { rss: mem.rss, heapUsed: mem.heapUsed, heapTotal: mem.heapTotal },
    disk,
    media: getMediaSweepSummary(),
    sessions: snap.sessions,
    messagesInMemory: snap.messagesInMemory
  })
})
