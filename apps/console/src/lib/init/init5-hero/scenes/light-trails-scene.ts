import * as THREE from 'three'
import {
  buildFiveField,
  chaikinClosed,
  extractIsoContours,
  getFiveContour,
  refineContour,
  resampleClosed,
  type Vec2,
} from '../five-field'
import {
  clamp01,
  createSceneController,
  damp,
  displayColor,
  easeInOutCubic,
  type SceneRuntime,
} from '../scene-runtime'
import type { Init5SceneFactory } from '../scene-types'

/**
 * Concept B - "Light-trail 5".
 * Init's line-art heritage, rebuilt in light: the outline is drawn on by a
 * pen of light, then comets race along the outline and a stack of inset /
 * outset contours. Lines bend around the cursor, flare on click, and sit on a
 * slow aurora.
 */

type Comet = {
  phase: number
  /** Arc per second; negative runs counter-clockwise. */
  speed: number
  /** Trail length in normalized arc. */
  trail: number
  intensity: number
}

type LineSpec = {
  level: number
  z: number
  tone: 'accent' | 'peach' | 'violet'
  base: number
  comets: Comet[]
  drawDelay: number
  drawDuration: number
  width: number
}

const LINE_SPECS: LineSpec[] = [
  {
    level: 0,
    z: 0,
    tone: 'accent',
    base: 0.2,
    width: 1,
    drawDelay: 0.15,
    drawDuration: 1.75,
    comets: [
      { phase: 0.0, speed: 0.15, trail: 0.24, intensity: 1 },
      { phase: 0.5, speed: 0.15, trail: 0.24, intensity: 1 },
      { phase: 0.3, speed: -0.1, trail: 0.14, intensity: 0.7 },
    ],
  },
  {
    level: 0,
    z: 0.055,
    tone: 'accent',
    base: 0.07,
    width: 0.8,
    drawDelay: 0.3,
    drawDuration: 1.8,
    comets: [{ phase: 0.2, speed: 0.19, trail: 0.16, intensity: 0.55 }],
  },
  {
    level: 0,
    z: -0.055,
    tone: 'violet',
    base: 0.06,
    width: 0.8,
    drawDelay: 0.35,
    drawDuration: 1.8,
    comets: [{ phase: 0.7, speed: -0.17, trail: 0.16, intensity: 0.5 }],
  },
  {
    level: -0.026,
    z: 0,
    tone: 'peach',
    base: 0.11,
    width: 0.85,
    drawDelay: 0.42,
    drawDuration: 1.6,
    comets: [
      { phase: 0.15, speed: -0.13, trail: 0.2, intensity: 0.85 },
      { phase: 0.65, speed: -0.13, trail: 0.2, intensity: 0.85 },
    ],
  },
  {
    level: -0.052,
    z: 0,
    tone: 'accent',
    base: 0.09,
    width: 0.8,
    drawDelay: 0.58,
    drawDuration: 1.55,
    comets: [
      { phase: 0.4, speed: 0.17, trail: 0.18, intensity: 0.75 },
      { phase: 0.9, speed: 0.17, trail: 0.18, intensity: 0.75 },
    ],
  },
  {
    level: -0.078,
    z: 0,
    tone: 'violet',
    base: 0.08,
    width: 0.75,
    drawDelay: 0.74,
    drawDuration: 1.5,
    comets: [{ phase: 0.05, speed: -0.2, trail: 0.22, intensity: 0.7 }],
  },
  {
    level: 0.034,
    z: 0,
    tone: 'violet',
    base: 0.045,
    width: 0.7,
    drawDelay: 0.9,
    drawDuration: 1.7,
    comets: [{ phase: 0.55, speed: 0.08, trail: 0.3, intensity: 0.4 }],
  },
  {
    level: 0.072,
    z: -0.02,
    tone: 'accent',
    base: 0.03,
    width: 0.6,
    drawDelay: 1.05,
    drawDuration: 1.8,
    comets: [{ phase: 0.85, speed: -0.06, trail: 0.34, intensity: 0.3 }],
  },
]

