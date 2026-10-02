import { useCallback, useEffect, useRef, useState, type RefObject } from 'react'
import shaka from 'shaka-player'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'

const MAX_EVENTS = 200
const MAX_FRAGMENTS = 300
const STATS_INTERVAL_MS = 500

/**
 * Manifests reference absolute `/v1/videos/...` paths carrying only `?project=`.
 * Console sessions need admin mode (the console user is not in the file's read
 * roles), so every playlist, segment, and init request is rewritten here.
 */
export function withConsoleVideoAccess(url: string): string {
  if (!url || url.includes('mode=admin')) return url
  return withAdminMode(url)
}

export type StreamVariantInfo = {
  index: number
  trackId: number
  width: number
  height: number
  bitrate: number
  averageBitrate: number
  frameRate: number
  videoCodec?: string
  audioCodec?: string
  name: string
  url: string
}

export type StreamLevelDetailsInfo = {
  level: number
  version: number | null
  type: string | null
  live: boolean
  targetDuration: number
  totalDuration: number
  fragments: number
  startSN: number
  endSN: number
  loadedAt: number
}

export type StreamSegmentMediaKind = 'video' | 'audio' | 'unknown'

export type StreamSegmentInfo = {
  sn: number | string
  level: number
  type: string
  mediaKind: StreamSegmentMediaKind
  start: number
  duration: number
  bytes: number
  loadMs: number
  firstByteMs: number
  url: string
  at: number
}

export type StreamEventEntry = {
  at: number
  kind: 'info' | 'warning' | 'error'
  name: string
  detail?: string
}

export type StreamTrackInfo = {
  id: number
  name: string
  lang?: string
  default: boolean
}

export type PlaybackStats = {
  currentTime: number
  duration: number
  bufferedAhead: number
  bufferedRanges: Array<[number, number]>
  readyState: number
  networkState: number
  paused: boolean
  playbackRate: number
  videoWidth: number
  videoHeight: number
  droppedFrames: number
  totalFrames: number
  bandwidthEstimate: number | null
  currentLevel: number
  loadLevel: number
  nextLevel: number
  autoLevelEnabled: boolean
  latency: number | null
}

export type StreamPlayerState = {
  engine: 'shaka' | 'hls.js' | 'native' | null
  playerVersion: string | null
  levels: StreamVariantInfo[]
  levelDetails: Record<number, StreamLevelDetailsInfo>
  audioTracks: StreamTrackInfo[]
  subtitleTracks: StreamTrackInfo[]
  currentAudioTrack: number
  currentSubtitleTrack: number
  fragments: StreamSegmentInfo[]
  events: StreamEventEntry[]
  stats: PlaybackStats | null
  fatalError: string | null
  loadStartedAt: number | null
  manifestLoadedAt: number | null
  firstFrameAt: number | null
}

const INITIAL_STATE: StreamPlayerState = {
  engine: null,
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
  loadStartedAt: null,
  manifestLoadedAt: null,
  firstFrameAt: null,
}

function readBuffered(video: HTMLVideoElement): Array<[number, number]> {
  const ranges: Array<[number, number]> = []
  for (let i = 0; i < video.buffered.length; i++) {
    ranges.push([video.buffered.start(i), video.buffered.end(i)])
  }
  return ranges
}

export function inferSegmentMediaKindForUrl(
  url: string,
  contextType: unknown,
): StreamSegmentMediaKind {
  if (typeof contextType === 'string') {
    const type = contextType.toLowerCase()
    if (type.includes('audio')) return 'audio'
    if (type.includes('video')) return 'video'
  }
  const lower = url.toLowerCase()
  if (
    /(^|[/_-])audio([/_-]|$)|type=audio|audio_\d|audio-\d|aud\.|\.aac|\.mp4a/.test(
      lower,
    )
  ) {
    return 'audio'
  }
  if (
    /(^|[/_-])video([/_-]|$)|type=video|video_\d|video-\d|\.m4v|\.avc/.test(
      lower,
    )
  ) {
    return 'video'
  }
  return 'unknown'
}

function describeShakaError(error: shaka.util.Error): string {
  const parts = [error.category, error.code].filter((p) => p != null)
  if (error.data?.length) {
    parts.push(String(error.data[0]))
  }
  return parts.join(' / ')
}

function variantTracks(player: shaka.Player): shaka.extern.Track[] {
  return player.getVariantTracks().filter((t) => t.type === 'variant')
}

function buildLevels(tracks: shaka.extern.Track[]): StreamVariantInfo[] {
  const sorted = [...tracks].sort((a, b) => b.bandwidth - a.bandwidth)
  return sorted.map((track, index) => ({
    index,
    trackId: track.id,
    width: track.width ?? 0,
    height: track.height ?? 0,
    bitrate: track.bandwidth,
    averageBitrate: track.bandwidth,
    frameRate: track.frameRate ?? 0,
    videoCodec: track.videoCodec ?? undefined,
    audioCodec: track.audioCodec ?? undefined,
    name: track.label ?? track.language ?? `Variant ${index}`,
    url: '',
  }))
}

