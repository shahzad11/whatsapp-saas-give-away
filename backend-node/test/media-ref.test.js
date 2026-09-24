import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mediaRefFromContent, contentFromMediaRef } from '../src/wa/media-ref.js'

const mediaMsg = () => ({
  imageMessage: {
    url: 'https://mmg.whatsapp.net/example',
    directPath: '/v/t62/example',
    mediaKey: Buffer.from('key-bytes-0123456789'),
    mimetype: 'image/jpeg',
    fileLength: 12345,
    fileSha256: Buffer.from('sha-plain'),
    fileEncSha256: Buffer.from('sha-enc')
  }
})

// The descriptor must survive a JSON round-trip — it is written into message
// shards — and still rebuild Buffers downloadMediaMessage can use.
test('mediaRef round-trips through JSON with Buffers restored', () => {
  const ref = mediaRefFromContent(mediaMsg())
  assert.equal(ref.key, 'imageMessage')
  assert.equal(ref.mimetype, 'image/jpeg')
  assert.equal(ref.fileLength, 12345)
  assert.equal(typeof ref.mediaKey, 'string')

  const ref2 = JSON.parse(JSON.stringify(ref))
  const content = contentFromMediaRef(ref2)
  assert.ok(content.imageMessage)
  assert.ok(Buffer.isBuffer(content.imageMessage.mediaKey))
  assert.equal(content.imageMessage.mediaKey.toString(), 'key-bytes-0123456789')
  assert.ok(Buffer.isBuffer(content.imageMessage.fileSha256))
  assert.equal(content.imageMessage.fileSha256.toString(), 'sha-plain')
  assert.equal(content.imageMessage.fileEncSha256.toString(), 'sha-enc')
  assert.equal(content.imageMessage.directPath, '/v/t62/example')
})

test('mediaRef picks the present media key and returns null without coordinates', () => {
  const ref = mediaRefFromContent({ stickerMessage: mediaMsg().imageMessage })
  assert.equal(ref.key, 'stickerMessage')

  assert.equal(mediaRefFromContent({ conversation: 'hi' }), null)
  assert.equal(mediaRefFromContent({ imageMessage: { mimetype: 'image/jpeg' } }), null)
  assert.equal(mediaRefFromContent(null), null)
})

test('mediaRef handles Long-shaped fileLength and JSON-shaped Buffers', () => {
  const ref = mediaRefFromContent({
    documentMessage: {
      directPath: '/x',
      mediaKey: { type: 'Buffer', data: [1, 2, 3] },
      fileLength: { low: 5, high: 0, unsigned: true }
    }
  })
  assert.equal(ref.fileLength, 5)
  const content = contentFromMediaRef(JSON.parse(JSON.stringify(ref)))
  assert.deepEqual([...content.documentMessage.mediaKey], [1, 2, 3])
})
