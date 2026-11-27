import { useEffect, useRef } from 'react'

type VideoProps = {
  src?: string
  title?: string
  autoplay?: boolean
}

/**
 * Markdoc `video` tag. With `autoplay`, the clip plays muted on a loop like an
 * animated image. React sets `muted` as a property, not an attribute, so the
 * server-rendered element can't autoplay on its own; start it after mount.
 */
export function Video({ src, title, autoplay }: VideoProps) {
  const ref = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const video = ref.current
    if (!autoplay || !video) return
    video.muted = true
    void video.play().catch(() => {})
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
          autoPlay
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
