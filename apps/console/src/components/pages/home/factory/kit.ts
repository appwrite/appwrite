import {
  Color,
  MeshBasicMaterial,
  MeshStandardMaterial,
  Vector2,
  type ShaderMaterial,
  type Texture,
} from 'three'
import {
  createBeamMaterial,
  createBlobShadowMaterial,
  createFloorMaterial,
  createGlowMaterial,
  createNameplateMaterial,
  createPowerLineMaterial,
  createScreenMaterial,
  glowBlending,
  type ScreenUniforms,
} from './materials'
import type { FactoryPalette } from './palette'

/** The machined finishes a rigid assembly is made of, in `aFinish` order. */
export const FINISHES = [
  'enclosure',
  'bed',
  'aluminum',
  'steel',
  'graphite',
  'matte',
  'glass',
  'accent',
] as const
export type Finish = (typeof FINISHES)[number]

export type FactoryKit = {
  /**
   * Every finish in one material: each vertex picks its color, roughness and
   * metalness by `aFinish`, so assemblies merge across finishes.
   */
  finish: MeshStandardMaterial
  /** Painted column housings. */
  enclosure: MeshStandardMaterial
  /** The bed the machine stands on. */
  bed: MeshStandardMaterial
  /** Anodized aluminum: arms, plates, brackets. */
  aluminum: MeshStandardMaterial
  /** Polished steel: rails, rods, pins. */
  steel: MeshStandardMaterial
  /** Dark anodized tool housings. */
  graphite: MeshStandardMaterial
  /** Rubber and shadow gaps. */
  matte: MeshStandardMaterial
  /**
   * The rubber pads under the products: `matte`, as a material of its own so
   * instanced and plain meshes never share (and keep switching) a program.
   */
  pad: MeshStandardMaterial
  /** Black glass. */
  glass: MeshStandardMaterial
  /** Glossy pink power cables. */
  cable: MeshStandardMaterial
  /** The network's cables: the same cable, with light pulses running out along it. */
  networkCable: MeshStandardMaterial
  networkUniforms: NetworkUniforms
  /** Self-lit displays (installer, counter), mapped per mesh. */
  displays: MeshBasicMaterial[]
  carrier: MeshStandardMaterial
  /** Product bodies, tinted per instance with the product's finish. */
  device: MeshStandardMaterial
  /** Pink anodized parts: the brand color as a machined detail. */
  accent: MeshStandardMaterial
  logo: MeshStandardMaterial
  track: MeshStandardMaterial
  /** Indicator bars, tinted per instance. */
  led: MeshBasicMaterial
  /** Tool light emitters, one per light so each can switch on its own. */
  lamps: MeshBasicMaterial[]
  nameplate: ShaderMaterial
  powerLine: ShaderMaterial
  floor: ShaderMaterial
  screen: ShaderMaterial
  screenUniforms: ScreenUniforms
  glow: ShaderMaterial
  blob: ShaderMaterial
  beams: ShaderMaterial[]
  palette: FactoryPalette
}

/** Every material the factory uses, created once and recolored on theme change. */
export function createKit(
  palette: FactoryPalette,
  textures: { shadow: Texture; blob: Texture; track: Texture },
): FactoryKit {
  const standard = (roughness: number, metalness: number) =>
    new MeshStandardMaterial({ roughness, metalness })

  const screen = createScreenMaterial()

  const kit: FactoryKit = {
    finish: createFinishMaterial(),
    // Housings are bead-blasted anodized aluminium, like a phone's frame: fully
    // metallic, satin, so the rounded edges pick up the studio's softboxes.
    // Enclosure and bed carry baked occlusion in vertex colors (see `ground`).
    enclosure: new MeshStandardMaterial({
      roughness: 0.32,
      metalness: 0.7,
      vertexColors: true,
    }),
    bed: new MeshStandardMaterial({
      roughness: 0.4,
      metalness: 0.6,
      vertexColors: true,
    }),
    aluminum: standard(0.36, 1),
    steel: standard(0.14, 1),
    graphite: standard(0.42, 0.75),
    matte: standard(0.9, 0),
    pad: standard(0.9, 0),
    glass: standard(0.06, 1),
    cable: new MeshStandardMaterial({ roughness: 0.55, metalness: 0.05 }),
    ...createNetworkCable(),
    displays: [],
    carrier: standard(0.5, 1),
    // Machined finish per instance; keys, ports and feet darken it per vertex.
    device: new MeshStandardMaterial({
      roughness: 0.32,
      metalness: 0.9,
      vertexColors: true,
    }),
    accent: standard(0.3, 0.9),
    logo: standard(0.5, 0.1),
    track: new MeshStandardMaterial({
      roughness: 0.6,
      metalness: 0.4,
      map: textures.track,
    }),
    led: new MeshBasicMaterial({ toneMapped: false }),
    lamps: [],
    nameplate: createNameplateMaterial(),
    powerLine: createPowerLineMaterial(),
    floor: createFloorMaterial(textures.shadow),
    screen: screen.material,
    screenUniforms: screen.uniforms,
    glow: createGlowMaterial(palette.dark),
    blob: createBlobShadowMaterial(textures.blob),
    beams: [],
    palette,
  }

  applyKitPalette(kit, palette)
  return kit
}

