import { prepareCoverIconDataUri } from '@/lib/cover-generator/brand-background'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { coverSvgConnectorBaseline, coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  buildIntegrationIconCardSvg,
  getIntegrationIconCardSize,
  INTEGRATION_ICON_DEFAULT_SIZE,
} from '@/lib/cover-generator/templates/integration-icon-card'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverIntegrationData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme } from '@/lib/cover-generator/themes'

const ICON_SIZE = INTEGRATION_ICON_DEFAULT_SIZE
const ICON_GAP_FROM_CENTER = 64
const CARD_SIZE = getIntegrationIconCardSize(ICON_SIZE)

export async function renderIntegrationTemplateSvg(
  data: CoverIntegrationData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const themeFamily = getCoverTheme(themeId).family
  const centerY = 250
  const [leftHref, rightHref] = await Promise.all([
    prepareCoverIconDataUri(data.logoLeft, ICON_SIZE, { themeFamily, themeId }),
    prepareCoverIconDataUri(data.logoRight, ICON_SIZE, { themeFamily, themeId }),
  ])

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 28, 2)
    : []
  const subtitle = data.subtitle?.trim()

  const titleFontSize = 52
  const subtitleFontSize = 24
  const connectorFontSize = 48
  const titleLineStep = titleFontSize + 6
  const cardBottom = centerY + CARD_SIZE / 2

  const titleStartY = titleLines.length
    ? cardBottom + 44
    : subtitle
      ? cardBottom + 36
      : cardBottom + 28
  const subtitleLayoutY = titleLines.length
    ? titleStartY + titleLines.length * titleLineStep + 18
    : titleStartY + 52

  const cardY = -CARD_SIZE / 2
  const leftCardX = -ICON_GAP_FROM_CENTER - CARD_SIZE
  const rightCardX = ICON_GAP_FROM_CENTER

  return `
    <g transform="translate(600 ${centerY})">
      ${buildIntegrationIconCardSvg({
        x: leftCardX,
        y: cardY,
        iconSize: ICON_SIZE,
        iconHref: leftHref,
        themeId,
      })}
      <text text-anchor="middle" class="cover-title" fill="${brand.mutedForeground}" font-size="${connectorFontSize}" y="${coverSvgConnectorBaseline(connectorFontSize)}">${escapeXml(data.connector)}</text>
      ${buildIntegrationIconCardSvg({
        x: rightCardX,
        y: cardY,
        iconSize: ICON_SIZE,
        iconHref: rightHref,
        themeId,
      })}
    </g>
    ${
      titleLines.length
        ? titleLines
            .map(
              (line, index) => {
                const layoutY = titleStartY + index * titleLineStep
                return `<text text-anchor="middle" class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="600" y="${coverSvgTextBaseline(layoutY, titleFontSize)}">${escapeXml(line)}</text>`
              },
            )
            .join('')
        : ''
    }
    ${
      subtitle
        ? `<text text-anchor="middle" class="cover-body" fill="${brand.mutedForeground}" font-size="${subtitleFontSize}" x="600" y="${coverSvgTextBaseline(subtitleLayoutY, subtitleFontSize)}">${escapeXml(subtitle)}</text>`
        : ''
    }
  `
}
