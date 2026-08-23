import sharp from 'sharp'
import { buildCoverSvgShell } from '@/lib/cover-generator/brand-background'
import '@/lib/cover-generator/code-snippet/prism-setup'
import { applyCoverImageFormat } from '@/lib/cover-generator/encode-cover-image-sharp'
import { getCoverFontFaceCss } from '@/lib/cover-generator/font-embed'
import { renderIntegrationIconTemplateSvg } from '@/lib/cover-generator/templates/integration-icon'
import { renderIntegrationTemplateSvg } from '@/lib/cover-generator/templates/integration'
import { renderShowcaseIconTemplateSvg } from '@/lib/cover-generator/templates/showcase-icon'
import { renderTitleIconTemplateSvg } from '@/lib/cover-generator/templates/title-icon'
import { renderSimpleTitleTemplateSvg } from '@/lib/cover-generator/templates/simple-title'
import { renderTableTemplateSvg } from '@/lib/cover-generator/templates/table'
import { renderBarChartTemplateSvg } from '@/lib/cover-generator/templates/bar-chart'
import { renderLineChartTemplateSvg } from '@/lib/cover-generator/templates/line-chart'
import { renderCliCodeTemplateSvg } from '@/lib/cover-generator/templates/cli-code'
import { renderCodeSnippetTemplateSvg } from '@/lib/cover-generator/templates/code-snippet'
import {
  renderMilestoneCenteredTemplateSvg,
  renderMilestoneSplitTemplateSvg,
} from '@/lib/cover-generator/milestone/render'
import {
  renderVersionNumberTemplateSvg,
  renderVersionTitleTemplateSvg,
} from '@/lib/cover-generator/version/render'
import { renderCoverExtraTemplateSvg } from '@/lib/cover-generator/extra-templates/render'
import { isCoverExtraTemplateData, type CoverRenderData } from '@/lib/cover-generator/types'

async function renderCoverSvg(data: CoverRenderData): Promise<string> {
  let content = ''

  if (isCoverExtraTemplateData(data)) {
    content = await renderCoverExtraTemplateSvg(data, data.theme)
  } else switch (data.template) {
    case 'simple-title': {
      content = renderSimpleTitleTemplateSvg(data, data.theme)
      break
    }
    case 'integration':
      content = await renderIntegrationTemplateSvg(data, data.theme)
      break
    case 'integration-icon':
      content = await renderIntegrationIconTemplateSvg(data, data.theme)
      break
    case 'showcase-icon':
      content = await renderShowcaseIconTemplateSvg(data, data.theme)
      break
    case 'title-icon':
      content = await renderTitleIconTemplateSvg(data, data.theme)
      break
    case 'table':
      content = renderTableTemplateSvg(data, data.theme)
      break
    case 'bar-chart':
      content = renderBarChartTemplateSvg(data, data.theme)
      break
    case 'line-chart':
      content = renderLineChartTemplateSvg(data, data.theme)
      break
    case 'cli-code':
      content = await renderCliCodeTemplateSvg(data, data.theme)
      break
    case 'code-snippet':
      content = renderCodeSnippetTemplateSvg(data, data.theme)
      break
    case 'milestone-split':
      content = renderMilestoneSplitTemplateSvg(data, data.theme)
      break
    case 'milestone-centered':
      content = renderMilestoneCenteredTemplateSvg(data, data.theme)
      break
    case 'version-number':
      content = renderVersionNumberTemplateSvg(data, data.theme)
      break
    case 'version-title':
      content = renderVersionTitleTemplateSvg(data, data.theme)
      break
    default:
      throw new Error(`Template "${data.template}" is not supported by SVG export`)
  }

  const fontFaceCss = await getCoverFontFaceCss()

  return buildCoverSvgShell({
    theme: data.theme,
    width: data.width,
    height: data.height,
    fontFaceCss,
    templateId: data.template,
    contentAnchor: data.template === 'cli-code' ? 'right' : undefined,
    content,
  })
}

/** Sharp + librsvg path for text/logo templates (more reliable than Satori for these layouts). */
export async function renderCoverImageWithSharp(
  data: CoverRenderData,
): Promise<Uint8Array> {
  const svg = await renderCoverSvg(data)
  const pipeline = sharp(Buffer.from(svg))

  return applyCoverImageFormat(pipeline, data.format)
}
