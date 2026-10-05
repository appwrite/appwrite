import { useEffect, useRef, useState, type RefObject } from 'react'
import { cn } from '@/lib/utils'

type HeadlessVideoPlayerProps = {
  /** HLS (`.m3u8`) or progressive MP4 / WebM URL. */
  src: string
  /** Used when `src` is HLS and MSE / native HLS is unavailable or fails. */
  fallbackSrc?: string
  /** First-frame image while the stream buffers, and when motion is reduced. */
  poster?: string
  /**
   * `cover` fills the parent (may crop). `contain` shows the full frame inside a
   * 16:9 parent.
   */
  fit?: 'cover' | 'contain'
  /**
   * `ambient` fills the parent with a heavily blurred copy (stage backdrop).
   * `primary` is the sharp foreground video.
   */
  variant?: 'primary' | 'ambient'
  /** Primary only; ambient stays muted. Default true for autoplay. */
  muted?: boolean
  /**
   * HLS: start at the highest rendition that fits the player (including DPR)
   * instead of climbing from the lowest rung.
   */
  maxQualityForPlayer?: boolean
  /** Skip the slight contrast/saturate grade on the primary video. */
  plain?: boolean
  /** Skip this many seconds at the start. Loops back here instead of 0. */
  startAt?: number
  /**
   * Ambient only. Dims the blurred video with a background wash. Set false when
   * the glow has to stay visible behind a small window (hero art).
   */
  wash?: boolean
  /** Fires on timeupdate / durationchange. Ambient and reduced-motion still emit. */
  onTimeUpdate?: (currentTime: number, duration: number) => void
  className?: string
}

function isHlsPlaylist(src: string): boolean {
  return src.includes('.m3u8')
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

function useVideoSource(
  videoRef: RefObject<HTMLVideoElement | null>,
  src: string,
  fallbackSrc: string | undefined,
  active: boolean,
  maxQualityForPlayer: boolean,
) {
  useEffect(() => {
    const video = videoRef.current
    if (!video || !active) return

    video.removeAttribute('src')

    if (!isHlsPlaylist(src)) {
      video.src = src
      return
    }

    const setMp4Fallback = () => {
      if (fallbackSrc) video.src = fallbackSrc
    }

    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = src
      return
    }

    let cancelled = false
    let hls: import('hls.js').default | null = null

    void import('hls.js').then(({ default: Hls }) => {
      if (cancelled || !videoRef.current) return
      if (!Hls.isSupported()) {
        setMp4Fallback()
        return
      }
      hls = new Hls({
        maxBufferLength: 30,
        startLevel: -1,
        capLevelToPlayerSize: maxQualityForPlayer,
        ignoreDevicePixelRatio: false,
      })
      hls.attachMedia(video)
      hls.loadSource(src)
      if (maxQualityForPlayer) {
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
          const capped = hls!.autoLevelCapping
          const last = hls!.levels.length - 1
          const level = capped >= 0 ? capped : last
          hls!.startLevel = level
          hls!.nextLevel = level
          hls!.currentLevel = level
        })
      }
      hls.on(Hls.Events.ERROR, (_, data) => {
        if (!data.fatal) return
        hls?.destroy()
        hls = null
        setMp4Fallback()
      })
    })

    return () => {
      cancelled = true
      hls?.destroy()
    }
  }, [src, fallbackSrc, active, maxQualityForPlayer, videoRef])
}

function useAutoplayVideo(
  videoRef: RefObject<HTMLVideoElement | null>,
  src: string,
  enabled: boolean,
  startAt: number,
) {
  useEffect(() => {
    const video = videoRef.current
    if (!video || !enabled) return

    const play = () => {
      if (startAt > 0 && video.currentTime < startAt) {
        video.currentTime = startAt
      }
      void video.play().catch(() => {
        // Autoplay may be blocked until user gesture; ignore.
      })
    }

    if (video.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) {
      play()
    } else {
      video.addEventListener('loadeddata', play, { once: true })
      return () => video.removeEventListener('loadeddata', play)
    }
  }, [src, enabled, startAt, videoRef])
}

