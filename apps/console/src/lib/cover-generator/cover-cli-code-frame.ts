import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  parseCoverCliCodeLines,
  COVER_CLI_CODE,
} from '@/lib/cover-generator/cli-code/constants'
import {
  buildCoverCliCodeTokenTspans,
  tokenizeCoverCliCodeLine,
} from '@/lib/cover-generator/cli-code/syntax-highlight'
import {
  getCoverFrameWidthPx,
  type CoverCanvasSize,
} from '@/lib/cover-generator/cover-frame-width'
import {
  coverExportYToArtboardY,
  getCoverContentLayoutTransform,
} from '@/lib/cover-generator/cover-layout-scale'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  type CoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import {
  COVER_SCREENSHOT_SIDE_SHELL,
  COVER_SCREENSHOT_SIDE_TITLE,
  getCoverScreenshotSideMinFrameX,
} from '@/lib/cover-generator/cover-screenshot-side-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverThemeFamily } from '@/lib/cover-generator/themes'

export { COVER_SCREENSHOT_SIDE_TITLE as COVER_CLI_CODE_TITLE } from '@/lib/cover-generator/cover-screenshot-side-frame'

export type CoverCliCodeFrameLayout = {
  shell: { x: number; y: number; width: number; height: number }
  header: { x: number; y: number; width: number; height: number }
  code: { x: number; y: number; width: number; height: number }
  outerRadius: number
  innerRadius: number
  borderWidth: number
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

export function buildCoverCliCodeGlassFrameSvg(
  layout: CoverCliCodeFrameLayout,
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

function buildCoverCliCodeTrafficLightsSvg(
  header: CoverCliCodeFrameLayout['header'],
  glass: CoverScreenshotGlassColors,
): string {
  const {
    terminalDotSize,
    terminalDotGap,
    terminalDotMarginLeft,
    terminalHeaderHeight,
  } = COVER_CLI_CODE
  const dotY = header.y + terminalHeaderHeight / 2
  const dotStartX = header.x + terminalDotMarginLeft + terminalDotSize / 2

  return Array.from({ length: 3 }, (_, index) => {
    const cx = dotStartX + index * (terminalDotSize + terminalDotGap)
    return `<circle cx="${cx}" cy="${dotY}" r="${terminalDotSize / 2}" fill="${glass.chromeDotFill}" />`
  }).join('\n    ')
}

export function buildCoverCliCodeTerminalHeaderSvg(options: {
  layout: CoverCliCodeFrameLayout
  glass: CoverScreenshotGlassColors
  terminalTitle: string
  terminalIconHref: string | null
  mutedForeground: string
  borderColor: string
}): string {
  const { layout, glass, terminalTitle, terminalIconHref, mutedForeground, borderColor } =
    options
  const { header, innerRadius } = layout
  const {
    terminalHeaderHeight,
    terminalTitleFontSize,
    terminalIconSize,
    terminalIconGap,
  } = COVER_CLI_CODE

  const titleText = terminalTitle.trim()
  const dotsReservedWidth =
    COVER_CLI_CODE.terminalDotMarginLeft +
    COVER_CLI_CODE.terminalDotSize * 3 +
    COVER_CLI_CODE.terminalDotGap * 2 +
    12
  const availableTitleWidth = Math.max(1, header.width - dotsReservedWidth * 2)
  const maxTitleChars = Math.max(
    4,
    Math.floor(availableTitleWidth / (terminalTitleFontSize * 0.52)),
  )
  const displayTitle =
    titleText.length <= maxTitleChars
      ? titleText
      : `${titleText.slice(0, Math.max(4, maxTitleChars - 1))}…`
  const titleWidth = displayTitle.length * terminalTitleFontSize * 0.52
  const lockupWidth =
    (terminalIconHref ? terminalIconSize + terminalIconGap : 0) + titleWidth
  const lockupStartX = header.x + (header.width - lockupWidth) / 2
  const iconY = header.y + (terminalHeaderHeight - terminalIconSize) / 2
  const titleX = lockupStartX + (terminalIconHref ? terminalIconSize + terminalIconGap : 0)
  const titleY = coverSvgTextBaseline(
    header.y + (terminalHeaderHeight - terminalTitleFontSize) / 2,
    terminalTitleFontSize,
  )

  return `
    ${buildCoverCliCodeTrafficLightsSvg(header, glass)}
    ${
      terminalIconHref
        ? `<image href="${terminalIconHref}" x="${Math.round(lockupStartX)}" y="${Math.round(iconY)}" width="${terminalIconSize}" height="${terminalIconSize}" />`
        : ''
    }
    ${
      displayTitle
        ? `<text class="cover-body" fill="${mutedForeground}" font-size="${terminalTitleFontSize}" font-weight="600" x="${Math.round(titleX)}" y="${titleY}">${escapeXml(displayTitle)}</text>`
        : ''
    }
    <line
      x1="${header.x + innerRadius}"
      y1="${header.y + terminalHeaderHeight}"
      x2="${header.x + header.width}"
      y2="${header.y + terminalHeaderHeight}"
      stroke="${borderColor}"
      stroke-width="1"
      opacity="0.55"
    />
  `
}

type CoverCliCodeShellDimensions = {
  shellWidth: number
  shellHeight: number
  innerWidth: number
  codeHeight: number
  frameX: number
  frameY: number
}

function getCoverCliCodeShellDimensions(options: {
  shellWidth: number
  codeContentHeight: number
  minFrameX: number
}): CoverCliCodeShellDimensions {
  const { paddingX } = COVER_HERO_SCREENSHOT_FRAME
  const { rightOverflowPercent, leftOffset } = COVER_SCREENSHOT_SIDE_SHELL
  const { terminalHeaderHeight } = COVER_CLI_CODE
  const paddingBottom = paddingX

  const innerWidth = Math.max(1, options.shellWidth - paddingX * 2)
  const codeHeight = Math.max(1, options.codeContentHeight)
  const shellHeight = terminalHeaderHeight + codeHeight + paddingBottom
  const shellWidth = innerWidth + paddingX * 2

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
    codeHeight,
    frameX,
    frameY,
  }
}

function buildCoverCliCodeFrameShellLayout(
  dimensions: CoverCliCodeShellDimensions,
): CoverCliCodeFrameLayout {
  const { paddingX, outerRadius, defaultInnerRadius } = COVER_HERO_SCREENSHOT_FRAME
  const { terminalHeaderHeight } = COVER_CLI_CODE
  const { shellWidth, shellHeight, innerWidth, codeHeight, frameX, frameY } =
    dimensions

  const headerY = frameY
  const codeY = frameY + terminalHeaderHeight

  return {
    shell: {
      x: frameX,
      y: frameY,
      width: shellWidth,
      height: shellHeight,
    },
    header: {
      x: frameX + paddingX,
      y: headerY,
      width: innerWidth,
      height: terminalHeaderHeight,
    },
    code: {
      x: frameX + paddingX,
      y: codeY,
      width: innerWidth,
      height: codeHeight,
    },
    outerRadius,
    innerRadius: defaultInnerRadius,
    borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
  }
}

function getCoverCliCodeTitleLines(
  data: Pick<Extract<CoverRenderData, { template: 'cli-code' }>, 'title'>,
): string[] {
  return data.title
    ? wrapTextLines(
        stripCoverTitleSuffix(data.title),
        COVER_SCREENSHOT_SIDE_TITLE.maxCharsPerLine,
        COVER_SCREENSHOT_SIDE_TITLE.maxLines,
      )
    : []
}

type CoverCliCodeTitleLayout = {
  titleLines: string[]
  titleXArtboard: number
  titleYArtboard: number
  subtitleYArtboard: number
}

function getCoverCliCodeTitleLayout(
  data: Pick<Extract<CoverRenderData, { template: 'cli-code' }>, 'title' | 'subtitle'>,
  canvas: CoverCanvasSize,
): CoverCliCodeTitleLayout {
  const titleLines = getCoverCliCodeTitleLines(data)
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

export function getCoverCliCodeSceneLayout(
  data: Extract<CoverRenderData, { template: 'cli-code' }>,
) {
  const canvas: CoverCanvasSize = { width: data.width, height: data.height }
  const codeLines = parseCoverCliCodeLines(data.code)

  const { titleLines, titleXArtboard, titleYArtboard, subtitleYArtboard } =
    getCoverCliCodeTitleLayout(data, canvas)

  const lineCount = Math.max(1, codeLines.length)
  const codeContentHeight =
    COVER_CLI_CODE.codePaddingY * 2 + lineCount * COVER_CLI_CODE.codeFontSize

  const requestedShellWidth = getCoverFrameWidthPx(data.frameWidthPercent, canvas)

  const dimensions = getCoverCliCodeShellDimensions({
    shellWidth: requestedShellWidth,
    codeContentHeight,
    minFrameX: getCoverScreenshotSideMinFrameX(titleLines, data.subtitle),
  })

  const layout = buildCoverCliCodeFrameShellLayout(dimensions)

  return {
    titleLines,
    layout,
    codeLines,
    titleX: titleXArtboard,
    titleY: titleYArtboard,
    subtitleY: subtitleYArtboard,
  }
}

function coverCliCodeLineY(codeY: number, index: number, fontSize: number): number {
  const layoutY = codeY + COVER_CLI_CODE.codePaddingY + index * fontSize
  return coverSvgTextBaseline(layoutY, fontSize)
}

export function buildCoverCliCodeLinesSvg(options: {
  lines: string[]
  showPrompt: boolean
  layout: CoverCliCodeFrameLayout
  themeFamily: CoverThemeFamily
  foreground: string
  mutedForeground: string
  promptColor: string
  codeBackground: string
}): string {
  const {
    lines,
    showPrompt,
    layout,
    themeFamily,
    foreground,
    promptColor,
    codeBackground,
  } = options
  const { code, innerRadius } = layout
  const { codePaddingX, codeFontSize, promptWidth } = COVER_CLI_CODE

  const lineElements = lines
    .map((line, index) => {
      const y = coverCliCodeLineY(code.y, index, codeFontSize)
      const commandX = code.x + codePaddingX + (showPrompt ? promptWidth : 0)
      const isComment = line.trim().startsWith('#')
      const tokens = tokenizeCoverCliCodeLine(line, themeFamily, foreground)

      const promptSvg =
        showPrompt && !isComment
          ? `<text class="cover-code" fill="${promptColor}" font-size="${codeFontSize}" font-weight="600" x="${code.x + codePaddingX}" y="${y}">$</text>`
          : ''

      return `
        ${promptSvg}
        ${buildCoverCliCodeTokenTspans(tokens, commandX, codeFontSize, y)}
      `
    })
    .join('')

  return `
    <rect
      x="${code.x}"
      y="${code.y}"
      width="${code.width}"
      height="${code.height}"
      rx="${innerRadius}"
      ry="${innerRadius}"
      fill="${codeBackground}"
      opacity="0.92"
    />
    ${lineElements}
  `
}
