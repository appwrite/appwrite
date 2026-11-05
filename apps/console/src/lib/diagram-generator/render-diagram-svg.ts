import {
  buildDiagramEdgeArrowheadPath,
  buildDiagramEdgePaths,
} from '@/lib/diagram-generator/edge-paths'
import type { DiagramDocument, DiagramNode } from '@/lib/diagram-generator/types'
import {
  getDiagramEdgeDash,
  getDiagramEdgeOpacity,
  getDiagramEdgeStroke,
} from '@/lib/diagram-generator/edge-appearance'
import { getDiagramEdgeLabelMetrics } from '@/lib/diagram-generator/edge-label'
import {
  getDiagramEdgeLabelSurfaceColors,
  getDiagramNodeSurfaceColors,
} from '@/lib/diagram-generator/node-chrome'
import {
  buildCoverBrandBackgroundParts,
  buildCoverExportFontStyleBlock,
  resolveCoverImageHref,
} from '@/lib/cover-generator/brand-background'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  COVER_BRAND_ICON_COLOR,
  getCoverLucideIconStrokeColor,
} from '@/lib/cover-generator/cover-icon-tone'
import { getCoverFontFaceCss } from '@/lib/cover-generator/font-embed'
import {
  isCoverLucideIconValue,
  parseCoverLucideIconName,
} from '@/lib/cover-generator/lucide-icon-utils'
import { loadCoverLucideIconSvgBuffer } from '@/lib/cover-generator/lucide-icon-render'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'
import { buildCoverTableFrameComposition } from '@/lib/cover-generator/table/render-frame'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'
import { DIAGRAM_NODE_KIND_LABELS } from '@/lib/diagram-generator/constants'
import { createDefaultDiagramTable } from '@/lib/diagram-generator/diagram-table'

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function withAlpha(color: string, alpha: number): string {
  if (color.startsWith('#') && color.length === 7) {
    const value = Math.round(alpha * 255)
      .toString(16)
      .padStart(2, '0')
    return `${color}${value}`
  }
  return color
}

function buildDiagramEdgeStrokesSvg(
  document: DiagramDocument,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  const paths = buildDiagramEdgePaths(document.nodes, document.edges)

  return paths
    .map((path) => {
      const stroke = getDiagramEdgeStroke(path.strokeTone, brand, false)
      const strokeOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
        selected: false,
        part: 'stroke',
      })
      const dash = getDiagramEdgeDash(path.lineStyle)
      const dashAttr = dash ? ` stroke-dasharray="${dash}"` : ''

      return `
        <g opacity="${strokeOpacity}">
          <path d="${path.d}" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="butt" stroke-linejoin="miter" stroke-miterlimit="4"${dashAttr} />
        </g>
      `
    })
    .join('')
}

function buildDiagramEdgeArrowheadsSvg(
  document: DiagramDocument,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  const paths = buildDiagramEdgePaths(document.nodes, document.edges)

  return paths
    .map((path) => {
      const stroke = getDiagramEdgeStroke(path.strokeTone, brand, false)
      const strokeOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
        selected: false,
        part: 'stroke',
      })

      const arrows = [
        path.forwardArrow
          ? `<path d="${buildDiagramEdgeArrowheadPath(path.forwardArrow)}" fill="${stroke}" stroke="none" opacity="${strokeOpacity}" />`
          : '',
        path.backwardArrow
          ? `<path d="${buildDiagramEdgeArrowheadPath(path.backwardArrow)}" fill="${stroke}" stroke="none" opacity="${strokeOpacity}" />`
          : '',
      ].join('')

      if (!arrows) return ''

      return `<g opacity="${strokeOpacity}">${arrows}</g>`
    })
    .join('')
}

function buildDiagramEdgeLabelsSvg(
  document: DiagramDocument,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  const paths = buildDiagramEdgePaths(document.nodes, document.edges)
  const labelSurface = getDiagramEdgeLabelSurfaceColors(brand, document.theme)

  return paths
    .filter((path) => path.label)
    .map((path) => {
      const labelOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
        selected: false,
        part: 'label',
      })
      const metrics = getDiagramEdgeLabelMetrics(path.label ?? '')

      return `
        <g opacity="${labelOpacity}">
          <rect x="${path.labelX - metrics.offsetX}" y="${path.labelY - metrics.offsetY}" width="${metrics.width}" height="${metrics.height}" rx="${metrics.rx}" fill="${labelSurface.fill}" stroke="${labelSurface.stroke}" stroke-width="1" />
          <text x="${path.labelX}" y="${path.labelY + 5}" text-anchor="middle" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(path.label ?? '')}</text>
        </g>
      `
    })
    .join('')
}

/** Recolor bundled `/icons/*.svg` fills so they match Lucide stroke for the theme. */
function recolorCoverBrandIconSvg(svg: string, color: string): string {
  const brandColor = COVER_BRAND_ICON_COLOR
  return svg
    .replaceAll(brandColor, color)
    .replaceAll(brandColor.toLowerCase(), color)
    .replaceAll(brandColor.toUpperCase(), color)
}