function useVideoTimeUpdate(
  videoRef: RefObject<HTMLVideoElement | null>,
  onTimeUpdate: ((currentTime: number, duration: number) => void) | undefined,
  startAt: number,
) {
  const onTimeUpdateRef = useRef(onTimeUpdate)
  onTimeUpdateRef.current = onTimeUpdate

  useEffect(() => {
    const video = videoRef.current
    if (!video || !onTimeUpdate) return

    const emit = () => {
      const duration = Number.isFinite(video.duration) ? video.duration : 0
      onTimeUpdateRef.current?.(
        Math.max(0, video.currentTime - startAt),
        Math.max(0, duration - startAt),
      )
    }

    emit()
    video.addEventListener('timeupdate', emit)
    video.addEventListener('durationchange', emit)
    video.addEventListener('loadedmetadata', emit)
    return () => {
      video.removeEventListener('timeupdate', emit)
      video.removeEventListener('durationchange', emit)
      video.removeEventListener('loadedmetadata', emit)
    }
  }, [onTimeUpdate, startAt, videoRef])
}

function useClipStart(
  videoRef: RefObject<HTMLVideoElement | null>,
  src: string,
  startAt: number,
) {
  useEffect(() => {
    const video = videoRef.current
    if (!video || startAt <= 0) return

    const seekIn = () => {
      if (video.currentTime < startAt) video.currentTime = startAt
    }
    const restart = () => {
      video.currentTime = startAt
      void video.play().catch(() => {
        // Autoplay may be blocked until user gesture; ignore.
      })
    }

    video.addEventListener('loadedmetadata', seekIn)
    video.addEventListener('timeupdate', seekIn)
    video.addEventListener('ended', restart)
    if (video.readyState >= HTMLMediaElement.HAVE_METADATA) seekIn()

    return () => {
      video.removeEventListener('loadedmetadata', seekIn)
      video.removeEventListener('timeupdate', seekIn)
      video.removeEventListener('ended', restart)
    }
  }, [src, startAt, videoRef])
}

/**
 * Muted, looping background video with no controls. HLS streams incrementally;
 * MP4 uses HTTP range requests when encoded with faststart. Respects reduced
 * motion (first frame only, paused).
 */
export function HeadlessVideoPlayer({
  src,
  fallbackSrc,
  poster,
  fit = 'cover',
  variant = 'primary',
  muted = true,
  maxQualityForPlayer = false,
  plain = false,
  startAt = 0,
  wash = true,
  onTimeUpdate,
  className,
}: HeadlessVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const reducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const shouldPlay = !reducedMotion

  useVideoSource(
    videoRef,
    src,
    fallbackSrc,
    shouldPlay || reducedMotion,
    maxQualityForPlayer,
  )
  useAutoplayVideo(videoRef, src, shouldPlay, startAt)
  useClipStart(videoRef, src, startAt)
  useVideoTimeUpdate(videoRef, onTimeUpdate, startAt)

  if (variant === 'ambient') {
    return (
      <div
        aria-hidden
        className={cn(
          'pointer-events-none absolute inset-0 overflow-hidden',
          className,
        )}
      >
        <video
          ref={videoRef}
          muted
          loop
          playsInline
          autoPlay={shouldPlay}
          loop={startAt <= 0}
          poster={poster}
          preload={reducedMotion ? 'metadata' : poster ? 'metadata' : 'none'}
          disablePictureInPicture
          controls={false}
          controlsList="nodownload noplaybackrate noremoteplayback"
          className="absolute left-1/2 top-1/2 size-[120%] max-w-none -translate-x-1/2 -translate-y-1/2 object-cover opacity-[0.72] blur-2xl saturate-150 contrast-125 dark:opacity-60"
        />
        {wash ? (
          <div
            aria-hidden
            className="absolute inset-0 bg-background/30 dark:bg-background/40"
          />
        ) : null}
      </div>
    )
  }

  return (
    <div
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-0 overflow-hidden',
        className,
      )}
    >
      <video
        ref={videoRef}
        muted={muted}
        loop
        playsInline
        autoPlay={shouldPlay}
        loop={startAt <= 0}
        poster={poster}
        preload={reducedMotion ? 'metadata' : poster ? 'metadata' : 'none'}
        disablePictureInPicture
        controls={false}
        controlsList="nodownload noplaybackrate noremoteplayback"
        className={cn(
          'size-full',
          !plain && 'contrast-[1.05] saturate-[1.08]',
          fit === 'contain' ? 'object-contain' : 'object-cover',
        )}
      />
    </div>
  )
}
