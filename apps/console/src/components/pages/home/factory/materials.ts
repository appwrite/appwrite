import {
  AdditiveBlending,
  Color,
  DoubleSide,
  NormalBlending,
  ShaderMaterial,
  UniformsLib,
  UniformsUtils,
  Vector2,
  Vector4,
  type Blending,
  type Texture,
} from 'three'
import { SHADOW_BOUNDS, type ScreenCell } from './textures'
import {
  BED_FRONT,
  CORE,
  PRODUCT_KINDS,
  STATION_COUNT,
  type ProductKind,
} from './layout'

/**
 * Custom materials. Colors are linear (three's working space) and every
 * fragment ends with `colorspace_fragment` then `fog_fragment`, the same
 * order three's own materials use, so the floor matches `--background`
 * exactly and distant geometry fades into the page.
 */

const FOG_VERTEX_PARS = /* glsl */ `
#include <common>
#include <fog_pars_vertex>
`

/** Additive on dark pages reads as light; on light pages it vanishes, so glows switch to tinted alpha. */
export function glowBlending(dark: boolean): Blending {
  return dark ? AdditiveBlending : NormalBlending
}

/**
 * The studio floor: the page background, darkened by baked occlusion under
 * the machine, lifted by a soft pool of light, and (where the GPU allows)
 * polished enough to mirror the line standing on it.
 */
