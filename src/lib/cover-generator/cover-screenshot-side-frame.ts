import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  getCoverFrameWidthPx,
  COVER_SCREENSHOT_FRAME_HEIGHT,
  clampCoverFrameHeightPercent,
  type CoverCanvasSize,
} from '@/lib/cover-generator/cover-frame-width'
import {
  coverExportYToArtboardY,
  getCoverContentLayoutTransform,
} from '@/lib/cover-generator/cover-layout-scale'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  type CoverScreenshotFrameLayout,
  type CoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO } from '@/lib/perspective-screenshot-card/constants'
import {
  stripCoverTitleSuffix,
  wrapTextLines,
} from '@/lib/cover-generator/text-utils'
import type { CoverRenderData, CoverScreenshotData } from '@/lib/cover-generator/types'

export const COVER_SCREENSHOT_SIDE_TITLE = {
  x: 72,
  fontSize: 56,
  lineHeight: 64,
  subtitleFontSize: 24,
  subtitleGap: 16,
  frameGap: 32,
  maxCharsPerLine: 22,
  maxLines: 3,
} as const

export const COVER_SCREENSHOT_SIDE_SHELL = {
  verticalInset: 24,
  rightOverflowPercent: 22,
  /** Shifts the browser frame left while keeping title clearance. */
  leftOffset: 88,
  aspectRatio: PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO,
} as const

function estimateCoverTextWidth(text: string, fontSize: number): number {
  return Math.ceil(text.length * fontSize * 0.52)
}

export function getCoverScreenshotSideMinFrameX(
  titleLines: string[],
  subtitle: string | undefined,
): number {
  const { x, fontSize, subtitleFontSize, frameGap } = COVER_SCREENSHOT_SIDE_TITLE
  const titleWidth = titleLines.reduce(
    (max, line) => Math.max(max, estimateCoverTextWidth(line, fontSize)),
    0,
  )
  const subtitleWidth = subtitle
    ? estimateCoverTextWidth(subtitle, subtitleFontSize)
    : 0

  return x + Math.max(titleWidth, subtitleWidth) + frameGap
}

function buildLeftRoundedRectPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height / 2)
  return [
    `M ${x + width} ${y}`,
    `L ${x + r} ${y}`,
    `Q ${x} ${y} ${x} ${y + r}`,
    `L ${x} ${y + height - r}`,
    `Q ${x} ${y + height} ${x + r} ${y + height}`,
    `L ${x + width} ${y + height}`,
    `L ${x + width} ${y}`,
    'Z',
  ].join(' ')
}

function buildLeftSidesBorderPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height / 2)
  return [
    `M ${x + width} ${y}`,
    `L ${x + r} ${y}`,
    `Q ${x} ${y} ${x} ${y + r}`,
    `L ${x} ${y + height - r}`,
    `Q ${x} ${y + height} ${x + r} ${y + height}`,
    `L ${x + width} ${y + height}`,
    `M ${x + width} ${y}`,
  ].join(' ')
}

function buildLeftRoundedClipPath(
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height / 2)
  return [
    `M ${width} 0`,
    `L ${r} 0`,
    `Q 0 0 0 ${r}`,
    `L 0 ${height - r}`,
    `Q 0 ${height} ${r} ${height}`,
    `L ${width} ${height}`,
    `L ${width} 0`,
    'Z',
  ].join(' ')
}

export function buildCoverScreenshotSideGlassFrameSvg(
  layout: CoverScreenshotFrameLayout,
  glass: CoverScreenshotGlassColors,
): string {
  const { shell, outerRadius, borderWidth } = layout
  const { x, y, width, height } = shell
  const shellPath = buildLeftRoundedRectPath(x, y, width, height, outerRadius)
  const borderPath = buildLeftSidesBorderPath(x, y, width, height, outerRadius)

  return `
    <path d="${shellPath}" fill="${glass.shellFill}" />
    <path d="${borderPath}" fill="none" stroke="${glass.shellBorder}" stroke-width="${borderWidth}" stroke-linecap="round" stroke-linejoin="round" />
  `
}

function buildScreenshotInnerBorderPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height / 2)
  return [
    `M ${x + width} ${y}`,
    `L ${x + r} ${y}`,
    `Q ${x} ${y} ${x} ${y + r}`,
    `L ${x} ${y + height - r}`,
    `Q ${x} ${y + height} ${x + r} ${y + height}`,
    `L ${x + width} ${y + height}`,
    `M ${x + width} ${y}`,
  ].join(' ')
}

export function buildCoverScreenshotSideScreenshotBorderSvg(
  layout: CoverScreenshotFrameLayout,
  borderColor: string,
): string {
  const { screenshot, innerRadius } = layout
  return `
    <path
      d="${buildScreenshotInnerBorderPath(screenshot.x, screenshot.y, screenshot.width, screenshot.height, innerRadius)}"
      fill="none"
      stroke="${borderColor}"
      stroke-width="1"
      opacity="0.75"
    />
  `
}

export function buildCoverScreenshotSideClipSvg(
  width: number,
  height: number,
  innerRadius: number,
): string {
  return `<svg width="${width}" height="${height}" xmlns="http://www.w3.org/2000/svg"><path d="${buildLeftRoundedClipPath(width, height, innerRadius)}" fill="#fff"/></svg>`
}

export type CoverScreenshotSideShellDimensions = {
  shellWidth: number
  shellHeight: number
  innerWidth: number
  innerHeight: number
  frameX: number
  frameY: number
  rightOverflow: number
}

export function getCoverScreenshotSideShellDimensions(options: {
  shellWidth: number
  maxShellHeight: number
  minFrameX: number
}): CoverScreenshotSideShellDimensions {
  const { paddingX, paddingTop, chromeHeight } = COVER_HERO_SCREENSHOT_FRAME
  const { rightOverflowPercent, aspectRatio, leftOffset } = COVER_SCREENSHOT_SIDE_SHELL
  const paddingBottom = paddingX

  const maxContentHeight = Math.max(
    1,
    options.maxShellHeight - paddingTop - chromeHeight - paddingBottom,
  )

  const fromWidth = () => {
    let shellWidth = Math.max(1, options.shellWidth)
    let innerWidth = Math.max(1, shellWidth - paddingX * 2)
    let innerHeight = Math.max(1, Math.round(innerWidth / aspectRatio))
    let shellHeight = paddingTop + chromeHeight + paddingBottom + innerHeight

    if (shellHeight > options.maxShellHeight) {
      shellHeight = Math.max(1, options.maxShellHeight)
      innerHeight = maxContentHeight
      innerWidth = Math.max(1, Math.round(innerHeight * aspectRatio))
      shellWidth = innerWidth + paddingX * 2
    }

    return { shellWidth, shellHeight, innerWidth, innerHeight }
  }

  const fromHeight = () => {
    const innerHeight = maxContentHeight
    const innerWidth = Math.max(1, Math.round(innerHeight * aspectRatio))
    const shellWidth = innerWidth + paddingX * 2
    const shellHeight = options.maxShellHeight

    return { shellWidth, shellHeight, innerWidth, innerHeight }
  }

  const widthBased = fromWidth()
  const heightBased = fromHeight()
  const { shellWidth, shellHeight, innerWidth, innerHeight } =
    heightBased.innerHeight > widthBased.innerHeight ? heightBased : widthBased

  const rightOverflow = Math.round(shellWidth * (rightOverflowPercent / 100))
  let frameX = COVER_WIDTH - shellWidth + rightOverflow
  frameX = Math.max(frameX, options.minFrameX - leftOffset)

  const availableHeight = COVER_HEIGHT - COVER_SCREENSHOT_SIDE_SHELL.verticalInset * 2
  const frameY =
    COVER_SCREENSHOT_SIDE_SHELL.verticalInset +
    Math.round((availableHeight - shellHeight) / 2)

  return {
    shellWidth,
    shellHeight,
    innerWidth,
    innerHeight,
    frameX,
    frameY,
    rightOverflow,
  }
}