async function buildNodeIconSvg(
  node: DiagramNode,
  themeId: CoverEditorThemeId,
  x: number,
  y: number,
  size: number,
): Promise<string> {
  if (!node.iconSrc?.trim()) return ''

  const iconSrc = node.iconSrc.trim()
  const iconColor = getCoverLucideIconStrokeColor(themeId)

  if (isCoverLucideIconValue(iconSrc)) {
    const iconName = parseCoverLucideIconName(iconSrc)
    if (!iconName) return ''
    const iconBuffer = await loadCoverLucideIconSvgBuffer(iconName, iconColor)
    if (!iconBuffer) return ''
    const href = `data:image/svg+xml;base64,${iconBuffer.toString('base64')}`
    return `<image href="${href}" x="${x}" y="${y}" width="${size}" height="${size}" />`
  }

  if (iconSrc.startsWith('/icons/') && iconSrc.toLowerCase().endsWith('.svg')) {
    const buffer = await readCoverPublicAssetBuffer(iconSrc)
    if (!buffer) return ''
    const recolored = recolorCoverBrandIconSvg(buffer.toString('utf-8'), iconColor)
    const href = `data:image/svg+xml;base64,${Buffer.from(recolored, 'utf-8').toString('base64')}`
    return `<image href="${href}" x="${x}" y="${y}" width="${size}" height="${size}" preserveAspectRatio="xMidYMid meet" />`
  }

  const href = await resolveCoverImageHref(iconSrc)
  if (!href) return ''

  return `<image href="${escapeXml(href)}" x="${x}" y="${y}" width="${size}" height="${size}" preserveAspectRatio="xMidYMid meet" />`
}