export function createFloorMaterial(shadow: Texture) {
  const material = new ShaderMaterial({
    fog: true,
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      {
        uBackground: { value: new Color() },
        uShadow: { value: null },
        uShadowBounds: {
          value: new Vector4(
            SHADOW_BOUNDS.minX,
            SHADOW_BOUNDS.minZ,
            SHADOW_BOUNDS.maxX,
            SHADOW_BOUNDS.maxZ,
          ),
        },
        uOcclusion: { value: 0.5 },
        // How far the racks have rolled in, so their painted shadow comes with them.
        uRacks: { value: 1 },
        uPool: { value: 0 },
        uPoolShape: { value: new Vector4(1, -0.2, 0.07, 0.26) },
        uReflection: { value: null },
        uResolution: { value: new Vector2(1, 1) },
        uReflectivity: { value: 0 },
        uFront: { value: BED_FRONT },
      },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying vec3 vWorld;
      void main() {
        vec4 world = modelMatrix * vec4(position, 1.0);
        vWorld = world.xyz;
        vec4 mvPosition = viewMatrix * world;
        gl_Position = projectionMatrix * mvPosition;
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform vec3 uBackground;
      uniform sampler2D uShadow;
      uniform vec4 uShadowBounds;
      uniform float uOcclusion;
      uniform float uRacks;
      uniform float uPool;
      uniform vec4 uPoolShape;
      uniform sampler2D uReflection;
      uniform vec2 uResolution;
      uniform float uReflectivity;
      uniform float uFront;
      varying vec3 vWorld;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        vec2 p = vWorld.xz;
        vec3 color = uBackground;

        vec2 pool = (p - uPoolShape.xy) * uPoolShape.zw;
        color += vec3(uPool) * exp(-dot(pool, pool));

        vec2 suv = (p - uShadowBounds.xy) / (uShadowBounds.zw - uShadowBounds.xy);
        // The bed's shadow (red), and the racks' (green) as far as they have arrived.
        vec2 shadows = texture2D(uShadow, vec2(suv.x, 1.0 - suv.y)).rg;
        float shade = shadows.r + shadows.g * uRacks;
        color *= 1.0 - shade * uOcclusion;

        #ifdef USE_REFLECTION
        // Skipped outright once the engine has faded the reflection out.
        if (uReflectivity > 0.0) {
          // The mirrored camera sees every floor point at the same height and the opposite side.
          vec2 uv = gl_FragCoord.xy / uResolution;
          uv.x = 1.0 - uv.x;
          float away = max(p.y - uFront, 0.0);
          // Sharp where the machine meets the floor, softer and fainter further out.
          float lod = 0.5 + min(away * 1.1, 3.2);
          // One texel of that mip (the target is half resolution), for a 3x3 tent blur.
          vec2 texel = exp2(lod + 1.0) / uResolution * 0.8;
          vec4 mirror = textureLod(uReflection, uv, lod) * 0.25;
          mirror += (textureLod(uReflection, uv + vec2(texel.x, 0.0), lod)
            + textureLod(uReflection, uv - vec2(texel.x, 0.0), lod)
            + textureLod(uReflection, uv + vec2(0.0, texel.y), lod)
            + textureLod(uReflection, uv - vec2(0.0, texel.y), lod)) * 0.125;
          mirror += (textureLod(uReflection, uv + texel, lod)
            + textureLod(uReflection, uv - texel, lod)
            + textureLod(uReflection, uv + vec2(texel.x, -texel.y), lod)
            + textureLod(uReflection, uv + vec2(-texel.x, texel.y), lod)) * 0.0625;
          float gloss = uReflectivity * exp(-away * 0.8);
          color = color * (1.0 - mirror.a * gloss) + mirror.rgb * gloss;
        }
        #endif

        gl_FragColor = vec4(color, 1.0);
        #include <colorspace_fragment>
        #include <fog_fragment>
      }
    `,
  })
  material.name = 'factory-floor'
  material.uniforms.uShadow!.value = shadow
  return material
}

export type ScreenUniforms = {
  uAtlas: { value: Texture | null }
  uCells: { value: Vector4[] }
  uStride: { value: number[] }
  uShape: { value: Vector4[] }
  /** Per kind, a black cutout over the screen (center and half size, in glass depths); none when the size is zero. */
  uCutout: { value: Vector4[] }
  /** Per kind, 1 for a light bezel, 0 for a black one. */
  uBezelTone: { value: number[] }
  uGlass: { value: Color }
  uBezel: { value: Color }
  uBrightness: { value: number }
  uPower: { value: number }
  uSheen: { value: number }
  /** Framework marks for the boot screen (stage 1), one square cell each. */
  uFrameworks: { value: Texture | null }
  /** Columns and rows of that atlas. */
  uFrameworkGrid: { value: Vector2 }
  uFrameworksReady: { value: number }
  /** Color of the level bar's filled segments. */
  uLevel: { value: Color }
}

/**
 * Every product screen in one instanced draw. `aScreen` carries the stage
 * the product reached, the stage before it, the reveal progress and the
 * product kind. Stage 0 is a blank screen, 1 the framework's boot screen,
 * 2 and up one app layer per Appwrite station. `aFx` carries a brightness
 * boost, whether the reveal wipes (a tool sweeping the screen) or fades,
 * the framework and the app's hue (so every app has its own brand color).
 */
export function createScreenMaterial() {
  const uniforms: ScreenUniforms = {
    uAtlas: { value: null },
    uCells: { value: PRODUCT_KINDS.map(() => new Vector4()) },
    uStride: { value: PRODUCT_KINDS.map(() => 0) },
    uShape: {
      value: PRODUCT_KINDS.map(() => new Vector4(1, 0.05, 0.03, 0.03)),
    },
    uCutout: { value: PRODUCT_KINDS.map(() => new Vector4()) },
    uBezelTone: { value: PRODUCT_KINDS.map(() => 0) },
    uGlass: { value: new Color() },
    uBezel: { value: new Color() },
    uBrightness: { value: 1 },
    uPower: { value: 1 },
    uSheen: { value: 0.05 },
    uFrameworks: { value: null },
    uFrameworkGrid: { value: new Vector2(1, 1) },
    uFrameworksReady: { value: 0 },
    uLevel: { value: new Color() },
  }

  const material = new ShaderMaterial({
    fog: true,
    uniforms: UniformsUtils.merge([UniformsLib.fog]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      attribute vec4 aScreen;
      attribute vec4 aFx;
      varying vec2 vUv;
      varying vec4 vScreen;
      varying vec4 vFx;
      varying vec3 vWorld;
      varying vec3 vFacing;
      void main() {
        vUv = uv;
        vScreen = aScreen;
        vFx = aFx;
        #include <begin_vertex>
        #include <project_vertex>
        vec4 world = vec4(transformed, 1.0);
        // The glass faces up unless the product holds it otherwise (an open lid, a front panel).
        vec3 facing = vec3(0.0, 1.0, 0.0);
        #ifdef USE_INSTANCING
          world = instanceMatrix * world;
          facing = mat3(instanceMatrix) * facing;
        #endif
        vWorld = (modelMatrix * world).xyz;
        vFacing = normalize(mat3(modelMatrix) * facing);
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform sampler2D uAtlas;
      uniform vec4 uCells[${PRODUCT_KINDS.length}];
      uniform float uStride[${PRODUCT_KINDS.length}];
      uniform vec4 uShape[${PRODUCT_KINDS.length}];
      uniform vec4 uCutout[${PRODUCT_KINDS.length}];
      uniform float uBezelTone[${PRODUCT_KINDS.length}];
      uniform vec3 uGlass;
      uniform vec3 uBezel;
      uniform float uBrightness;
      uniform float uPower;
      uniform float uSheen;
      uniform sampler2D uFrameworks;
      uniform vec2 uFrameworkGrid;
      uniform float uFrameworksReady;
      uniform vec3 uLevel;
      varying vec2 vUv;
      varying vec4 vScreen;
      varying vec4 vFx;
      varying vec3 vWorld;
      varying vec3 vFacing;
      #include <common>
      #include <fog_pars_fragment>

      float roundedBox(vec2 p, vec2 halfSize, float radius) {
        vec2 q = abs(p) - halfSize + radius;
        return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - radius;
      }

      // Rotates a color about the grey axis: greys stay grey, brand colors change hue.
      vec3 hueShift(vec3 color, float angle) {
        const vec3 k = vec3(0.57735);
        float c = cos(angle);
        return color * c + cross(k, color) * sin(angle) + k * dot(k, color) * (1.0 - c);
      }

      // The framework's mark centered on a dark boot screen.
      vec3 bootScreen(vec2 uv, float aspect) {
        vec2 q = (uv - 0.5) * vec2(aspect, 1.0);
        vec3 color = mix(vec3(0.016), vec3(0.003), clamp(length(q) * 1.6, 0.0, 1.0));
        float side = 0.42 * min(aspect, 1.0);
        vec2 cell = q / side + 0.5;
        if (uFrameworksReady > 0.5 && all(greaterThanEqual(cell, vec2(0.0))) && all(lessThanEqual(cell, vec2(1.0)))) {
          // Atlas row 0 is at the canvas top, which is v = 1.
          float column = mod(vFx.z, uFrameworkGrid.x);
          float row = floor(vFx.z / uFrameworkGrid.x);
          vec2 st = vec2(
            (column + cell.x) / uFrameworkGrid.x,
            1.0 - (row + 1.0 - cell.y) / uFrameworkGrid.y
          );
          vec4 mark = texture2D(uFrameworks, st);
          color = mix(color, mark.rgb, mark.a);
        }
        return color;
      }

      vec3 stageColor(float stage, int kind, vec2 uv, float aspect) {
        if (stage < 0.5) return uGlass;
        if (stage < 1.5) return bootScreen(uv, aspect) * uBrightness * uPower;
        vec4 cell = uCells[kind];
        vec2 st = vec2(
          cell.x + (stage - 2.0) * uStride[kind] + clamp(uv.x, 0.004, 0.996) * cell.z,
          cell.y + clamp(uv.y, 0.004, 0.996) * cell.w
        );
        vec3 color = hueShift(texture2D(uAtlas, st).rgb, vFx.w);
        return max(color, vec3(0.0)) * uBrightness * uPower;
      }

      void main() {
        int kind = int(vScreen.w + 0.5);
        vec4 shape = uShape[kind];
        vec2 size = vec2(shape.x, 1.0);
        vec2 p = (vUv - 0.5) * size;
        float outer = roundedBox(p, size * 0.5, shape.y);
        if (outer > 0.0) discard;
        vec2 innerHalf = size * 0.5 - shape.z;
        float inner = roundedBox(p, innerHalf, shape.w);
        vec2 uv = (p + innerHalf) / (innerHalf * 2.0);

        float stage = vScreen.x;
        float reveal = vScreen.z;
        float aspect = innerHalf.x / innerHalf.y;
        vec3 next = stageColor(stage, kind, uv, aspect);
        vec3 before = stageColor(vScreen.y, kind, uv, aspect);
        vec3 color;
        if (vFx.y > 0.5) {
          // A tool sweeps the screen from its far edge to the near one.
          float front = 1.0 - reveal * 1.12;
          color = mix(before, next, smoothstep(front - 0.012, front + 0.012, uv.y));
          float sweeping = step(0.001, reveal) * (1.0 - step(0.999, reveal));
          color += vec3(0.75) * exp(-pow((uv.y - front) / 0.025, 2.0)) * sweeping * uPower;
        } else {
          color = mix(before, next, smoothstep(0.0, 0.5, reveal));
        }
        color *= 1.0 + vFx.x;

        // Level bar: one segment fills at every station the app has been through.
        if (stage > 1.5 && kind != ${PRODUCT_KINDS.indexOf('mini')}) {
          float inset = kind == ${PRODUCT_KINDS.indexOf('watch')} ? 0.22 : 0.08;
          vec2 low = vec2(inset, 0.035);
          vec2 high = vec2(1.0 - inset, 0.085);
          if (all(greaterThan(uv, low)) && all(lessThan(uv, high))) {
            float t = (uv.x - low.x) / (high.x - low.x) * ${STATION_COUNT}.0;
            float segment = floor(t);
            float level = stage - 1.0;
            float filled = step(segment + 0.5, level);
            float newest = filled * step(level - 1.5, segment);
            float gap = step(0.14, fract(t));
            vec3 lit = uLevel * (1.0 + 2.0 * vFx.x * newest);
            color = mix(color * 0.3, mix(vec3(0.1), lit, filled), gap);
          }
        }

        // The panel's own black cutout: the phone's island, the laptop's notch.
        vec4 cutout = uCutout[kind];
        if (cutout.z > 0.0) {
          float hole = roundedBox(p - cutout.xy, cutout.zw, cutout.w);
          float holeEdge = max(fwidth(hole), 0.0015);
          color = mix(vec3(0.0), color, smoothstep(-holeEdge, holeEdge, hole));
        }

        // The bezel is a pixel or two wide on screen: blend it over the pixel footprint.
        float edge = max(fwidth(inner), 0.0015);
        vec3 bezel = mix(uBezel, vec3(0.62), uBezelTone[kind]);
        color = mix(color, bezel, smoothstep(-edge, edge, inner));

        // The studio's back softbox, mirrored in the cover glass.
        vec3 bounce = reflect(normalize(vWorld - cameraPosition), normalize(vFacing));
        float softbox = smoothstep(0.24, 0.44, bounce.y) * (1.0 - smoothstep(0.52, 0.74, bounce.y));
        color += vec3(uSheen) * softbox;

        gl_FragColor = vec4(color, 1.0);
        #include <colorspace_fragment>
        #include <fog_fragment>
      }
    `,
  })
  Object.assign(material.uniforms, uniforms)
  return { material, uniforms }
}

