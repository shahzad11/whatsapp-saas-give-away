import path from 'path'
import fs from 'fs/promises'
import { randomUUID } from 'crypto'
import { spawn } from 'child_process'
import { tmpdir } from 'os'

// A voice note has to be ogg/opus. MediaRecorder produces webm/opus in Chrome,
// ogg/opus in Firefox and mp4/aac in Safari, so anything that is not already
// ogg is transcoded rather than passed through — WhatsApp clients render a
// mislabelled voice note as a broken attachment.
//
// Temp files rather than pipes because Safari's mp4 needs a seekable input, and
// ffmpeg cannot seek a pipe.
//
// Every run is capped at 30s: ffmpeg is handed whatever bytes a client (or a
// downloaded voice note) produced, and a malformed input must not leave a
// process spinning forever behind a request that already failed.
const FFMPEG_TIMEOUT_MS = 30_000

function runFfmpeg(buffer, args, outExt) {
  const base = path.join(tmpdir(), `wa-audio-${randomUUID()}`)
  const inPath = base + '.in'
  const outPath = base + '.' + outExt

  return (async () => {
    try {
      await fs.writeFile(inPath, buffer)
      await new Promise((resolve, reject) => {
        const ff = spawn('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', inPath, ...args, outPath])
        let stderr = ''
        let done = false
        const timer = setTimeout(() => {
          done = true
          ff.kill('SIGKILL')
          reject(new Error('Audio conversion timed out'))
        }, FFMPEG_TIMEOUT_MS)
        ff.stderr.on('data', d => { stderr += d.toString().slice(0, 500) })
        ff.on('error', err => {
          if (done) return
          done = true
          clearTimeout(timer)
          reject(new Error(
            err.code === 'ENOENT' ? 'Voice notes need ffmpeg, which is not installed' : err.message
          ))
        })
        ff.on('close', code => {
          if (done) return
          done = true
          clearTimeout(timer)
          code === 0
            ? resolve()
            : reject(new Error('Audio conversion failed' + (stderr ? ': ' + stderr.trim() : '')))
        })
      })
      return await fs.readFile(outPath)
    } finally {
      await Promise.all([fs.rm(inPath, { force: true }), fs.rm(outPath, { force: true })])
    }
  })()
}

export function transcodeToOpus(buffer) {
  return runFfmpeg(buffer, ['-vn', '-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-ac', '1', '-f', 'ogg'], 'ogg')
}

// FenLLM's transcription input accepts only mp3 and wav. Inbound WhatsApp
// voice notes are ogg/opus and the PHP frontend has no ffmpeg, so the bytes
// come here to be converted first — mono 16 kHz mp3, which is what speech
// recognition wants anyway and keeps the payload small.
export async function transcodeForTranscription(buffer) {
  const out = await runFfmpeg(buffer,
    ['-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', '-f', 'mp3'], 'mp3')
  if (out.length === 0) throw new Error('Audio conversion produced no audio')
  return out
}
