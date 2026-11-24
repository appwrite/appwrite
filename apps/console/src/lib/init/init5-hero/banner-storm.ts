import type { Init5ScenePalette } from './scene-types'

/**
 * The Init 5 storm in the site banner: the hero's fog (the aurora in
 * `scenes/light-trails-scene.ts`, the same fog the Init films use) laid along
 * the bar, with lightning striking inside it. A strike is not a drawn bolt: it
 * is light blooming in the cloud, a flash, a dimmer re-flash or two, then dark.
 *
 * Plain WebGL with one full-bar pass, drawn at half resolution (the fog is
 * soft), so every page carrying the banner stays light.
 */

export type InitBannerStorm = {
  /** Banner size in CSS px. */
  resize: (width: number, height: number) => void
  /** Where the init_5 mark sits, in CSS px from the banner's top-left. */
  setMark: (x: number, y: number) => void
  setPalette: (palette: Init5ScenePalette) => void
  /** Run while the banner is on screen; pause when it scrolls away. */
  setActive: (active: boolean) => void
  dispose: () => void
}

const MAX_BOLTS = 2
/** Fog units per banner height: sets the size of the clouds and wisps. */
const UNITS_PER_HEIGHT = 0.62
const FRAME_MS = 1000 / 30

const vertex = /* glsl */ `
attribute vec2 position;
void main() {
  gl_Position = vec4(position, 0.0, 1.0);
}
`

const fragment = /* glsl */ `
#ifdef GL_FRAGMENT_PRECISION_HIGH
precision highp float;
#else
precision mediump float;
#endif
uniform vec2 uResolution;
uniform float uScale;
uniform vec2 uMark;
uniform float uTime;
uniform float uIntensity;
uniform vec3 uColorA;
uniform vec3 uColorB;
uniform vec3 uColorC;
uniform vec3 uBolts[${MAX_BOLTS}];
uniform vec3 uBoltColor;

float hash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
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
  for (int i = 0; i < 5; i++) {
    v += a * noise(p);
    p = p * 2.03 + vec2(1.7, 9.2);
    a *= 0.5;
  }
  return v;
}

void main() {
  // Fog units, x from the left edge, y from the bar's middle (up).
  vec2 p = (gl_FragCoord.xy - vec2(0.0, uResolution.y * 0.5)) / uScale;
  float t = uTime * 0.05;
  vec2 q = vec2(fbm(p * 1.05 + vec2(t, -t)), fbm(p * 1.05 + vec2(-t * 0.7, t * 1.3) + 5.2));
  float n = fbm(p * 1.5 + q * 1.9 + vec2(t * 0.5, 0.0));
  // The hero's fog body plus fine ridged filaments: crisp wisps, not a blur.
  float body = smoothstep(0.34, 0.8, n);
  float r = fbm(p * 3.4 + q * 2.2 + vec2(-t * 0.8, t * 0.6) + 11.3);
  float wisps = pow(1.0 - abs(r * 2.0 - 1.0), 7.0) * smoothstep(0.2, 0.7, n);
  float density = body * 0.8 + wisps * 0.75;
  vec3 color = mix(uColorB, uColorA, smoothstep(0.3, 0.72, n));
  color = mix(color, uColorC, smoothstep(0.55, 0.9, q.x) * 0.55);
  color = mix(color, uColorC, wisps * 0.3);

  // Along the bar the fog gathers in banks that drift by, with gaps between,
  // and thickest around the init_5 mark (the hero's fog is the 5's halo).
  float banks = smoothstep(0.18, 0.82, fbm(vec2(p.x * 0.24 + p.y * 0.5 - uTime * 0.012, p.y * 1.2 + 3.7)));
  vec2 dm = (p - uMark) * vec2(0.5, 1.0);
  float halo = exp(-dot(dm, dm) * 1.6);
  float mask = min(1.0, banks * 0.8 + halo * 0.45);
  float a = density * mask * uIntensity;

  // Lightning: light blooms from the strike point and scatters through the
  // fog, so the denser cloud lights up most and the thin gaps stay dark. The
  // bar is thin, so each strike also lifts the whole bank a little, the way
  // a sky lights up.
  float lit = 0.0;
  for (int i = 0; i < ${MAX_BOLTS}; i++) {
    vec2 bd = (p - uBolts[i].xy) * vec2(0.42, 1.0);
    float reach = exp(-dot(bd, bd) * 0.9);
    lit += uBolts[i].z * (reach + 0.1 * banks) * (0.15 + density * 1.25);
  }
  gl_FragColor = vec4(color * a + uBoltColor * lit, a + lit * 0.5);
}
`

