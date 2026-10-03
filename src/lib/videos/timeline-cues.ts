import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'

/** Preview sprite id embedded in a timeline cue URL (`.../previews/{id}`). */
export function previewIdFromCue(cue: VideoTimelineCue): string | null {
  try {
    const segments = new URL(cue.imageUrl).pathname.split('/')
    const index = segments.lastIndexOf('previews')
    return index >= 0 ? (segments[index + 1] ?? null) || null : null
  } catch {
    return null
  }
}

/** Index of the poster frame: matches `previewId` when set, otherwise the first cue. */
export function getVideoPosterCueIndex(
  cues: VideoTimelineCue[],
  previewId?: string | null,
): number {
  if (cues.length === 0) return 0
  if (previewId) {
    const index = cues.findIndex(
      (cue) => previewIdFromCue(cue) === previewId,
    )
    if (index >= 0) return index
  }
  return 0
}

/** Evenly sample cues for a short hover preview loop. */
export function sampleTimelineCuesForPreview(
  cues: VideoTimelineCue[],
  maxFrames = 48,
): VideoTimelineCue[] {
  if (cues.length <= maxFrames) return cues
  const result: VideoTimelineCue[] = []
  for (let i = 0; i < maxFrames; i++) {
    const index = Math.min(
      cues.length - 1,
      Math.floor((i * cues.length) / maxFrames),
    )
    result.push(cues[index])
  }
  return result
}

/** Returns the sprite cue that covers `time` (seconds), if any. */
export function findTimelineCueAtTime(
  cues: VideoTimelineCue[],
  time: number,
): VideoTimelineCue | undefined {
  if (cues.length === 0 || !Number.isFinite(time) || time < 0) return undefined
  for (const cue of cues) {
    if (time >= cue.start && time < cue.end) return cue
  }
  const last = cues[cues.length - 1]
  if (time >= last.start) return last
  return cues[0]
}
