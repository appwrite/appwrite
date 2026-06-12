import { getCssColorExpression, withAlpha } from '@/lib/css-theme-colors'
import type {
  InitGlobeArc,
  InitGlobeMarker,
  InitGlobePresenceData,
} from '@/lib/init/build-init-globe-arcs'
import { getInitGlobeBrandRgb } from '@/lib/init/init-globe-theme'
import {
  networkLocations,
  type NetworkLocation,
  type NetworkSegment,
} from '@/lib/home/network-locations'

/** CSS variables for legend swatches (SSR-safe, follows active theme). */
export const NETWORK_SEGMENT_CSS_VARS: Record<NetworkSegment, string> = {
  'pop-locations': 'var(--brand-cta)',
  edges: 'var(--network-globe-edge)',
  regions: 'var(--network-globe-region)',
}

/** @deprecated Use {@link getNetworkSegmentColors} or {@link NETWORK_SEGMENT_CSS_VARS}. */
export const NETWORK_SEGMENT_COLORS: Record<NetworkSegment, string> = {
  'pop-locations': 'rgb(253, 54, 110)',
  edges: 'rgb(124, 103, 254)',
  regions: 'rgb(254, 149, 103)',
}

export const NETWORK_SEGMENT_LABELS: Record<NetworkSegment, string> = {
  'pop-locations': 'PoP locations',
  edges: 'Edges',
  regions: 'Regions',
}

const NETWORK_MARKER_OPACITY = 0.85
const PLANNED_NETWORK_MARKER_OPACITY = 0.5

const SEGMENT_LAYER: Record<
  NetworkSegment,
  { altitude: number; pointRadius: number; ringMaxRadius: number }
> = {
  'pop-locations': { altitude: 0.001, pointRadius: 0.75, ringMaxRadius: 0 },
  edges: { altitude: 0.0025, pointRadius: 1.0, ringMaxRadius: 3.0 },
  regions: { altitude: 0.004, pointRadius: 1.2, ringMaxRadius: 3.5 },
}

export function getNetworkSegmentColors(): Record<NetworkSegment, string> {
  return {
    'pop-locations': getInitGlobeBrandRgb(),
    edges: getCssColorExpression(
      'var(--network-globe-edge)',
      NETWORK_SEGMENT_COLORS.edges,
    ),
    regions: getCssColorExpression(
      'var(--network-globe-region)',
      NETWORK_SEGMENT_COLORS.regions,
    ),
  }
}

function isLocationLive(location: NetworkLocation): boolean {
  return location.available !== false && !location.date
}

const COORD_EPSILON = 0.01

function coordsMatch(
  a: Pick<NetworkLocation, 'lat' | 'lng'>,
  b: Pick<NetworkLocation, 'lat' | 'lng'>,
): boolean {
  return (
    Math.abs(a.lat - b.lat) < COORD_EPSILON &&
    Math.abs(a.lng - b.lng) < COORD_EPSILON
  )
}

const COLLISION_OFFSET_DISTANCE = 1.1

/** Split edge and region pins around shared hub coordinates. */
function getCollisionOffset(location: NetworkLocation): {
  lat: number
  lng: number
} {
  const hash = location.code
    .split('')
    .reduce((acc, char) => acc + char.charCodeAt(0), 0)
  const angle = (hash % 360) * (Math.PI / 180)

  return {
    lat: Math.sin(angle) * COLLISION_OFFSET_DISTANCE * 0.55,
    lng: Math.cos(angle) * COLLISION_OFFSET_DISTANCE,
  }
}

function resolveMarkerPosition(
  location: NetworkLocation,
  segment: NetworkSegment,
  peerLocations?: NetworkLocation[],
): { lat: number; lng: number } {
  const hasPeerCollision = peerLocations?.some((peer) =>
    coordsMatch(location, peer),
  )

  if (!hasPeerCollision) {
    return { lat: location.lat, lng: location.lng }
  }

  const offset = getCollisionOffset(location)

  if (segment === 'edges') {
    return {
      lat: location.lat - offset.lat,
      lng: location.lng - offset.lng,
    }
  }

  if (segment === 'regions') {
    return {
      lat: location.lat + offset.lat,
      lng: location.lng + offset.lng,
    }
  }

  return { lat: location.lat, lng: location.lng }
}

function createMarker(
  location: NetworkLocation,
  segment: NetworkSegment,
  color: string,
  peerLocations?: NetworkLocation[],
): InitGlobeMarker {
  const live = isLocationLive(location)
  const layer = SEGMENT_LAYER[segment]
  const { lat, lng } = resolveMarkerPosition(
    location,
    segment,
    peerLocations,
  )

  return {
    lat,
    lng,
    color: live
      ? withAlpha(color, NETWORK_MARKER_OPACITY)
      : withAlpha(color, PLANNED_NETWORK_MARKER_OPACITY),
    count: 1,
    pointRadius: layer.pointRadius,
    ringMaxRadius: layer.ringMaxRadius,
    pointAltitude: layer.altitude,
  }
}

function buildSegmentGlobeData(
  locations: NetworkLocation[],
  segment: NetworkSegment,
  color: string,
  peerLocations?: NetworkLocation[],
): InitGlobePresenceData {
  const markers = locations.map((location) =>
    createMarker(location, segment, color, peerLocations),
  )

  const liveMarkers = markers.filter((_, index) => isLocationLive(locations[index]))
  const arcs: InitGlobeArc[] = []

  if (segment !== 'pop-locations' && liveMarkers.length >= 2) {
    for (let index = 0; index < liveMarkers.length; index += 1) {
      const current = liveMarkers[index]
      const next = liveMarkers[(index + 1) % liveMarkers.length]

      arcs.push({
        order: index + 1,
        startLat: current.lat,
        startLng: current.lng,
        endLat: next.lat,
        endLng: next.lng,
        arcAlt: 0.18 + (index % 3) * 0.06,
        color: withAlpha(color, 0.75),
      })
    }
  }

  return { arcs, markers }
}

/** Combined PoP, edge, and region markers for the marketing network globe. */
export function buildCombinedNetworkGlobeData(
  colors: Record<NetworkSegment, string>,
): InitGlobePresenceData {
  const markers: InitGlobeMarker[] = []
  const arcs: InitGlobeArc[] = []
  const segments: NetworkSegment[] = ['pop-locations', 'edges', 'regions']

  for (const segment of segments) {
    const peerLocations =
      segment === 'edges'
        ? networkLocations.regions
        : segment === 'regions'
          ? networkLocations.edges
          : undefined

    const segmentData = buildSegmentGlobeData(
      networkLocations[segment],
      segment,
      colors[segment],
      peerLocations,
    )
    markers.push(...segmentData.markers)
    arcs.push(...segmentData.arcs)
  }

  return { markers, arcs }
}
