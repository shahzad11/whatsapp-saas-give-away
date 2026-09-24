// Cached-media housekeeping (#15).
//
// Media files accumulate forever otherwise: every viewed or eagerly cached
// attachment is a file under tenants/<t>/sessions/<sid>/media/. The sweep
// deletes past a retention age, then enforces a per-tenant byte cap
// oldest-first. Files are only a cache — anything deleted is re-downloaded
// lazily through the message's persisted mediaRef, so deletion loses nothing
// WhatsApp can still serve.

import path from 'path'
import fs from 'fs/promises'

const RETENTION_DAYS = Number(process.env.MEDIA_RETENTION_DAYS ?? 30)
const TENANT_CAP_BYTES = Number(process.env.MEDIA_TENANT_CAP_MB ?? 2048) * 1048576

const FIRST_RUN_MS = 60_000
const INTERVAL_MS = 60 * 60 * 1000

// The pure part: given one tenant's cached files, pick what goes.
//   retentionDays > 0  — files older than the retention window go
//   retentionDays = 0  — every cached file goes
// then, if what remains still exceeds capBytes, delete oldest-first until
// under the cap.
export function selectMediaToDelete(files, { now, retentionDays, capBytes }) {
  const del = []
  let keep = []

  for (const f of files) {
    if (retentionDays === 0 || f.mtimeMs < now - retentionDays * 86_400_000) {
      del.push(f)
    } else {
      keep.push(f)
    }
  }

  keep.sort((a, b) => a.mtimeMs - b.mtimeMs)
  let bytes = 0
  for (const f of keep) bytes += f.size
  while (bytes > capBytes && keep.length > 0) {
    const f = keep.shift()
    del.push(f)
    bytes -= f.size
  }

  return {
    delete: del,
    remainingBytes: bytes,
    remainingFiles: keep.length
  }
}

let lastSummary = null

export function getMediaSweepSummary() {
  return lastSummary
}

async function sweepMedia(tenantsDir, { now = Date.now(), retentionDays = RETENTION_DAYS, capBytes = TENANT_CAP_BYTES } = {}) {
  const tenants = {}
  let deleted = 0
  let deletedBytes = 0

  let tenantDirs = []
  try {
    tenantDirs = await fs.readdir(tenantsDir, { withFileTypes: true })
  } catch {
    return
  }

  for (const td of tenantDirs) {
    // The quarantine bucket holds pre-tenancy orphans; nothing serves from it,
    // so sweeping it would just delete forensic material.
    if (!td.isDirectory() || td.name === '_unassigned') continue

    const files = []
    let sessionDirs = []
    try {
      sessionDirs = await fs.readdir(path.join(tenantsDir, td.name, 'sessions'), { withFileTypes: true })
    } catch {
      continue
    }
    for (const sd of sessionDirs) {
      if (!sd.isDirectory()) continue
      const mediaDir = path.join(tenantsDir, td.name, 'sessions', sd.name, 'media')
      let entries = []
      try {
        entries = await fs.readdir(mediaDir, { withFileTypes: true })
      } catch {
        continue
      }
      for (const e of entries) {
        if (!e.isFile()) continue
        const p = path.join(mediaDir, e.name)
        try {
          const st = await fs.stat(p)
          files.push({ path: p, size: st.size, mtimeMs: st.mtimeMs })
        } catch (_) {}
      }
    }

    const sel = selectMediaToDelete(files, { now, retentionDays, capBytes })
    for (const f of sel.delete) {
      try {
        await fs.rm(f.path, { force: true })
        deleted++
        deletedBytes += f.size
      } catch (_) {}
    }
    tenants[td.name] = { bytes: sel.remainingBytes, files: sel.remainingFiles }
  }

  lastSummary = { at: new Date(now).toISOString(), tenants }

  // One line, and only when something happened: an hourly "deleted 0" log
  // teaches whoever reads it to ignore the line that matters.
  if (deleted > 0) {
    console.log(`Media sweep: deleted ${deleted} cached file(s), freed ${(deletedBytes / 1048576).toFixed(1)} MB`)
  }
}

// Runs once shortly after boot, then hourly. Unref'd so a quiet process can
// still exit.
export function startMediaSweeper(tenantsDir) {
  const run = () => sweepMedia(tenantsDir).catch(err => console.error('Media sweep failed:', err.message))
  const first = setTimeout(run, FIRST_RUN_MS)
  if (typeof first.unref === 'function') first.unref()
  const timer = setInterval(run, INTERVAL_MS)
  if (typeof timer.unref === 'function') timer.unref()
}
