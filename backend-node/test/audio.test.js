import { test } from 'node:test'
import assert from 'node:assert/strict'
import path from 'path'
import fs from 'fs/promises'
import { randomUUID } from 'crypto'
import { spawnSync } from 'child_process'
import { tmpdir } from 'os'
import { transcodeForTranscription, transcodeToOpus } from '../src/wa/audio.js'

const hasFfmpeg = spawnSync('ffmpeg', ['-version']).status === 0

// Builds a real ogg/opus file with ffmpeg itself, so the test exercises the
// exact decode path production hits for an inbound WhatsApp voice note.
async function makeSineOgg() {
  const file = path.join(tmpdir(), `wa-test-${randomUUID()}.ogg`)
  const r = spawnSync('ffmpeg', [
    '-hide_banner', '-loglevel', 'error', '-y',
    '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
    '-c:a', 'libopus', '-f', 'ogg', file
  ])
  assert.equal(r.status, 0, r.stderr?.toString())
  const buf = await fs.readFile(file)
  await fs.rm(file, { force: true })
  return buf
}

test('transcodeForTranscription turns ogg/opus into mp3', { skip: !hasFfmpeg && 'ffmpeg not installed' }, async () => {
  const ogg = await makeSineOgg()
  const mp3 = await transcodeForTranscription(ogg)
  assert.ok(mp3.length > 1000, `mp3 suspiciously small (${mp3.length} bytes)`)
  const isId3 = mp3.subarray(0, 3).toString('latin1') === 'ID3'
  const isFrameSync = mp3[0] === 0xFF && (mp3[1] & 0xE0) === 0xE0
  assert.ok(isId3 || isFrameSync, 'output is not an mp3 stream')
})

test('transcodeForTranscription rejects garbage input', { skip: !hasFfmpeg && 'ffmpeg not installed' }, async () => {
  await assert.rejects(() => transcodeForTranscription(Buffer.from('not audio')))
})

test('transcodeToOpus still produces ogg', { skip: !hasFfmpeg && 'ffmpeg not installed' }, async () => {
  const ogg = await transcodeToOpus(Buffer.from(await makeSineOgg()))
  assert.equal(ogg.subarray(0, 4).toString('latin1'), 'OggS')
})
