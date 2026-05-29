import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react'
import { useTheme } from 'next-themes'
import { buildInitGlobeArcs } from '@/lib/init/build-init-globe-arcs'
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

type InitCommunityGlobeProps = {
  countries: InitCommunityCountry[]
  className?: string
}

function InitCommunityGlobe({ countries, className }: InitCommunityGlobeProps) {
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
  const arcs = useMemo(
    () => buildInitGlobeArcs(countries, brandRgb),
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
            <World key={themeKey} globeConfig={globeConfig} data={arcs} />
          ) : (
            <div className="flex aspect-square items-center justify-center">
              <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
            </div>
          )}
        </Suspense>
      </div>
    </div>
  )
}

type InitGlobalCommunitySectionProps = {
  countries: InitCommunityCountry[]
  isAuthenticated: boolean
}

export function InitGlobalCommunitySection({
  countries,
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
            className="relative z-0 -mt-6 w-full sm:-mt-10 lg:-mt-14"
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
