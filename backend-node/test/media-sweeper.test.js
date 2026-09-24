import { test } from 'node:test'
import assert from 'node:assert/strict'
import { selectMediaToDelete } from '../src/wa/media-sweeper.js'

const DAY = 86_400_000
const f = (name, size, ageDays) => ({ path: `/m/${name}`, size, mtimeMs: 1_000_000_000_000 - ageDays * DAY })
const NOW = 1_000_000_000_000

test('deletes files older than the retention window', () => {
  const sel = selectMediaToDelete(
    [f('old.bin', 10, 31), f('new.bin', 10, 1)],
    { now: NOW, retentionDays: 30, capBytes: 10_000 }
  )
  assert.deepEqual(sel.delete.map(x => x.path), ['/m/old.bin'])
  assert.equal(sel.remainingFiles, 1)
  assert.equal(sel.remainingBytes, 10)
})

test('enforces the tenant cap oldest-first', () => {
  const files = [f('a', 100, 10), f('b', 100, 5), f('c', 100, 1)]
  const sel = selectMediaToDelete(files, { now: NOW, retentionDays: 30, capBytes: 150 })
  assert.deepEqual(sel.delete.map(x => x.path), ['/m/a', '/m/b'])
  assert.equal(sel.remainingBytes, 100)
})

test('retention 0 deletes every cached file', () => {
  const sel = selectMediaToDelete(
    [f('a', 10, 0), f('b', 10, 100)],
    { now: NOW, retentionDays: 0, capBytes: 1_000_000 }
  )
  assert.equal(sel.delete.length, 2)
  assert.equal(sel.remainingBytes, 0)
  assert.equal(sel.remainingFiles, 0)
})

test('cap under limit deletes nothing', () => {
  const sel = selectMediaToDelete([f('a', 10, 1)], { now: NOW, retentionDays: 30, capBytes: 100 })
  assert.equal(sel.delete.length, 0)
})