export function applyScreenCells(
  uniforms: ScreenUniforms,
  cells: Record<ProductKind, ScreenCell>,
) {
  PRODUCT_KINDS.forEach((kind, index) => {
    const cell = cells[kind]
    uniforms.uCells.value[index]!.set(cell.u, cell.v, cell.du, cell.dv)
    uniforms.uStride.value[index] = cell.stride
  })
}

/**
 * Soft round sprites for LEDs and the light they throw. Size is in world
 * units and attenuates with depth. Fog dims them instead of tinting them.
 */
export function createGlowMaterial(dark: boolean) {
  return new ShaderMaterial({
    fog: true,
    transparent: true,
    depthWrite: false,
    blending: glowBlending(dark),
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      { uScale: { value: 400 }, uLight: { value: dark ? 0 : 1 } },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      attribute float aSize;
      attribute vec4 aColor;
      uniform float uScale;
      varying vec4 vColor;
      void main() {
        vColor = aColor;
        vec4 mvPosition = modelViewMatrix * vec4(position, 1.0);
        gl_Position = projectionMatrix * mvPosition;
        gl_PointSize = aColor.a <= 0.001 ? 0.0 : aSize * uScale / -mvPosition.z;
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform float uLight;
      varying vec4 vColor;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        vec2 c = gl_PointCoord - 0.5;
        float d = dot(c, c) * 4.0;
        if (d > 1.0) discard;
        float halo = (exp(-d * 5.0) - exp(-5.0)) / (1.0 - exp(-5.0));
        float core = exp(-d * 40.0);
        float alpha = clamp(halo * 0.85 + core, 0.0, 1.0) * vColor.a;
        vec3 color = mix(vColor.rgb, vec3(1.0), core * 0.55);
        #ifdef USE_FOG
          float fogFactor = smoothstep(fogNear, fogFar, vFogDepth);
          alpha *= 1.0 - fogFactor;
        #endif
        // Both blend modes multiply by alpha themselves (SRC_ALPHA).
        gl_FragColor = vec4(color, alpha * (uLight > 0.5 ? 0.9 : 1.0));
        #include <colorspace_fragment>
      }
    `,
  })
}

/** Light curtains under tool heads: brightest at the source (uv.y = 1), fading toward the product. */
export function createBeamMaterial(dark: boolean) {
  return new ShaderMaterial({
    fog: true,
    transparent: true,
    depthWrite: false,
    side: DoubleSide,
    blending: glowBlending(dark),
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      {
        uColor: { value: new Color() },
        uAlpha: { value: 0 },
        uLight: { value: dark ? 0 : 1 },
      },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying vec2 vUv;
      void main() {
        vUv = uv;
        #include <begin_vertex>
        #include <project_vertex>
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform vec3 uColor;
      uniform float uAlpha;
      uniform float uLight;
      varying vec2 vUv;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        float body = mix(0.25, 1.0, vUv.y * vUv.y);
        body *= smoothstep(0.0, 0.08, vUv.x) * smoothstep(1.0, 0.92, vUv.x);
        float alpha = clamp(body * uAlpha, 0.0, 1.0);
        #ifdef USE_FOG
          alpha *= 1.0 - smoothstep(fogNear, fogFar, vFogDepth);
        #endif
        gl_FragColor = vec4(uColor, alpha * (uLight > 0.5 ? 0.85 : 1.0));
        #include <colorspace_fragment>
      }
    `,
  })
}

