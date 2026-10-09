import { useEffect, useRef } from 'react'
import { useReducedMotion } from 'motion/react'
import { cn } from '@/lib/utils'

// One cut per theme family; the `dark:` variant covers every dark theme.
const VIDEOS = [
  { theme: 'light', className: 'dark:hidden' },
  { theme: 'dark', className: 'hidden dark:block' },
] as const

/**
 * The hero walkthrough: paste the setup prompt, approve sign-in, build the
 * app, deploy it. Both cuts load lazily and the one the theme hides never
 * intersects, so only the visible cut downloads and plays. It pauses off
 * screen and stays on the poster frame for reduced motion.
 */
export function DocsHeroVideo({ className }: { className?: string }) {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const reducedMotion = useReducedMotion()

  useEffect(() => {
    const container = containerRef.current
    if (!container || reducedMotion !== false) return

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const video = entry.target as HTMLVideoElement
          if (entry.isIntersecting) {
            void video.play().catch(() => {
              // Autoplay can be blocked until a user gesture; the poster stays.
            })
          } else {
            video.pause()
          }
        }
      },
      { threshold: 0.25 },
    )
    container
      .querySelectorAll('video')
      .forEach((video) => observer.observe(video))
    return () => observer.disconnect()
  }, [reducedMotion])

  return (
    <div
      ref={containerRef}
      className={cn(
        'relative aspect-video overflow-hidden rounded-xl border border-border bg-card',
        className,
      )}
      aria-hidden
    >
      {VIDEOS.map(({ theme, className: themeClassName }) => (
        <video
          key={theme}
          src={`/videos/docs-hero-${theme}.mp4`}
          poster={`/videos/docs-hero-${theme}-poster.webp`}
          muted
          loop
          playsInline
          preload="none"
          disablePictureInPicture
          controls={false}
          className={cn('size-full object-cover', themeClassName)}
        />
      ))}
    </div>
  )
}
