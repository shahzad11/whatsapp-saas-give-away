import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mergeMessages } from '../src/wa/messages-merge.js'

const m = (id, time, extra = {}) => ({ id, time, ...extra })

test('dedups on id, memory wins on conflict', () => {
  const disk = [m('a', '2024-01-01T00:00:00Z', { text: 'old' }), m('b', '2024-01-02T00:00:00Z')]
  const mem = [m('a', '2024-01-01T00:00:00Z', { text: 'new', mediaRef: { key: 'imageMessage' } })]
  const out = mergeMessages(disk, mem, 100)
  assert.equal(out.length, 2)
  assert.equal(out.find(x => x.id === 'a').text, 'new')
  assert.ok(out.find(x => x.id === 'a').mediaRef)
})

test('output is sorted oldest to newest regardless of input order', () => {
  const disk = [m('c', '2024-01-03T00:00:00Z'), m('a', '2024-01-01T00:00:00Z')]
  const mem = [m('b', '2024-01-02T00:00:00Z')]
  const out = mergeMessages(disk, mem, 100)
  assert.deepEqual(out.map(x => x.id), ['a', 'b', 'c'])
})

test('trims to the newest max entries', () => {
  const disk = [m('old1', '2024-01-01T00:00:00Z'), m('old2', '2024-01-02T00:00:00Z')]
  const mem = [m('new1', '2024-01-03T00:00:00Z'), m('new2', '2024-01-04T00:00:00Z')]
  const out = mergeMessages(disk, mem, 3)
  assert.deepEqual(out.map(x => x.id), ['old2', 'new1', 'new2'])
})

test('disk history survives a short memory tail (no truncation to the bound)', () => {
  const disk = Array.from({ length: 300 }, (_, i) => m('d' + i, new Date(1700000000000 + i * 1000).toISOString()))
  const mem = Array.from({ length: 5 }, (_, i) => m('m' + i, new Date(1700000500000 + i * 1000).toISOString()))
  const out = mergeMessages(disk, mem, 5000)
  assert.equal(out.length, 305)
  assert.equal(out[0].id, 'd0')
  assert.equal(out[out.length - 1].id, 'm4')
})
