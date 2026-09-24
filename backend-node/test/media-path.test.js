import { test } from 'node:test'
import assert from 'node:assert/strict'
import path from 'path'
import { fileURLToPath } from 'url'
import { getMediaPath, isSafeMessageId } from '../src/wa/media-path.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const SESSION = path.join(__dirname, 'fixtures', 'session-x')
const MEDIA = path.resolve(SESSION, 'media')

// Every resolved path must stay inside the session's media directory.
function inside(p) {
  return p.startsWith(MEDIA + path.sep)
}

test('hostile message ids resolve inside media/', () => {
  for (const id of [
    '../../../etc/passwd',
    '../../t5/sessions/abc/creds',
    'a/b/c',
    'a\\b\\c',
    'id\0.json',
    'x'.repeat(10000),
    '',
    null,
    42,
  ]) {
    const p = getMediaPath(SESSION, id, 'image/jpeg')
    assert.ok(inside(p), `id ${JSON.stringify(String(id)).slice(0, 60)} escaped: ${p}`)
  }
})

test('hostile mime types produce a sanitised extension', () => {
  for (const mime of ['application/../../x', 'a/../../json', 'text/html; charset=../../', '', null]) {
    const p = getMediaPath(SESSION, 'ABCD1234', mime)
    assert.ok(inside(p), `mime ${mime} escaped: ${p}`)
    const ext = path.basename(p).split('.').pop()
    assert.match(ext, /^[a-z0-9]{1,10}$/, `bad ext ${ext}`)
  }
  assert.equal(getMediaPath(SESSION, 'X1', 'image/jpeg'), path.join(MEDIA, 'X1.jpeg'))
  assert.equal(getMediaPath(SESSION, 'X1', 'audio/ogg; codecs=opus'), path.join(MEDIA, 'X1.ogg'))
  assert.equal(getMediaPath(SESSION, 'X1', ''), path.join(MEDIA, 'X1.bin'))
})

test('isSafeMessageId accepts real-looking ids and rejects the rest', () => {
  for (const id of ['ABCD1234EFGH5678', '3EB0' + 'a1'.repeat(20), 'a-b_c']) {
    assert.ok(isSafeMessageId(id), `expected ${id} to be safe`)
  }
  for (const id of ['../x', 'a/b', 'a\\b', 'a\0b', 'x'.repeat(129), '', 'has space', 123, null]) {
    assert.ok(!isSafeMessageId(id), `expected ${JSON.stringify(id)} to be rejected`)
  }
})