const MAX_COMETS = 3

const ribbonVertex = /* glsl */ `
uniform float uHalfWidth;
uniform vec3 uPointer;
uniform float uPointerStrength;
uniform vec3 uShockOrigin;
uniform float uShockAge;
uniform float uScroll;

attribute vec2 aNormal;
attribute float aSide;
attribute float aArc;

varying float vArc;
varying float vSide;
varying vec2 vLocal;
varying float vShock;

void main() {
  vec3 p = position;

  vec2 toPointer = p.xy - uPointer.xy;
  float d2 = dot(toPointer, toPointer);
  float field = uPointerStrength * exp(-d2 / 0.02);
  p.xy += normalize(toPointer + 1e-5) * field * 0.034;
  p.z += field * 0.08;

  vec2 toShock = p.xy - uShockOrigin.xy;
  float shockDist = length(toShock);
  float ringOffset = (shockDist - uShockAge * 1.15) / 0.07;
  float ring = exp(-ringOffset * ringOffset) * exp(-uShockAge * 1.6);
  p.xy += (toShock / max(shockDist, 1e-4)) * ring * 0.05;
  p.z += ring * 0.12;

  p.z -= uScroll * 0.6;
  p.xy += aNormal * aSide * uHalfWidth * (1.0 + field * 0.6 + ring * 0.8);

  vArc = aArc;
  vSide = aSide;
  vLocal = position.xy;
  vShock = ring;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
}
`

const ribbonFragment = /* glsl */ `
uniform float uClock;
uniform float uDraw;
uniform float uPen;
uniform float uBase;
uniform float uComet;
uniform float uFlash;
uniform float uGlow;
uniform float uSweep;
uniform vec4 uHeads[${MAX_COMETS}];
uniform vec3 uColor;
uniform vec3 uHot;
uniform vec3 uPointer;
uniform float uPointerStrength;

varying float vArc;
varying float vSide;
varying vec2 vLocal;
varying float vShock;

void main() {
  float s = vSide;
  float core = exp(-s * s * 60.0);
  float glow = exp(-s * s * 5.0);

  float trail = 0.0;
  float spark = 0.0;
  for (int i = 0; i < ${MAX_COMETS}; i++) {
    vec4 h = uHeads[i];
    if (h.w <= 0.0) continue;
    float head = fract(h.x + uClock * h.y);
    float behind = h.y >= 0.0 ? fract(head - vArc) : fract(vArc - head);
    trail += pow(max(0.0, 1.0 - behind / h.z), 2.6) * h.w;
    spark += exp(-(behind * behind) / 0.000045) * h.w;
  }

  float drawn = 1.0 - smoothstep(uDraw - 0.003, uDraw + 0.001, vArc);
  float penDist = (vArc - uDraw) / 0.01;
  float pen = exp(-penDist * penDist) * uPen;
  float penTail = drawn * exp(-max(0.0, uDraw - vArc) * 14.0) * uPen;

  float band = (vLocal.x * 0.55 + vLocal.y * 0.85 - uSweep) / 0.1;
  float sweep = exp(-band * band);

  float pd = length(vLocal - uPointer.xy);
  float near = uPointerStrength * exp(-pd * pd / 0.018);

  float lum = uBase * drawn * (1.0 + sweep * 2.5 + uFlash * 3.0)
    + (trail + spark * 1.6) * uComet
    + pen * 2.2 + penTail * 0.9
    + vShock * 1.6;
  lum *= 1.0 + near * 1.8;

  float heat = clamp(core * (trail * 0.55 + spark + pen + penTail * 0.4 + sweep * 0.5 + vShock), 0.0, 1.0);
  vec3 color = mix(uColor, uHot, heat);
  float a = clamp((core * 1.1 + glow * 0.36) * lum * uGlow, 0.0, 1.0);
  gl_FragColor = vec4(color * a, a);
}
`

const fillVertex = /* glsl */ `
varying vec2 vLocal;
void main() {
  vLocal = position.xy;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
`

