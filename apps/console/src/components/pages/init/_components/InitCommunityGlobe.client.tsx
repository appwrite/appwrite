'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { buildInitGlobePresenceData } from '@/lib/init/build-init-globe-arcs'
import { getInitGlobeBrandRgb } from '@/lib/init/init-globe-theme'
import { getCssColorExpression } from '@/lib/css-theme-colors'
import { useGlobeThemeConfig } from '@/hooks/use-globe-theme-config'
import { World } from '@/components/ui/globe'
import type { InitCommunityCountry } from '@/lib/init/types'
import { cn } from '@/lib/utils'

function serializeCommunityCountries(
  countries: InitCommunityCountry[],
): string {
  return countries
    .map((country) => `${country.code}:${country.count}`)
    .join('|')
}

type InitCommunityGlobeProps = {
  countries: InitCommunityCountry[]
  className?: string
  /** Init 5: light-trail arcs and a comet orbiting the globe. */
  electric?: boolean
}

/**
 * The globe's outline as a share of its square: three-globe's 100-unit sphere
 * seen from the camera at 300 with a 50° field of view (see `ui/globe`).
 */
const GLOBE_OUTLINE_RADIUS_PCT =
  ((0.5 * Math.tan(Math.asin(100 / 300))) / Math.tan((25 * Math.PI) / 180)) *
  100

export function InitCommunityGlobe({
  countries,
  className,
  electric = false,
}: InitCommunityGlobeProps) {
  const globeHostRef = useRef<HTMLDivElement>(null)
  const [globeActive, setGlobeActive] = useState(false)
  const { config: globeConfig, themeKey } = useGlobeThemeConfig()
  const countriesKey = useMemo(
    () => serializeCommunityCountries(countries),
    [countries],
  )
  const brandRgb = useMemo(
    () => (globeConfig ? getInitGlobeBrandRgb() : 'rgb(253, 54, 110)'),
    [globeConfig, themeKey],
  )
  const trail = useMemo(
    () =>
      electric && globeConfig
        ? ([
            getCssColorExpression(
              'var(--network-globe-region)',
              'rgb(255, 176, 136)',
            ),
            brandRgb,
            getCssColorExpression(
              'var(--network-globe-edge)',
              'rgb(155, 138, 255)',
            ),
          ] as const)
        : undefined,
    [brandRgb, electric, globeConfig, themeKey],
  )
  const globePresence = useMemo(
    () => buildInitGlobePresenceData(countries, brandRgb, trail),
    [brandRgb, countriesKey, trail],
  )

  useEffect(() => {
    const host = globeHostRef.current
    if (!host) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        setGlobeActive(entry?.isIntersecting ?? false)
      },
      { root: null, threshold: 0.08, rootMargin: '120px 0px' },
    )

    observer.observe(host)
    return () => observer.disconnect()
  }, [])

  return (
    <div
      ref={globeHostRef}
      className={cn(
        'relative mx-auto w-full max-w-[min(100%,44rem)] overflow-hidden aspect-[100/55] sm:max-w-[min(100%,52rem)] lg:max-w-[min(100%,60rem)] xl:max-w-[min(100%,68rem)]',
        className,
      )}
    >
      <div className="absolute inset-x-0 top-0 aspect-square w-full">
        {globeConfig ? (
          <World
            key={themeKey}
            globeConfig={globeConfig}
            data={globePresence.arcs}
            markers={globePresence.markers}
            active={globeActive}
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
          </div>
        )}
        {electric && globeConfig ? (
          // A comet of the hero's light orbiting the globe's outline.
          <div
            aria-hidden
            className="init5-globe-orbit"
            style={{
              left: `${50 - GLOBE_OUTLINE_RADIUS_PCT}%`,
              top: `${50 - GLOBE_OUTLINE_RADIUS_PCT}%`,
              width: `${GLOBE_OUTLINE_RADIUS_PCT * 2}%`,
              height: `${GLOBE_OUTLINE_RADIUS_PCT * 2}%`,
            }}
          >
            <span className="init5-light-halo" />
          </div>
        ) : null}
      </div>
    </div>
  )
}
