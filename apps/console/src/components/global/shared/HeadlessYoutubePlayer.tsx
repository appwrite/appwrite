import { useEffect, useRef, useState } from 'react'
import { cn } from '@/lib/utils'

const YOUTUBE_EMBED_ORIGIN = 'https://www.youtube-nocookie.com'
const YOUTUBE_PLAYER_STATE_PLAYING = 1
/** Reveal the player even if the iframe never reports playback (e.g. autoplay blocked). */
const REVEAL_FALLBACK_MS = 4000
const COVER_OVERSCAN = 1.2
/** 1 = exact fit inside the parent; no extra scale (avoids clipping video edges). */
const CONTAIN_UI_OVERSCAN = 1
const DESKTOP_MEDIA_QUERY = '(min-width: 1024px) and (pointer: fine)'
const TABLET_MEDIA_QUERY = '(min-width: 768px) and (pointer: fine)'
/** YouTube picks stream resolution from layout size; scale up, then CSS scale down. */
const DESKTOP_RENDER_SCALE = 3
const TABLET_RENDER_SCALE = 2
type HeadlessYoutubePlayerProps = {
  videoId: string
  title: string
  /**
   * `cover` crops to fill the parent. `contain` fills a 16:9 parent edge-to-edge
   * (parent must already be 16:9).
   */
  fit?: 'cover' | 'contain'
  className?: string
}

export function youtubePosterUrl(
  videoId: string,
  size: 'maxres' | 'hq' = 'maxres',
): string {
  return `https://i.ytimg.com/vi/${videoId}/${size}default.jpg`
}

function buildEmbedSrc(videoId: string): string {
  const url = new URL(`${YOUTUBE_EMBED_ORIGIN}/embed/${videoId}`)
  const params: Record<string, string> = {
    autoplay: '1',
    mute: '1',
    loop: '1',
    // `loop` only works for a single video when it is also its own playlist.
    playlist: videoId,
    controls: '0',
    disablekb: '1',
    fs: '0',
    iv_load_policy: '3',
    modestbranding: '1',
    autohide: '1',
    playsinline: '1',
    rel: '0',
    cc_load_policy: '0',
    enablejsapi: '1',
    vq: 'hd1080',
    hl: 'en',
  }
  if (typeof window !== 'undefined') {
    params.origin = window.location.origin
  }
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value)
  }
  return url.toString()
}

function postPlayerCommand(
  iframe: HTMLIFrameElement | null,
  func: string,
  args: unknown[] = [],
) {
  iframe?.contentWindow?.postMessage(
    JSON.stringify({ event: 'command', func, args }),
    YOUTUBE_EMBED_ORIGIN,
  )
}

/** Chromeless playback: no control bar, captions, or annotations when the API allows. */
function suppressPlayerChrome(iframe: HTMLIFrameElement | null) {
  postPlayerCommand(iframe, 'setOption', ['controls', 0])
  postPlayerCommand(iframe, 'unloadModule', ['captions'])
  postPlayerCommand(iframe, 'unloadModule', ['cc'])
  postPlayerCommand(iframe, 'setOption', ['captions', 'track', {}])
  postPlayerCommand(iframe, 'setOption', ['showCaptions', false])
  postPlayerCommand(iframe, 'setOption', ['showAnnotations', false])
  // Legacy no-ops on modern players; harmless if ignored.
  postPlayerCommand(iframe, 'setPlaybackQuality', ['hd1080'])
  postPlayerCommand(iframe, 'setPlaybackQuality', ['highres'])
}

function isPlayingMessage(data: unknown): boolean {
  if (typeof data !== 'string') return false
  try {
    const message = JSON.parse(data) as {
      event?: string
      info?: number | { playerState?: number } | null
    }
    if (message.event === 'onStateChange') {
      return message.info === YOUTUBE_PLAYER_STATE_PLAYING
    }
    if (
      (message.event === 'infoDelivery' ||
        message.event === 'initialDelivery') &&
      message.info &&
      typeof message.info === 'object'
    ) {
      return message.info.playerState === YOUTUBE_PLAYER_STATE_PLAYING
    }
  } catch {
    return false
  }
  return false
}

function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(false)
  useEffect(() => {
    const list = window.matchMedia(query)
    setMatches(list.matches)
    const onChange = (event: MediaQueryListEvent) => setMatches(event.matches)
    list.addEventListener('change', onChange)
    return () => list.removeEventListener('change', onChange)
  }, [query])
  return matches
}