async function buildDiagramNodeSvg(
  node: DiagramNode,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
  themeId: CoverEditorThemeId,
): Promise<string> {
  const { x, y, width, height, label } = node

  if (node.kind === 'group') {
    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="16" fill="${withAlpha(brand.muted, 0.18)}" stroke="${withAlpha(brand.border, 0.9)}" stroke-width="1" stroke-dasharray="6 4" />
        <text x="${x + 16}" y="${y + 12}" dominant-baseline="hanging" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif" font-weight="600" letter-spacing="0.08em">${escapeXml(label.toUpperCase())}</text>
      </g>
    `
  }

  if (node.kind === 'label') {
    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="8" fill="${withAlpha(brand.background, 0.72)}" stroke="${withAlpha(brand.border, 0.65)}" stroke-width="1" />
        <text x="${x + width / 2}" y="${y + height / 2}" dominant-baseline="middle" text-anchor="middle" fill="${brand.foreground}" font-size="14" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(label)}</text>
      </g>
    `
  }

  if (node.kind === 'title') {
    const titleLineHeight = 35
    const subtitleGap = 8
    const subtitleLineHeight = 19
    const blockHeight = node.subtitle
      ? titleLineHeight + subtitleGap + subtitleLineHeight
      : titleLineHeight
    const blockTop = y + (height - blockHeight) / 2
    const titleBaseline = blockTop + 24
    const subtitleBaseline = blockTop + titleLineHeight + subtitleGap + 12

    return `
      <g>
        <text x="${x + width / 2}" y="${titleBaseline}" text-anchor="middle" class="cover-title" fill="${brand.foreground}" font-size="28">${escapeXml(label)}</text>
        ${node.subtitle ? `<text x="${x + width / 2}" y="${subtitleBaseline}" text-anchor="middle" class="cover-body" fill="${brand.mutedForeground}" font-size="14">${escapeXml(node.subtitle)}</text>` : ''}
      </g>
    `
  }

  if (node.kind === 'table') {
    const defaults = createDefaultDiagramTable()
    const headers = node.tableHeaders ?? defaults.tableHeaders
    const rows = node.tableRows ?? defaults.tableRows
    const defaultLabel = DIAGRAM_NODE_KIND_LABELS.table
    const showTitle = Boolean(label.trim() && label !== defaultLabel)
    const composition = buildCoverTableFrameComposition({
      themeId,
      frameWidth: width,
      title: showTitle ? label : undefined,
      subtitle: node.subtitle,
      headers,
      rows,
      clipIdPrefix: `diagram-table-${node.id}`,
    })

    return `
      <g transform="translate(${x}, ${y})">
        <defs>${composition.defs}</defs>
        ${composition.svg}
      </g>
    `
  }

  if (node.kind === 'screenshot') {
    const defaultLabel = DIAGRAM_NODE_KIND_LABELS.screenshot
    const showCaption = Boolean(label.trim() && label !== defaultLabel)
    const captionBandHeight = showCaption ? 28 : 0
    const frameHeight = Math.max(1, height - captionBandHeight)
    const imageHref = node.imageSrc?.trim()
      ? await resolveCoverImageHref(node.imageSrc.trim())
      : null
    const clipId = `diagram-screenshot-${node.id}`

    return `
      <g>
        <defs>
          <clipPath id="${clipId}">
            <rect x="${x}" y="${y}" width="${width}" height="${frameHeight}" rx="12" />
          </clipPath>
        </defs>
        <rect x="${x}" y="${y}" width="${width}" height="${frameHeight}" rx="12" fill="${withAlpha(brand.muted, 0.35)}" stroke="${brand.border}" stroke-width="1" />
        ${
          imageHref
            ? `<image href="${escapeXml(imageHref)}" x="${x}" y="${y}" width="${width}" height="${frameHeight}" preserveAspectRatio="xMidYMid slice" clip-path="url(#${clipId})" />`
            : ''
        }
        ${
          showCaption
            ? `
              <rect x="${x}" y="${y + frameHeight}" width="${width}" height="${captionBandHeight}" fill="${withAlpha(brand.background, 0.88)}" stroke="${brand.border}" stroke-width="1" />
              <text x="${x + width / 2}" y="${y + frameHeight + captionBandHeight / 2}" dominant-baseline="middle" text-anchor="middle" fill="${brand.foreground}" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(label)}</text>
            `
            : ''
        }
      </g>
    `
  }

  const serviceIconBoxSize = 40
  const serviceIconRenderSize = 24
  const servicePaddingX = 16
  const serviceGap = 12

  const surface = getDiagramNodeSurfaceColors(brand, themeId)

  if (node.kind === 'icon') {
    const defaultLabel = DIAGRAM_NODE_KIND_LABELS.icon
    const showCaption = Boolean(label.trim() && label !== defaultLabel)
    const chromePadding = 16
    const captionBand = showCaption ? 20 : 0
    const availableWidth = width - chromePadding * 2
    const availableHeight = height - chromePadding * 2 - captionBand
    const iconSize = Math.max(24, Math.min(availableWidth, availableHeight))
    const iconX = x + (width - iconSize) / 2
    const iconY = y + chromePadding + (availableHeight - iconSize) / 2
    const iconMarkup = await buildNodeIconSvg(node, themeId, iconX, iconY, iconSize)

    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="12" fill="${surface.fill}" stroke="${surface.stroke}" stroke-width="${surface.strokeWidth}" />
        ${iconMarkup}
        ${
          showCaption
            ? `<text x="${x + width / 2}" y="${y + height - 10}" text-anchor="middle" fill="${brand.mutedForeground}" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(label)}</text>`
            : ''
        }
      </g>
    `
  }

  const iconBoxX = x + servicePaddingX
  const iconBoxY = y + (height - serviceIconBoxSize) / 2
  const iconX = iconBoxX + (serviceIconBoxSize - serviceIconRenderSize) / 2
  const iconY = iconBoxY + (serviceIconBoxSize - serviceIconRenderSize) / 2
  const iconMarkup = await buildNodeIconSvg(node, themeId, iconX, iconY, serviceIconRenderSize)
  const hasIcon = Boolean(iconMarkup)
  const textX = hasIcon ? x + servicePaddingX + serviceIconBoxSize + serviceGap : x + servicePaddingX
  const titleBaseline = y + (node.subtitle ? height / 2 - 6 : height / 2 + 5)
  const subtitleBaseline = y + height / 2 + 14

  return `
    <g>
      <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="12" fill="${surface.fill}" stroke="${surface.stroke}" stroke-width="${surface.strokeWidth}" />
      ${hasIcon ? `<rect x="${iconBoxX}" y="${iconBoxY}" width="${serviceIconBoxSize}" height="${serviceIconBoxSize}" rx="8" fill="${withAlpha(brand.muted, 0.85)}" />` : ''}
      ${iconMarkup}
      <text x="${textX}" y="${titleBaseline}" fill="${brand.foreground}" font-size="14" font-family="Inter, system-ui, sans-serif" font-weight="600">${escapeXml(label)}</text>
      ${node.subtitle ? `<text x="${textX}" y="${subtitleBaseline}" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif">${escapeXml(node.subtitle)}</text>` : ''}
    </g>
  `
}

export async function renderDiagramTemplateSvg(document: DiagramDocument): Promise<string> {
  const brand = getCoverBrandThemeForSvgExport(document.theme)
  const { defs, layers } = buildCoverBrandBackgroundParts(
    document.theme,
    document.width,
    document.height,
  )
  const fontFaceCss = await getCoverFontFaceCss()
  const edgeStrokes = buildDiagramEdgeStrokesSvg(document, brand)
  const nodeFragments = await Promise.all(
    document.nodes.map((node) => buildDiagramNodeSvg(node, brand, document.theme)),
  )
  const edgeArrowheads = buildDiagramEdgeArrowheadsSvg(document, brand)
  const edgeLabels = buildDiagramEdgeLabelsSvg(document, brand)

  return `
    <svg width="${document.width}" height="${document.height}" viewBox="0 0 ${document.width} ${document.height}" xmlns="http://www.w3.org/2000/svg">
      ${buildCoverExportFontStyleBlock(fontFaceCss)}
      <defs>${defs}</defs>
      ${layers}
      ${edgeStrokes}
      ${nodeFragments.join('')}
      ${edgeArrowheads}
      ${edgeLabels}
    </svg>
  `
}