/**
 * A standard material whose base color, roughness and metalness come from
 * the finish each vertex names (`aFinish`), and whose vertex colors carry the
 * baked occlusion of the parts that have it (white elsewhere).
 */
function createFinishMaterial() {
  const colors = FINISHES.map(() => new Color())
  const surfaces = FINISHES.map(() => new Vector2())
  const material = new MeshStandardMaterial({ vertexColors: true })
  material.userData.colors = colors
  material.userData.surfaces = surfaces
  material.onBeforeCompile = (shader) => {
    shader.uniforms.uFinishColor = { value: colors }
    shader.uniforms.uFinishSurface = { value: surfaces }
    shader.vertexShader = shader.vertexShader
      .replace(
        '#include <common>',
        `#include <common>
        attribute float aFinish;
        flat varying int vFinish;`,
      )
      .replace(
        '#include <begin_vertex>',
        `#include <begin_vertex>
        vFinish = int(aFinish + 0.5);`,
      )
    shader.fragmentShader = shader.fragmentShader
      .replace(
        '#include <common>',
        `#include <common>
        uniform vec3 uFinishColor[${FINISHES.length}];
        uniform vec2 uFinishSurface[${FINISHES.length}];
        flat varying int vFinish;`,
      )
      .replace(
        '#include <color_fragment>',
        `#include <color_fragment>
        diffuseColor.rgb *= uFinishColor[vFinish];`,
      )
      .replace(
        '#include <roughnessmap_fragment>',
        `#include <roughnessmap_fragment>
        roughnessFactor = uFinishSurface[vFinish].x;`,
      )
      .replace(
        '#include <metalnessmap_fragment>',
        `#include <metalnessmap_fragment>
        metalnessFactor = uFinishSurface[vFinish].y;`,
      )
  }
  material.customProgramCacheKey = () => 'factory-finish'
  return material
}

/** Copies every finish's color and surface into the shared finish material. */
function syncFinishes(kit: FactoryKit) {
  const colors = kit.finish.userData.colors as Color[]
  const surfaces = kit.finish.userData.surfaces as Vector2[]
  FINISHES.forEach((key, index) => {
    const material = kit[key]
    colors[index]!.copy(material.color)
    surfaces[index]!.set(material.roughness, material.metalness)
  })
}

export type NetworkUniforms = {
  /** Seconds since the last product shipped. */
  uSince: { value: number }
  /** World units per second the pulses travel. */
  uSpeed: { value: number }
  uPulse: { value: Color }
  uPower: { value: number }
}

/**
 * A glossy cable whose light pulse runs outward each time a product ships.
 * `aT` is the distance along the cable, `aLine` a per-cable delay (0..1).
 */
function createNetworkCable() {
  const networkUniforms: NetworkUniforms = {
    uSince: { value: 10 },
    uSpeed: { value: 10 },
    uPulse: { value: new Color() },
    uPower: { value: 1 },
  }
  const networkCable = new MeshStandardMaterial({
    roughness: 0.55,
    metalness: 0.05,
  })
  networkCable.onBeforeCompile = (shader) => {
    Object.assign(shader.uniforms, networkUniforms)
    shader.vertexShader = shader.vertexShader
      .replace(
        '#include <common>',
        `#include <common>
        attribute float aT;
        attribute float aLine;
        varying float vT;
        varying float vLine;`,
      )
      .replace(
        '#include <begin_vertex>',
        `#include <begin_vertex>
        vT = aT;
        vLine = aLine;`,
      )
    shader.fragmentShader = shader.fragmentShader
      .replace(
        '#include <common>',
        `#include <common>
        uniform float uSince;
        uniform float uSpeed;
        uniform vec3 uPulse;
        uniform float uPower;
        varying float vT;
        varying float vLine;`,
      )
      .replace(
        '#include <emissivemap_fragment>',
        `#include <emissivemap_fragment>
        {
          float head = (uSince - vLine * 0.3) * uSpeed;
          float lead = vT - head;
          float pulse = exp(-lead * lead / 0.35) * step(0.0, head);
          totalEmissiveRadiance += uPulse * pulse * 1.1 * uPower;
        }`,
      )
  }
  networkCable.customProgramCacheKey = () => 'factory-network-cable'
  return { networkCable, networkUniforms }
}

