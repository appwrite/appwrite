'use client'

import { useMemo } from 'react'
import { World } from '@/components/ui/globe'
import { useGlobeThemeConfig } from '@/hooks/use-globe-theme-config'
import {
  buildCombinedNetworkGlobeData,
  getNetworkSegmentColors,
} from '@/lib/home/build-network-globe-data'
import { cn } from '@/lib/utils'

export function NetworkGlobe({
  active = true,
  ready = false,
  onReady,
  className,
}: {
  active?: boolean
  ready?: boolean
  onReady?: () => void
  className?: string
}) {
  const { config: globeConfig, themeKey } = useGlobeThemeConfig()
  const segmentColors = useMemo(() => getNetworkSegmentColors(), [themeKey])
  const globePresence = useMemo(
    () => buildCombinedNetworkGlobeData(segmentColors),
    [segmentColors],
  )

  if (!globeConfig) {
    return null
  }

  return (
    <div
      className={cn(
        'h-full w-full',
        ready ? 'opacity-100' : 'opacity-0',
        className,
      )}
      aria-hidden={!ready}
    >
      <World
        key={themeKey}
        globeConfig={globeConfig}
        data={globePresence.arcs}
        markers={globePresence.markers}
        active={active}
        onReady={onReady}
      />
    </div>
  )
}