function buildCoverScreenshotSideFrameShellLayout(
  dimensions: CoverScreenshotSideShellDimensions,
): CoverScreenshotFrameLayout {
  const { paddingX, paddingTop, chromeHeight, outerRadius, defaultInnerRadius } =
    COVER_HERO_SCREENSHOT_FRAME
  const { shellWidth, shellHeight, innerWidth, innerHeight, frameX, frameY } =
    dimensions

  return {
    shell: {
      x: frameX,
      y: frameY,
      width: shellWidth,
      height: shellHeight,
    },
    screenshot: {
      x: frameX + paddingX,
      y: frameY + paddingTop + chromeHeight,
      width: innerWidth,
      height: innerHeight,
    },
    outerRadius,
    innerRadius: defaultInnerRadius,
    borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
    paddingX,
    paddingTop,
    chromeHeight,
  }
}

function getCoverScreenshotSideTitleLines(
  data: Pick<CoverScreenshotData, 'title'>,
): string[] {
  return data.title
    ? wrapTextLines(
        stripCoverTitleSuffix(data.title),
        COVER_SCREENSHOT_SIDE_TITLE.maxCharsPerLine,
        COVER_SCREENSHOT_SIDE_TITLE.maxLines,
      )
    : []
}

type CoverScreenshotSideTitleLayout = {
  titleLines: string[]
  titleXArtboard: number
  titleYArtboard: number
  subtitleYArtboard: number
}

function getCoverScreenshotSideTitleLayout(
  data: Pick<CoverScreenshotData, 'title' | 'subtitle'>,
  canvas: CoverCanvasSize,
): CoverScreenshotSideTitleLayout {
  const titleLines = getCoverScreenshotSideTitleLines(data)
  const layoutTransform = getCoverContentLayoutTransform(
    canvas.width,
    canvas.height,
    'right',
  )
  const { scale } = layoutTransform
  const { x, lineHeight, subtitleFontSize, subtitleGap } = COVER_SCREENSHOT_SIDE_TITLE

  const titleBlockHeightExport =
    (titleLines.length > 0 ? titleLines.length * lineHeight : 0) +
    (titleLines.length > 0 && data.subtitle ? subtitleGap : 0) +
    (data.subtitle ? subtitleFontSize + 8 : 0)

  const titleYExport =
    titleBlockHeightExport > 0
      ? (canvas.height - titleBlockHeightExport * scale) / 2
      : canvas.height / 2

  const lineHeightExport = lineHeight * scale
  const subtitleGapExport = subtitleGap * scale
  const titleXExport = x * scale

  const subtitleYExport =
    titleLines.length > 0
      ? titleYExport + titleLines.length * lineHeightExport + subtitleGapExport
      : titleYExport + subtitleGapExport

  return {
    titleLines,
    titleXArtboard: titleXExport / scale,
    titleYArtboard: coverExportYToArtboardY(titleYExport, layoutTransform),
    subtitleYArtboard: coverExportYToArtboardY(subtitleYExport, layoutTransform),
  }
}

export function getCoverScreenshotSideSceneLayout(
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
) {
  const canvas: CoverCanvasSize = { width: data.width, height: data.height }

  const { titleLines, titleXArtboard, titleYArtboard, subtitleYArtboard } =
    getCoverScreenshotSideTitleLayout(data, canvas)

  const requestedShellWidth = getCoverFrameWidthPx(data.frameWidthPercent, canvas)
  const availableHeight = COVER_HEIGHT - COVER_SCREENSHOT_SIDE_SHELL.verticalInset * 2
  const frameHeightPercent = clampCoverFrameHeightPercent(
    data.frameHeightPercent ?? COVER_SCREENSHOT_FRAME_HEIGHT.defaultPercent,
  )
  const maxShellHeight = Math.max(
    1,
    Math.round((availableHeight * frameHeightPercent) / 100),
  )

  const dimensions = getCoverScreenshotSideShellDimensions({
    shellWidth: requestedShellWidth,
    maxShellHeight,
    minFrameX: getCoverScreenshotSideMinFrameX(titleLines, data.subtitle),
  })

  const layout = buildCoverScreenshotSideFrameShellLayout(dimensions)

  return {
    titleLines,
    layout,
    titleX: titleXArtboard,
    titleY: titleYArtboard,
    subtitleY: subtitleYArtboard,
    frameWidth: dimensions.shellWidth,
    frameHeight: dimensions.shellHeight,
    rightOverflow: dimensions.rightOverflow,
  }
}
