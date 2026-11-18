import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'

/** How many timeline frames are scored when choosing a poster. */
const CANDIDATES = 12
/** Frames are scored at this size; detail beyond it does not change the pick. */
export const SAMPLE_WIDTH = 32
export const SAMPLE_HEIGHT = 18

/**
 * How good a frame is as a poster, from its RGBA pixels: 0 for black, white
 * or flat frames (fades, title cards on a solid color), higher for frames
 * with mid brightness and contrast.
 */
export function scorePosterFrame(pixels: Uint8ClampedArray): number {
  const count = pixels.length / 4
  if (count === 0) return 0
  let sum = 0
  let sumSquares = 0
  for (let i = 0; i < pixels.length; i += 4) {
    const luma =
      (0.2126 * pixels[i] + 0.7152 * pixels[i + 1] + 0.0722 * pixels[i + 2]) /
      255
    sum += luma
    sumSquares += luma * luma
  }
  const mean = sum / count
  const contrast = Math.sqrt(Math.max(0, sumSquares / count - mean * mean))
  // Ramp down to zero for frames that are nearly black or nearly white.
  const exposure =
    Math.min(1, Math.max(0, (mean - 0.06) / 0.14)) *
    Math.min(1, Math.max(0, (0.96 - mean) / 0.14))
  return contrast * exposure
}

/** Evenly spaced cues to score, skipping the very end (credits, fade out). */
export function posterCandidates(cues: VideoTimelineCue[]): VideoTimelineCue[] {
  if (cues.length <= CANDIDATES) return cues
  const span = Math.max(1, Math.floor(cues.length * 0.9))
  const picked: VideoTimelineCue[] = []
  for (let i = 0; i < CANDIDATES; i++) {
    picked.push(cues[Math.floor((i * span) / CANDIDATES)])
  }
  return picked
}

/**
 * The best scoring candidate. Earlier frames win close calls, since the
 * opening usually says what a video is. Returns the first cue when nothing
 * could be scored.
 */
export function pickPosterCue(
  cues: VideoTimelineCue[],
  scores: (number | null)[],
): VideoTimelineCue | null {
  if (cues.length === 0) return null
  let best = 0
  let bestScore = -1
  scores.forEach((score, index) => {
    if (score == null) return
    const weighted = score * (1 - (0.3 * index) / Math.max(1, cues.length))
    if (weighted > bestScore) {
      best = index
      bestScore = weighted
    }
  })
  return cues[best]
}
