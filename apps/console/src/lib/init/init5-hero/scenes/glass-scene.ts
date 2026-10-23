import * as THREE from 'three'
import { toCreasedNormals } from 'three/addons/utils/BufferGeometryUtils.js'
import { getFiveContour, refineContour } from '../five-field'
import {
  clamp01,
  createSceneController,
  damp,
  easeInOutCubic,
  easeOutCubic,
  easeOutExpo,
  type SceneRuntime,
} from '../scene-runtime'
import type { Init5ScenePalette, Init5SceneFactory } from '../scene-types'

/**
 * Concept C - "Glass monolith 5".
 * An extruded, beveled glass 5 on a lit stage. Studio softboxes in brand
 * pink / violet / peach reflect across the bevels, Init's vertical light
 * lines refract and disperse through the body, a glint follows the cursor,
 * and a click spins the monolith a full turn.
 */

const REST_ROTATION_Y = -0.3
const INTRO_SECONDS = 2.8

const barsVertex = /* glsl */ `
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
`

const barsFragment = (count: number) => /* glsl */ `
uniform vec3 uBg;
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform vec3 uColorC;
uniform float uTime;
uniform float uIntensity;
uniform float uLight;
varying vec2 vUv;

float hash(float n) {
  return fract(sin(n * 12.9898) * 43758.5453);
}

void main() {
  vec2 p = (vUv - 0.5) * vec2(3.6, 2.8);
  vec3 light = vec3(0.0);
  float ink = 0.0;
  vec3 inkColor = vec3(0.0);
  for (int i = 0; i < ${count}; i++) {
    float fi = float(i);
    float cx = -1.05 + fi * (2.1 / ${count - 1}.0) + (hash(fi + 3.0) - 0.5) * 0.1;
    float width = 0.004 + hash(fi + 7.0) * 0.012;
    float dx = (p.x - cx) / width;
    float bar = exp(-dx * dx);
    float halo = exp(-dx * dx * 0.02) * 0.08;
    float speed = 0.12 + hash(fi + 11.0) * 0.16;
    float head = fract(uTime * speed + hash(fi + 17.0)) * 3.6 - 1.8;
    float behind = head - p.y;
    float streak = behind > 0.0 ? exp(-behind * 2.4) : exp(behind * 40.0);
    float fadeY = smoothstep(1.4, 0.3, abs(p.y));
    float fadeX = smoothstep(1.3, 0.45, abs(p.x));
    float pick = mod(fi, 3.0);
    vec3 color = pick < 0.5 ? uColorA : (pick < 1.5 ? uColorB : uColorC);
    float amount = (bar + halo) * (0.16 + streak * 1.9) * fadeY * fadeX * uIntensity;
    light += color * amount;
    ink = max(ink, amount);
    inkColor = amount >= ink ? color : inkColor;
  }
  // Soft backlight directly behind the glyph so the glass reads lit from within.
  vec2 q = p * vec2(1.15, 0.95);
  float back = exp(-dot(q, q) * 2.4) * uIntensity;
  vec3 backColor = mix(uColorB, uColorA, smoothstep(-0.6, 0.5, p.y + p.x * 0.3));
  light += backColor * back * 0.42;
  ink = max(ink, back * 0.22);
  inkColor = back * 0.22 >= ink ? backColor : inkColor;

  vec3 dark = uBg + light / (1.0 + light);
  vec3 bright = mix(uBg, inkColor, clamp(ink * 0.85, 0.0, 1.0));
  gl_FragColor = vec4(mix(dark, bright, uLight), 1.0);
  #include <colorspace_fragment>
}
`

type Environment = {
  target: THREE.WebGLRenderTarget
  dispose: () => void
}

