import { useEffect, useState } from 'react'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import {
  pickPosterCue,
  posterCandidates,
  SAMPLE_HEIGHT,
  SAMPLE_WIDTH,
  scorePosterFrame,
} from '@/lib/videos/poster-frame'

/** Picks per timeline, shared by every thumb of the same video. */
const picks = new Map<string, Promise<VideoTimelineCue | null>>()

function loadSheet(url: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const image = new Image()
    // Pixels are read back, so the sheet must load with CORS; Console
    // requests carry the session cookie.
    image.crossOrigin = 'use-credentials'
    image.onload = () => resolve(image)
    image.onerror = () => resolve(null)
    image.src = url
  })
}

async function pick(
  cues: VideoTimelineCue[],
): Promise<VideoTimelineCue | null> {
  const candidates = posterCandidates(cues)
  const canvas = document.createElement('canvas')
  canvas.width = SAMPLE_WIDTH
  canvas.height = SAMPLE_HEIGHT
  const context = canvas.getContext('2d', { willReadFrequently: true })
  if (!context) return cues[0] ?? null

  const sheets = new Map<string, Promise<HTMLImageElement | null>>()
  const scores = await Promise.all(
    candidates.map(async (cue) => {
      if (!sheets.has(cue.imageUrl)) {
        sheets.set(cue.imageUrl, loadSheet(cue.imageUrl))
      }
      const sheet = await sheets.get(cue.imageUrl)!
      if (!sheet) return null
      try {
        context.drawImage(
          sheet,
          cue.x,
          cue.y,
          cue.width,
          cue.height,
          0,
          0,
          SAMPLE_WIDTH,
          SAMPLE_HEIGHT,
        )
        return scorePosterFrame(
          context.getImageData(0, 0, SAMPLE_WIDTH, SAMPLE_HEIGHT).data,
        )
      } catch {
        // A sheet served without CORS taints the canvas.
        return null
      }
    }),
  )
  return pickPosterCue(candidates, scores)
}

/**
 * The timeline frame to show as a video's poster: the first frame until the
 * sampled frames are scored, then the best one, so black openings and fades
 * are skipped.
 */
export function usePosterCue(
  cues: VideoTimelineCue[] | null | undefined,
): VideoTimelineCue | null {
  const first = cues?.[0] ?? null
  const key = first ? `${first.imageUrl}#${cues!.length}` : null
  const [picked, setPicked] = useState<{
    key: string
    cue: VideoTimelineCue | null
  } | null>(null)

  useEffect(() => {
    if (!key || !cues) return
    let current = true
    if (!picks.has(key)) picks.set(key, pick(cues))
    void picks.get(key)!.then((cue) => {
      if (current) setPicked({ key, cue })
    })
    return () => {
      current = false
    }
    // `key` identifies the cues.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  return picked?.key === key ? (picked.cue ?? first) : first
}
