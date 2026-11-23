import { describe, expect, test } from 'bun:test'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import {
  pickPosterCue,
  posterCandidates,
  scorePosterFrame,
} from '@/lib/videos/poster-frame'

function frame(lumas: number[]): Uint8ClampedArray {
  const pixels = new Uint8ClampedArray(lumas.length * 4)
  lumas.forEach((luma, i) => {
    pixels.set([luma, luma, luma, 255], i * 4)
  })
  return pixels
}

function cue(index: number): VideoTimelineCue {
  return {
    start: index,
    end: index + 1,
    imageUrl: 'sheet.png',
    x: index * 160,
    y: 0,
    width: 160,
    height: 90,
  }
}

describe('scorePosterFrame', () => {
  test('scores black, white and flat frames zero', () => {
    expect(scorePosterFrame(frame([0, 0, 4, 2]))).toBe(0)
    expect(scorePosterFrame(frame([255, 252, 255, 250]))).toBe(0)
    expect(scorePosterFrame(frame([120, 120, 120, 120]))).toBe(0)
  })

  test('scores a frame with detail above a dim one', () => {
    const detailed = scorePosterFrame(frame([30, 200, 90, 160]))
    const dim = scorePosterFrame(frame([10, 30, 15, 25]))
    expect(detailed).toBeGreaterThan(dim)
  })
})

describe('posterCandidates', () => {
  test('keeps short timelines whole', () => {
    expect(posterCandidates([cue(0), cue(1)])).toHaveLength(2)
  })

  test('samples long timelines and skips the ending', () => {
    const cues = Array.from({ length: 100 }, (_, i) => cue(i))
    const picked = posterCandidates(cues)
    expect(picked).toHaveLength(12)
    expect(picked[0]).toBe(cues[0])
    expect(picked.at(-1)!.start).toBeLessThan(90)
  })
})

describe('pickPosterCue', () => {
  test('skips a black opening', () => {
    expect(pickPosterCue([cue(0), cue(1)], [0, 0.2])).toEqual(cue(1))
  })

  test('prefers the earlier frame on a close call', () => {
    expect(pickPosterCue([cue(0), cue(1)], [0.2, 0.21])).toEqual(cue(0))
  })

  test('falls back to the first frame when nothing was scored', () => {
    expect(pickPosterCue([cue(0), cue(1)], [null, null])).toEqual(cue(0))
  })
})
