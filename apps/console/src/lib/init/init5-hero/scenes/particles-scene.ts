import * as THREE from 'three'
import {
  buildFiveField,
  createRandom,
  getFiveContour,
  measureLoop,
  pointOnLoop,
  refineContour,
  sampleFiveInterior,
} from '../five-field'
import {
  CAMERA_DISTANCE,
  clamp01,
  createSceneController,
  damp,
  displayColor,
  type SceneRuntime,
} from '../scene-runtime'
import type { Init5SceneFactory } from '../scene-types'

/**
 * Concept A - "Luminous particle 5".
 * Tens of thousands of GPU particles spiral in from a deep vortex and settle
 * into a volumetric 5. Once assembled it breathes, catches a light sweep,
 * bends around the cursor, ripples on click and dissolves into embers on scroll.
 */

const DEPTH = 0.14
const INTRO_SECONDS = 3.1
const SWEEP_FIRST_AT = 3.0
const SWEEP_EVERY = 6.5
const SWEEP_SECONDS = 1.5

const particleVertex = /* glsl */ `
uniform float uTime;
uniform float uIntro;
uniform float uSize;
uniform float uDepthRef;
uniform vec3 uPointer;
uniform float uPointerStrength;
uniform vec3 uShockOrigin;
uniform float uShockAge;
uniform float uScroll;
uniform float uSweep;
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform vec3 uColorC;
uniform vec3 uHot;
uniform float uLight;

attribute vec3 aStart;
attribute vec4 aSeed;
attribute float aDelay;
attribute float aSwirl;
attribute float aEdge;

varying vec3 vColor;
varying float vAlpha;

void main() {
  float t = clamp((uIntro - aDelay) / 0.5, 0.0, 1.0);
  float e = 1.0 - pow(1.0 - t, 4.0);
  vec3 p = mix(aStart, position, e);
  // The galaxy keeps turning while particles wait for their cue.
  float ang = (1.0 - e) * (aSwirl + uIntro * 1.6);
  float c = cos(ang);
  float s = sin(ang);
  p.xy = mat2(c, s, -s, c) * p.xy;

  float settled = smoothstep(0.72, 1.0, t);
  float landed = uIntro - aDelay - 0.5;
  float flash = landed > 0.0 ? exp(-landed * 16.0) : 0.0;

  float ph = aSeed.y * 6.2831853;
  p += vec3(
    sin(uTime * 0.9 + position.y * 11.0 + ph),
    cos(uTime * 0.75 + position.x * 9.0 + ph),
    sin(uTime * 0.6 + (position.x + position.y) * 7.0 + ph)
  ) * 0.0032 * settled;

  vec2 toPointer = p.xy - uPointer.xy;
  float pointerDist = length(toPointer);
  float field = uPointerStrength * settled * exp(-pointerDist * pointerDist / 0.016);
  p.xy += (toPointer / max(pointerDist, 1e-4)) * field * 0.085;
  p.z += field * 0.26;

  vec2 toShock = p.xy - uShockOrigin.xy;
  float shockDist = length(toShock);
  float ringRadius = uShockAge * 1.2;
  float ringOffset = (shockDist - ringRadius) / 0.075;
  float ring = exp(-ringOffset * ringOffset) * exp(-uShockAge * 1.7) * settled;
  p.xy += (toShock / max(shockDist, 1e-4)) * ring * 0.085;
  p.z += ring * 0.28;

  float sc = uScroll * uScroll;
  p += ((aSeed.xyz - 0.5) * vec3(1.5, 1.1, 2.4) + vec3(0.0, 0.85, 0.9)) * sc * 1.35;

  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;

  float depth = uDepthRef / max(0.6, -mv.z);
  float twinkle = 0.7 + 0.3 * sin(uTime * (1.3 + aSeed.x * 2.6) + ph);
  float travel = 1.0 - settled;
  gl_PointSize = uSize * (0.55 + aSeed.x * 0.8) * depth
    * (1.0 + travel * 0.8 + field * 0.9 + ring * 1.3 + flash * 1.1);

  float g = clamp(position.y + 0.5, 0.0, 1.0);
  vec3 base = mix(uColorB, uColorA, smoothstep(0.02, 0.62, g + position.x * 0.3));
  base = mix(base, uColorC, smoothstep(0.6, 1.1, g * 0.65 + position.x * 0.95 + 0.25) * 0.65);

  float band = (position.x * 0.55 + position.y * 0.85 - uSweep) / 0.13;
  float sweep = exp(-band * band) * settled;
  float heat = clamp(aEdge * 0.32 + sweep * 1.1 + field * 0.75 + ring + flash * 0.8, 0.0, 1.0);
  vColor = mix(base, uHot, heat);
  gl_PointSize *= 1.0 + sweep * 0.45;

  float alpha = mix(0.26, 0.92, pow(aEdge, 1.5)) * twinkle;
  alpha *= mix(0.62, 1.0, settled);
  alpha *= 1.0 + sweep * 1.8 + flash * 0.8;
  alpha *= 1.0 - smoothstep(0.25, 0.95, uScroll);
  alpha *= smoothstep(0.0, 0.06, uIntro - aSeed.z * 0.03);
  vAlpha = alpha * mix(1.0, 0.9, uLight);
}
`