/**
 * The fan of light a station's hologram is projected through, from the lens
 * (`position.y` 0) up to the screen (1; the mesh is scaled to the real
 * height). `uWidths` is its width at either end, so the shader can tell
 * across from along. It reaches up as far as `uReach` and shines at
 * `uLevel`, brightest at the lens, with faint rays rising through it.
 */
export function createProjectionMaterial(dark: boolean) {
  return new ShaderMaterial({
    fog: true,
    transparent: true,
    depthWrite: false,
    side: DoubleSide,
    blending: glowBlending(dark),
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      {
        uColor: { value: new Color() },
        uLevel: { value: 0 },
        uReach: { value: 0 },
        uTime: { value: 0 },
        uWidths: { value: new Vector2(1, 1) },
        uLight: { value: dark ? 0 : 1 },
      },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying vec2 vLocal;
      void main() {
        vLocal = position.xy;
        #include <begin_vertex>
        #include <project_vertex>
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform vec3 uColor;
      uniform float uLevel;
      uniform float uReach;
      uniform float uTime;
      uniform vec2 uWidths;
      uniform float uLight;
      varying vec2 vLocal;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        float along = vLocal.y;
        if (along > uReach) discard;
        float across = vLocal.x / mix(uWidths.x, uWidths.y, along) + 0.5;
        float sides = smoothstep(0.0, 0.22, across) * smoothstep(1.0, 0.78, across);
        float body = mix(1.0, 0.3, along);
        // Rays fanning out from the lens, and light rising through them.
        float rays = 0.72 + 0.28 * sin(across * 57.0 + sin(across * 11.0) * 3.0);
        float rise = 0.85 + 0.15 * sin(along * 26.0 - uTime * 6.0);
        // The front of the light as it shoots up.
        float front = smoothstep(uReach, uReach - 0.1, along);
        float alpha = clamp(sides * body * rays * rise * front * uLevel * 0.45, 0.0, 1.0);
        #ifdef USE_FOG
          alpha *= 1.0 - smoothstep(fogNear, fogFar, vFogDepth);
        #endif
        gl_FragColor = vec4(uColor, alpha * (uLight > 0.5 ? 0.85 : 1.0));
        #include <colorspace_fragment>
      }
    `,
  })
}

export type HologramUniforms = {
  uAtlas: { value: Texture | null }
  /** The station's cell in the atlas: u, v, du, dv. */
  uCell: { value: Vector4 }
  /** The screen's size (meters). */
  uSize: { value: Vector2 }
  /** 0 → 1 as the scan line draws the screen in from the bottom. */
  uReveal: { value: number }
  /** 1 while the image is still forming and unsteady, 0 once it holds. */
  uFlicker: { value: number }
  uTime: { value: number }
  uRim: { value: Color }
  /** How much the glass dims what is behind it. */
  uPane: { value: number }
}

/**
 * A station's hologram: its cell of the description atlas as light in a pane
 * of smoked glass, drawn in from the bottom by a scan line, unsteady while it
 * forms, with fine scan lines and a lit rim. Premultiplied, so one draw both
 * dims what is behind the glass and adds the light on top: it reads on dark
 * and light pages alike.
 */
export function createHologramMaterial() {
  const uniforms: HologramUniforms = {
    uAtlas: { value: null },
    uCell: { value: new Vector4(0, 0, 1, 1) },
    uSize: { value: new Vector2(1, 1) },
    uReveal: { value: 0 },
    uFlicker: { value: 0 },
    uTime: { value: 0 },
    uRim: { value: new Color() },
    uPane: { value: 0.5 },
  }
  const material = new ShaderMaterial({
    fog: true,
    transparent: true,
    depthWrite: false,
    premultipliedAlpha: true,
    uniforms: UniformsUtils.merge([UniformsLib.fog]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying vec2 vUv;
      void main() {
        vUv = uv;
        #include <begin_vertex>
        #include <project_vertex>
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform sampler2D uAtlas;
      uniform vec4 uCell;
      uniform vec2 uSize;
      uniform float uReveal;
      uniform float uFlicker;
      uniform float uTime;
      uniform vec3 uRim;
      uniform float uPane;
      varying vec2 vUv;
      #include <common>
      #include <fog_pars_fragment>
      float noise(float seed) {
        return fract(sin(seed * 12.9898) * 43758.5453);
      }
      void main() {
        // Distance to the rounded edge of the glass (meters, negative inside).
        vec2 extent = uSize * 0.5 - 0.06;
        vec2 q = abs((vUv - 0.5) * uSize) - extent;
        float edge = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - 0.06;
        if (edge > 0.0) discard;
        // The scan line draws the screen in from the bottom, and out from the top.
        float scan = mix(-0.04, 1.04, uReveal);
        float drawn = smoothstep(scan + 0.004, scan - 0.02, vUv.y);
        if (drawn <= 0.0) discard;
        float line = exp(-abs(vUv.y - scan) * 160.0) * (1.0 - step(1.0, uReveal));

        vec3 image = texture2D(uAtlas, uCell.xy + vUv * uCell.zw).rgb;
        // Fine scan lines drifting up, and a faint band drifting down.
        float rows = 0.88 + 0.12 * sin((vUv.y * uSize.y * 90.0 - uTime * 1.5) * 6.2832);
        float band = smoothstep(0.08, 0.0, abs(fract(vUv.y + uTime * 0.06) - 0.5)) * 0.1;
        // While it forms the image is unsteady: whole-frame dips and torn rows.
        float frame = floor(uTime * 30.0);
        float dip = step(0.55, noise(frame)) * 0.45;
        float torn = step(0.9, noise(floor(vUv.y * 36.0) + frame * 7.0)) * 0.7;
        float steady = 1.0 - uFlicker * max(dip, torn);

        vec3 light = image * rows * steady * 1.2 + uRim * band * steady;
        // A thin lit rim, and a soft glow just inside it.
        light += uRim * (smoothstep(-0.016, -0.004, edge) * 0.8 + exp(edge * 40.0) * 0.16);
        light += mix(uRim, vec3(1.0), 0.6) * line * 1.5;
        light *= drawn;
        float alpha = uPane * drawn;
        #ifdef USE_FOG
          float fade = 1.0 - smoothstep(fogNear, fogFar, vFogDepth);
          light *= fade;
          alpha *= fade;
        #endif
        gl_FragColor = vec4(light, alpha);
        #include <colorspace_fragment>
      }
    `,
  })
  Object.assign(material.uniforms, uniforms)
  return { material, uniforms }
}

