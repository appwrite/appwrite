'use client'

import { lazy, Suspense, useEffect, useState } from 'react'
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

function NetworkGlobeLegend({ className }: { className?: string }) {
  const segments: NetworkSegment[] = ['pop-locations', 'edges', 'regions']

  return (
    <div
      className={cn(
        'pointer-events-none flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-border/80 bg-background/85 px-2.5 py-1.5 shadow-sm backdrop-blur-sm',
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
          <span className="text-foreground">{NETWORK_SEGMENT_LABELS[segment]}</span>
        </span>
      ))}
    </div>
  )
}

function NetworkGlobeSsrPlaceholder({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'relative mx-auto w-full max-w-[min(100%,50rem)] overflow-hidden aspect-[100/48] sm:max-w-[min(100%,60rem)] lg:max-w-[min(100%,68rem)] xl:max-w-[min(100%,76rem)]',
        className,
      )}
    >
      <div className="absolute inset-x-0 top-0 aspect-square w-full">
        <div className="flex h-full w-full items-center justify-center">
          <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
        </div>
      </div>
      <NetworkGlobeLegend className="absolute bottom-4 start-3 z-30 sm:bottom-5 sm:start-4" />
    </div>
  )
}

/** Loads Three.js globe only in the browser so SSR does not import three-globe. */
export function NetworkGlobeMount({ className }: { className?: string }) {
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  if (!mounted) {
    return <NetworkGlobeSsrPlaceholder className={className} />
  }

  return (
    <Suspense fallback={<NetworkGlobeSsrPlaceholder className={className} />}>
      <LazyNetworkGlobe className={className} />
    </Suspense>
  )
}
