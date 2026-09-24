// Merges a chat's on-disk shard with its in-memory tail (#16).
//
// Memory is bounded far below the on-disk cap, so every shard write must merge
// rather than overwrite — writing the 200-entry memory tail over a 5,000-entry
// shard would silently truncate the thread.
//
// Rules: dedup on id, a memory entry wins over its disk twin (it may carry a
// fresher rawMessage/mediaRef), output is sorted oldest → newest and trimmed
// to the newest `max`.

function msgTime(m) {
  const t = Date.parse(m?.time)
  return Number.isNaN(t) ? 0 : t
}

export function mergeMessages(diskMsgs, memMsgs, max) {
  const byId = new Map()
  for (const m of diskMsgs || []) {
    if (m && m.id) byId.set(m.id, m)
  }
  for (const m of memMsgs || []) {
    if (m && m.id) byId.set(m.id, m)
  }
  const merged = Array.from(byId.values())
  merged.sort((a, b) => msgTime(a) - msgTime(b))
  return merged.slice(-max)
}