function levelIndexForTrack(
  levels: StreamVariantInfo[],
  track: shaka.extern.Track | undefined,
): number {
  if (!track) return -1
  return levels.findIndex((l) => l.trackId === track.id)
}

export type DashPlayerSource = { url: string; type: 'dash' }

/**
 * Attach a DASH manifest with Shaka Player and collect stream debug telemetry.
 */
export function useDashPlayer(
  videoRef: RefObject<HTMLVideoElement | null>,
  source: DashPlayerSource | null,
  reloadToken: number,
) {
  const src = source?.url ?? null
  const sourceType = source?.type ?? null
  const playerRef = useRef<shaka.Player | null>(null)
  const levelsRef = useRef<StreamVariantInfo[]>([])
  const segmentCounterRef = useRef(0)
  const [state, setState] = useState<StreamPlayerState>(INITIAL_STATE)

  const pushEvent = useCallback((entry: Omit<StreamEventEntry, 'at'>) => {
    setState((prev) => ({
      ...prev,
      events: [{ ...entry, at: Date.now() }, ...prev.events].slice(
        0,
        MAX_EVENTS,
      ),
    }))
  }, [])

  const syncTracks = useCallback((player: shaka.Player) => {
    const levels = buildLevels(variantTracks(player))
    levelsRef.current = levels

    const textTracks = player.getTextTracks()
    const activeText = textTracks.find((t) => t.active)

    setState((prev) => ({
      ...prev,
      levels,
      subtitleTracks: textTracks.map((t) => ({
        id: t.id,
        name: t.label || t.language || `Track ${t.id}`,
        lang: t.language,
        default: t.primary,
      })),
      currentSubtitleTrack: activeText?.id ?? -1,
    }))
  }, [])

  useEffect(() => {
    const video = videoRef.current
    if (!video || !src) {
      setState(INITIAL_STATE)
      return
    }

    let cancelled = false
    let player: shaka.Player | null = null
    segmentCounterRef.current = 0
    setState({ ...INITIAL_STATE, loadStartedAt: Date.now() })

    const onFirstFrame = () => {
      setState((prev) =>
        prev.firstFrameAt ? prev : { ...prev, firstFrameAt: Date.now() },
      )
    }
    video.addEventListener('loadeddata', onFirstFrame)

    if (sourceType !== 'dash') {
      return () => {
        video.removeEventListener('loadeddata', onFirstFrame)
      }
    }

    shaka.polyfill.installAll()

    if (!shaka.Player.isBrowserSupported()) {
      setState((prev) => ({
        ...prev,
        fatalError: 'Adaptive streaming is not supported in this browser.',
      }))
      return () => {
        video.removeEventListener('loadeddata', onFirstFrame)
      }
    }

    player = new shaka.Player(video)
    playerRef.current = player
    setState((prev) => ({
      ...prev,
      engine: 'shaka',
      playerVersion: shaka.Player.version,
    }))

    const net = player.getNetworkingEngine()
    const RequestType = shaka.net.NetworkingEngine.RequestType

    net?.registerRequestFilter((type, request) => {
      request.uris = request.uris.map((uri) => withConsoleVideoAccess(uri))
      request.allowCrossSiteCredentials = true
    })

    net?.registerResponseFilter((type, response, context) => {
      if (type !== RequestType.SEGMENT) return
      const bytes =
        response.data instanceof ArrayBuffer
          ? response.data.byteLength
          : response.data.byteLength
      const segment = context?.segment
      const start = segment?.startTime ?? 0
      const duration = segment != null ? segment.endTime - segment.startTime : 0
      const active = variantTracks(player!).find((t) => t.active)
      const levelIdx = levelIndexForTrack(levelsRef.current, active)
      segmentCounterRef.current += 1
      const entry: StreamSegmentInfo = {
        sn: segmentCounterRef.current,
        level: levelIdx,
        type: context?.type ?? 'segment',
        mediaKind: inferSegmentMediaKindForUrl(response.uri, context?.type),
        start,
        duration,
        bytes,
        loadMs: response.timeMs ?? 0,
        firstByteMs: response.originalRequest.timeToFirstByte ?? 0,
        url: response.uri,
        at: Date.now(),
      }
      setState((prev) => ({
        ...prev,
        fragments: [entry, ...prev.fragments].slice(0, MAX_FRAGMENTS),
      }))
    })

    player.addEventListener('error', (event) => {
      const error = (event as { detail: shaka.util.Error }).detail
      const detail = describeShakaError(error)
      pushEvent({
        kind:
          error.severity === shaka.util.Error.Severity.CRITICAL
            ? 'error'
            : 'warning',
        name: 'PLAYER_ERROR',
        detail,
      })
      if (error.severity === shaka.util.Error.Severity.CRITICAL) {
        setState((prev) => ({ ...prev, fatalError: detail }))
      }
    })

    player.addEventListener('adaptation', () => {
      const active = variantTracks(player!).find((t) => t.active)
      pushEvent({
        kind: 'info',
        name: 'ADAPTATION',
        detail: active
          ? `#${levelIndexForTrack(levelsRef.current, active)} ${active.width}×${active.height} @ ${Math.round(active.bandwidth / 1000)} kbps`
          : undefined,
      })
      syncTracks(player!)
    })

    player.addEventListener('trackschanged', () => {
      syncTracks(player!)
    })

    const manifestUrl = withConsoleVideoAccess(src)
    pushEvent({ kind: 'info', name: 'LOAD_SOURCE', detail: manifestUrl })

    void player
      .load(manifestUrl)
      .then(() => {
        if (cancelled) return
        video.muted = false
        if (video.volume === 0) video.volume = 1
        setState((prev) => ({ ...prev, manifestLoadedAt: Date.now() }))
        syncTracks(player!)
        const variants = variantTracks(player!)
        pushEvent({
          kind: 'info',
          name: 'MANIFEST_LOADED',
          detail: `${variants.length} variant(s), ${player!.getTextTracks().length} text track(s)`,
        })
      })
      .catch((err: shaka.util.Error | Error) => {
        if (cancelled) return
        const detail =
          err instanceof Error ? err.message : describeShakaError(err)
        setState((prev) => ({ ...prev, fatalError: detail }))
        pushEvent({ kind: 'error', name: 'LOAD_FAILED', detail })
      })

    return () => {
      cancelled = true
      video.removeEventListener('loadeddata', onFirstFrame)
      void player?.destroy()
      playerRef.current = null
      levelsRef.current = []
      video.removeAttribute('src')
      video.load()
    }
  }, [videoRef, src, sourceType, reloadToken, pushEvent, syncTracks])

  useEffect(() => {
    if (!src) return
    const interval = window.setInterval(() => {
      const video = videoRef.current
      if (!video) return
      const player = playerRef.current
      const ranges = readBuffered(video)
      const current = video.currentTime
      const containing = ranges.find(([s, e]) => current >= s && current <= e)
      const quality =
        typeof video.getVideoPlaybackQuality === 'function'
          ? video.getVideoPlaybackQuality()
          : null

      let bandwidthEstimate: number | null = null
      let currentLevel = -1
      let autoLevelEnabled = true
      let latency: number | null = null
      let droppedFrames = quality?.droppedVideoFrames ?? 0
      let totalFrames = quality?.totalFrames ?? 0

      if (player) {
        const stats = player.getStats()
        bandwidthEstimate =
          stats.estimatedBandwidth || stats.streamBandwidth || null
        droppedFrames = stats.droppedFrames
        totalFrames = stats.decodedFrames + stats.droppedFrames
        latency = Number.isFinite(stats.liveLatency) ? stats.liveLatency : null
        const abrEnabled = player.getConfiguration().abr.enabled
        autoLevelEnabled = abrEnabled
        const active = variantTracks(player).find((t) => t.active)
        currentLevel = levelIndexForTrack(levelsRef.current, active)
      }

      const stats: PlaybackStats = {
        currentTime: current,
        duration: video.duration,
        bufferedAhead: containing ? containing[1] - current : 0,
        bufferedRanges: ranges,
        readyState: video.readyState,
        networkState: video.networkState,
        paused: video.paused,
        playbackRate: video.playbackRate,
        videoWidth: video.videoWidth,
        videoHeight: video.videoHeight,
        droppedFrames,
        totalFrames,
        bandwidthEstimate,
        currentLevel,
        loadLevel: -1,
        nextLevel: -1,
        autoLevelEnabled,
        latency,
      }
      setState((prev) => ({ ...prev, stats }))
    }, STATS_INTERVAL_MS)
    return () => window.clearInterval(interval)
  }, [videoRef, src, reloadToken])

  const setLevel = useCallback(
    (level: number) => {
      const player = playerRef.current
      if (!player) return
      if (level < 0) {
        player.configure({ abr: { enabled: true } })
        pushEvent({ kind: 'info', name: 'ABR_ENABLED', detail: 'auto' })
        return
      }
      const variant = levelsRef.current.find((l) => l.index === level)
      if (!variant) return
      const track = variantTracks(player).find((t) => t.id === variant.trackId)
      if (!track) return
      player.configure({ abr: { enabled: false } })
      player.selectVariantTrack(track, true)
      pushEvent({
        kind: 'info',
        name: 'VARIANT_LOCKED',
        detail: `#${level}`,
      })
    },
    [pushEvent],
  )

  const setSubtitleTrack = useCallback((id: number) => {
    const player = playerRef.current
    if (!player) return
    if (id < 0) {
      player.setTextTrackVisibility(false)
      setState((prev) => ({ ...prev, currentSubtitleTrack: -1 }))
      return
    }
    const track = player.getTextTracks().find((t) => t.id === id)
    if (!track) return
    player.selectTextTrack(track)
    player.setTextTrackVisibility(true)
    setState((prev) => ({ ...prev, currentSubtitleTrack: id }))
  }, [])

  const setAudioTrack = useCallback((_id: number) => {
    // Variant tracks bundle audio; separate audio selection is not exposed yet.
  }, [])

  const clearEvents = useCallback(() => {
    setState((prev) => ({ ...prev, events: [], fragments: [] }))
    segmentCounterRef.current = 0
  }, [])

  return { state, setLevel, setSubtitleTrack, setAudioTrack, clearEvents }
}
