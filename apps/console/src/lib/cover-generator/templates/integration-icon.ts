import { prepareCoverIconDataUri } from '@/lib/cover-generator/brand-background'
import {
  buildIntegrationIconCardSvg,
  getIntegrationIconCardSize,
} from '@/lib/cover-generator/templates/integration-icon-card'
import type { CoverIntegrationIconData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme } from '@/lib/cover-generator/themes'

const ARTBOARD_CENTER_X = 600
const ARTBOARD_CENTER_Y = 315

export async function renderIntegrationIconTemplateSvg(
  data: CoverIntegrationIconData,
  themeId: CoverThemeId,
): Promise<string> {
  const themeFamily = getCoverTheme(themeId).family
  const iconSize = data.iconSize
  const cardSize = getIntegrationIconCardSize(iconSize)
  const iconHref = await prepareCoverIconDataUri(data.icon, iconSize, { themeFamily, themeId })

  return `
    <g transform="translate(${ARTBOARD_CENTER_X} ${ARTBOARD_CENTER_Y})">
      ${buildIntegrationIconCardSvg({
        x: -cardSize / 2,
        y: -cardSize / 2,
        iconSize,
        iconHref,
        themeId,
      })}
    </g>
  `
}
