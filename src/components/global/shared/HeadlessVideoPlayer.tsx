import { useEffect, useRef, useState, type RefObject } from 'react'
import { cn } from '@/lib/utils'

type HeadlessVideoPlayerProps = {
  src: string
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
 * Muted, looping background video with no controls. Respects reduced motion
 * (shows the first frame, paused).
 */
export function HeadlessVideoPlayer({
  src,
  fit = 'cover',
  variant = 'primary',
  muted = true,
  className,
}: HeadlessVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const reducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const shouldPlay = !reducedMotion

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
          src={src}
          muted
          loop
          playsInline
          autoPlay={shouldPlay}
          preload={reducedMotion ? 'metadata' : 'auto'}
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
        src={src}
        muted={muted}
        loop
        playsInline
        autoPlay={shouldPlay}
        preload={reducedMotion ? 'metadata' : 'auto'}
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
