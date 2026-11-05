/** Shared globe prop types — keep this file free of Three.js / WebGL imports. */

export type GlobeArc = {
  order: number
  startLat: number
  startLng: number
  endLat: number
  endLng: number
  arcAlt: number
  color: string
}

export type GlobeMarker = {
  lat: number
  lng: number
  color: string
  count: number
  pointRadius: number
  ringMaxRadius: number
  pointAltitude?: number
}

export type GlobeConfig = {
  pointSize?: number
  globeColor?: string
  showAtmosphere?: boolean
  atmosphereColor?: string
  atmosphereAltitude?: number
  emissive?: string
  emissiveIntensity?: number
  shininess?: number
  polygonColor?: string
  ambientLight?: string
  directionalLeftLight?: string
  directionalTopLight?: string
  pointLight?: string
  fogColor?: string
  arcTime?: number
  arcLength?: number
  rings?: number
  maxRings?: number
  autoRotate?: boolean
  autoRotateSpeed?: number
  /** Even ambient lighting - no dark side on the sphere. */
  evenLighting?: boolean
  ambientLightIntensity?: number
  directionalLightIntensity?: number
  pointLightIntensity?: number
}