const fillFragment = /* glsl */ `
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform float uOpacity;
uniform float uSweep;
varying vec2 vLocal;
void main() {
  float g = clamp(vLocal.y + 0.5, 0.0, 1.0);
  vec3 color = mix(uColorB, uColorA, smoothstep(0.0, 0.9, g + vLocal.x * 0.3));
  float band = (vLocal.x * 0.55 + vLocal.y * 0.85 - uSweep) / 0.16;
  float sweep = exp(-band * band);
  float a = uOpacity * (0.55 + 0.45 * g) * (1.0 + sweep * 1.6);
  gl_FragColor = vec4(color * a, a);
}
`

const auroraVertex = /* glsl */ `
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
`

const auroraFragment = (octaves: number) => /* glsl */ `
uniform float uTime;
uniform float uIntensity;
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform vec3 uColorC;
uniform vec3 uPointer;
uniform float uPointerStrength;
varying vec2 vUv;

float hash(vec2 p) {
  return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float noise(vec2 p) {
  vec2 i = floor(p);
  vec2 f = fract(p);
  vec2 u = f * f * (3.0 - 2.0 * f);
  return mix(
    mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
    mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x),
    u.y
  );
}

float fbm(vec2 p) {
  float v = 0.0;
  float a = 0.5;
  for (int i = 0; i < ${octaves}; i++) {
    v += a * noise(p);
    p = p * 2.03 + vec2(1.7, 9.2);
    a *= 0.5;
  }
  return v;
}

void main() {
  vec2 p = (vUv - 0.5) * vec2(3.2, 2.6);
  float t = uTime * 0.05;
  vec2 q = vec2(fbm(p * 1.05 + vec2(t, -t)), fbm(p * 1.05 + vec2(-t * 0.7, t * 1.3) + 5.2));
  float n = fbm(p * 1.5 + q * 1.9 + vec2(t * 0.5, 0.0));
  vec2 m = p * vec2(0.85, 0.95);
  float mask = exp(-dot(m, m) * 1.9);
  vec3 color = mix(uColorB, uColorA, smoothstep(0.3, 0.72, n));
  color = mix(color, uColorC, smoothstep(0.55, 0.9, q.x) * 0.55);
  float a = smoothstep(0.28, 0.9, n) * mask * uIntensity;
  float pd = length(p - uPointer.xy * 1.0);
  a *= 1.0 + uPointerStrength * exp(-pd * pd * 2.5) * 0.9;
  gl_FragColor = vec4(color * a, a);
}
`

function buildRibbon(points: Vec2[], z: number) {
  const count = points.length
  const vertexCount = (count + 1) * 2
  const position = new Float32Array(vertexCount * 3)
  const normal = new Float32Array(vertexCount * 2)
  const side = new Float32Array(vertexCount)
  const arc = new Float32Array(vertexCount)
  const index: number[] = []

  for (let i = 0; i <= count; i++) {
    const point = points[i % count]
    const prev = points[(i - 1 + count) % count]
    const next = points[(i + 1) % count]
    let ax = point.x - prev.x
    let ay = point.y - prev.y
    let bx = next.x - point.x
    let by = next.y - point.y
    const la = Math.hypot(ax, ay) || 1
    const lb = Math.hypot(bx, by) || 1
    ax /= la
    ay /= la
    bx /= lb
    by /= lb
    let tx = ax + bx
    let ty = ay + by
    const lt = Math.hypot(tx, ty) || 1
    tx /= lt
    ty /= lt
    const nx = ty
    const ny = -tx
    // Miter: stretch the averaged normal so the ribbon keeps its width at corners.
    const cosine = Math.abs(nx * ay - ny * ax)
    const scale = Math.min(2.2, 1 / Math.max(0.45, cosine))
    for (let s = 0; s < 2; s++) {
      const v = i * 2 + s
      position[v * 3] = point.x
      position[v * 3 + 1] = point.y
      position[v * 3 + 2] = z
      normal[v * 2] = nx * scale
      normal[v * 2 + 1] = ny * scale
      side[v] = s === 0 ? -1 : 1
      arc[v] = i / count
    }
    if (i < count) {
      // Counter-clockwise from the camera (outward normal is to the right of travel).
      const a = i * 2
      index.push(a, a + 1, a + 2, a + 1, a + 3, a + 2)
    }
  }

  const geometry = new THREE.BufferGeometry()
  geometry.setAttribute('position', new THREE.BufferAttribute(position, 3))
  geometry.setAttribute('aNormal', new THREE.BufferAttribute(normal, 2))
  geometry.setAttribute('aSide', new THREE.BufferAttribute(side, 1))
  geometry.setAttribute('aArc', new THREE.BufferAttribute(arc, 1))
  geometry.setIndex(index)
  geometry.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 4)
  return geometry
}

