import { describe, expect, test } from 'bun:test'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import {
  serializeTimelineVtt,
  videoFileSlug,
} from '@/lib/videos/timeline-download'

const cues: VideoTimelineCue[] = [
  {
    start: 0,
    end: 5,
    imageUrl: 'https://api.test/v1/videos/v/thumbnails/a?project=p',
    x: 0,
    y: 0,
    width: 160,
    height: 90,
  },
  {
    start: 3665.5,
    end: 3670.5,
    imageUrl: 'https://api.test/v1/videos/v/thumbnails/b?project=p',
    x: 160,
    y: 90,
    width: 160,
    height: 90,
  },
]

describe('serializeTimelineVtt', () => {
  test('points each cue at its local sheet', () => {
    const names: Record<string, string> = {
      [cues[0].imageUrl]: 'sprite-1.jpg',
      [cues[1].imageUrl]: 'sprite-2.jpg',
    }
    expect(serializeTimelineVtt(cues, (url) => names[url])).toBe(
      'WEBVTT\n\n' +
        '00:00:00.000 --> 00:00:05.000\nsprite-1.jpg#xywh=0,0,160,90\n\n' +
        '01:01:05.500 --> 01:01:10.500\nsprite-2.jpg#xywh=160,90,160,90\n',
    )
  })
})

describe('videoFileSlug', () => {
  test('makes a file-name-safe slug', () => {
    expect(videoFileSlug('Launch keynote (final)')).toBe('launch-keynote-final')
  })

  test('falls back when nothing is left', () => {
    expect(videoFileSlug('???')).toBe('video')
  })
})