const particleFragment = /* glsl */ `
varying vec3 vColor;
varying float vAlpha;

void main() {
  vec2 uv = gl_PointCoord - 0.5;
  float d2 = dot(uv, uv);
  if (d2 > 0.25) discard;
  float core = exp(-d2 * 42.0);
  float halo = (1.0 - d2 * 4.0) * 0.16;
  float a = clamp((core + halo) * vAlpha, 0.0, 1.0);
  gl_FragColor = vec4(vColor * a, a);
}
`

const dustVertex = /* glsl */ `
uniform float uTime;
uniform float uSize;
uniform float uFade;
uniform float uRangeY;
uniform float uDepthRef;
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform vec3 uHot;

attribute vec4 aSeed;

varying vec3 vColor;
varying float vAlpha;

void main() {
  vec3 p = position;
  p.y = mod(p.y + uTime * (0.012 + aSeed.x * 0.02) + uRangeY * 0.5, uRangeY) - uRangeY * 0.5;
  p.x += sin(uTime * 0.11 + aSeed.y * 6.2831853) * 0.015;
  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;
  float depth = uDepthRef / max(0.6, -mv.z);
  gl_PointSize = uSize * (0.5 + aSeed.z * 1.6) * depth;
  vColor = mix(mix(uColorB, uColorA, aSeed.w), uHot, step(0.8, aSeed.x) * 0.55);
  vAlpha = (0.035 + aSeed.z * 0.11) * uFade
    * (0.55 + 0.45 * sin(uTime * (0.5 + aSeed.w * 0.8) + aSeed.y * 6.2831853));
}
`

const dustFragment = /* glsl */ `
varying vec3 vColor;
varying float vAlpha;

void main() {
  float d = length(gl_PointCoord - 0.5);
  if (d > 0.5) discard;
  float a = smoothstep(0.5, 0.12, d) * vAlpha;
  gl_FragColor = vec4(vColor * a, a);
}
`

const auraVertex = /* glsl */ `
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
`

const auraFragment = /* glsl */ `
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform float uIntensity;
varying vec2 vUv;

void main() {
  vec2 p = vUv - 0.5;
  float d = length(p * vec2(1.05, 0.85));
  float a = exp(-d * d * 10.0) * uIntensity;
  vec3 color = mix(uColorB, uColorA, smoothstep(-0.25, 0.3, p.y + p.x * 0.4));
  gl_FragColor = vec4(color * a, a);
}
`

type ParticleBuffers = {
  count: number
  position: Float32Array
  start: Float32Array
  seed: Float32Array
  delay: Float32Array
  swirl: Float32Array
  edge: Float32Array
}

function gaussian(random: () => number) {
  const u = Math.max(1e-6, random())
  const v = random()
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v)
}

