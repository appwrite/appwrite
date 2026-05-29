import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react'
import { useTheme } from 'next-themes'
import { buildInitGlobePresenceData } from '@/lib/init/build-init-globe-arcs'
import { buildInitGlobeConfig, getInitGlobeBrandRgb } from '@/lib/init/init-globe-theme'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitExploringGlobeActivity } from '@/lib/init/init-presence-activity'
import type { GlobeConfig } from '@/components/ui/globe'
import type { InitCommunityCountry } from '@/lib/init/types'
import { cn } from '@/lib/utils'

const World = lazy(() =>
  import('@/components/ui/globe').then((module) => ({ default: module.World })),
)

function useInitGlobeConfig(): {
  config: GlobeConfig | null
  themeKey: string
} {
  const { resolvedTheme } = useTheme()
  const [mounted, setMounted] = useState(false)
  const [config, setConfig] = useState<GlobeConfig | null>(null)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    if (!mounted) return

    let cancelled = false
    const isDark = resolvedTheme === 'dark'

    const syncConfig = () => {
      if (!cancelled) {
        setConfig(buildInitGlobeConfig(isDark))
      }
    }

    // Re-read CSS theme tokens after the html class / variables have updated.
    const frame = requestAnimationFrame(() => {
      requestAnimationFrame(syncConfig)
    })

    return () => {
      cancelled = true
      cancelAnimationFrame(frame)
    }
  }, [mounted, resolvedTheme])

  const themeKey = !mounted ? 'pending' : resolvedTheme === 'dark' ? 'dark' : 'light'

  return { config, themeKey }
}

function serializeCommunityCountries(countries: InitCommunityCountry[]): string {
  return countries.map((country) => `${country.code}:${country.count}`).join('|')
}

function InitGlobePresenceStats({
  countries,
  developerCount,
  isLive,
  className,
}: {
  countries: InitCommunityCountry[]
  developerCount: number
  isLive: boolean
  className?: string
}) {
  const countryCount = countries.length
  const developers =
    developerCount > 0
      ? developerCount
      : countries.reduce((total, country) => total + country.count, 0)

  if (countryCount === 0 && developers === 0) return null

  return (
    <div
      className={cn(
        'pointer-events-none flex items-center gap-2 rounded-lg border border-border/80 bg-background/85 px-2.5 py-1.5 shadow-sm backdrop-blur-sm',
        className,
      )}
    >
      {isLive ? (
        <span className="relative flex size-2 shrink-0" aria-hidden>
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-green-500/60 opacity-75" />
          <span className="relative inline-flex size-2 rounded-full bg-green-500" />
        </span>
      ) : null}
      <p className="text-[12px] tabular-nums text-foreground">
        <span className="font-semibold">{countryCount.toLocaleString()}</span>
        <span className="text-muted-foreground">
          {' '}
          {countryCount === 1 ? 'country' : 'countries'}
        </span>
        {developers > 0 ? (
          <>
            <span className="text-muted-foreground"> · </span>
            <span className="font-semibold">{developers.toLocaleString()}</span>
            <span className="text-muted-foreground"> online</span>
          </>
        ) : null}
      </p>
      {isLive ? <span className="sr-only">Live presence</span> : null}
    </div>
  )
}

type InitCommunityGlobeProps = {
  countries: InitCommunityCountry[]
  developerCount: number
  isLive: boolean
  className?: string
}

function InitCommunityGlobe({
  countries,
  developerCount,
  isLive,
  className,
}: InitCommunityGlobeProps) {
  const { config: globeConfig, themeKey } = useInitGlobeConfig()
  const { setTransientActivity } = useInitPresenceActivity()
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

  const handleGlobeInteractionStart = useCallback(() => {
    setTransientActivity(buildInitExploringGlobeActivity())
  }, [setTransientActivity])

  const handleGlobeInteractionEnd = useCallback(() => {
    setTransientActivity(null)
  }, [setTransientActivity])

  return (
    <div
      className={cn(
        'relative mx-auto w-full max-w-[min(100%,44rem)] overflow-hidden aspect-[100/55] sm:max-w-[min(100%,52rem)] lg:max-w-[min(100%,60rem)] xl:max-w-[min(100%,68rem)]',
        className,
      )}
      onPointerDown={handleGlobeInteractionStart}
      onPointerUp={handleGlobeInteractionEnd}
      onPointerLeave={handleGlobeInteractionEnd}
      onPointerCancel={handleGlobeInteractionEnd}
    >
      <div className="aspect-square w-full">
        <Suspense
          fallback={
            <div className="flex aspect-square items-center justify-center">
              <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
            </div>
          }
        >
          {globeConfig ? (
            <World
              key={themeKey}
              globeConfig={globeConfig}
              data={globePresence.arcs}
              markers={globePresence.markers}
            />
          ) : (
            <div className="flex aspect-square items-center justify-center">
              <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
            </div>
          )}
        </Suspense>
      </div>

      <InitGlobePresenceStats
        countries={countries}
        developerCount={developerCount}
        isLive={isLive}
        className="absolute bottom-4 left-3 z-20 sm:bottom-5 sm:left-4"
      />
    </div>
  )
}

type InitGlobalCommunitySectionProps = {
  countries: InitCommunityCountry[]
  developerCount: number
  isLive: boolean
  isAuthenticated: boolean
}

export function InitGlobalCommunitySection({
  countries,
  developerCount,
  isLive,
  isAuthenticated,
}: InitGlobalCommunitySectionProps) {
  return (
    <div className="relative">
      <div className="relative overflow-hidden">
        <section className="relative px-4 pt-6 sm:px-6 sm:pt-8">
          <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col items-center space-y-3 text-center">
            <div className="space-y-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                Developers joining from every corner of the world
              </h3>
              <p className="mx-auto max-w-xl text-[13px] leading-relaxed text-muted-foreground">
                Init brings developers together to connect through a shared passion for
                code and open source. Watch the globe light up as builders tune in live
                from around the globe.
              </p>
            </div>

            {!isAuthenticated ? (
              <p className="text-[12px] text-muted-foreground">
                Sign in to share your location and appear on the globe.
              </p>
            ) : null}
          </div>

          <InitCommunityGlobe
            countries={countries}
            developerCount={developerCount}
            isLive={isLive}
            className="relative z-20 -mt-6 w-full sm:-mt-10 lg:-mt-14"
          />
        </section>

        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 z-10"
          aria-hidden
        >
          <div className="h-32 w-full bg-gradient-to-b from-transparent to-background sm:h-40 lg:h-48" />
        </div>
      </div>

      <div className="w-full border-t border-border bg-background" aria-hidden />
    </div>
  )
}