function useRenderScale(): number {
  const desktop = useMediaQuery(DESKTOP_MEDIA_QUERY)
  const tablet = useMediaQuery(TABLET_MEDIA_QUERY)
  if (desktop) return DESKTOP_RENDER_SCALE
  if (tablet) return TABLET_RENDER_SCALE
  return 1
}

/**
 * Chromeless, muted, looping YouTube background video that covers its parent
 * (like `object-fit: cover`). The parent must be positioned and clip overflow.
 * With reduced motion, only the poster frame is shown.
 */
export function HeadlessYoutubePlayer({
  videoId,
  fit = 'cover',
  className,
}: HeadlessYoutubePlayerProps) {
  const iframeRef = useRef<HTMLIFrameElement | null>(null)
  const [playing, setPlaying] = useState(false)
  const reducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const renderScale = useRenderScale()
  const [embedSrc, setEmbedSrc] = useState<string | null>(null)

  useEffect(() => {
    setEmbedSrc(buildEmbedSrc(videoId))
  }, [videoId])

  useEffect(() => {
    if (reducedMotion) return
    const reveal = () => {
      setPlaying(true)
      suppressPlayerChrome(iframeRef.current)
    }
    const onMessage = (event: MessageEvent) => {
      if (event.origin !== YOUTUBE_EMBED_ORIGIN) return
      if (event.source !== iframeRef.current?.contentWindow) return
      if (isPlayingMessage(event.data)) reveal()
    }
    window.addEventListener('message', onMessage)
    const fallback = window.setTimeout(reveal, REVEAL_FALLBACK_MS)
    return () => {
      window.removeEventListener('message', onMessage)
      window.clearTimeout(fallback)
    }
  }, [reducedMotion])

  useEffect(() => {
    if (reducedMotion || !playing) return
    suppressPlayerChrome(iframeRef.current)
    const interval = window.setInterval(
      () => suppressPlayerChrome(iframeRef.current),
      400,
    )
    const stop = window.setTimeout(() => window.clearInterval(interval), 30000)
    return () => {
      window.clearInterval(interval)
      window.clearTimeout(stop)
    }
  }, [reducedMotion, playing])

  const handleLoad = () => {
    iframeRef.current?.contentWindow?.postMessage(
      JSON.stringify({ event: 'listening', id: videoId, channel: 'widget' }),
      YOUTUBE_EMBED_ORIGIN,
    )
    suppressPlayerChrome(iframeRef.current)
  }

  return (
    <div
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-0 overflow-hidden',
        'bg-black',
        className,
      )}
      style={fit === 'cover' ? { containerType: 'size' } : undefined}
    >
      {!playing ? (
        <img
          src={youtubePosterUrl(videoId)}
          onError={(event) => {
            const fallback = youtubePosterUrl(videoId, 'hq')
            if (event.currentTarget.src !== fallback) {
              event.currentTarget.src = fallback
            }
          }}
          alt=""
          className="absolute inset-0 size-full object-cover"
        />
      ) : null}
      {!reducedMotion && embedSrc ? (
        <iframe
          ref={iframeRef}
          src={embedSrc}
          title=""
          aria-hidden
          tabIndex={-1}
          onLoad={handleLoad}
          allow="autoplay; encrypted-media"
          disablePictureInPicture
          referrerPolicy="strict-origin-when-cross-origin"
          className={cn(
            'pointer-events-none absolute left-1/2 top-1/2 max-w-none origin-center border-0 transition-opacity duration-700 ease-out',
            playing ? 'opacity-100' : 'opacity-0',
          )}
          style={
            // YouTube picks the stream from the player's layout size, so we lay it out
            // larger and scale it back down. `vq` / setPlaybackQuality are hints only.
            fit === 'contain'
              ? {
                  width: `${renderScale * 100}%`,
                  height: `${renderScale * 100}%`,
                  transform: `translate(-50%, -50%) scale(${CONTAIN_UI_OVERSCAN / renderScale})`,
                }
              : {
                  width: `calc(max(100cqw, 100cqh * 16 / 9) * ${renderScale})`,
                  height: `calc(max(100cqh, 100cqw * 9 / 16) * ${renderScale})`,
                  transform: `translate(-50%, -50%) scale(${COVER_OVERSCAN / renderScale})`,
                }
          }
        />
      ) : null}
    </div>
  )
}
