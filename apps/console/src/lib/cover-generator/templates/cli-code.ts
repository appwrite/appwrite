import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { prepareCoverIconDataUri } from '@/lib/cover-generator/brand-background'
import { normalizeCoverCliCodeData } from '@/lib/cover-generator/cli-code/constants'
import {
  buildCoverCliCodeGlassFrameSvg,
  buildCoverCliCodeLinesSvg,
  buildCoverCliCodeTerminalHeaderSvg,
  COVER_CLI_CODE_TITLE,
  getCoverCliCodeSceneLayout,
} from '@/lib/cover-generator/cover-cli-code-frame'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'
import { COVER_CLI_CODE } from '@/lib/cover-generator/cli-code/constants'

function buildCliCodeTitleSvgContent(
  data: Extract<CoverRenderData, { template: 'cli-code' }>,
  titleLines: string[],
  titleX: number,
  titleY: number,
  subtitleY: number,
): string {
  const brand = getCoverBrandThemeForSvgExport(data.theme)
  const { fontSize, lineHeight, subtitleFontSize } = COVER_CLI_CODE_TITLE

  return `
    ${
      titleLines.length
        ? titleLines
            .map(
              (line, index) =>
                `<text text-anchor="start" class="cover-title" fill="${brand.foreground}" font-size="${fontSize}" x="${titleX}" y="${coverSvgTextBaseline(titleY + index * lineHeight, fontSize)}">${escapeXml(line)}${
                  index === titleLines.length - 1
                    ? `<tspan fill="${brand.brandCta}">_</tspan>`
                    : ''
                }</text>`,
            )
            .join('')
        : ''
    }
    ${
      data.subtitle
        ? `<text text-anchor="start" class="cover-body" fill="${brand.mutedForeground}" font-size="${subtitleFontSize}" x="${titleX}" y="${coverSvgTextBaseline(subtitleY, subtitleFontSize)}">${escapeXml(data.subtitle)}</text>`
        : ''
    }
  `
}

export async function renderCliCodeTemplateSvg(
  data: Extract<CoverRenderData, { template: 'cli-code' }>,
  themeId: CoverThemeId,
): Promise<string> {
  const normalized = normalizeCoverCliCodeData(data)
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const theme = getCoverTheme(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const { titleLines, layout, codeLines, titleX, titleY, subtitleY } =
    getCoverCliCodeSceneLayout(normalized)

  const codeBackground =
    theme.family === 'dark' ? 'rgba(0, 0, 0, 0.35)' : 'rgba(9, 9, 11, 0.06)'

  const terminalIconHref = await prepareCoverIconDataUri(
    normalized.terminalIcon,
    COVER_CLI_CODE.terminalIconSize,
    {
      themeFamily: theme.family,
      themeId,
      contentAlign: 'left',
      insetRatio: 0.08,
    },
  )

  return `
    ${buildCliCodeTitleSvgContent(normalized, titleLines, titleX, titleY, subtitleY)}
    ${buildCoverCliCodeGlassFrameSvg(layout, glass)}
    ${buildCoverCliCodeTerminalHeaderSvg({
      layout,
      glass,
      terminalTitle: normalized.terminalTitle,
      terminalIconHref,
      mutedForeground: brand.mutedForeground,
      borderColor: brand.border,
    })}
    ${buildCoverCliCodeLinesSvg({
      lines: codeLines,
      showPrompt: normalized.showPrompt,
      layout,
      themeFamily: theme.family,
      foreground: brand.foreground,
      mutedForeground: brand.mutedForeground,
      promptColor: brand.brandCta,
      codeBackground,
    })}
  `
}