type Strike = { at: number; peak: number; decay: number }
type Bolt = { x: number; y: number; startedAt: number; strikes: Strike[] }

function rgb(hex: string): [number, number, number] {
  const value = hex.replace('#', '')
  return [0, 2, 4].map((i) => parseInt(value.slice(i, i + 2), 16) / 255) as [
    number,
    number,
    number,
  ]
}

function mixRgb(
  a: [number, number, number],
  b: [number, number, number],
  amount: number,
): [number, number, number] {
  return a.map((channel, i) => channel + (b[i]! - channel) * amount) as [
    number,
    number,
    number,
  ]
}

/** The hero's strike: a flash, then a dimmer re-flash or two. */
function strikeShape(): Strike[] {
  const count = 1 + Math.floor(Math.random() * 3)
  return Array.from({ length: count }, (_, index) => ({
    at: index === 0 ? 0 : 0.06 + index * 0.08 + Math.random() * 0.06,
    peak: index === 0 ? 1 : 0.35 + Math.random() * 0.4,
    decay: 8 + Math.random() * 6,
  }))
}

function boltLight(bolt: Bolt, time: number) {
  const t = time - bolt.startedAt
  if (t < 0) return 0
  let value = 0
  for (const strike of bolt.strikes) {
    if (t >= strike.at) {
      value += strike.peak * Math.exp(-(t - strike.at) * strike.decay)
    }
  }
  return Math.min(1, value)
}

