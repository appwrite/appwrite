import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { prepareCoverIconDataUri } from '@/lib/cover-generator/brand-background'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverShowcaseIconData } from '@/lib/cover-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'
import { getCoverTheme } from '@/lib/cover-generator/themes'

export async function renderShowcaseIconTemplateSvg(
  data: CoverShowcaseIconData,
  theme: CoverTheme,
): Promise<string> {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const iconSize = data.iconSize
  const contentX = 96
  const iconHref = await prepareCoverIconDataUri(data.icon, iconSize, {
    themeFamily: getCoverTheme(theme).family,
    themeId: theme,
    contentAlign: 'left',
  })
  const iconY = 150
  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 24, 2)
    : []
  const titleStartY = iconY + iconSize + 56
  const titleFontSize = 58
  const subtitleFontSize = 24
  const titleLineStep = titleFontSize + 8
  const subtitleLayoutY = titleLines.length
    ? titleStartY + titleLines.length * titleLineStep + 18
    : titleStartY + 18

  return `
    ${
      iconHref
        ? `<image href="${iconHref}" x="${contentX}" y="${iconY}" width="${iconSize}" height="${iconSize}" />`
        : `<rect x="${contentX}" y="${iconY}" width="${iconSize}" height="${iconSize}" rx="24" fill="${brand.border}" opacity="0.35" />`
    }
    ${
      titleLines.length
        ? titleLines
            .map(
              (line, index) => {
                const layoutY = titleStartY + index * titleLineStep
                return `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="${contentX}" y="${coverSvgTextBaseline(layoutY, titleFontSize)}">${escapeXml(line)}${
                  index === titleLines.length - 1
                    ? `<tspan fill="${brand.brandCta}">_</tspan>`
                    : ''
                }</text>`
              },
            )
            .join('')
        : ''
    }
    ${
      data.subtitle
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${subtitleFontSize}" x="${contentX}" y="${coverSvgTextBaseline(subtitleLayoutY, subtitleFontSize)}">${escapeXml(data.subtitle)}</text>`
        : ''
    }
  `
}
