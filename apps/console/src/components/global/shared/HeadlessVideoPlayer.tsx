import { useEffect, useRef, useState, type RefObject } from 'react'
import { cn } from '@/lib/utils'

type HeadlessVideoPlayerProps = {
  /** HLS (`.m3u8`) or progressive MP4 URL. */
  src: string
  /** Used when `src` is HLS and MSE / native HLS is unavailable or fails. */
  fallbackSrc?: string
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
        capLevelToPlayerSize: false,
      })
      hls.loadSource(src)
      hls.attachMedia(video)
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
  }, [src, fallbackSrc, active, videoRef])
}

function useAutoplayVideo(
  videoRef: RefObject<HTMLVideoElement | null>,
  src: string,
  enabled: boolean,
) {
  useEffect(() => {
    const video = videoRef.current
    if (!video || !enabled) return

    const play = () => {
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
  }, [src, enabled, videoRef])
}

/**
 * Muted, looping background video with no controls. HLS streams incrementally;
 * MP4 uses HTTP range requests when encoded with faststart. Respects reduced
 * motion (first frame only, paused).
 */
export function HeadlessVideoPlayer({
  src,
  fallbackSrc,
  fit = 'cover',
  variant = 'primary',
  muted = true,
  className,
}: HeadlessVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const reducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const shouldPlay = !reducedMotion

  useVideoSource(videoRef, src, fallbackSrc, shouldPlay || reducedMotion)
  useAutoplayVideo(videoRef, src, shouldPlay)

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
          preload={reducedMotion ? 'metadata' : 'none'}
          disablePictureInPicture
          controls={false}
          controlsList="nodownload noplaybackrate noremoteplayback"
          className="absolute left-1/2 top-1/2 size-[120%] max-w-none -translate-x-1/2 -translate-y-1/2 object-cover opacity-[0.72] blur-2xl saturate-150 contrast-125 dark:opacity-60"
        />
        <div
          aria-hidden
          className="absolute inset-0 bg-background/30 dark:bg-background/40"
        />
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
        preload={reducedMotion ? 'metadata' : 'none'}
        disablePictureInPicture
        controls={false}
        controlsList="nodownload noplaybackrate noremoteplayback"
        className={cn(
          'size-full contrast-[1.05] saturate-[1.08]',
          fit === 'contain' ? 'object-contain' : 'object-cover',
        )}
      />
    </div>
  )
}
