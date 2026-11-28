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
  test("counts from the manifest's last byte to the first frame", () => {
    const metrics = computeQoeMetrics(
      qoe,
      player({
        loadStartedAt: 1000,
        requestedAt: 1500,
        manifestLoadedAt: 1620,
        firstFrameAt: 1706,
      }),
      null,
    )
    expect(metrics.startupMs).toBe(86)
    expect(metrics.manifestMs).toBe(120)
  })

  test('waits for the manifest instead of counting from the request', () => {
    const metrics = computeQoeMetrics(
      qoe,
      player({ loadStartedAt: 1000, requestedAt: 1500, firstFrameAt: 1706 }),
      null,
    )
    expect(metrics.startupMs).toBeNull()
    expect(metrics.manifestMs).toBeNull()
  })
})
