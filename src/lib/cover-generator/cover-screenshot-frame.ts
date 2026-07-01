import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  getCoverFrameWidthPx,
  COVER_SCREENSHOT_FRAME_HEIGHT,
  COVER_SCREENSHOT_FRAME_MIN_HEIGHT_PX,
  resolveCoverScreenshotFrameHeightExportPx,
  type CoverCanvasSize,
} from '@/lib/cover-generator/cover-frame-width'
import {
  coverExportYToArtboardY,
  getCoverContentLayoutTransform,
} from '@/lib/cover-generator/cover-layout-scale'
import { getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'
import {
  stripCoverTitleSuffix,
  wrapTextLines,
} from '@/lib/cover-generator/text-utils'
import type { CoverRenderData, CoverScreenshotData } from '@/lib/cover-generator/types'

/** Title block metrics shared by export and preview. */
export const COVER_SCREENSHOT_TITLE = {
  y: 72,
  fontSize: 56,
  lineHeight: 64,
  subtitleFontSize: 24,
  subtitleGap: 20,
  /** Space between title block and browser frame top. */
  frameGap: 48,
  maxCharsPerLine: 42,
  maxLines: 1,
} as const

/** Matches homepage hero browser chrome (`src/routes/home.tsx`). */
export const COVER_HERO_SCREENSHOT_FRAME = {
  outerRadius: 28,
  defaultInnerRadius: 8,
  borderWidth: 2,
  /** Shell horizontal padding (`px-4`). */
  paddingX: 16,
  /** Shell top padding (`pt-1`). */
  paddingTop: 4,
  /** Chrome bar height (`h-10`). */
  chromeHeight: 40,
  /** Traffic-light dot size (`size-2.5`). */
  chromeDotSize: 10,
  /** Gap between dots (`gap-1.5`). */
  chromeDotGap: 6,
  /** Dot row inset from padded shell edge (`ms-2`). */
  chromeDotMarginLeft: 8,
  /** Bottom inset when the shell is fully closed (diagram / floating frames). */
  paddingBottom: 16,
  imageOpacity: 0.95,
} as const

export function getCoverScreenshotFrameRadii(closed: boolean): {
  topLeft: number
  topRight: number
  bottomLeft: number
  bottomRight: number
} {
  const { outerRadius, defaultInnerRadius, paddingX } = COVER_HERO_SCREENSHOT_FRAME
  const bottomRadius = closed
    ? Math.max(defaultInnerRadius, outerRadius - paddingX)
    : 0

  return {
    topLeft: defaultInnerRadius,
    topRight: defaultInnerRadius,
    bottomLeft: bottomRadius,
    bottomRight: bottomRadius,
  }
}

export type CoverScreenshotGlassColors = {
  shellFill: string
  shellBorder: string
  chromeDotFill: string
}

export type CoverScreenshotFrameLayout = {
  shell: { x: number; y: number; width: number; height: number }
  screenshot: { x: number; y: number; width: number; height: number }
  outerRadius: number
  innerRadius: number
  borderWidth: number
  paddingX: number
  paddingTop: number
  chromeHeight: number
}

/** Glass tint/border aligned with homepage hero browser frame. */
export function getCoverScreenshotGlassColors(
  themeId: CoverThemeId,
): CoverScreenshotGlassColors {
  const theme = getCoverTheme(themeId)

  if (theme.family === 'dark') {
    return {
      shellFill: 'rgba(63, 67, 79, 0.1)',
      shellBorder: 'rgba(63, 67, 79, 0.3)',
      chromeDotFill: 'rgba(161, 161, 170, 0.3)',
    }
  }

  return {
    shellFill: 'rgba(113, 113, 122, 0.035)',
    shellBorder: 'rgba(113, 113, 122, 0.08)',
    chromeDotFill: 'rgba(113, 113, 122, 0.3)',
  }
}

/** CSS color-mix values for live preview (matches homepage Tailwind classes). */
export function getCoverScreenshotGlassPreviewStyles(
  themeId: CoverThemeId,
): CoverScreenshotGlassColors {
  const theme = getCoverTheme(themeId)

  if (theme.family === 'dark') {
    return {
      shellFill: `color-mix(in oklch, ${theme.muted} 10%, transparent)`,
      shellBorder: `color-mix(in oklch, ${theme.muted} 30%, transparent)`,
      chromeDotFill: `color-mix(in oklch, ${theme.mutedForeground} 30%, transparent)`,
    }
  }

  return {
    shellFill: `color-mix(in oklch, ${theme.mutedForeground} 3.5%, transparent)`,
    shellBorder: `color-mix(in oklch, ${theme.mutedForeground} 8%, transparent)`,
    chromeDotFill: `color-mix(in oklch, ${theme.mutedForeground} 30%, transparent)`,
  }
}

export function buildCoverScreenshotFrameShellLayout(
  frameX: number,
  frameY: number,
  frameWidth: number,
  frameHeightPx: number,
  options?: { closed?: boolean },
): CoverScreenshotFrameLayout {
  const { paddingX, paddingTop, chromeHeight, outerRadius, defaultInnerRadius, paddingBottom } =
    COVER_HERO_SCREENSHOT_FRAME
  const closed = options?.closed ?? false
  const bottomInset = closed ? paddingBottom : 0

  const screenshotWidth = Math.max(1, frameWidth - paddingX * 2)
  const screenshotHeight = Math.max(
    1,
    frameHeightPx - paddingTop - chromeHeight - bottomInset,
  )

  return {
    shell: {
      x: frameX,
      y: frameY,
      width: frameWidth,
      height: frameHeightPx,
    },
    screenshot: {
      x: frameX + paddingX,
      y: frameY + paddingTop + chromeHeight,
      width: screenshotWidth,
      height: screenshotHeight,
    },
    outerRadius,
    innerRadius: defaultInnerRadius,
    borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
    paddingX,
    paddingTop,
    chromeHeight,
  }
}

export function getCoverScreenshotFrameLayout(
  data: Pick<CoverRenderData, 'frameWidthPercent' | 'width' | 'height'>,
  frameX: number,
  frameY: number,
  frameHeightPx: number,
): CoverScreenshotFrameLayout {
  const canvas: CoverCanvasSize = { width: data.width, height: data.height }
  const frameWidth = getCoverFrameWidthPx(data.frameWidthPercent, canvas)
  return buildCoverScreenshotFrameShellLayout(
    frameX,
    frameY,
    frameWidth,
    frameHeightPx,
  )
}

function buildTopRoundedRectPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height)
  return [
    `M ${x + r} ${y}`,
    `L ${x + width - r} ${y}`,
    `Q ${x + width} ${y} ${x + width} ${y + r}`,
    `L ${x + width} ${y + height}`,
    `L ${x} ${y + height}`,
    `L ${x} ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
    'Z',
  ].join(' ')
}

function buildTopSidesBorderPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height)
  return [
    `M ${x + r} ${y}`,
    `L ${x + width - r} ${y}`,
    `Q ${x + width} ${y} ${x + width} ${y + r}`,
    `L ${x + width} ${y + height}`,
    `M ${x} ${y + height}`,
    `L ${x} ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
  ].join(' ')
}

