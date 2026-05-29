import { getCountryCoordinates } from '@/lib/country-coordinates'
import { withAlpha } from '@/lib/css-theme-colors'
import type { InitCommunityCountry } from '@/lib/init/types'

export type InitGlobeArc = {
  order: number
  startLat: number
  startLng: number
  endLat: number
  endLng: number
  arcAlt: number
  color: string
}

function buildArcColors(brandColor: string): string[] {
  return [brandColor, withAlpha(brandColor, 0.75), withAlpha(brandColor, 0.55)]
}

const FALLBACK_LOCATIONS: Array<{ code: string; lat: number; lng: number }> = [
  { code: 'US', lat: 37.09, lng: -95.71 },
  { code: 'IN', lat: 20.59, lng: 78.96 },
  { code: 'GB', lat: 55.38, lng: -3.44 },
  { code: 'DE', lat: 51.17, lng: 10.45 },
  { code: 'BR', lat: -14.24, lng: -51.93 },
  { code: 'SG', lat: 1.35, lng: 103.82 },
  { code: 'NG', lat: 9.08, lng: 8.68 },
  { code: 'AU', lat: -25.27, lng: 133.78 },
]

function toLatLng(code: string): { lat: number; lng: number } | null {
  const coords = getCountryCoordinates(code)
  if (!coords) return null
  const [lng, lat] = coords
  return { lat, lng }
}

function pushArc(
  arcs: InitGlobeArc[],
  order: number,
  start: { lat: number; lng: number },
  end: { lat: number; lng: number },
  arcAlt: number,
  colorIndex: number,
  arcColors: string[],
) {
  arcs.push({
    order,
    startLat: start.lat,
    startLng: start.lng,
    endLat: end.lat,
    endLng: end.lng,
    arcAlt,
    color: arcColors[colorIndex % arcColors.length],
  })
}

function buildCountryToCountryArcs(
  locations: Array<{ lat: number; lng: number; code?: string }>,
  arcColors: string[],
  step = 1,
): InitGlobeArc[] {
  if (locations.length < 2) return []

  const arcs: InitGlobeArc[] = []
  let order = 1

  for (let index = 0; index < locations.length; index += step) {
    const current = locations[index]
    const next = locations[(index + step) % locations.length]
    if (current.code && next.code && current.code === next.code) continue

    pushArc(
      arcs,
      order,
      current,
      next,
      0.12 + (index % 3) * 0.05,
      order,
      arcColors,
    )
    order += 1
  }

  return arcs
}

/** Build animated arcs for the Aceternity GitHub-style globe from live community data. */
export function buildInitGlobeArcs(
  countries: InitCommunityCountry[],
  brandColor = 'rgb(253, 54, 110)',
): InitGlobeArc[] {
  const arcColors = buildArcColors(brandColor)
  const positions = countries
    .map((country) => {
      const point = toLatLng(country.code)
      if (!point) return null
      return { ...point, count: country.count, code: country.code }
    })
    .filter((entry): entry is NonNullable<typeof entry> => entry !== null)

  if (positions.length === 0) {
    return buildCountryToCountryArcs(FALLBACK_LOCATIONS, arcColors, 2)
  }

  return buildCountryToCountryArcs(positions, arcColors).slice(0, 40)
}
