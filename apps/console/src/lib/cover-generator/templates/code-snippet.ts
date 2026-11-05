import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  alignCoverCodeTokensToContent,
  buildCoverCodeTokenTexts,
  getCoverCodeLineIndentPx,
  stripLeadingWhitespaceCoverTokens,
} from '@/lib/cover-generator/code-share/svg-tokens'
import {
  COVER_CODE_SNIPPET,
  COVER_CODE_SNIPPET_LANGUAGE_LABELS,
  getCoverCodeSnippetLineHeight,
  getCoverCodeSnippetMaxCharsForLine,
  getCoverCodeSnippetMaxLines,
  normalizeCoverCodeSnippetData,
  parseCoverCodeSnippetLines,
} from '@/lib/cover-generator/code-snippet/constants'
import { buildCoverCodeSnippetActivityBarSvg } from '@/lib/cover-generator/cover-code-snippet-activity-bar'
import { tokenizeCoverCodeSnippet } from '@/lib/cover-generator/code-snippet/syntax-highlight'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { escapeXml, stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'

const { borderWidth } = COVER_HERO_SCREENSHOT_FRAME

const CODE_SNIPPET_CLIP_ID = 'cover-code-snippet-code-clip'

function truncateTitle(title: string): string {
  const text = stripCoverTitleSuffix(title).trim()
  if (text.length <= COVER_CODE_SNIPPET.maxTitleChars) return text
  return `${text.slice(0, COVER_CODE_SNIPPET.maxTitleChars - 1)}…`
}

function coverCodeSnippetLineY(
  codeY: number,
  index: number,
  fontSize: number,
  lineHeight: number,
): number {
  const layoutY = codeY + COVER_CODE_SNIPPET.codePaddingY + index * lineHeight
  return coverSvgTextBaseline(layoutY, fontSize)
}

export function renderCodeSnippetTemplateSvg(
  data: Extract<CoverRenderData, { template: 'code-snippet' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverCodeSnippetData(data)
  const artboardWidth = COVER_WIDTH
  const artboardHeight = COVER_HEIGHT
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const theme = getCoverTheme(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const titleText = truncateTitle(normalized.title)
  const codeFontSize = normalized.codeFontSize
  const codeLineHeight = getCoverCodeSnippetLineHeight(codeFontSize)

  const frameWidth = getCoverFrameWidthPx(normalized.frameWidthPercent, {
    width: normalized.width,
    height: normalized.height,
  })
  const innerRectWidth = frameWidth - COVER_CODE_SNIPPET.shellInsetX * 2
  const codeColumnWidth = innerRectWidth - COVER_CODE_SNIPPET.activityBarWidth
  const maxCharsForLine = (line: string) =>
    getCoverCodeSnippetMaxCharsForLine(line, codeColumnWidth, codeFontSize)

  const maxCodeLines = getCoverCodeSnippetMaxLines(
    artboardHeight,
    Boolean(titleText),
    codeFontSize,
  )
  const codeLines = parseCoverCodeSnippetLines(
    normalized.code,
    maxCodeLines,
    maxCharsForLine,
  )
  const tokenLines = tokenizeCoverCodeSnippet(
    codeLines.join('\n'),
    normalized.language,
    theme.family,
    brand.foreground,
  )
  const lineCount = Math.max(1, codeLines.length)
  const centerX = artboardWidth / 2

  const lineBasedCodeContentHeight =
    COVER_CODE_SNIPPET.codePaddingY * 2 + lineCount * codeLineHeight
  const codeContentHeight = Math.max(
    COVER_CODE_SNIPPET.minCodeContentHeight,
    lineBasedCodeContentHeight,
  )
  const cardHeight =
    COVER_CODE_SNIPPET.headerHeight +
    codeContentHeight +
    COVER_CODE_SNIPPET.shellPaddingBottom

  const titleHeight = titleText ? COVER_CODE_SNIPPET.titleLineHeight : 0
  const titleCardGap = titleText ? COVER_CODE_SNIPPET.titleCardGap : 0
  const compositionHeight = titleHeight + titleCardGap + cardHeight
  const compositionY = Math.round((artboardHeight - compositionHeight) / 2)
  const cardY = compositionY + titleHeight + titleCardGap

  const frameX = Math.round((artboardWidth - frameWidth) / 2)

  const codeBackground =
    theme.family === 'dark' ? 'rgba(0, 0, 0, 0.35)' : 'rgba(9, 9, 11, 0.06)'

  const innerRectX = frameX + COVER_CODE_SNIPPET.shellInsetX
  const activityBarWidth = COVER_CODE_SNIPPET.activityBarWidth
  const codeX = innerRectX + activityBarWidth
  const codeWidth = codeColumnWidth
  const codeTextX = codeX + COVER_CODE_SNIPPET.codePaddingX
  const codeAreaY = cardY + COVER_CODE_SNIPPET.headerHeight
  const languageLabel = COVER_CODE_SNIPPET_LANGUAGE_LABELS[normalized.language]
  const indentOptions = {
    tabWidthSpaces: COVER_CODE_SNIPPET.tabWidthSpaces,
    charWidthRatio: COVER_CODE_SNIPPET.monoCharWidthRatio,
  }

  const lineElements = codeLines
    .map((line, index) => {
      const y = coverCodeSnippetLineY(codeAreaY, index, codeFontSize, codeLineHeight)
      const indentPx = getCoverCodeLineIndentPx(
        line,
        codeFontSize,
        indentOptions,
      )
      const lineContent = line.trimEnd().replace(/^\s+/, '')
      const tokens = alignCoverCodeTokensToContent(
        lineContent,
        stripLeadingWhitespaceCoverTokens(
          tokenLines[index] ??
            (lineContent
              ? [{ content: lineContent, color: brand.foreground }]
              : [{ content: ' ', color: brand.foreground }]),
          brand.foreground,
        ),
        brand.foreground,
      )

      return buildCoverCodeTokenTexts(
        tokens,
        codeTextX + indentPx,
        codeFontSize,
        y,
        COVER_CODE_SNIPPET.monoCharWidthRatio,
      )
    })
    .join('')

  const titleSvg = titleText
    ? `<text class="cover-title" text-anchor="middle" fill="${brand.foreground}" font-size="${COVER_CODE_SNIPPET.titleFontSize}" font-weight="600" x="${centerX}" y="${coverSvgTextBaseline(compositionY, COVER_CODE_SNIPPET.titleFontSize)}">${escapeXml(titleText)}<tspan fill="${brand.brandCta}">_</tspan></text>`
    : ''

  const activityBarSvg = buildCoverCodeSnippetActivityBarSvg({
    x: innerRectX,
    y: codeAreaY,
    width: activityBarWidth,
    dividerX: codeX,
    dividerY: codeAreaY + 8,
    dividerHeight: codeContentHeight - 16,
    borderColor: brand.border,
    mutedIconColor: brand.mutedForeground,
    activeIconColor: brand.foreground,
    accentColor: brand.brandCta,
  })

  const headerLabelY = coverSvgTextBaseline(
    cardY + (COVER_CODE_SNIPPET.headerHeight - COVER_CODE_SNIPPET.headerFontSize) / 2,
    COVER_CODE_SNIPPET.headerFontSize,
  )
  const headerLabelX = innerRectX + innerRectWidth / 2

  return `
    ${titleSvg}
    <g>
      <rect
        x="${frameX}"
        y="${cardY}"
        width="${frameWidth}"
        height="${cardHeight}"
        rx="${COVER_CODE_SNIPPET.outerRadius}"
        ry="${COVER_CODE_SNIPPET.outerRadius}"
        fill="${glass.shellFill}"
        stroke="${glass.shellBorder}"
        stroke-width="${borderWidth}"
      />
      ${activityBarSvg}
      <text
        class="cover-body"
        text-anchor="middle"
        fill="${brand.mutedForeground}"
        font-size="${COVER_CODE_SNIPPET.headerFontSize}"
        font-weight="600"
        x="${headerLabelX}"
        y="${headerLabelY}"
      >${escapeXml(languageLabel)}</text>
      <rect
        x="${codeX}"
        y="${codeAreaY}"
        width="${codeWidth}"
        height="${codeContentHeight}"
        rx="${COVER_CODE_SNIPPET.innerRadius}"
        ry="${COVER_CODE_SNIPPET.innerRadius}"
        fill="${codeBackground}"
        opacity="0.92"
      />
      <clipPath id="${CODE_SNIPPET_CLIP_ID}">
        <rect
          x="${codeX}"
          y="${codeAreaY}"
          width="${codeWidth}"
          height="${codeContentHeight}"
          rx="${COVER_CODE_SNIPPET.innerRadius}"
          ry="${COVER_CODE_SNIPPET.innerRadius}"
        />
      </clipPath>
      <g clip-path="url(#${CODE_SNIPPET_CLIP_ID})">
        ${lineElements}
      </g>
    </g>
  `
}
