import { useEffect, useRef } from 'react'

type VideoProps = {
  src?: string
  title?: string
  autoplay?: boolean
}

/**
 * Markdoc `video` tag. With `autoplay`, the clip plays muted on a loop like an
 * animated image, but only while it is on screen: the copy inside the hidden
 * `only_dark` or `only_light` wrapper never intersects, so it never plays.
 */
export function Video({ src, title, autoplay }: VideoProps) {
  const ref = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const video = ref.current
    if (!autoplay || !video || typeof IntersectionObserver === 'undefined') {
      return
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (entry?.isIntersecting) {
        video.muted = true
        void video.play().catch(() => {})
      } else {
        video.pause()
      }
    })
    observer.observe(video)
    return () => observer.disconnect()
  }, [autoplay, src])

  return (
    <div className="not-prose my-6 overflow-hidden rounded-xl border border-border">
      {autoplay ? (
        <video
          ref={ref}
          src={src}
          className="w-full"
          title={title}
          aria-label={title}
          preload="metadata"
          loop
          muted
          playsInline
        />
      ) : (
        <video src={src} controls className="w-full" title={title} />
      )}
    </div>
  )
}