function buildTopRoundedClipPath(
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height)
  return [
    `M ${r} 0`,
    `L ${width - r} 0`,
    `Q ${width} 0 ${width} ${r}`,
    `L ${width} ${height}`,
    `L 0 ${height}`,
    `L 0 ${r}`,
    `Q 0 0 ${r} 0`,
    'Z',
  ].join(' ')
}

export function buildCoverScreenshotChromeSvg(
  layout: CoverScreenshotFrameLayout,
  glass: CoverScreenshotGlassColors,
): string {
  const { shell, chromeHeight, paddingTop, paddingX } = layout
  const { chromeDotSize, chromeDotGap, chromeDotMarginLeft } =
    COVER_HERO_SCREENSHOT_FRAME
  const dotY = shell.y + paddingTop + chromeHeight / 2
  const dotStartX = shell.x + paddingX + chromeDotMarginLeft + chromeDotSize / 2

  return Array.from({ length: 3 }, (_, index) => {
    const cx = dotStartX + index * (chromeDotSize + chromeDotGap)
    return `<circle cx="${cx}" cy="${dotY}" r="${chromeDotSize / 2}" fill="${glass.chromeDotFill}" />`
  }).join('\n    ')
}

export function buildCoverScreenshotGlassFrameSvg(
  layout: CoverScreenshotFrameLayout,
  glass: CoverScreenshotGlassColors,
): string {
  const { shell, outerRadius, borderWidth } = layout
  const { x, y, width, height } = shell
  const shellPath = buildTopRoundedRectPath(x, y, width, height, outerRadius)
  const borderPath = buildTopSidesBorderPath(x, y, width, height, outerRadius)

  return `
    <path d="${shellPath}" fill="${glass.shellFill}" />
    <path d="${borderPath}" fill="none" stroke="${glass.shellBorder}" stroke-width="${borderWidth}" stroke-linecap="round" stroke-linejoin="round" />
  `
}

