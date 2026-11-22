import { Color } from 'three'
import type { FactoryTone } from './layout'

/**
 * Light and dark looks for the factory. The machine is neutral (painted
 * enclosures, anodized aluminum, steel, black glass); the only color is the
 * brand pink, in small anodized parts and indicator light. Surfaces follow
 * the Console neutrals so the scene sits on the page instead of in a box.
 */
export type FactoryPalette = {
  dark: boolean
  /** Page background, read from `--background` so the floor blends in. */
  background: Color
  /** Strength of the ambient occlusion painted into the floor. */
  occlusion: number
  /** Opacity of the cast shadows the floor catches. */
  shadowOpacity: number
  /** Soft pool of light on the floor around the line. */
  pool: number
  /** How much of the machine the polished floor mirrors. */
  reflectivity: number
  /** Painted column housings. */
  enclosure: Color
  /** The bed everything stands on. */
  bed: Color
  aluminum: Color
  steel: Color
  /** Dark anodized tool housings. */
  graphite: Color
  /** Rubber, way covers, shadow gaps. */
  matte: Color
  /** Black glass (the rack's door). */
  glass: Color
  /** The panel the service name is printed on. */
  fascia: Color
  /** Power cables from the Appwrite rack: a deep, satin brand pink. */
  cable: Color
  carrier: Color
  /** Linear motor channel between the rails. */
  track: string
  trackLine: string
  /** Print on the glass fascias. */
  ink: string
  /** A screen that is still off. */
  screenGlass: Color
  bezel: Color
  tones: Record<FactoryTone, Color>
  toneHex: Record<FactoryTone, string>
  /** Indicator and work light. */
  light: Color
  finishes: Color[]
  /** The desktop's colors, seen on its chin. */
  desktopFinishes: Color[]
  hemiSky: Color
  hemiGround: Color
  hemiIntensity: number
  keyIntensity: number
  rimIntensity: number
  envIntensity: number
  glowBoost: number
  /** Extra alpha for light curtains, which read weaker on light pages. */
  beamBoost: number
}

const LIGHT_TONES: Record<FactoryTone, string> = {
  pink: '#fd366e',
  purple: '#7c67fe',
  mint: '#5fcfca',
  orange: '#fe9567',
}

const DARK_TONES: Record<FactoryTone, string> = {
  pink: '#fd366e',
  purple: '#9b8aff',
  mint: '#85dbd8',
  orange: '#ffb088',
}

/** Device finishes: silver, space grey, white, midnight, starlight, graphite, natural. */
const FINISHES = [
  '#d9dbe1',
  '#63646c',
  '#f1f1f4',
  '#343a4b',
  '#e4ddce',
  '#45464d',
  '#c5c8cf',
]

/** Desktop colors as they read on the chin: blue, green, pink, silver, yellow, orange, purple. */
const DESKTOP_FINISHES = [
  '#a9c2dc',
  '#b3cbae',
  '#e6b5bd',
  '#dcdde1',
  '#ead595',
  '#efb894',
  '#c2b7dc',
]

function toneColors(hex: Record<FactoryTone, string>) {
  return {
    pink: new Color(hex.pink),
    purple: new Color(hex.purple),
    mint: new Color(hex.mint),
    orange: new Color(hex.orange),
  }
}

export function createFactoryPalette(
  dark: boolean,
  background: Color,
): FactoryPalette {
  const finishes = FINISHES.map((hex) => new Color(hex))
  const desktopFinishes = DESKTOP_FINISHES.map((hex) => new Color(hex))
  if (dark) {
    return {
      dark,
      background,
      occlusion: 0.72,
      shadowOpacity: 0.55,
      pool: 0.03,
      reflectivity: 0.36,
      /** Space grey. */
      enclosure: new Color('#a9adb7'),
      bed: new Color('#5c6069'),
      aluminum: new Color('#c3c6ce'),
      steel: new Color('#e2e5eb'),
      graphite: new Color('#33343a'),
      matte: new Color('#0d0d0f'),
      glass: new Color('#3a3b41'),
      fascia: new Color('#7d818a'),
      cable: new Color('#a83a5c'),
      carrier: new Color('#5b5e67'),
      track: '#121214',
      trackLine: '#232328',
      ink: '#e9eaf0',
      screenGlass: new Color('#08080a'),
      bezel: new Color('#040405'),
      tones: toneColors(DARK_TONES),
      toneHex: DARK_TONES,
      light: new Color('#ffffff'),
      finishes,
      desktopFinishes,
      hemiSky: new Color('#c9cee0'),
      hemiGround: new Color('#121216'),
      hemiIntensity: 1.6,
      keyIntensity: 5,
      rimIntensity: 0.5,
      envIntensity: 1,
      glowBoost: 1,
      beamBoost: 1,
    }
  }

  return {
    dark,
    background,
    occlusion: 0.5,
    shadowOpacity: 0.2,
    pool: 0,
    reflectivity: 0.16,
    /** Silver. */
    enclosure: new Color('#dcdfe4'),
    bed: new Color('#c7cad1'),
    aluminum: new Color('#cfd2d9'),
    steel: new Color('#e9ebf0'),
    graphite: new Color('#34353b'),
    matte: new Color('#1b1b1e'),
    glass: new Color('#1d1d21'),
    fascia: new Color('#c4c7ce'),
    cable: new Color('#d2486f'),
    carrier: new Color('#a9adb6'),
    track: '#2a2a2f',
    trackLine: '#3c3c43',
    ink: '#3b3c44',
    screenGlass: new Color('#101013'),
    bezel: new Color('#09090b'),
    tones: toneColors(LIGHT_TONES),
    toneHex: LIGHT_TONES,
    light: new Color('#ffffff'),
    finishes,
    desktopFinishes,
    hemiSky: new Color('#ffffff'),
    hemiGround: new Color('#c4c5cf'),
    hemiIntensity: 1.3,
    keyIntensity: 3,
    rimIntensity: 0.3,
    envIntensity: 0.75,
    glowBoost: 0.9,
    beamBoost: 1.3,
  }
}

/**
 * Resolves any CSS color (oklch, hex, var-derived) to sRGB by painting one
 * pixel, because `Color` cannot parse modern color functions.
 */
export function resolveCssColor(value: string, fallback: string): Color {
  if (typeof document === 'undefined') return new Color(fallback)
  try {
    const canvas = document.createElement('canvas')
    canvas.width = 1
    canvas.height = 1
    const context = canvas.getContext('2d', { willReadFrequently: true })
    if (!context) return new Color(fallback)
    context.fillStyle = fallback
    context.fillStyle = value
    context.fillRect(0, 0, 1, 1)
    const [r = 0, g = 0, b = 0] = context.getImageData(0, 0, 1, 1).data
    return new Color().setRGB(r / 255, g / 255, b / 255, 'srgb')
  } catch {
    return new Color(fallback)
  }
}

export function readPageBackground(dark: boolean): Color {
  const fallback = dark ? '#19191c' : '#ffffff'
  if (typeof document === 'undefined') return new Color(fallback)
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue('--background')
    .trim()
  return value ? resolveCssColor(value, fallback) : new Color(fallback)
}