function loopForLevel(level: number, spacing: number): Vec2[] {
  if (level === 0) {
    return resampleClosed(refineContour(getFiveContour(), 0.004), spacing)
      .points
  }
  const field = buildFiveField(640)
  const loops = extractIsoContours(field, level)
  const loop = loops[0]
  if (!loop) return []
  const smooth = chaikinClosed(loop, 2)
  // Align the seam near the top-left corner so every line draws from the same place.
  let startIndex = 0
  let best = Infinity
  for (let i = 0; i < smooth.length; i++) {
    const score = -smooth[i].y * 2 + smooth[i].x
    if (score < best) {
      best = score
      startIndex = i
    }
  }
  const rotated = smooth.slice(startIndex).concat(smooth.slice(0, startIndex))
  // Match the outline winding (counter-clockwise).
  let area = 0
  for (let i = 0; i < rotated.length; i++) {
    const a = rotated[i]
    const b = rotated[(i + 1) % rotated.length]
    area += a.x * b.y - b.x * a.y
  }
  if (area < 0) {
    const [first, ...rest] = rotated
    rotated.splice(0, rotated.length, first, ...rest.reverse())
  }
  return resampleClosed(rotated, spacing).points
}

type LineRuntime = {
  spec: LineSpec
  mesh: THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial>
  uniforms: {
    uHalfWidth: { value: number }
    uPointer: { value: THREE.Vector3 }
    uPointerStrength: { value: number }
    uShockOrigin: { value: THREE.Vector3 }
    uShockAge: { value: number }
    uScroll: { value: number }
    uClock: { value: number }
    uDraw: { value: number }
    uPen: { value: number }
    uBase: { value: number }
    uComet: { value: number }
    uFlash: { value: number }
    uGlow: { value: number }
    uSweep: { value: number }
    uHeads: { value: THREE.Vector4[] }
    uColor: { value: THREE.Color }
    uHot: { value: THREE.Color }
  }
}

