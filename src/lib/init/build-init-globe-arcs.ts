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

export type InitGlobeMarker = {
  lat: number
  lng: number
  color: string
  count: number
  pointRadius: number
  ringMaxRadius: number
  /** Height above the globe surface, in globe-radius units. */
  pointAltitude?: number
}

export type InitGlobePresenceData = {
  arcs: InitGlobeArc[]
  markers: InitGlobeMarker[]
}

function buildArcColors(brandColor: string): string[] {
  return [brandColor, withAlpha(brandColor, 0.75), withAlpha(brandColor, 0.55)]
}

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

function buildMarkerVisualWeights(count: number, maxCount: number) {
  const MIN_POINT = 1.25
  const MAX_POINT = 4.5
  const MIN_RING = 2
  const MAX_RING = 7
  const weight = maxCount <= 1 ? 1 : Math.sqrt(count / maxCount)

  return {
    pointRadius: MIN_POINT + weight * (MAX_POINT - MIN_POINT),
    ringMaxRadius: MIN_RING + weight * (MAX_RING - MIN_RING),
  }
}

/** Build globe arcs and country markers from live presence. No data → empty. */
export function buildInitGlobePresenceData(
  countries: InitCommunityCountry[],
  brandColor = 'rgb(253, 54, 110)',
): InitGlobePresenceData {
  const arcColors = buildArcColors(brandColor)
  const positions = countries
    .map((country) => {
      const point = toLatLng(country.code)
      if (!point) return null
      return { ...point, count: country.count, code: country.code }
    })
    .filter((entry): entry is NonNullable<typeof entry> => entry !== null)

  if (positions.length === 0) {
    return { arcs: [], markers: [] }
  }

  const maxCount = Math.max(...positions.map((position) => position.count))
  const markers: InitGlobeMarker[] = positions.map((position, index) => {
    const { pointRadius, ringMaxRadius } = buildMarkerVisualWeights(
      position.count,
      maxCount,
    )

    return {
      lat: position.lat,
      lng: position.lng,
      color: arcColors[index % arcColors.length],
      count: position.count,
      pointRadius,
      ringMaxRadius,
    }
  })

  if (positions.length < 2) {
    return { arcs: [], markers }
  }

  const arcs: InitGlobeArc[] = []
  let order = 1

  for (let index = 0; index < positions.length; index += 1) {
    const current = positions[index]
    const arcCount = Math.min(current.count, 2)

    for (let offset = 1; offset <= arcCount; offset += 1) {
      const target = positions[(index + offset) % positions.length]
      if (current.code === target.code) continue

      pushArc(
        arcs,
        order,
        current,
        target,
        0.12 + (offset - 1) * 0.05,
        order,
        arcColors,
      )
      order += 1
    }
  }

  return { arcs: arcs.slice(0, 40), markers }
}
