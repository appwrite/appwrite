import {
  buildCoverBackgroundGridSvgPattern,
  getCoverBackgroundGridCssStyle,
} from '@/lib/cover-generator/cover-background-grid'
import {
  getCoverBrandLightRgb,
  type CoverSoftLightOpacity,
  type CoverSoftLightTone,
} from '@/lib/cover-generator/cover-brand-lights'
import type { CoverSoftLightVariant } from '@/lib/cover-generator/themes'
import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  buildCoverMilestoneConfettiSvg,
  isCoverMilestoneTemplate,
} from '@/lib/cover-generator/milestone/confetti'
import {
  getCoverBrandThemeForSvgExport,
  getCoverTheme,
  type CoverThemeId,
} from '@/lib/cover-generator/themes'

export type CoverBackgroundContext = {
  templateId?: CoverTemplateId
}

export type CoverSoftLightLayout = {
  left?: number
  right?: number
  centerX?: number
  anchor?: 'top' | 'bottom'
  verticalOffset?: number
  bottomOverflow?: number
  /** Fraction of canvas width covered by the light ellipse. */
  widthRatio: number
  /** Fraction of canvas height covered by the light ellipse. */
  heightRatio: number
  tone: CoverSoftLightTone
  midStop: number
  fadeStop: number
}

/** Hero soft-light layout (homepage hero lg sizing, scaled for OG canvas). */
export const COVER_HERO_SOFT_LIGHT_LAYOUT = {
  left: {
    left: -0.28,
    anchor: 'bottom',
    bottomOverflow: 0.22,
    widthRatio: 1000 / 1200,
    heightRatio: 560 / 630,
    tone: 'pink',
    midStop: 38,
    fadeStop: 72,
  },
  right: {
    right: -0.3,
    anchor: 'bottom',
    bottomOverflow: 0.24,
    widthRatio: 1020 / 1200,
    heightRatio: 580 / 630,
    tone: 'purple',
    midStop: 40,
    fadeStop: 74,
  },
} satisfies Record<'left' | 'right', CoverSoftLightLayout>

/** Pink from the top for the single integration-icon template only. */
const COVER_INTEGRATION_ICON_SOFT_LIGHT_LAYOUT = {
  top: {
    left: -0.44,
    anchor: 'top',
    verticalOffset: -0.36,
    widthRatio: 1360 / 1200,
    heightRatio: 780 / 630,
    tone: 'pink',
    midStop: 40,
    fadeStop: 76,
  },
  bottom: {
    right: -0.4,
    anchor: 'bottom',
    bottomOverflow: 0.32,
    widthRatio: 1240 / 1200,
    heightRatio: 700 / 630,
    tone: 'purple',
    midStop: 40,
    fadeStop: 74,
  },
} satisfies Record<'top' | 'bottom', CoverSoftLightLayout>

const COVER_AURORA_SOFT_LIGHT_LAYOUT = {
  left: {
    left: -0.18,
    anchor: 'top',
    verticalOffset: -0.06,
    widthRatio: 820 / 1200,
    heightRatio: 480 / 630,
    tone: 'teal',
    midStop: 34,
    fadeStop: 68,
  },
  right: {
    right: -0.18,
    anchor: 'top',
    verticalOffset: -0.06,
    widthRatio: 820 / 1200,
    heightRatio: 480 / 630,
    tone: 'purple',
    midStop: 36,
    fadeStop: 70,
  },
} satisfies Record<'left' | 'right', CoverSoftLightLayout>

const COVER_BEAM_SOFT_LIGHT_LAYOUT = {
  center: {
    centerX: 0.5,
    anchor: 'top',
    verticalOffset: -0.12,
    widthRatio: 1100 / 1200,
    heightRatio: 500 / 630,
    tone: 'pink',
    midStop: 28,
    fadeStop: 62,
  },
  accent: {
    centerX: 0.58,
    anchor: 'top',
    verticalOffset: -0.08,
    widthRatio: 680 / 1200,
    heightRatio: 380 / 630,
    tone: 'orange',
    midStop: 32,
    fadeStop: 66,
  },
} satisfies Record<'center' | 'accent', CoverSoftLightLayout>

/** Moody ambient teal wash from the lower-left corner. */
const COVER_GLOW_SOFT_LIGHT_LAYOUT = {
  bottomLeft: {
    left: -0.42,
    anchor: 'bottom',
    bottomOverflow: 0.34,
    widthRatio: 1280 / 1200,
    heightRatio: 720 / 630,
    tone: 'teal',
    midStop: 36,
    fadeStop: 76,
  },
} satisfies Record<'bottomLeft', CoverSoftLightLayout>

