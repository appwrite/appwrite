'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { buildInitGlobePresenceData } from '@/lib/init/build-init-globe-arcs'
import { getInitGlobeBrandRgb } from '@/lib/init/init-globe-theme'
import { useGlobeThemeConfig } from '@/hooks/use-globe-theme-config'
import { World } from '@/components/ui/globe'
import type { InitCommunityCountry } from '@/lib/init/types'
import { cn } from '@/lib/utils'

function serializeCommunityCountries(countries: InitCommunityCountry[]): string {
  return countries.map((country) => `${country.code}:${country.count}`).join('|')
}

type InitCommunityGlobeProps = {
  countries: InitCommunityCountry[]
  className?: string
}

export function InitCommunityGlobe({ countries, className }: InitCommunityGlobeProps) {
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
  const globePresence = useMemo(
    () => buildInitGlobePresenceData(countries, brandRgb),
    [brandRgb, countriesKey],
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
      </div>
    </div>
  )
}