/** A light curtain material that follows the theme. */
export function createKitBeam(kit: FactoryKit) {
  const material = createBeamMaterial(kit.palette.dark)
  kit.beams.push(material)
  return material
}

/** A self-lit display showing `map`. */
export function createKitDisplay(kit: FactoryKit, map: Texture) {
  const material = new MeshBasicMaterial({ map, toneMapped: false })
  kit.displays.push(material)
  return material
}

/** An emitter (diffuser, LED ring, laser line) that `setLamp` switches on and off. */
export function createKitLamp(kit: FactoryKit) {
  const material = new MeshBasicMaterial({ toneMapped: false })
  kit.lamps.push(material)
  setLamp(material, kit.palette, 0)
  return material
}

const lampOff = new Color()

/** `level` 0 is the unlit diffuser, 1 is full output (white, or `color`). */
export function setLamp(
  material: MeshBasicMaterial,
  palette: FactoryPalette,
  level: number,
  color: Color = palette.light,
) {
  lampOff.set(palette.dark ? '#2b2c31' : '#c9cad1')
  material.color.copy(lampOff).lerp(color, Math.min(level, 1))
}

export function applyKitPalette(kit: FactoryKit, palette: FactoryPalette) {
  kit.palette = palette
  kit.enclosure.color.copy(palette.enclosure)
  kit.bed.color.copy(palette.bed)
  kit.aluminum.color.copy(palette.aluminum)
  kit.steel.color.copy(palette.steel)
  kit.graphite.color.copy(palette.graphite)
  kit.matte.color.copy(palette.matte)
  kit.pad.color.copy(palette.matte)
  kit.glass.color.copy(palette.glass)
  kit.cable.color.copy(palette.cable)
  kit.cable.emissive.copy(palette.tones.pink)
  kit.networkCable.color.copy(palette.cable)
  kit.networkCable.emissive.copy(palette.tones.pink)
  kit.networkCable.emissiveIntensity = palette.dark ? 0.04 : 0
  kit.networkUniforms.uPulse.value.copy(palette.tones.pink)
  kit.carrier.color.copy(palette.carrier)
  kit.device.color.set(0xffffff)
  kit.accent.color.copy(palette.tones.pink)
  kit.logo.color.copy(palette.tones.pink)
  kit.logo.emissive.copy(palette.tones.pink)
  kit.logo.emissiveIntensity = palette.dark ? 0.3 : 0.08
  kit.track.color.set(0xffffff)
  kit.led.color.set(0xffffff)
  for (const lamp of kit.lamps) setLamp(lamp, palette, 0)
  syncFinishes(kit)

  const floor = kit.floor.uniforms
  floor.uBackground!.value.copy(palette.background)
  floor.uOcclusion!.value = palette.occlusion
  floor.uPool!.value = palette.pool
  floor.uReflectivity!.value = palette.reflectivity

  kit.screenUniforms.uGlass.value.copy(palette.screenGlass)
  kit.screenUniforms.uBezel.value.copy(palette.bezel)
  kit.screenUniforms.uBrightness.value = palette.dark ? 0.94 : 0.98
  kit.screenUniforms.uSheen.value = palette.dark ? 0.045 : 0.09
  kit.screenUniforms.uLevel.value.copy(palette.tones.pink)

  const blending = glowBlending(palette.dark)
  for (const material of [kit.glow, ...kit.beams]) {
    material.blending = blending
    material.uniforms.uLight!.value = palette.dark ? 0 : 1
    material.needsUpdate = true
  }

  kit.blob.uniforms.uStrength!.value = palette.dark ? 0.5 : 0.28
}

export function disposeKit(kit: FactoryKit) {
  const materials = [
    kit.finish,
    kit.enclosure,
    kit.bed,
    kit.aluminum,
    kit.steel,
    kit.graphite,
    kit.matte,
    kit.pad,
    kit.glass,
    kit.cable,
    kit.networkCable,
    kit.carrier,
    kit.device,
    kit.accent,
    kit.logo,
    kit.track,
    kit.led,
    kit.nameplate,
    kit.powerLine,
    kit.floor,
    kit.screen,
    kit.glow,
    kit.blob,
    ...kit.lamps,
    ...kit.displays,
    ...kit.beams,
  ]
  for (const material of materials) material.dispose()
}