export const COVER_SOFT_LIGHT_VARIANTS: Record<
  CoverSoftLightVariant,
  Record<string, CoverSoftLightLayout>
> = {
  hero: COVER_HERO_SOFT_LIGHT_LAYOUT,
  aurora: COVER_AURORA_SOFT_LIGHT_LAYOUT,
  beam: COVER_BEAM_SOFT_LIGHT_LAYOUT,
  glow: COVER_GLOW_SOFT_LIGHT_LAYOUT,
}

/** Reference canvas the light layouts were tuned against (Open Graph). */
export const COVER_SOFT_LIGHT_REFERENCE = {
  width: 1200,
  height: 630,
} as const

export type CoverSoftLightRect = {
  x: number
  y: number
  width: number
  height: number
}

function rgba([r, g, b]: [number, number, number], alpha: number): string {
  return `rgba(${r},${g},${b},${alpha})`
}

export function getCoverSoftLightGradient(
  softLights: CoverSoftLightOpacity,
  layout: CoverSoftLightLayout,
): string {
  const rgb = getCoverBrandLightRgb(layout.tone)
  const { strong, mid } = softLights[layout.tone]

  return `radial-gradient(ellipse at center, ${rgba(rgb, strong)} 0%, ${rgba(rgb, mid)} ${layout.midStop}%, transparent ${layout.fadeStop}%)`
}

function getCoverSoftLightReferenceHeight(layout: CoverSoftLightLayout): number {
  return layout.heightRatio * COVER_SOFT_LIGHT_REFERENCE.height
}

/** Width tracks canvas width; height keeps the same visible wash depth as the OG reference. */
function getCoverSoftLightSize(
  layout: CoverSoftLightLayout,
  canvasWidth: number,
  canvasHeight: number,
): { width: number; height: number } {
  const { height: refCanvasHeight } = COVER_SOFT_LIGHT_REFERENCE
  const width = layout.widthRatio * canvasWidth
  const refLightHeight = getCoverSoftLightReferenceHeight(layout)

  if (layout.anchor === 'bottom') {
    const refVisibleHeight =
      refLightHeight - (layout.bottomOverflow ?? 0) * refCanvasHeight
    const visibleHeightRatio = refVisibleHeight / refCanvasHeight
    const visibleHeight = visibleHeightRatio * canvasHeight
    const height = visibleHeight + (layout.bottomOverflow ?? 0) * canvasHeight
    return { width, height }
  }

  const refOffset = (layout.verticalOffset ?? 0) * refCanvasHeight
  const refVisibleDepth = refLightHeight + Math.max(0, -refOffset)
  const visibleDepthRatio = refVisibleDepth / refCanvasHeight
  const height =
    visibleDepthRatio * canvasHeight + (layout.verticalOffset ?? 0) * canvasHeight

  return { width, height }
}

/** Visible wash depth on canvas (px), clamped to canvas bounds. */
export function getCoverSoftLightVisibleDepth(
  layout: CoverSoftLightLayout,
  canvasWidth: number,
  canvasHeight: number,
): number {
  const { height } = getCoverSoftLightSize(layout, canvasWidth, canvasHeight)

  if (layout.anchor === 'bottom') {
    const y = canvasHeight - height + (layout.bottomOverflow ?? 0) * canvasHeight
    return Math.min(canvasHeight, Math.max(0, canvasHeight - y))
  }

  const y = (layout.verticalOffset ?? 0) * canvasHeight
  return Math.min(canvasHeight, Math.max(0, y + height))
}

export function getCoverSoftLightRect(
  layout: CoverSoftLightLayout,
  canvasWidth: number,
  canvasHeight: number,
): CoverSoftLightRect {
  const { width, height } = getCoverSoftLightSize(layout, canvasWidth, canvasHeight)

  let x: number
  if (layout.centerX != null) {
    x = layout.centerX * canvasWidth - width / 2
  } else if (layout.left != null) {
    x = layout.left * canvasWidth
  } else {
    x = canvasWidth - layout.right! * canvasWidth - width
  }

  const y =
    layout.anchor === 'top'
      ? (layout.verticalOffset ?? 0) * canvasHeight
      : canvasHeight - height + (layout.bottomOverflow ?? 0) * canvasHeight

  return { x, y, width, height }
}