export const createLightTrailsScene: Init5SceneFactory = (options) =>
  createSceneController(options, (runtime) => {
    const { scene } = runtime
    const root = new THREE.Group()
    scene.add(root)

    const tones = {
      accent: new THREE.Color(),
      peach: new THREE.Color(),
      violet: new THREE.Color(),
      hot: new THREE.Color(),
    }

    const pointerLocal = new THREE.Vector3(9, 9, 0)
    const shockOrigin = new THREE.Vector3(9, 9, 0)
    let shockAge = 99
    let flash = 0
    let flashed = false
    let rotationX = 0
    let rotationY = 0

    const spacing = runtime.quality === 'low' ? 0.008 : 0.0045
    const specs =
      runtime.quality === 'low'
        ? LINE_SPECS.filter((spec) => spec.z === 0 && spec.level > -0.07)
        : LINE_SPECS

    const lines: LineRuntime[] = []
    for (const spec of specs) {
      const loop = loopForLevel(spec.level, spacing)
      if (loop.length < 16) continue
      const geometry = buildRibbon(loop, spec.z)
      const heads = Array.from({ length: MAX_COMETS }, (_, i) => {
        const comet = spec.comets[i]
        return comet
          ? new THREE.Vector4(
              comet.phase,
              comet.speed,
              comet.trail,
              comet.intensity,
            )
          : new THREE.Vector4(0, 0, 0.1, 0)
      })
      const uniforms: LineRuntime['uniforms'] = {
        uHalfWidth: { value: 0.01 },
        uPointer: { value: pointerLocal },
        uPointerStrength: { value: 0 },
        uShockOrigin: { value: shockOrigin },
        uShockAge: { value: 99 },
        uScroll: { value: 0 },
        uClock: { value: 0 },
        uDraw: { value: runtime.reducedMotion ? 1.01 : 0 },
        uPen: { value: 0 },
        uBase: { value: spec.base },
        uComet: { value: runtime.reducedMotion ? 1 : 0 },
        uFlash: { value: 0 },
        uGlow: { value: 1 },
        uSweep: { value: -9 },
        uHeads: { value: heads },
        uColor: { value: tones[spec.tone] },
        uHot: { value: tones.hot },
      }
      const material = new THREE.ShaderMaterial({
        uniforms,
        vertexShader: ribbonVertex,
        fragmentShader: ribbonFragment,
        transparent: true,
        depthWrite: false,
        depthTest: false,
        premultipliedAlpha: true,
        // Tilted past edge-on during the intro turn, ribbons must stay visible from behind.
        side: THREE.DoubleSide,
      })
      const mesh = new THREE.Mesh(geometry, material)
      mesh.frustumCulled = false
      root.add(mesh)
      lines.push({ spec, mesh, uniforms })
    }

    // Soft body inside the glyph so the 5 reads as a shape between the lines.
    const shape = new THREE.Shape(
      refineContour(getFiveContour(), 0.01).map(
        (p) => new THREE.Vector2(p.x, p.y),
      ),
    )
    const fillUniforms = {
      uColorA: { value: tones.accent },
      uColorB: { value: tones.violet },
      uOpacity: { value: 0 },
      uSweep: { value: -9 },
    }
    const fillMaterial = new THREE.ShaderMaterial({
      uniforms: fillUniforms,
      vertexShader: fillVertex,
      fragmentShader: fillFragment,
      transparent: true,
      depthWrite: false,
      depthTest: false,
      premultipliedAlpha: true,
    })
    const fill = new THREE.Mesh(new THREE.ShapeGeometry(shape, 1), fillMaterial)
    fill.position.z = -0.01
    fill.renderOrder = -1
    root.add(fill)

    const auroraUniforms = {
      uTime: { value: 0 },
      uIntensity: { value: 0 },
      uColorA: { value: tones.accent },
      uColorB: { value: tones.violet },
      uColorC: { value: tones.peach },
      uPointer: { value: pointerLocal },
      uPointerStrength: { value: 0 },
    }
    const auroraMaterial = new THREE.ShaderMaterial({
      uniforms: auroraUniforms,
      vertexShader: auroraVertex,
      fragmentShader: auroraFragment(runtime.quality === 'low' ? 2 : 4),
      transparent: true,
      depthWrite: false,
      depthTest: false,
      premultipliedAlpha: true,
    })
    const aurora = new THREE.Mesh(
      new THREE.PlaneGeometry(3.2, 2.6),
      auroraMaterial,
    )
    aurora.position.z = -0.5
    aurora.renderOrder = -2
    root.add(aurora)

    const materials = [
      ...lines.map((line) => line.mesh.material),
      fillMaterial,
      auroraMaterial,
    ]

    const applyPalette = () => {
      const { palette } = runtime
      displayColor(palette.accent, tones.accent)
      displayColor(palette.peach, tones.peach)
      displayColor(palette.violet, tones.violet)
      if (palette.isDark) {
        displayColor('#fff4f7', tones.hot)
      } else {
        displayColor(palette.foreground, tones.hot)
        tones.peach.lerp(tones.accent, 0.35)
      }
      const blending = palette.isDark
        ? THREE.AdditiveBlending
        : THREE.NormalBlending
      for (const material of materials) {
        if (material.blending !== blending) {
          material.blending = blending
          material.needsUpdate = true
        }
      }
    }

    const applySize = () => {
      const widthPx = THREE.MathUtils.clamp(runtime.pixelsPerUnit / 50, 6, 13)
      for (const line of lines) {
        line.uniforms.uHalfWidth.value =
          (widthPx * line.spec.width) / runtime.pixelsPerUnit
      }
    }

    applyPalette()
    applySize()

    const SWEEP_EVERY = 7
    let nextSweepAt = 2.6
    let sweepStartedAt = -1

    return {
      update: (rt: SceneRuntime, dt: number) => {
        const time = rt.time
        const clock = rt.clock
        const presence = rt.pointer.presence

        root.position.set(
          rt.anchor.x,
          rt.anchor.y + rt.scroll * rt.anchor.scale * 0.08,
          0,
        )
        root.scale.setScalar(rt.anchor.scale)

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
        const targetY = relX * 0.4 * presence + Math.sin(clock * 0.27) * 0.16
        const targetX =
          -relY * 0.26 * presence +
          Math.sin(clock * 0.21) * 0.06 +
          rt.scroll * 0.55
        rotationY += (targetY - rotationY) * damp(2.6, dt || 1)
        rotationX += (targetX - rotationX) * damp(2.6, dt || 1)
        root.rotation.set(rotationX, rotationY, 0)

        rt.pointerOnPlane(root, pointerLocal)
        shockAge += dt

        if (!rt.reducedMotion && !flashed && time > 1.95) {
          flashed = true
          flash = 1
        }
        flash *= Math.exp(-dt * 3.2)

        if (!rt.reducedMotion && time >= nextSweepAt) {
          sweepStartedAt = time
          nextSweepAt = time + SWEEP_EVERY
        }
        const sweepT = sweepStartedAt < 0 ? -1 : (time - sweepStartedAt) / 1.6
        const sweep = sweepT >= 0 && sweepT <= 1 ? -1.1 + sweepT * 2.2 : -9

        const cometFade = rt.reducedMotion ? 1 : clamp01((time - 1.5) / 1.1)
        const fade = 1 - clamp01(rt.scroll * 1.15)

        for (const line of lines) {
          const { spec, uniforms } = line
          const drawT = rt.reducedMotion
            ? 1
            : clamp01((time - spec.drawDelay) / spec.drawDuration)
          uniforms.uDraw.value = drawT >= 1 ? 1.01 : easeInOutCubic(drawT)
          uniforms.uPen.value =
            drawT > 0 && drawT < 1 ? Math.sin(drawT * Math.PI) ** 0.5 : 0
          uniforms.uClock.value = clock
          uniforms.uComet.value = cometFade
          uniforms.uFlash.value = flash
          uniforms.uSweep.value = sweep
          uniforms.uPointerStrength.value = presence
          uniforms.uShockAge.value = shockAge
          uniforms.uScroll.value = rt.scroll
          uniforms.uGlow.value = (rt.palette.isDark ? 1 : 0.85) * fade
        }

        fillUniforms.uOpacity.value =
          (rt.palette.isDark ? 0.07 : 0.06) * clamp01((time - 1.3) / 1.4) * fade
        fillUniforms.uSweep.value = sweep

        auroraUniforms.uTime.value = clock
        auroraUniforms.uPointerStrength.value = presence
        auroraUniforms.uIntensity.value =
          (rt.palette.isDark ? 0.34 : 0.16) * clamp01((time - 0.4) / 2.6) * fade
      },
      onPalette: applyPalette,
      onResize: applySize,
      onPulse: (rt, ndc) => {
        if (rt.pointerOnPlane(root, shockOrigin, ndc)) {
          shockAge = 0
          flash = Math.max(flash, 0.55)
        }
      },
      onReplay: () => {
        flashed = false
        flash = 0
        shockAge = 99
        nextSweepAt = 2.6
        sweepStartedAt = -1
      },
      dispose: () => {
        for (const line of lines) {
          line.mesh.geometry.dispose()
          line.mesh.material.dispose()
        }
        fill.geometry.dispose()
        fillMaterial.dispose()
        aurora.geometry.dispose()
        auroraMaterial.dispose()
      },
    }
  })
