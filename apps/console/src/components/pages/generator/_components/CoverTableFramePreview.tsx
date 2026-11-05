import { useMemo } from 'react'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { getCoverScreenshotGlassPreviewStyles } from '@/lib/cover-generator/cover-screenshot-frame'
import {
  getScaledCoverTableLayout,
  measureCoverTableTitleBlockHeight,
} from '@/lib/cover-generator/table/render-frame'
import { stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'

type CoverTableFramePreviewProps = {
  themeId: CoverEditorThemeId
  width: number
  height: number
  title?: string
  subtitle?: string
  headers: string[]
  rows: string[][]
  showHeader?: boolean
  instanceId: string
}

function truncateCellText(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, Math.max(0, maxLength - 1))}…`
}

export function CoverTableFramePreview({
  themeId,
  width,
  height,
  title,
  subtitle,
  headers,
  rows,
  showHeader = true,
}: CoverTableFramePreviewProps) {
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const glass = getCoverScreenshotGlassPreviewStyles(themeId)
  const layout = useMemo(() => getScaledCoverTableLayout(width), [width])

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  const titleBlockHeight = measureCoverTableTitleBlockHeight(title, subtitle, layout)
  const titleGap = titleText && subtitle ? layout.subtitleGap : 0
  const frameGap = titleBlockHeight > 0 ? layout.titleCardGap : 0

  const outerRadius = Math.max(4, layout.outerRadius)
  const innerRadius = Math.max(3, layout.innerRadius)
  const paddingX = Math.max(8, layout.cardPaddingX)
  const paddingY = Math.max(8, layout.cardPaddingY)
  const columnCount = Math.max(1, headers.length)
  const colWidth = (width - paddingX * 2) / columnCount
  const maxChars = Math.max(8, Math.floor(colWidth / 8))

  return (
    <div
      className="flex size-full flex-col"
      style={{ width, height, color: brand.foreground }}
    >
      {titleText || subtitle ? (
        <div className="shrink-0 text-center" style={{ marginBottom: frameGap }}>
          {titleText ? (
            <p
              className="font-semibold leading-none"
              style={{ fontSize: layout.titleFontSize }}
            >
              {titleText}
            </p>
          ) : null}
          {subtitle ? (
            <p
              className="leading-none"
              style={{
                fontSize: layout.subtitleFontSize,
                color: brand.mutedForeground,
                marginTop: titleText ? titleGap : 0,
              }}
            >
              {subtitle}
            </p>
          ) : null}
        </div>
      ) : null}

      <div
        className="flex min-h-0 flex-1 flex-col overflow-hidden"
        style={{
          borderRadius: outerRadius,
          border: `${layout.borderWidth}px solid ${glass.shellBorder}`,
          backgroundColor: glass.shellFill,
          padding: `${paddingY}px ${paddingX}px`,
        }}
      >
        <div
          className="flex min-h-0 flex-1 flex-col overflow-hidden"
          style={{
            borderRadius: innerRadius,
            border: `1px solid ${brand.border}`,
            backgroundColor: `${brand.background}66`,
          }}
        >
          {showHeader ? (
            <div
              className="grid shrink-0"
              style={{
                gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))`,
                minHeight: layout.rowHeight,
                backgroundColor: `${brand.border}73`,
              }}
            >
              {headers.map((header, columnIndex) => (
                <div
                  key={`header-${columnIndex}`}
                  className="flex items-center justify-center px-1 font-semibold uppercase tracking-wider"
                  style={{
                    fontSize: layout.headerFontSize,
                    borderLeft:
                      columnIndex > 0 ? `1px solid ${brand.border}` : undefined,
                    opacity: columnIndex > 0 ? 0.65 : 1,
                  }}
                >
                  <span className="truncate">
                    {truncateCellText(header, maxChars).toUpperCase()}
                  </span>
                </div>
              ))}
            </div>
          ) : null}

          <div className="flex min-h-0 flex-1 flex-col">
            {rows.map((row, rowIndex) => (
              <div
                key={`row-${rowIndex}`}
                className="grid flex-1"
                style={{
                  gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))`,
                  minHeight: layout.rowHeight,
                  borderTop:
                    rowIndex > 0 || showHeader
                      ? `1px solid ${brand.border}`
                      : undefined,
                }}
              >
                {row.map((cell, columnIndex) => (
                  <div
                    key={`cell-${rowIndex}-${columnIndex}`}
                    className="flex items-center justify-center px-1"
                    style={{
                      fontSize: layout.cellFontSize,
                      fontWeight: columnIndex === 0 ? 600 : 400,
                      color: columnIndex === 0 ? brand.foreground : brand.mutedForeground,
                      borderLeft:
                        columnIndex > 0 ? `1px solid ${brand.border}` : undefined,
                      opacity: columnIndex > 0 ? 0.65 : 1,
                    }}
                  >
                    <span className="truncate">{truncateCellText(cell, maxChars)}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