function buildParticles(
  fill: number,
  edge: number,
  rim: number,
): ParticleBuffers {
  const random = createRandom(0x1a17)
  const field = buildFiveField(512)
  const contour = refineContour(getFiveContour(), 0.006)
  const cumulative = measureLoop(contour)
  const interior = sampleFiveInterior(field, fill, random)

  const count = fill + edge + rim * 2
  const position = new Float32Array(count * 3)
  const start = new Float32Array(count * 3)
  const seed = new Float32Array(count * 4)
  const delay = new Float32Array(count)
  const swirl = new Float32Array(count)
  const edgeFactor = new Float32Array(count)

  let index = 0
  const push = (x: number, y: number, z: number, edgeness: number) => {
    const i3 = index * 3
    position[i3] = x
    position[i3 + 1] = y
    position[i3 + 2] = z

    // Start on a compact, tilted three-arm galaxy behind the glyph; the swirl unwinds it into the 5.
    const arm = Math.floor(random() * 3)
    const radius = 0.08 + random() ** 1.45 * 0.92
    const angle =
      arm * ((Math.PI * 2) / 3) +
      radius * 4.2 +
      gaussian(random) * (0.18 + radius * 0.2)
    const spread = gaussian(random) * 0.03
    start[i3] = Math.cos(angle) * radius + spread
    start[i3 + 1] = Math.sin(angle) * radius * 0.46 + spread
    start[i3 + 2] =
      -0.7 - Math.sin(angle) * radius * 0.5 + gaussian(random) * 0.06

    const i4 = index * 4
    seed[i4] = random()
    seed[i4 + 1] = random()
    seed[i4 + 2] = random()
    seed[i4 + 3] = random()

    const fromCenter = Math.min(1, Math.hypot(x, y) / 0.62)
    delay[index] = 0.04 + fromCenter * 0.22 + random() * 0.2 + edgeness * 0.04
    // Differential rotation (inner arms turn faster) keeps the spiral coherent while it unwinds.
    swirl[index] = 2.3 + (1 - Math.min(1, radius)) * 1.1
    edgeFactor[index] = edgeness
    index++
  }

  for (let i = 0; i < fill; i++) {
    const x = interior[i * 3]
    const y = interior[i * 3 + 1]
    const inset = -interior[i * 3 + 2]
    const edgeness = 1 - Math.min(1, inset / 0.05)
    push(x, y, (random() - 0.5) * DEPTH, edgeness * 0.7)
  }

  for (let i = 0; i < edge; i++) {
    const point = pointOnLoop(contour, cumulative, random())
    const offset = -Math.abs(gaussian(random)) * 0.0045
    push(
      point.x + point.nx * offset,
      point.y + point.ny * offset,
      (random() - 0.5) * DEPTH,
      1,
    )
  }

  for (let side = 0; side < 2; side++) {
    const z = side === 0 ? DEPTH / 2 : -DEPTH / 2
    for (let i = 0; i < rim; i++) {
      const point = pointOnLoop(contour, cumulative, (i + random() * 0.6) / rim)
      const offset = gaussian(random) * 0.0018
      push(point.x + point.nx * offset, point.y + point.ny * offset, z, 1)
    }
  }

  return { count: index, position, start, seed, delay, swirl, edge: edgeFactor }
}

function buildDust(count: number) {
  const random = createRandom(0xd057)
  const position = new Float32Array(count * 3)
  const seed = new Float32Array(count * 4)
  for (let i = 0; i < count; i++) {
    position[i * 3] = (random() - 0.5) * 1.9
    position[i * 3 + 1] = (random() - 0.5) * 1.5
    position[i * 3 + 2] = -5.5 + random() * 7.5
    seed[i * 4] = random()
    seed[i * 4 + 1] = random()
    seed[i * 4 + 2] = random()
    seed[i * 4 + 3] = random()
  }
  return { position, seed }
}

function particleBudget(runtime: SceneRuntime) {
  const small = runtime.width < 640
  if (runtime.quality === 'low') {
    return small
      ? { fill: 3600, edge: 2400, rim: 600, dust: 180 }
      : { fill: 6000, edge: 3800, rim: 900, dust: 320 }
  }
  return small
    ? { fill: 11000, edge: 5200, rim: 1300, dust: 420 }
    : { fill: 24000, edge: 9500, rim: 2400, dust: 900 }
}