/**
 * Service names printed on the glass fascias: one instanced quad per
 * station, one atlas row each, lit from behind so they read in the dark.
 * `aPlate` is the atlas row and the print's brightness (above 1 on hover).
 */
export function createNameplateMaterial() {
  return new ShaderMaterial({
    fog: true,
    transparent: true,
    depthWrite: false,
    polygonOffset: true,
    polygonOffsetFactor: -1,
    polygonOffsetUnits: -1,
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      { uMap: { value: null }, uRows: { value: STATION_COUNT } },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      attribute vec2 aPlate;
      uniform float uRows;
      varying vec2 vUv;
      varying float vLevel;
      void main() {
        // Row 0 is at the top of the atlas canvas.
        vUv = vec2(uv.x, 1.0 - (aPlate.x + 1.0 - uv.y) / uRows);
        vLevel = aPlate.y;
        #include <begin_vertex>
        #include <project_vertex>
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform sampler2D uMap;
      varying vec2 vUv;
      varying float vLevel;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        vec4 print = texture2D(uMap, vUv);
        float alpha = print.a * clamp(vLevel, 0.0, 1.0);
        if (alpha < 0.004) discard;
        #ifdef USE_FOG
          alpha *= 1.0 - smoothstep(fogNear, fogFar, vFogDepth);
        #endif
        vec3 color = print.rgb * mix(0.84, 1.0, clamp(vLevel - 1.0, 0.0, 1.0));
        gl_FragColor = vec4(color, alpha);
        #include <colorspace_fragment>
      }
    `,
  })
}

/**
 * The light line along the bed. It idles dim; every cycle a pulse leaves
 * the core and runs down the line, and each station starts as it passes.
 */
export function createPowerLineMaterial() {
  return new ShaderMaterial({
    fog: true,
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      {
        uBase: { value: new Color() },
        uPulse: { value: new Color() },
        uWave: { value: -10 },
        uPower: { value: 1 },
        uCoreX: { value: CORE.x },
      },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying float vX;
      void main() {
        vec4 world = modelMatrix * vec4(position, 1.0);
        vX = world.x;
        vec4 mvPosition = viewMatrix * world;
        gl_Position = projectionMatrix * mvPosition;
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform vec3 uBase;
      uniform vec3 uPulse;
      uniform float uWave;
      uniform float uPower;
      uniform float uCoreX;
      varying float vX;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        float away = uCoreX - vX;
        float lead = away - uWave;
        float head = exp(-lead * lead / 0.7) * step(0.0, away);
        float trail = exp(lead * 0.28) * step(lead, 0.0) * step(0.0, away) * 0.3;
        vec3 color = (uBase + uPulse * (head + trail)) * uPower;
        gl_FragColor = vec4(color, 1.0);
        #include <colorspace_fragment>
        #include <fog_fragment>
      }
    `,
  })
}