function buildEnvironment(
  renderer: THREE.WebGLRenderer,
  palette: Init5ScenePalette,
): Environment {
  const env = new THREE.Scene()
  const dark = palette.isDark
  const disposables: Array<{ dispose: () => void }> = []

  const room = new THREE.Mesh(
    new THREE.BoxGeometry(14, 14, 14),
    new THREE.MeshBasicMaterial({
      color: new THREE.Color(dark ? '#0b0b0e' : '#e4e6ea'),
      side: THREE.BackSide,
    }),
  )
  env.add(room)
  disposables.push(room.geometry, room.material)

  const panel = (
    width: number,
    height: number,
    hex: string,
    intensity: number,
    x: number,
    y: number,
    z: number,
  ) => {
    const mesh = new THREE.Mesh(
      new THREE.PlaneGeometry(width, height),
      new THREE.MeshBasicMaterial({
        color: new THREE.Color(hex).multiplyScalar(intensity),
        side: THREE.DoubleSide,
      }),
    )
    mesh.position.set(x, y, z)
    mesh.lookAt(0, 0, 0)
    env.add(mesh)
    disposables.push(mesh.geometry, mesh.material)
  }

  // Softbox rig: brand colors land on the bevels as long, clean reflections,
  // and a broad key behind the camera gives the face its glassy sheen.
  panel(8, 1.1, '#ffffff', dark ? 5 : 3.2, 0, 5, 1.6)
  panel(1.1, 7, palette.accent, dark ? 7 : 4.5, -5.5, 0.4, 1.2)
  panel(1.2, 6, '#ffffff', dark ? 3.2 : 2.2, -4.6, 3, -2.2)
  panel(0.9, 7, palette.violet, dark ? 5 : 3.2, 5.5, 0.2, -0.6)
  panel(6, 0.7, palette.peach, dark ? 3.4 : 2.4, 0, -5, 2.2)
  panel(2.4, 5, '#ffffff', dark ? 1.8 : 2.4, 4.2, 1.2, 4.2)
  panel(5, 2.6, '#ffffff', dark ? 1.5 : 2.2, 1.6, 1.9, 6.4)
  if (!dark) {
    // Negative fill: on a white stage, dark flags give the bevels crisp edges.
    panel(2.6, 9, '#0b0b0f', 1, -4, 0, 2.6)
    panel(9, 1.8, '#0b0b0f', 1, 0, -4.2, 2.6)
    panel(1.6, 6, '#0b0b0f', 1, 4.4, 2.2, 0.6)
  }

  const pmrem = new THREE.PMREMGenerator(renderer)
  const target = pmrem.fromScene(env, 0.03)
  pmrem.dispose()
  for (const item of disposables) item.dispose()

  return {
    target,
    dispose: () => target.dispose(),
  }
}

/** Copy one draw group of a non-indexed geometry into its own geometry. */
function sliceGroup(source: THREE.BufferGeometry, groupIndex: number) {
  const group = source.groups[groupIndex]
  const position = source.getAttribute('position')
  const out = new THREE.BufferGeometry()
  if (!group) return out
  const start = group.start
  const end = Math.min(position.count, group.start + group.count)
  out.setAttribute(
    'position',
    new THREE.BufferAttribute(
      (position.array as Float32Array).slice(start * 3, end * 3),
      3,
    ),
  )
  return out
}

/**
 * Beveled extrusion of the 5. Caps and walls are shaded separately: caps keep
 * perfectly flat normals (earcut slivers along straight edges would otherwise
 * smear into dark facets), walls and bevels get creased smooth normals.
 */
function buildGlyphGeometry(quality: SceneRuntime['quality']) {
  const contour = refineContour(
    getFiveContour(),
    quality === 'low' ? 0.012 : 0.0065,
  )
  const shape = new THREE.Shape(
    contour.map((point) => new THREE.Vector2(point.x, point.y)),
  )
  const extruded = new THREE.ExtrudeGeometry(shape, {
    depth: 0.17,
    bevelEnabled: true,
    bevelThickness: 0.034,
    bevelSize: 0.021,
    bevelOffset: 0,
    bevelSegments: quality === 'low' ? 3 : 6,
    curveSegments: 1,
    steps: 1,
  })
  extruded.center()

  const caps = sliceGroup(extruded, 0)
  const capPositions = caps.getAttribute('position')
  const capNormals = new Float32Array(capPositions.count * 3)
  for (let i = 0; i < capPositions.count; i++) {
    capNormals[i * 3 + 2] = capPositions.getZ(i) >= 0 ? 1 : -1
  }
  caps.setAttribute('normal', new THREE.BufferAttribute(capNormals, 3))

  // toCreasedNormals welds vertices on a 0.01 grid; scale up so the glyph's
  // ~0.006-unit spacing and bevel rings stay distinct, then scale back.
  const rawWalls = sliceGroup(extruded, 1)
  rawWalls.scale(100, 100, 100)
  const walls = toCreasedNormals(rawWalls, THREE.MathUtils.degToRad(32))
  walls.scale(0.01, 0.01, 0.01)
  extruded.dispose()
  return { caps, walls }
}