export const createParticlesScene: Init5SceneFactory = (options) =>
  createSceneController(options, (runtime) => {
    const { scene } = runtime
    const budget = particleBudget(runtime)

    const root = new THREE.Group()
    scene.add(root)

    const colors = {
      a: new THREE.Color(),
      b: new THREE.Color(),
      c: new THREE.Color(),
      hot: new THREE.Color(),
    }

    const buffers = buildParticles(budget.fill, budget.edge, budget.rim)
    const geometry = new THREE.BufferGeometry()
    geometry.setAttribute(
      'position',
      new THREE.BufferAttribute(buffers.position, 3),
    )
    geometry.setAttribute('aStart', new THREE.BufferAttribute(buffers.start, 3))
    geometry.setAttribute('aSeed', new THREE.BufferAttribute(buffers.seed, 4))
    geometry.setAttribute('aDelay', new THREE.BufferAttribute(buffers.delay, 1))
    geometry.setAttribute('aSwirl', new THREE.BufferAttribute(buffers.swirl, 1))
    geometry.setAttribute('aEdge', new THREE.BufferAttribute(buffers.edge, 1))
    geometry.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 12)

    const uniforms = {
      uTime: { value: 0 },
      uIntro: { value: runtime.reducedMotion ? 1 : 0 },
      uSize: { value: 6 },
      uDepthRef: { value: CAMERA_DISTANCE },
      uPointer: { value: new THREE.Vector3(9, 9, 0) },
      uPointerStrength: { value: 0 },
      uShockOrigin: { value: new THREE.Vector3(9, 9, 0) },
      uShockAge: { value: 99 },
      uScroll: { value: 0 },
      uSweep: { value: -9 },
      uColorA: { value: colors.a },
      uColorB: { value: colors.b },
      uColorC: { value: colors.c },
      uHot: { value: colors.hot },
      uLight: { value: 0 },
    }

    const material = new THREE.ShaderMaterial({
      uniforms,
      vertexShader: particleVertex,
      fragmentShader: particleFragment,
      transparent: true,
      depthWrite: false,
      depthTest: false,
      premultipliedAlpha: true,
    })
    const points = new THREE.Points(geometry, material)
    points.frustumCulled = false
    root.add(points)

    const auraUniforms = {
      uColorA: { value: colors.a },
      uColorB: { value: colors.b },
      uIntensity: { value: 0 },
    }
    const aura = new THREE.Mesh(
      new THREE.PlaneGeometry(2.2, 2.2),
      new THREE.ShaderMaterial({
        uniforms: auraUniforms,
        vertexShader: auraVertex,
        fragmentShader: auraFragment,
        transparent: true,
        depthWrite: false,
        depthTest: false,
        premultipliedAlpha: true,
      }),
    )
    aura.position.z = -0.6
    aura.renderOrder = -2
    root.add(aura)

    const dustData = buildDust(budget.dust)
    const dustGeometry = new THREE.BufferGeometry()
    dustGeometry.setAttribute(
      'position',
      new THREE.BufferAttribute(dustData.position, 3),
    )
    dustGeometry.setAttribute(
      'aSeed',
      new THREE.BufferAttribute(dustData.seed, 4),
    )
    const dustUniforms = {
      uTime: { value: 0 },
      uSize: { value: 8 },
      uFade: { value: 0 },
      uRangeY: { value: 1.5 },
      uDepthRef: { value: CAMERA_DISTANCE },
      uColorA: { value: colors.a },
      uColorB: { value: colors.b },
      uHot: { value: colors.hot },
    }
    const dustMaterial = new THREE.ShaderMaterial({
      uniforms: dustUniforms,
      vertexShader: dustVertex,
      fragmentShader: dustFragment,
      transparent: true,
      depthWrite: false,
      depthTest: false,
      premultipliedAlpha: true,
    })
    const dust = new THREE.Points(dustGeometry, dustMaterial)
    dust.frustumCulled = false
    dust.renderOrder = -1
    const dustRig = new THREE.Group()
    dustRig.add(dust)
    scene.add(dustRig)

    const localPointer = new THREE.Vector3()
    const localShock = new THREE.Vector3()
    let shockAge = 99
    let sweepStartedAt = -1
    let nextSweepAt = SWEEP_FIRST_AT
    let rotationX = 0
    let rotationY = 0

    const applyPalette = () => {
      const { palette } = runtime
      displayColor(palette.accent, colors.a)
      displayColor(palette.violet, colors.b)
      displayColor(palette.peach, colors.c)
      if (palette.isDark) {
        displayColor('#fff1f5', colors.hot)
      } else {
        displayColor(palette.foreground, colors.hot)
        colors.a.multiplyScalar(0.92)
      }
      const blending = palette.isDark
        ? THREE.AdditiveBlending
        : THREE.NormalBlending
      for (const mat of [material, dustMaterial, aura.material]) {
        if (mat.blending !== blending) {
          mat.blending = blending
          mat.needsUpdate = true
        }
      }
      uniforms.uLight.value = palette.isDark ? 0 : 1
    }

    const applySize = () => {
      const px = THREE.MathUtils.clamp(runtime.pixelsPerUnit / 250, 1.25, 3.2)
      uniforms.uSize.value = px * runtime.pixelRatio * 3.1
      dustUniforms.uSize.value =
        THREE.MathUtils.clamp(runtime.height / 110, 4, 9) * runtime.pixelRatio
      dust.scale.set(runtime.viewWidth, runtime.viewHeight, 1)
    }

    applyPalette()
    applySize()

    return {
      update: (rt, dt) => {
        const time = rt.time
        const clock = rt.clock
        uniforms.uTime.value = clock
        dustUniforms.uTime.value = clock
        uniforms.uIntro.value = clamp01(time / INTRO_SECONDS)
        uniforms.uScroll.value = rt.scroll
        dustUniforms.uFade.value = clamp01(time / 1.6) * (1 - rt.scroll * 0.8)

        root.position.set(
          rt.anchor.x,
          rt.anchor.y + rt.scroll * rt.anchor.scale * 0.1,
          0,
        )
        const breathe = 1 + Math.sin(clock * 0.8) * 0.006
        root.scale.setScalar(rt.anchor.scale * breathe)

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
        const presence = rt.pointer.presence
        const targetY = relX * 0.34 * presence + Math.sin(clock * 0.31) * 0.12
        const targetX =
          -relY * 0.22 * presence +
          Math.sin(clock * 0.23) * 0.05 +
          rt.scroll * 0.45
        rotationY += (targetY - rotationY) * damp(3, dt || 1)
        rotationX += (targetX - rotationX) * damp(3, dt || 1)
        root.rotation.set(rotationX, rotationY, 0)

        if (rt.pointerOnPlane(root, localPointer)) {
          uniforms.uPointer.value.copy(localPointer)
        }
        uniforms.uPointerStrength.value = presence

        shockAge += dt
        uniforms.uShockAge.value = shockAge
        uniforms.uShockOrigin.value.copy(localShock)

        if (!rt.reducedMotion && time >= nextSweepAt) {
          sweepStartedAt = time
          nextSweepAt = time + SWEEP_EVERY
        }
        const sweepT =
          sweepStartedAt < 0 ? -1 : (time - sweepStartedAt) / SWEEP_SECONDS
        uniforms.uSweep.value =
          sweepT >= 0 && sweepT <= 1 ? -1.1 + sweepT * 2.2 : -9

        const introGlow = clamp01((time - 1.2) / 1.6)
        const sweepGlow =
          sweepT >= 0 && sweepT <= 1 ? Math.sin(sweepT * Math.PI) * 0.35 : 0
        auraUniforms.uIntensity.value =
          (rt.palette.isDark ? 0.2 : 0.07) *
          introGlow *
          (1 + sweepGlow + presence * 0.25) *
          (1 - rt.scroll)

        dustRig.position.set(
          -rt.pointer.ndc.x * 0.18,
          -rt.pointer.ndc.y * 0.12,
          0,
        )
      },
      onPalette: applyPalette,
      onResize: applySize,
      onPulse: (rt, ndc) => {
        if (rt.pointerOnPlane(root, localShock, ndc)) {
          shockAge = 0
        }
      },
      onReplay: () => {
        sweepStartedAt = -1
        nextSweepAt = SWEEP_FIRST_AT
        shockAge = 99
      },
      dispose: () => {
        geometry.dispose()
        material.dispose()
        dustGeometry.dispose()
        dustMaterial.dispose()
        aura.geometry.dispose()
        aura.material.dispose()
      },
    }
  })
