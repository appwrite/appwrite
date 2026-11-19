import { describe, expect, test } from 'bun:test'
import type { StreamPlayerState } from '@/components/pages/projects/$projectId/videos/_components/player/useStreamPlayer'
import {
  computeQoeMetrics,
  type QoeState,
} from '@/components/pages/projects/$projectId/videos/_components/player/useQoeTracker'

const qoe: QoeState = {
  samples: [],
  stalls: [],
  switches: [],
  seeks: 0,
  watchMs: 0,
  levelMs: {},
  bitrateMs: 0,
  stalled: false,
}

function player(timing: Partial<StreamPlayerState>): StreamPlayerState {
  return {
    engine: 'hls.js',
    playerVersion: null,
    levels: [],
    levelDetails: {},
    audioTracks: [],
    subtitleTracks: [],
    currentAudioTrack: -1,
    currentSubtitleTrack: -1,
    fragments: [],
    events: [],
    stats: null,
    fatalError: null,
    fatalStatus: null,
    loadStartedAt: null,
    requestedAt: null,
    manifestLoadedAt: null,
    firstFrameAt: null,
    ...timing,
  }
}

describe('computeQoeMetrics startup', () => {
  test('starts at the stream request, not while the player library loads', () => {
    const metrics = computeQoeMetrics(
      qoe,
      player({ loadStartedAt: 1000, requestedAt: 1500, firstFrameAt: 1660 }),
      null,
    )
    expect(metrics.startupMs).toBe(160)
  })

  test('falls back to the mount when the request time is unknown', () => {
    const metrics = computeQoeMetrics(
      qoe,
      player({ loadStartedAt: 1000, firstFrameAt: 1660 }),
      null,
    )
    expect(metrics.startupMs).toBe(660)
  })
})
