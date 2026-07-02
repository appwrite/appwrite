'use client'

import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useIntersectionVisible } from '@/hooks/use-intersection-visible'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  NETWORK_SEGMENT_CSS_VARS,
  NETWORK_SEGMENT_LABELS,
} from '@/lib/home/build-network-globe-data'
import type { NetworkSegment } from '@/lib/home/network-locations'

const LazyNetworkGlobe = lazy(() =>
  import('./NetworkGlobe.client').then((module) => ({
    default: module.NetworkGlobe,
  })),
)

const GLOBE_FRAME_CLASSNAME =
  'relative mx-auto w-full max-w-[min(100%,50rem)] overflow-hidden aspect-[100/48] sm:max-w-[min(100%,60rem)] lg:max-w-[min(100%,68rem)] xl:max-w-[min(100%,76rem)]'

const GLOBE_BACKDROP_CLASSNAME =
  'h-full w-full bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--foreground)_5%,transparent)_0%,transparent_68%)]'

function NetworkGlobeLegend({ className }: { className?: string }) {
  const t = useT()
  const segments: NetworkSegment[] = ['pop-locations', 'edges', 'regions']

  return (
    <div
      className={cn(
        'pointer-events-none flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-border/80 bg-background/90 px-2.5 py-1.5 shadow-sm',
        className,
      )}
    >
      {segments.map((segment) => (
        <span key={segment} className="flex items-center gap-1.5 text-[12px]">
          <span
            className="size-2 shrink-0 rounded-full"
            style={{ backgroundColor: NETWORK_SEGMENT_CSS_VARS[segment] }}
            aria-hidden
          />
          <span className="text-foreground">{t(NETWORK_SEGMENT_LABELS[segment])}</span>
        </span>
      ))}
    </div>
  )
}

/**
 * Stable globe frame that never swaps placeholders. The WebGL canvas mounts on
 * idle, paints its first frame off-screen, then appears instantly over the
 * matching backdrop. After that it stays mounted and only pauses rendering.
 */
export function NetworkGlobeMount({ className }: { className?: string }) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [mounted, setMounted] = useState(false)
  const [shouldMountGlobe, setShouldMountGlobe] = useState(false)
  const [isReady, setIsReady] = useState(false)
  const { isVisible } = useIntersectionVisible(containerRef, {
    rootMargin: '480px 0px',
  })

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    if (!mounted) return

    const preloadGlobe = () => {
      void import('./NetworkGlobe.client')
      setShouldMountGlobe(true)
    }

    if ('requestIdleCallback' in window) {
      const idleId = window.requestIdleCallback(preloadGlobe)
      return () => window.cancelIdleCallback(idleId)
    }

    const timeoutId = window.setTimeout(preloadGlobe, 300)
    return () => window.clearTimeout(timeoutId)
  }, [mounted])

  // Paint the first frame even while off-screen; after that pause when far away.
  const globeActive = isVisible || !isReady

  return (
    <div ref={containerRef} className={className}>
      <div className={GLOBE_FRAME_CLASSNAME}>
        <div className="absolute inset-x-0 top-0 aspect-square w-full" aria-hidden>
          <div className={GLOBE_BACKDROP_CLASSNAME} />
        </div>

        {mounted && shouldMountGlobe ? (
          <div className="absolute inset-x-0 top-0 aspect-square w-full">
            <Suspense fallback={null}>
              <LazyNetworkGlobe
                active={globeActive}
                ready={isReady}
                onReady={() => setIsReady(true)}
                className="h-full w-full"
              />
            </Suspense>
          </div>
        ) : null}

        <NetworkGlobeLegend className="absolute bottom-4 start-3 z-30 sm:bottom-5 sm:start-4" />
      </div>
    </div>
  )
}