export function buildCoverScreenshotClipSvg(
  width: number,
  height: number,
  innerRadius: number,
): string {
  return `<svg width="${width}" height="${height}" xmlns="http://www.w3.org/2000/svg"><path d="${buildTopRoundedClipPath(width, height, innerRadius)}" fill="#fff"/></svg>`
}

function getCoverScreenshotTitleLines(
  data: Pick<CoverScreenshotData, 'title'>,
): string[] {
  return data.title
    ? wrapTextLines(
        stripCoverTitleSuffix(data.title),
        COVER_SCREENSHOT_TITLE.maxCharsPerLine,
        COVER_SCREENSHOT_TITLE.maxLines,
      )
    : []
}

type CoverScreenshotTitleLayout = {
  titleLines: string[]
  titleYArtboard: number
  subtitleYArtboard: number
  titleBlockBottomExport: number
  layoutTransform: ReturnType<typeof getCoverContentLayoutTransform>
}

function getCoverScreenshotTitleLayout(
  data: Pick<CoverScreenshotData, 'title' | 'subtitle'>,
  canvas: CoverCanvasSize,
): CoverScreenshotTitleLayout {
  const titleLines = getCoverScreenshotTitleLines(data)
  const layoutTransform = getCoverContentLayoutTransform(
    canvas.width,
    canvas.height,
    'bottom',
  )
  const { scale } = layoutTransform
  const { y, lineHeight, subtitleGap, subtitleFontSize, frameGap } =
    COVER_SCREENSHOT_TITLE

  const titleYExport = y * scale
  const lineHeightExport = lineHeight * scale
  const subtitleGapExport = subtitleGap * scale
  const subtitleFontSizeExport = subtitleFontSize * scale
  const frameGapExport = frameGap * scale

  const subtitleYExport =
    titleLines.length > 0
      ? titleYExport + titleLines.length * lineHeightExport + subtitleGapExport
      : titleYExport + subtitleGapExport

  const contentBottomExport = data.subtitle
    ? subtitleYExport + subtitleFontSizeExport
    : titleLines.length > 0
      ? titleYExport + titleLines.length * lineHeightExport
      : titleYExport

  const titleBlockBottomExport =
    contentBottomExport > titleYExport
      ? contentBottomExport + frameGapExport
      : titleYExport + frameGapExport

  return {
    titleLines,
    titleYArtboard: coverExportYToArtboardY(titleYExport, layoutTransform),
    subtitleYArtboard: coverExportYToArtboardY(subtitleYExport, layoutTransform),
    titleBlockBottomExport,
    layoutTransform,
  }
}

export function getCoverScreenshotSceneLayout(
  data: Extract<CoverRenderData, { template: 'screenshot' }>,
) {
  const canvas: CoverCanvasSize = { width: data.width, height: data.height }
  const {
    titleLines,
    titleYArtboard,
    subtitleYArtboard,
    titleBlockBottomExport,
    layoutTransform,
  } = getCoverScreenshotTitleLayout(data, canvas)
  const { scale } = layoutTransform

  const frameWidth = getCoverFrameWidthPx(data.frameWidthPercent, canvas)
  const frameX = Math.round((COVER_WIDTH - frameWidth) / 2)
  const maxFrameHeightExport = Math.max(
    0,
    canvas.height - titleBlockBottomExport,
  )
  const minFrameHeightExport = COVER_SCREENSHOT_FRAME_MIN_HEIGHT_PX * scale
  const frameHeightExport = resolveCoverScreenshotFrameHeightExportPx(
    data.frameHeightPercent ?? COVER_SCREENSHOT_FRAME_HEIGHT.defaultPercent,
    maxFrameHeightExport,
    minFrameHeightExport,
  )
  const frameHeightPx = Math.max(1, Math.round(frameHeightExport / scale))

  // Browser shell sits flush on the export bottom edge.
  const frameY = COVER_HEIGHT - frameHeightPx

  const layout = getCoverScreenshotFrameLayout(
    data,
    frameX,
    frameY,
    frameHeightPx,
  )

  return {
    titleLines,
    layout,
    titleY: titleYArtboard,
    subtitleY: subtitleYArtboard,
    frameHeight: frameHeightPx,
  }
}