export const createGlassScene: Init5SceneFactory = (options) =>
  createSceneController(
    options,
    (runtime) => {
      const { scene, renderer } = runtime
      const lowQuality = runtime.quality === 'low'

      const background = new THREE.Color()
      scene.background = background

      let environment = buildEnvironment(renderer, runtime.palette)
      scene.environment = environment.target.texture

      const root = new THREE.Group()
      scene.add(root)
      const pivot = new THREE.Group()
      root.add(pivot)

      const glyphGeometry = buildGlyphGeometry(runtime.quality)
      const material = lowQuality
        ? new THREE.MeshPhysicalMaterial({
            color: 0xffffff,
            metalness: 1,
            roughness: 0.16,
            clearcoat: 0.6,
            clearcoatRoughness: 0.08,
            envMapIntensity: 0,
          })
        : new THREE.MeshPhysicalMaterial({
            color: 0xffffff,
            metalness: 0,
            roughness: 0.03,
            transmission: 1,
            thickness: 0.34,
            ior: 1.45,
            dispersion: 0.6,
            attenuationColor: new THREE.Color('#ffffff'),
            attenuationDistance: 1.8,
            clearcoat: 1,
            clearcoatRoughness: 0.03,
            specularIntensity: 1,
            iridescence: 0.22,
            iridescenceIOR: 1.25,
            iridescenceThicknessRange: [120, 420],
            envMapIntensity: 0,
          })
      pivot.add(
        new THREE.Mesh(glyphGeometry.caps, material),
        new THREE.Mesh(glyphGeometry.walls, material),
      )

      const barUniforms = {
        uBg: { value: new THREE.Color() },
        uColorA: { value: new THREE.Color() },
        uColorB: { value: new THREE.Color() },
        uColorC: { value: new THREE.Color() },
        uTime: { value: 0 },
        uIntensity: { value: 0 },
        uLight: { value: 0 },
      }
      const bars = new THREE.Mesh(
        new THREE.PlaneGeometry(3.6, 2.8),
        new THREE.ShaderMaterial({
          uniforms: barUniforms,
          vertexShader: barsVertex,
          fragmentShader: barsFragment(lowQuality ? 7 : 11),
          toneMapped: false,
        }),
      )
      bars.position.z = -0.95
      root.add(bars)

      const glint = new THREE.PointLight(0xffffff, 0, 0, 2)
      scene.add(glint)
      const rim = new THREE.DirectionalLight(0xffffff, 0)
      rim.position.set(-3, 2, -4)
      scene.add(rim)
      const key = new THREE.DirectionalLight(0xffffff, 0)
      key.position.set(3, 4, 5)
      scene.add(key)

      const glintPlane = new THREE.Object3D()
      glintPlane.position.set(0, 0, 2.4)
      scene.add(glintPlane)
      const glintLocal = new THREE.Vector3()

      let rotationX = 0
      let rotationY = REST_ROTATION_Y
      let spinFrom = 0
      let spinTo = 0
      let spinStartedAt = -99
      let spin = 0

      const applyPalette = () => {
        const { palette } = runtime
        background.set(palette.background)
        barUniforms.uBg.value.set(palette.background)
        barUniforms.uColorA.value.set(palette.accent)
        barUniforms.uColorB.value.set(palette.violet)
        barUniforms.uColorC.value.set(palette.peach)
        barUniforms.uLight.value = palette.isDark ? 0 : 1
        rim.color.set(palette.accent)
        glint.color.set(palette.isDark ? '#ffe9f0' : '#ffffff')
        // Brand-tinted glass: thick parts (walls, bevels) pick up a pink body color.
        material.attenuationColor
          .set(palette.accent)
          .lerp(new THREE.Color('#ffffff'), palette.isDark ? 0.5 : 0.72)
        environment.dispose()
        environment = buildEnvironment(renderer, palette)
        scene.environment = environment.target.texture
      }
      applyPalette()

      const envStrength = () => (runtime.palette.isDark ? 1.6 : 1.15)

      return {
        update: (rt: SceneRuntime, dt: number) => {
          const time = rt.time
          const clock = rt.clock
          const presence = rt.pointer.presence
          const intro = rt.reducedMotion ? 1 : clamp01(time / INTRO_SECONDS)
          const introEase = easeOutExpo(intro)
          const lightsOn = rt.reducedMotion
            ? 1
            : easeOutCubic(clamp01((time - 0.25) / 1.9))
          const fade = 1 - clamp01(rt.scroll * 1.1)

          const bob = rt.reducedMotion ? 0 : Math.sin(clock * 0.9) * 0.012
          root.position.set(
            rt.anchor.x,
            rt.anchor.y +
              (bob - (1 - introEase) * 0.16 + rt.scroll * 0.12) *
                rt.anchor.scale,
            -rt.scroll * 1.6,
          )
          root.scale.setScalar(rt.anchor.scale * (0.88 + introEase * 0.12))

          const anchorNdcX = rt.anchor.x / (rt.viewWidth / 2)
          const anchorNdcY = rt.anchor.y / (rt.viewHeight / 2)
          const relX = THREE.MathUtils.clamp(
            rt.pointer.ndc.x - anchorNdcX,
            -1.2,
            1.2,
          )
          const relY = THREE.MathUtils.clamp(
            rt.pointer.ndc.y - anchorNdcY,
            -1.2,
            1.2,
          )

          if (spinStartedAt > -99) {
            const spinT = clamp01((time - spinStartedAt) / 1.7)
            spin = spinFrom + (spinTo - spinFrom) * easeInOutCubic(spinT)
          }

          const introTurn = (1 - introEase) * -2.3
          const idleY = rt.reducedMotion ? 0 : Math.sin(clock * 0.33) * 0.2
          const targetY =
            REST_ROTATION_Y + idleY + relX * 0.4 * presence + introTurn + spin
          const targetX =
            (rt.reducedMotion ? 0 : Math.sin(clock * 0.26) * 0.05) -
            relY * 0.2 * presence +
            rt.scroll * 0.5 +
            (1 - introEase) * 0.35
          const follow = intro < 1 ? 1 : damp(3.2, dt || 1)
          rotationY += (targetY - rotationY) * follow
          rotationX += (targetX - rotationX) * follow
          pivot.rotation.set(rotationX, rotationY, 0)

          const env = envStrength() * lightsOn * (0.35 + 0.65 * fade)
          material.envMapIntensity = env
          scene.environmentRotation.set(
            0,
            (1 - introEase) * 1.8 +
              (rt.reducedMotion ? 0.4 : Math.sin(clock * 0.11) * 0.6),
            0,
          )
          barUniforms.uTime.value = clock
          barUniforms.uIntensity.value =
            (rt.palette.isDark ? 1 : 0.7) *
            (rt.reducedMotion ? 1 : easeOutCubic(clamp01((time - 0.7) / 1.8))) *
            fade

          rim.intensity = 2.4 * lightsOn
          key.intensity = (rt.palette.isDark ? 1.1 : 1.6) * lightsOn
          if (rt.pointerOnPlane(glintPlane, glintLocal)) {
            glint.position.copy(glintLocal).add(glintPlane.position)
          }
          glint.intensity = 26 * presence * lightsOn
        },
        onPalette: applyPalette,
        onPulse: (rt) => {
          spinFrom = spin
          spinTo = Math.round(spin / (Math.PI * 2)) * Math.PI * 2 + Math.PI * 2
          spinStartedAt = rt.time
        },
        onReplay: () => {
          spin = 0
          spinFrom = 0
          spinTo = 0
          spinStartedAt = -99
          rotationY = REST_ROTATION_Y - 2.3
        },
        dispose: () => {
          glyphGeometry.caps.dispose()
          glyphGeometry.walls.dispose()
          material.dispose()
          bars.geometry.dispose()
          bars.material.dispose()
          environment.dispose()
          scene.environment = null
          scene.background = null
        },
      }
    },
    {
      maxPixelRatio: 1.75,
      renderer: { antialias: true, alpha: false, premultipliedAlpha: false },
      configureRenderer: (renderer) => {
        renderer.toneMapping = THREE.NeutralToneMapping
        renderer.toneMappingExposure = 1
        renderer.transmissionResolutionScale = 0.75
      },
    },
  )
