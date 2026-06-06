import { useMemo } from 'react'
import { CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { World } from '@/components/ui/globe'
import { useGlobeThemeConfig } from '@/hooks/use-globe-theme-config'
import {
  buildCombinedNetworkGlobeData,
  getNetworkSegmentColors,
  NETWORK_SEGMENT_COLORS,
  NETWORK_SEGMENT_LABELS,
} from '@/lib/home/build-network-globe-data'
import type { NetworkSegment } from '@/lib/home/network-locations'
import { cn } from '@/lib/utils'

const networkProtections = [
  'Global CDN',
  'DDoS protection',
  'Sub-50ms latency',
] as const

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
            style={{ backgroundColor: NETWORK_SEGMENT_COLORS[segment] }}
            aria-hidden
          />
          <span className="text-foreground">{NETWORK_SEGMENT_LABELS[segment]}</span>
        </span>
      ))}
    </div>
  )
}

function NetworkGlobe({ className }: { className?: string }) {
  const { config: globeConfig, themeKey } = useGlobeThemeConfig()
  const segmentColors = useMemo(() => getNetworkSegmentColors(), [themeKey])
  const globePresence = useMemo(
    () => buildCombinedNetworkGlobeData(segmentColors),
    [segmentColors],
  )

  return (
    <div
      className={cn(
        'relative mx-auto w-full max-w-[min(100%,50rem)] overflow-hidden aspect-[100/48] sm:max-w-[min(100%,60rem)] lg:max-w-[min(100%,68rem)] xl:max-w-[min(100%,76rem)]',
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
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <div className="size-10 animate-spin rounded-full border-2 border-border border-t-[var(--brand-cta)]" />
          </div>
        )}
      </div>

      <NetworkGlobeLegend className="absolute bottom-4 left-3 z-30 sm:bottom-5 sm:left-4" />
    </div>
  )
}

export function NetworkSection() {
  return (
    <section className="border-t border-border bg-background">
      <div className="relative overflow-hidden px-4 pt-16 pb-0 sm:px-6 sm:pt-20">
        <div className="mx-auto flex w-full max-w-4xl flex-col items-center text-center">
          <h2 className="font-aeonik-pro text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            The Appwrite Network
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-5 max-w-2xl text-[14px] leading-6 text-muted-foreground sm:text-[15px]">
            Built into every Appwrite project: backend APIs, serverless functions,
            and hosted websites, with requests and assets served through our CDN
            and DDoS protection at the network edge. Choose global regions and
            edges to optimize latency, compliance, and data residency.
          </p>

          <ul
            className="mt-6 flex list-none flex-wrap items-center justify-center gap-x-5 gap-y-2"
            aria-label="Network protections included with every Appwrite project"
          >
            {networkProtections.map((label) => (
              <li
                key={label}
                className="flex items-center gap-1.5 text-[13px] font-medium text-foreground"
              >
                <CheckCircle2
                  className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                  aria-hidden
                />
                {label}
              </li>
            ))}
          </ul>

          <Button variant="outline" className="mt-6 h-10 text-[13px]" asChild>
            <a
              href="https://appwrite.io/docs/products/network"
              target="_blank"
              rel="noopener noreferrer"
            >
              More about the Appwrite Network
            </a>
          </Button>
        </div>

        <NetworkGlobe className="relative z-20 -mt-2 w-full sm:-mt-6 lg:-mt-10" />

        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 z-10"
          aria-hidden
        >
          <div className="h-32 w-full bg-gradient-to-b from-transparent to-background sm:h-40 lg:h-48" />
        </div>
      </div>

      <div className="w-full border-t border-border" aria-hidden />
    </section>
  )
}