export function createInitBannerStorm(
  canvas: HTMLCanvasElement,
  options: { palette: Init5ScenePalette; reducedMotion: boolean },
): InitBannerStorm | null {
  const gl = canvas.getContext('webgl', {
    alpha: true,
    premultipliedAlpha: true,
    antialias: false,
    depth: false,
    stencil: false,
    powerPreference: 'low-power',
  })
  if (!gl || gl.isContextLost()) return null

  const compile = (type: number, source: string) => {
    const shader = gl.createShader(type)!
    gl.shaderSource(shader, source)
    gl.compileShader(shader)
    return shader
  }
  const program = gl.createProgram()!
  gl.attachShader(program, compile(gl.VERTEX_SHADER, vertex))
  gl.attachShader(program, compile(gl.FRAGMENT_SHADER, fragment))
  gl.linkProgram(program)
  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) return null
  gl.useProgram(program)

  // One triangle that covers the bar.
  const buffer = gl.createBuffer()
  gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
  gl.bufferData(
    gl.ARRAY_BUFFER,
    new Float32Array([-1, -1, 3, -1, -1, 3]),
    gl.STATIC_DRAW,
  )
  const position = gl.getAttribLocation(program, 'position')
  gl.enableVertexAttribArray(position)
  gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0)

  const uniform = (name: string) => gl.getUniformLocation(program, name)
  const u = {
    resolution: uniform('uResolution'),
    scale: uniform('uScale'),
    mark: uniform('uMark'),
    time: uniform('uTime'),
    intensity: uniform('uIntensity'),
    colorA: uniform('uColorA'),
    colorB: uniform('uColorB'),
    colorC: uniform('uColorC'),
    bolts: uniform('uBolts'),
    boltColor: uniform('uBoltColor'),
  }

  let palette = options.palette
  let width = 1
  let height = 1
  let ratio = 1
  let markX = 0
  let markY = 0
  let active = false
  let disposed = false
  let rafId = 0
  let lastFrame = 0
  // Start mid-drift so the first frame already shows formed clouds.
  let time = 40
  let lastNow = 0
  let nextBoltAt = time + 1.2
  const bolts: Bolt[] = []
  const packed = new Float32Array(MAX_BOLTS * 3)

  const applyPalette = () => {
    const accent = rgb(palette.accent)
    const violet = rgb(palette.violet)
    const peach = rgb(palette.peach)
    gl.uniform3fv(u.colorA, accent)
    gl.uniform3fv(u.colorB, violet)
    gl.uniform3fv(
      u.colorC,
      palette.isDark ? peach : mixRgb(peach, accent, 0.35),
    )
    // Cool, nearly white light with a violet cast, like lightning in cloud.
    const hot = palette.isDark ? rgb('#fff4f7') : accent
    gl.uniform3fv(u.boltColor, mixRgb(hot, violet, 0.3))
  }

  const scheduleBolt = () => {
    const units = width / (height / UNITS_PER_HEIGHT)
    bolts.push({
      x: Math.random() * units,
      y: (Math.random() - 0.5) * 0.4,
      startedAt: time,
      strikes: strikeShape(),
    })
    if (bolts.length > MAX_BOLTS) bolts.shift()
    nextBoltAt = time + 3.5 + Math.random() * 5
  }

  const draw = () => {
    const scale = (height / UNITS_PER_HEIGHT) * ratio
    gl.viewport(0, 0, canvas.width, canvas.height)
    gl.clearColor(0, 0, 0, 0)
    gl.clear(gl.COLOR_BUFFER_BIT)
    gl.uniform2f(u.resolution, canvas.width, canvas.height)
    gl.uniform1f(u.scale, scale)
    gl.uniform2f(
      u.mark,
      (markX * ratio) / scale,
      ((height / 2 - markY) * ratio) / scale,
    )
    gl.uniform1f(u.time, time)
    gl.uniform1f(u.intensity, palette.isDark ? 0.3 : 0.16)
    const strength = palette.isDark ? 0.7 : 0.3
    packed.fill(0)
    bolts.forEach((bolt, i) => {
      packed.set([bolt.x, bolt.y, boltLight(bolt, time) * strength], i * 3)
    })
    gl.uniform3fv(u.bolts, packed)
    gl.drawArrays(gl.TRIANGLES, 0, 3)
  }

  const frame = (now: number) => {
    if (!active || disposed || gl.isContextLost()) return
    rafId = requestAnimationFrame(frame)
    if (now - lastFrame < FRAME_MS - 1) return
    lastFrame = now
    if (lastNow) time += Math.min(0.1, (now - lastNow) / 1000)
    lastNow = now
    if (time >= nextBoltAt) scheduleBolt()
    draw()
  }

  applyPalette()

  return {
    resize: (nextWidth, nextHeight) => {
      width = Math.max(1, nextWidth)
      height = Math.max(1, nextHeight)
      ratio = Math.min(window.devicePixelRatio || 1, 2) * 0.5
      canvas.width = Math.max(1, Math.round(width * ratio))
      canvas.height = Math.max(1, Math.round(height * ratio))
      draw()
    },
    setMark: (x, y) => {
      markX = x
      markY = y
      draw()
    },
    setPalette: (next) => {
      palette = next
      applyPalette()
      draw()
    },
    setActive: (next) => {
      if (options.reducedMotion || disposed) {
        // Reduced motion keeps one still frame of fog and no lightning.
        draw()
        return
      }
      if (next === active) return
      active = next
      cancelAnimationFrame(rafId)
      lastNow = 0
      if (active) rafId = requestAnimationFrame(frame)
    },
    dispose: () => {
      disposed = true
      active = false
      cancelAnimationFrame(rafId)
      // Free what this storm made but keep the context: a canvas hands the
      // same context to whoever asks next (React remounts in development), and
      // a lost one would show the browser's broken-canvas icon.
      gl.deleteBuffer(buffer)
      gl.deleteProgram(program)
      gl.clearColor(0, 0, 0, 0)
      gl.clear(gl.COLOR_BUFFER_BIT)
    },
  }
}
