import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'

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
