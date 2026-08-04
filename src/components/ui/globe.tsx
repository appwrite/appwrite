'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Color,
  PerspectiveCamera,
  Vector3,
  type Group,
} from 'three'
import ThreeGlobe from 'three-globe'
import { useThree, Canvas, extend, useFrame } from '@react-three/fiber'
import { OrbitControls } from '@react-three/drei'
import countries from '@/data/globe.json'

declare module '@react-three/fiber' {
  interface ThreeElements {
    threeGlobe: ThreeElements['mesh'] & {
      new (): ThreeGlobe
    }
  }
}

extend({ ThreeGlobe: ThreeGlobe })

const RING_PROPAGATION_SPEED = 3
const CAMERA_Z = 300
const ARC_STROKE = 0.3

function applyGlobeLand(globe: ThreeGlobe, landColor: string) {
  globe
    .hexPolygonsData(countries.features)
    .hexPolygonResolution(3)
    .hexPolygonMargin(0.35)
    .hexPolygonAltitude(0)
    .hexPolygonUseDots(false)
    .hexPolygonsTransitionDuration(0)
    .hexPolygonColor(() => landColor)
    .polygonsData([])
}

type Position = {
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

type GlobeRingEntry = {
  lat: number
  lng: number
  color: string
  ringMaxRadius: number
}

function markerToRingEntry(marker: GlobeMarker): GlobeRingEntry {
  return {
    lat: marker.lat,
    lng: marker.lng,
    color: marker.color,
    ringMaxRadius: marker.ringMaxRadius,
  }
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

interface WorldProps {
  globeConfig: GlobeConfig
  data: Position[]
  markers?: GlobeMarker[]
  /** When false, pauses the WebGL render loop (e.g. globe scrolled off-screen). */
  active?: boolean
  maxPixelRatio?: number
  /** Fires once after the first rendered frame. */
  onReady?: () => void
}

function FirstFrameNotifier({ onReady }: { onReady?: () => void }) {
  const notified = useRef(false)

  useFrame(() => {
    if (notified.current || !onReady) return
    notified.current = true
    onReady()
  })

  return null
}

function GlobeRenderControl({ active }: { active: boolean }) {
  const invalidate = useThree((state) => state.invalidate)

  useEffect(() => {
    if (active) invalidate()
  }, [active, invalidate])

  return null
}

type ResolvedGlobeProps = Required<
  Pick<
    GlobeConfig,
    | 'pointSize'
    | 'globeColor'
    | 'showAtmosphere'
    | 'atmosphereColor'
    | 'atmosphereAltitude'
    | 'emissive'
    | 'emissiveIntensity'
    | 'shininess'
    | 'polygonColor'
    | 'arcTime'
    | 'arcLength'
    | 'rings'
    | 'maxRings'
    | 'autoRotateSpeed'
  >
>

function resolveGlobeProps(globeConfig: GlobeConfig): ResolvedGlobeProps {
  return {
    pointSize: globeConfig.pointSize ?? 1,
    globeColor: globeConfig.globeColor ?? '#1d072e',
    showAtmosphere: globeConfig.showAtmosphere ?? true,
    atmosphereColor: globeConfig.atmosphereColor ?? '#ffffff',
    atmosphereAltitude: globeConfig.atmosphereAltitude ?? 0.1,
    emissive: globeConfig.emissive ?? '#000000',
    emissiveIntensity: globeConfig.emissiveIntensity ?? 0.1,
    shininess: globeConfig.shininess ?? 0.9,
    polygonColor: globeConfig.polygonColor ?? 'rgba(255,255,255,0.7)',
    arcTime: globeConfig.arcTime ?? 2000,
    arcLength: globeConfig.arcLength ?? 0.9,
    rings: globeConfig.rings ?? 1,
    maxRings: globeConfig.maxRings ?? 3,
    autoRotateSpeed: globeConfig.autoRotateSpeed ?? 1,
  }
}

function WebGLRendererConfig({ maxPixelRatio = 1.5 }: { maxPixelRatio?: number }) {
  const { gl, size } = useThree()

  useEffect(() => {
    gl.setPixelRatio(Math.min(window.devicePixelRatio, maxPixelRatio))
    gl.setSize(size.width, size.height)
    gl.setClearColor(0x000000, 0)
  }, [gl, maxPixelRatio, size.height, size.width])

  return null
}

function CameraSync() {
  const { camera, size } = useThree()

  useEffect(() => {
    if (!(camera instanceof PerspectiveCamera) || size.height === 0) return
    camera.aspect = size.width / size.height
    camera.updateProjectionMatrix()
  }, [camera, size.width, size.height])

  return null
}

export function Globe({
  globeConfig,
  data,
  markers = [],
  active = true,
}: WorldProps) {
  const globeRef = useRef<ThreeGlobe | null>(null)
  const groupRef = useRef<Group | null>(null)
  const [isInitialized, setIsInitialized] = useState(false)
  const resolved = useMemo(() => resolveGlobeProps(globeConfig), [globeConfig])
  const resolvedRef = useRef(resolved)
  resolvedRef.current = resolved
  const dataRef = useRef(data)
  dataRef.current = data
  const markersRef = useRef(markers)
  markersRef.current = markers

  useEffect(() => {
    if (!globeRef.current && groupRef.current) {
      globeRef.current = new ThreeGlobe({ animateIn: false })
      groupRef.current.add(globeRef.current)
      applyGlobeLand(globeRef.current, resolvedRef.current.polygonColor)
      setIsInitialized(true)
    }
  }, [])

  useEffect(() => {
    if (!globeRef.current || !isInitialized) return

    const globeMaterial = globeRef.current.globeMaterial() as unknown as {
      color: Color
      emissive: Color
      emissiveIntensity: number
      shininess: number
    }
    globeMaterial.color = new Color(resolved.globeColor)
    globeMaterial.emissive = new Color(resolved.emissive)
    globeMaterial.emissiveIntensity = resolved.emissiveIntensity
    globeMaterial.shininess = resolved.shininess

    globeRef.current
      .showAtmosphere(resolved.showAtmosphere)
      .atmosphereColor(resolved.atmosphereColor)
      .atmosphereAltitude(resolved.atmosphereAltitude)

    applyGlobeLand(globeRef.current, resolved.polygonColor)
  }, [
    isInitialized,
    resolved.atmosphereAltitude,
    resolved.atmosphereColor,
    resolved.emissive,
    resolved.emissiveIntensity,
    resolved.globeColor,
    resolved.polygonColor,
    resolved.shininess,
    resolved.showAtmosphere,
  ])

  useEffect(() => {
    if (!globeRef.current || !isInitialized) return

    const arcs = data
    const countryMarkers = markers
    const { pointSize, arcLength, arcTime, maxRings, rings, polygonColor } =
      resolvedRef.current
    const points: Array<{
      size: number
      order: number
      color: string
      lat: number
      lng: number
      radius: number
      altitude: number
    }> = []

    if (countryMarkers.length > 0) {
      for (const [index, marker] of countryMarkers.entries()) {
        points.push({
          size: pointSize,
          order: index + 1,
          color: marker.color,
          lat: marker.lat,
          lng: marker.lng,
          radius: marker.pointRadius,
          altitude: marker.pointAltitude ?? 0,
        })
      }
    } else {
      for (const arc of arcs) {
        points.push({
          size: pointSize,
          order: arc.order,
          color: arc.color,
          lat: arc.startLat,
          lng: arc.startLng,
          radius: 2,
          altitude: 0,
        })
        points.push({
          size: pointSize,
          order: arc.order,
          color: arc.color,
          lat: arc.endLat,
          lng: arc.endLng,
          radius: 2,
          altitude: 0,
        })
      }
    }

    const filteredPoints = points.filter(
      (point, index, all) =>
        all.findIndex(
          (other) =>
            other.lat === point.lat &&
            other.lng === point.lng &&
            other.color === point.color,
        ) === index,
    )

    globeRef.current
      .arcsData(arcs)
      .arcStartLat((entry) => (entry as Position).startLat)
      .arcStartLng((entry) => (entry as Position).startLng)
      .arcEndLat((entry) => (entry as Position).endLat)
      .arcEndLng((entry) => (entry as Position).endLng)
      .arcColor((entry) => (entry as Position).color)
      .arcAltitude((entry: object) => (entry as Position).arcAlt)
      .arcStroke(() => ARC_STROKE)
      .arcDashLength(arcLength)
      .arcDashInitialGap((entry) => (entry as Position).order)
      .arcDashGap(15)
      .arcDashAnimateTime(() => arcTime)

    globeRef.current
      .pointsData(filteredPoints)
      .pointColor((entry) => (entry as { color: string }).color)
      .pointsMerge(countryMarkers.length === 0)
      .pointAltitude((entry) => (entry as { altitude: number }).altitude)
      .pointRadius((entry) => (entry as { radius: number }).radius)

    if (countryMarkers.length > 0) {
      const ringMarkers = countryMarkers.filter(
        (marker) => marker.ringMaxRadius > 0,
      )

      globeRef.current
        .ringsData(ringMarkers.map(markerToRingEntry))
        .ringColor((entry) => (entry as { color: string }).color)
        .ringMaxRadius((entry) => (entry as GlobeRingEntry).ringMaxRadius)
        .ringPropagationSpeed(RING_PROPAGATION_SPEED)
        .ringRepeatPeriod((arcTime * arcLength) / rings)
    } else {
      globeRef.current
        .ringsData([])
        .ringColor(() => polygonColor)
        .ringMaxRadius(maxRings)
        .ringPropagationSpeed(RING_PROPAGATION_SPEED)
        .ringRepeatPeriod((arcTime * arcLength) / rings)
    }
  }, [data, isInitialized, markers])

  useEffect(() => {
    if (!active || !globeRef.current || !isInitialized) return
    if (data.length === 0 && markers.length === 0) return

    const interval = window.setInterval(() => {
      if (!globeRef.current) return

      const arcs = dataRef.current
      const countryMarkers = markersRef.current

      if (countryMarkers.length > 0) {
        const ringMarkers = countryMarkers.filter(
          (marker) => marker.ringMaxRadius > 0,
        )

        if (ringMarkers.length === 0) return

        const ringIndexes =
          ringMarkers.length === 1
            ? [0]
            : genRandomNumbers(
                0,
                ringMarkers.length,
                Math.max(1, Math.floor((ringMarkers.length * 4) / 5)),
              )

        globeRef.current
          .ringsData(ringIndexes.map((index) => markerToRingEntry(ringMarkers[index])))
          .ringMaxRadius((entry) => (entry as GlobeRingEntry).ringMaxRadius)
        return
      }

      const ringIndexes = genRandomNumbers(
        0,
        arcs.length,
        Math.max(1, Math.floor((arcs.length * 4) / 5)),
      )

      globeRef.current.ringsData(
        arcs
          .filter((_arc, index) => ringIndexes.includes(index))
          .map((arc) => ({
            lat: arc.startLat,
            lng: arc.startLng,
            color: arc.color,
          })),
      )
    }, 2000)

    return () => window.clearInterval(interval)
  }, [active, data, isInitialized, markers])

  return <group ref={groupRef} />
}

function GlobeLights({ globeConfig }: { globeConfig: GlobeConfig }) {
  if (globeConfig.evenLighting) {
    return (
      <>
        <ambientLight
          color={globeConfig.ambientLight ?? '#ffffff'}
          intensity={globeConfig.ambientLightIntensity ?? 2.4}
        />
        <directionalLight
          color={globeConfig.directionalLeftLight ?? '#ffffff'}
          position={new Vector3(-300, 200, 300)}
          intensity={globeConfig.directionalLightIntensity ?? 0.2}
        />
        <directionalLight
          color={globeConfig.directionalTopLight ?? '#ffffff'}
          position={new Vector3(300, 100, -300)}
          intensity={globeConfig.directionalLightIntensity ?? 0.2}
        />
      </>
    )
  }

  return (
    <>
      <ambientLight color={globeConfig.ambientLight ?? '#ffffff'} intensity={1.2} />
      <directionalLight
        color={globeConfig.directionalLeftLight ?? '#ffffff'}
        position={new Vector3(-400, 100, 400)}
        intensity={1.1}
      />
      <directionalLight
        color={globeConfig.directionalTopLight ?? '#ffffff'}
        position={new Vector3(-200, 500, 200)}
        intensity={0.9}
      />
      <pointLight
        color={globeConfig.pointLight ?? '#ffffff'}
        position={new Vector3(-200, 500, 200)}
        intensity={globeConfig.pointLightIntensity ?? 1}
      />
    </>
  )
}

export function World({
  globeConfig,
  data,
  markers,
  active = true,
  maxPixelRatio = 1.5,
  onReady,
}: WorldProps) {
  return (
    <Canvas
      className="h-full w-full"
      dpr={[1, maxPixelRatio]}
      frameloop={active ? 'always' : 'never'}
      gl={{
        alpha: true,
        antialias: true,
        powerPreference: 'high-performance',
        preserveDrawingBuffer: true,
      }}
      camera={{
        fov: 50,
        position: [0, 0, CAMERA_Z],
        near: 180,
        far: 1800,
      }}
    >
      <WebGLRendererConfig maxPixelRatio={maxPixelRatio} />
      <CameraSync />
      <FirstFrameNotifier onReady={onReady} />
      <GlobeRenderControl active={active} />
      <GlobeLights
        key={`${globeConfig.evenLighting ? 'even' : 'dir'}-${globeConfig.ambientLight}-${globeConfig.directionalLeftLight}-${globeConfig.pointLight}`}
        globeConfig={globeConfig}
      />
      <Globe globeConfig={globeConfig} data={data} markers={markers} active={active} />
      <OrbitControls
        enablePan={false}
        enableZoom={false}
        minDistance={CAMERA_Z}
        maxDistance={CAMERA_Z}
        autoRotateSpeed={globeConfig.autoRotateSpeed ?? 1}
        autoRotate={active && (globeConfig.autoRotate ?? true)}
        minPolarAngle={Math.PI / 3.5}
        maxPolarAngle={Math.PI - Math.PI / 3}
      />
    </Canvas>
  )
}

export function genRandomNumbers(min: number, max: number, count: number) {
  const values: number[] = []
  const upper = Math.max(min, max)
  while (values.length < count && values.length < upper - min) {
    const value = Math.floor(Math.random() * (upper - min)) + min
    if (!values.includes(value)) values.push(value)
  }
  return values
}
