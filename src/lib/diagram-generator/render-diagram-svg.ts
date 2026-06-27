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
import {
  buildCoverBrandBackgroundParts,
  buildCoverExportFontStyleBlock,
  resolveCoverImageHref,
} from '@/lib/cover-generator/brand-background'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { getCoverFontFaceCss } from '@/lib/cover-generator/font-embed'
import {
  isCoverLucideIconValue,
  parseCoverLucideIconName,
} from '@/lib/cover-generator/lucide-icon-utils'
import { loadCoverLucideIconSvgBuffer } from '@/lib/cover-generator/lucide-icon-render'

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

      const arrows = [
        path.forwardArrow
          ? `<path d="${buildDiagramEdgeArrowheadPath(path.forwardArrow)}" fill="${stroke}" stroke="none" opacity="${strokeOpacity}" />`
          : '',
        path.backwardArrow
          ? `<path d="${buildDiagramEdgeArrowheadPath(path.backwardArrow)}" fill="${stroke}" stroke="none" opacity="${strokeOpacity}" />`
          : '',
      ].join('')

      return `
        <g opacity="${strokeOpacity}">
          <path d="${path.d}" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"${dashAttr} />
          ${arrows}
        </g>
      `
    })
    .join('')
}

function buildDiagramEdgeLabelsSvg(
  document: DiagramDocument,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  const paths = buildDiagramEdgePaths(document.nodes, document.edges)

  return paths
    .filter((path) => path.label)
    .map((path) => {
      const labelOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
        selected: false,
        part: 'label',
      })

      return `
        <g opacity="${labelOpacity}">
          <rect x="${path.labelX - 36}" y="${path.labelY - 11}" width="72" height="22" rx="11" fill="${brand.background}" stroke="${brand.border}" stroke-width="1" />
          <text x="${path.labelX}" y="${path.labelY + 4}" text-anchor="middle" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(path.label ?? '')}</text>
        </g>
      `
    })
    .join('')
}

async function buildNodeIconSvg(
  node: DiagramNode,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
  x: number,
  y: number,
  size: number,
): Promise<string> {
  if (!node.iconSrc?.trim()) return ''

  const iconSrc = node.iconSrc.trim()
  if (isCoverLucideIconValue(iconSrc)) {
    const iconName = parseCoverLucideIconName(iconSrc)
    if (!iconName) return ''
    const iconBuffer = await loadCoverLucideIconSvgBuffer(iconName, brand.foreground)
    if (!iconBuffer) return ''
    const href = `data:image/svg+xml;base64,${iconBuffer.toString('base64')}`
    return `<image href="${href}" x="${x}" y="${y}" width="${size}" height="${size}" />`
  }

  const href = await resolveCoverImageHref(iconSrc)
  if (!href) return ''

  return `<image href="${escapeXml(href)}" x="${x}" y="${y}" width="${size}" height="${size}" preserveAspectRatio="xMidYMid meet" />`
}