/** Contact shadow blobs: tinted alpha, never written to depth. */
export function createBlobShadowMaterial(map: Texture) {
  return new ShaderMaterial({
    transparent: true,
    depthWrite: false,
    polygonOffset: true,
    polygonOffsetFactor: -2,
    polygonOffsetUnits: -2,
    uniforms: { uMap: { value: map }, uStrength: { value: 0.3 } },
    vertexShader: /* glsl */ `
      attribute float aAlpha;
      varying vec2 vUv;
      varying float vAlpha;
      void main() {
        vUv = uv;
        vAlpha = aAlpha;
        #include <begin_vertex>
        #include <project_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform sampler2D uMap;
      uniform float uStrength;
      varying vec2 vUv;
      varying float vAlpha;
      void main() {
        float a = texture2D(uMap, vUv).r * uStrength * vAlpha;
        gl_FragColor = vec4(0.0, 0.0, 0.02, a);
      }
    `,
  })
}

/**
 * Chevrons on the network gate, running toward the network. `uSince` is the
 * time since the last product shipped; the strip brightens as it does.
 */
export function createChevronMaterial() {
  return new ShaderMaterial({
    fog: true,
    uniforms: UniformsUtils.merge([
      UniformsLib.fog,
      {
        uBase: { value: new Color() },
        uColor: { value: new Color() },
        uTime: { value: 0 },
        uSince: { value: 10 },
        uPower: { value: 1 },
        uCount: { value: 14 },
      },
    ]),
    vertexShader: /* glsl */ `
      ${FOG_VERTEX_PARS}
      varying vec2 vUv;
      void main() {
        vUv = uv;
        #include <begin_vertex>
        #include <project_vertex>
        #include <fog_vertex>
      }
    `,
    fragmentShader: /* glsl */ `
      uniform vec3 uBase;
      uniform vec3 uColor;
      uniform float uTime;
      uniform float uSince;
      uniform float uPower;
      uniform float uCount;
      varying vec2 vUv;
      #include <common>
      #include <fog_pars_fragment>
      void main() {
        float x = vUv.x * uCount - abs(vUv.y - 0.5) * 1.4 - uTime * 1.6;
        float f = fract(x);
        float aa = fwidth(x) * 1.2;
        float chevron = smoothstep(0.0, aa, f) * (1.0 - smoothstep(0.34, 0.34 + aa, f));
        // Each chevron lights in turn, and the whole strip flares as a product ships.
        float run = 0.35 + 0.65 * pow(0.5 + 0.5 * sin((vUv.x * 3.0 - uTime * 2.4) * 6.2831), 3.0);
        float flare = exp(-uSince * 3.0);
        vec3 color = mix(uBase, uColor, chevron * clamp(run * 0.7 + flare, 0.0, 1.0)) * uPower;
        gl_FragColor = vec4(color, 1.0);
        #include <colorspace_fragment>
        #include <fog_fragment>
      }
    `,
  })
}