export function getCoverSoftLightLayoutsForTheme(
  themeId: CoverThemeId,
  context?: CoverBackgroundContext,
): Array<[string, CoverSoftLightLayout]> {
  const theme = getCoverTheme(themeId)

  if (
    context?.templateId === 'integration-icon' &&
    theme.softLightVariant !== 'glow'
  ) {
    return Object.entries(COVER_INTEGRATION_ICON_SOFT_LIGHT_LAYOUT)
  }

  const layouts = COVER_SOFT_LIGHT_VARIANTS[theme.softLightVariant]

  if (theme.softLightVariant === 'hero') {
    if (theme.backgroundGrid === 'grid') {
      return applyHeroToneOverrides(layouts, { left: 'purple', right: 'teal' })
    }
  }

  return Object.entries(layouts)
}

function applyHeroToneOverrides(
  layouts: Record<string, CoverSoftLightLayout>,
  tones: Partial<Record<'left' | 'right', CoverSoftLightTone>>,
): Array<[string, CoverSoftLightLayout]> {
  return Object.entries(layouts).map(([key, layout]) => {
    const toneOverride = tones[key as keyof typeof tones]
    if (!toneOverride) return [key, layout] as const
    return [key, { ...layout, tone: toneOverride }] as const
  })
}

export function buildCoverSoftLightSvgDefs(
  themeId: CoverThemeId,
  canvasWidth: number,
  canvasHeight: number,
  context?: CoverBackgroundContext,
): { defs: string; lightRects: string } {
  const { softLights } = getCoverTheme(themeId)
  const defs: string[] = []
  const lightRects: string[] = []

  for (const [side, layout] of getCoverSoftLightLayoutsForTheme(themeId, context)) {
    const rect = getCoverSoftLightRect(layout, canvasWidth, canvasHeight)
    const rgb = getCoverBrandLightRgb(layout.tone)
    const { strong, mid } = softLights[layout.tone]
    const gradientId = `cover-light-${side}`

    defs.push(`
      <radialGradient id="${gradientId}" gradientUnits="objectBoundingBox" cx="50%" cy="50%" r="50%">
        <stop offset="0%" stop-color="${rgba(rgb, strong)}" />
        <stop offset="${layout.midStop}%" stop-color="${rgba(rgb, mid)}" />
        <stop offset="${layout.fadeStop}%" stop-color="${rgba(rgb, 0)}" />
      </radialGradient>
    `)

    lightRects.push(
      `<rect x="${rect.x}" y="${rect.y}" width="${rect.width}" height="${rect.height}" fill="url(#${gradientId})" />`,
    )
  }

  return {
    defs: defs.join('\n'),
    lightRects: lightRects.join('\n'),
  }
}

/**
 * Background stack: solid fill → pattern grid → soft lights (lights on top).
 */
export function buildCoverBrandBackgroundSvgLayers(
  themeId: CoverThemeId,
  width: number,
  height: number,
  context?: CoverBackgroundContext,
): { defs: string; layers: string } {
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const { backgroundGrid, family } = getCoverTheme(themeId)
  const gridPattern =
    backgroundGrid === 'none'
      ? ''
      : buildCoverBackgroundGridSvgPattern(themeId, backgroundGrid)
  const gridLayer =
    backgroundGrid === 'none'
      ? ''
      : `<rect width="${width}" height="${height}" fill="url(#cover-background-grid)" />`
  const { defs: lightDefs, lightRects } = buildCoverSoftLightSvgDefs(
    themeId,
    width,
    height,
    context,
  )
  const confettiLayer = isCoverMilestoneTemplate(context?.templateId)
    ? buildCoverMilestoneConfettiSvg(width, height, family)
    : ''

  const backgroundDefs = [gridPattern, lightDefs]
    .filter(Boolean)
    .join('\n')

  return {
    defs: `${backgroundDefs}\n<clipPath id="cover-soft-lights-clip"><rect width="${width}" height="${height}" /></clipPath>`,
    layers: `
      <rect width="${width}" height="${height}" fill="${brand.background}" />
      ${gridLayer}
      <g clip-path="url(#cover-soft-lights-clip)">
        ${lightRects}
        ${confettiLayer}
      </g>
    `.trim(),
  }
}

export function getCoverBackgroundGridStyleForTheme(themeId: CoverThemeId): {
  backgroundImage: string
  backgroundSize: string
  backgroundPosition?: string
} | null {
  const { backgroundGrid } = getCoverTheme(themeId)
  return getCoverBackgroundGridCssStyle(themeId, backgroundGrid)
}

export function getCoverSoftLightCssGradient(
  themeId: CoverThemeId,
  layout: CoverSoftLightLayout,
): string {
  return getCoverSoftLightGradient(getCoverTheme(themeId).softLights, layout)
}

export {
  buildCoverBackgroundGridSvgPattern,
  buildCoverDotGridSvgPattern,
  getCoverBackgroundGridCssStyle,
  getCoverDottedBackgroundStyle,
} from '@/lib/cover-generator/cover-background-grid'