async function buildDiagramNodeSvg(
  node: DiagramNode,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): Promise<string> {
  const { x, y, width, height, label } = node

  if (node.kind === 'group') {
    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="16" fill="${withAlpha(brand.muted, 0.18)}" stroke="${withAlpha(brand.border, 0.9)}" stroke-width="1" stroke-dasharray="6 4" />
        <text x="${x + 16}" y="${y + 24}" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif" font-weight="600" letter-spacing="0.08em">${escapeXml(label.toUpperCase())}</text>
      </g>
    `
  }

  if (node.kind === 'label') {
    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="8" fill="${withAlpha(brand.background, 0.72)}" stroke="${withAlpha(brand.border, 0.65)}" stroke-width="1" />
        <text x="${x + width / 2}" y="${y + height / 2 + 5}" text-anchor="middle" fill="${brand.foreground}" font-size="14" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(label)}</text>
      </g>
    `
  }

  if (node.kind === 'title') {
    const titleY = node.subtitle ? y + height / 2 - 8 : y + height / 2 + 6
    return `
      <g>
        <text x="${x + width / 2}" y="${titleY}" text-anchor="middle" fill="${brand.foreground}" font-size="28" font-family="Inter, system-ui, sans-serif" font-weight="700">${escapeXml(label)}</text>
        ${node.subtitle ? `<text x="${x + width / 2}" y="${titleY + 28}" text-anchor="middle" fill="${brand.mutedForeground}" font-size="14" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(node.subtitle)}</text>` : ''}
      </g>
    `
  }

  if (node.kind === 'table') {
    const headers = node.tableHeaders ?? []
    const rows = node.tableRows ?? []
    const headerHeight = 28
    const rowHeight = 24
    const tableHeight = headerHeight + rows.length * rowHeight
    const colWidth = headers.length > 0 ? width / headers.length : width

    const headerCells = headers
      .map(
        (header, index) => `
          <rect x="${x + index * colWidth}" y="${y}" width="${colWidth}" height="${headerHeight}" fill="${withAlpha(brand.muted, 0.35)}" stroke="${brand.border}" stroke-width="1" />
          <text x="${x + index * colWidth + colWidth / 2}" y="${y + 18}" text-anchor="middle" fill="${brand.foreground}" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="600">${escapeXml(header)}</text>
        `,
      )
      .join('')

    const bodyRows = rows
      .map(
        (row, rowIndex) =>
          row
            .map((cell, colIndex) => {
              const cellY = y + headerHeight + rowIndex * rowHeight
              return `
                <rect x="${x + colIndex * colWidth}" y="${cellY}" width="${colWidth}" height="${rowHeight}" fill="${withAlpha(brand.background, 0.92)}" stroke="${brand.border}" stroke-width="1" />
                <text x="${x + colIndex * colWidth + 8}" y="${cellY + 16}" fill="${brand.foreground}" font-size="11" font-family="Inter, system-ui, sans-serif">${escapeXml(cell)}</text>
              `
            })
            .join(''),
      )
      .join('')

    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${Math.max(height, tableHeight)}" rx="12" fill="${withAlpha(brand.background, 0.92)}" stroke="${brand.border}" stroke-width="1" />
        ${headerCells}
        ${bodyRows}
      </g>
    `
  }

  const iconSize = node.kind === 'icon' ? Math.min(width, height) - 24 : 24
  const iconX = x + 16
  const iconY = y + (height - (node.kind === 'icon' ? iconSize : 40)) / 2
  const iconMarkup = await buildNodeIconSvg(node, brand, iconX, iconY, iconSize)
  const hasIcon = Boolean(iconMarkup)

  if (node.kind === 'icon') {
    return `
      <g>
        <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="12" fill="${withAlpha(brand.background, 0.92)}" stroke="${brand.border}" stroke-width="1" />
        ${iconMarkup}
        ${label && label !== 'Icon'
          ? `<text x="${x + width / 2}" y="${y + height - 10}" text-anchor="middle" fill="${brand.mutedForeground}" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="500">${escapeXml(label)}</text>`
          : ''}
      </g>
    `
  }

  const textX = hasIcon ? x + 64 : x + 16

  return `
    <g>
      <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="12" fill="${withAlpha(brand.background, 0.92)}" stroke="${brand.border}" stroke-width="1" />
      ${hasIcon ? `<rect x="${iconX - 4}" y="${iconY - 4}" width="40" height="40" rx="8" fill="${withAlpha(brand.muted, 0.85)}" />` : ''}
      ${iconMarkup}
      <text x="${textX}" y="${y + (node.subtitle ? height / 2 - 2 : height / 2 + 5)}" fill="${brand.foreground}" font-size="14" font-family="Inter, system-ui, sans-serif" font-weight="600">${escapeXml(label)}</text>
      ${node.subtitle ? `<text x="${textX}" y="${y + height / 2 + 18}" fill="${brand.mutedForeground}" font-size="12" font-family="Inter, system-ui, sans-serif">${escapeXml(node.subtitle)}</text>` : ''}
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
  const nodeFragments = await Promise.all(document.nodes.map((node) => buildDiagramNodeSvg(node, brand)))
  const edgeLabels = buildDiagramEdgeLabelsSvg(document, brand)

  return `
    <svg width="${document.width}" height="${document.height}" viewBox="0 0 ${document.width} ${document.height}" xmlns="http://www.w3.org/2000/svg">
      ${buildCoverExportFontStyleBlock(fontFaceCss)}
      <defs>${defs}</defs>
      ${layers}
      ${edgeStrokes}
      ${nodeFragments.join('')}
      ${edgeLabels}
    </svg>
  `
}
