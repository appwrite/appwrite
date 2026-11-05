import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'
import { CoverIconPreview } from '@/components/pages/generator/_components/CoverIconPreview'
import { CoverTableFramePreview } from '@/components/pages/generator/_components/CoverTableFramePreview'
import { CoverHeroBrowserFrame } from '@/components/global/shared/CoverHeroBrowserFrame'
import type { DiagramAnchorSide, DiagramNode } from '@/lib/diagram-generator/types'
import { DIAGRAM_NODE_KIND_LABELS } from '@/lib/diagram-generator/constants'
import { createDefaultDiagramTable } from '@/lib/diagram-generator/diagram-table'
import { getDiagramNodeSurfaceColors } from '@/lib/diagram-generator/node-chrome'
import { getDiagramNodeIconSrc, hasDiagramNodeIcon } from '@/lib/diagram-generator/node-normalize'
import { getDiagramScreenshotFocus } from '@/lib/diagram-generator/screenshot-focus'
import { DiagramNodeChrome } from '@/components/pages/generator/diagrams/_components/DiagramNodeChrome'
import { ImageIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

type DiagramNodeViewProps = {
  node: DiagramNode
  brand: CoverBrandTheme
  themeId: CoverEditorThemeId
  stackIndex?: number
  selected?: boolean
  interactive?: boolean
  showPorts?: boolean
  connectDraftSide?: DiagramAnchorSide | null
  onPointerDown?: (event: React.PointerEvent<HTMLDivElement>) => void
  onClick?: (event: React.MouseEvent<HTMLDivElement>) => void
  onPortPointerDown?: (side: DiagramAnchorSide, event: React.PointerEvent<HTMLButtonElement>) => void
  onResizePointerDown?: (event: React.PointerEvent<HTMLButtonElement>) => void
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

function shouldShowCaption(node: DiagramNode): boolean {
  const defaultLabel = DIAGRAM_NODE_KIND_LABELS[node.kind]
  return Boolean(node.label.trim() && node.label !== defaultLabel)
}

function getSelectedChromeShadow(brand: CoverBrandTheme, selected: boolean): string | undefined {
  if (!selected) return `0 1px 3px ${withAlpha(brand.foreground, 0.04)}`
  return `0 2px 8px ${withAlpha(brand.foreground, 0.05)}, 0 0 0 1px ${withAlpha(brand.brandCta, 0.25)}`
}

export function DiagramNodeView({
  node,
  brand,
  themeId,
  stackIndex = 0,
  selected = false,
  interactive = false,
  showPorts = false,
  connectDraftSide = null,
  onPointerDown,
  onClick,
  onPortPointerDown,
  onResizePointerDown,
}: DiagramNodeViewProps) {
  const chromeProps = {
    nodeId: node.id,
    node,
    brand,
    stackIndex,
    selected,
    interactive,
    showPorts,
    connectDraftSide,
    onPointerDown,
    onClick: onClick ? (event: React.MouseEvent<HTMLDivElement>) => onClick(event) : undefined,
    onPortPointerDown,
    onResizePointerDown: selected ? onResizePointerDown : undefined,
  }

  if (node.kind === 'group') {
    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn('rounded-2xl border border-dashed', selected && 'ring-1 ring-offset-1')}
        style={{
          borderColor: withAlpha(brand.border, 0.9),
          backgroundColor: withAlpha(brand.muted, 0.18),
          boxShadow: selected
            ? `0 0 0 1px ${withAlpha(brand.brandCta, 0.35)}`
            : undefined,
        }}
      >
        <div
          className="absolute left-4 top-3 text-[12px] font-semibold uppercase tracking-wider"
          style={{ color: brand.mutedForeground }}
        >
          {node.label}
        </div>
      </DiagramNodeChrome>
    )
  }

  if (node.kind === 'label') {
    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn(
          'flex items-center justify-center rounded-md px-3 py-1 text-center text-[14px] font-medium',
          interactive && 'cursor-pointer',
        )}
        style={{
          color: brand.foreground,
          backgroundColor: withAlpha(brand.background, 0.72),
          border: `1px solid ${withAlpha(brand.border, 0.65)}`,
          boxShadow: selected
            ? `0 1px 4px ${withAlpha(brand.foreground, 0.04)}, 0 0 0 1px ${withAlpha(brand.brandCta, 0.3)}`
            : `0 1px 2px ${withAlpha(brand.foreground, 0.03)}`,
          minHeight: node.height,
        }}
      >
        {node.label}
      </DiagramNodeChrome>
    )
  }

  if (node.kind === 'title') {
    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn(
          'flex flex-col items-center justify-center px-4 text-center',
          interactive && 'cursor-pointer',
        )}
        style={{
          backgroundColor: 'transparent',
          border: selected ? `1px dashed ${withAlpha(brand.brandCta, 0.45)}` : '1px solid transparent',
          boxShadow: selected
            ? `0 0 0 1px ${withAlpha(brand.brandCta, 0.2)}`
            : undefined,
        }}
      >
        <p
          className="font-aeonik-pro max-w-full text-balance text-[28px] font-normal leading-tight tracking-[-0.022em]"
          style={{ color: brand.foreground }}
        >
          {node.label}
        </p>
        {node.subtitle ? (
          <p
            className="mt-2 max-w-full text-balance text-[14px] font-medium leading-snug"
            style={{ color: brand.mutedForeground }}
          >
            {node.subtitle}
          </p>
        ) : null}
      </DiagramNodeChrome>
    )
  }

  if (node.kind === 'icon') {
    const showCaption = shouldShowCaption(node)
    const chromePadding = 16
    const captionBand = showCaption ? 20 : 0
    const availableWidth = node.width - chromePadding * 2
    const availableHeight = node.height - chromePadding * 2 - captionBand
    const iconSize = Math.max(24, Math.min(availableWidth, availableHeight))
    const surface = getDiagramNodeSurfaceColors(brand, themeId)

    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn(
          'flex flex-col items-center justify-center rounded-xl border px-4 py-4',
          interactive && 'cursor-pointer',
        )}
        style={{
          backgroundColor: surface.fill,
          borderColor: selected ? brand.brandCta : surface.stroke,
          borderWidth: surface.strokeWidth,
          boxShadow: getSelectedChromeShadow(brand, selected),
        }}
      >
        <CoverIconPreview
          src={getDiagramNodeIconSrc(node)}
          themeId={themeId}
          colorMode="cover"
          size={iconSize}
        />
        {showCaption ? (
          <p
            className="mt-1 max-w-full truncate px-1 text-center text-[11px] font-medium"
            style={{ color: brand.mutedForeground }}
          >
            {node.label}
          </p>
        ) : null}
      </DiagramNodeChrome>
    )
  }

  if (node.kind === 'screenshot') {
    const showCaption = shouldShowCaption(node)
    const captionBandHeight = showCaption ? 28 : 0
    const frameHeight = Math.max(1, node.height - captionBandHeight)
    const focus = getDiagramScreenshotFocus(node)

    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn(
          'flex flex-col overflow-hidden',
          interactive && 'cursor-pointer',
          selected && 'ring-1 ring-offset-1',
        )}
        style={{
          boxShadow: selected
            ? `0 0 0 1px ${withAlpha(brand.brandCta, 0.35)}`
            : undefined,
        }}
      >
        <div className="min-h-0 flex-1 overflow-hidden">
          <CoverHeroBrowserFrame
            frameWidth={node.width}
            frameHeight={frameHeight}
            themeId={themeId}
            closed
            src={node.imageSrc}
            focusX={focus.focusX}
            focusY={focus.focusY}
            alt={node.label || 'Screenshot'}
            placeholder={
              <div
                className="flex size-full flex-col items-center justify-center gap-2 px-3 text-center"
                style={{ color: brand.mutedForeground }}
              >
                <ImageIcon className="size-6" strokeWidth={1.5} />
                <span className="text-[11px] font-medium">Upload screenshot</span>
              </div>
            }
          />
        </div>
        {showCaption ? (
          <div
            className="shrink-0 border-t px-2 py-1.5 text-center text-[11px] font-medium"
            style={{
              borderColor: withAlpha(brand.border, 0.8),
              color: brand.foreground,
              backgroundColor: withAlpha(brand.background, 0.88),
            }}
          >
            <span className="block truncate">{node.label}</span>
          </div>
        ) : null}
      </DiagramNodeChrome>
    )
  }

  if (node.kind === 'table') {
    const defaults = createDefaultDiagramTable()
    const headers = node.tableHeaders ?? defaults.tableHeaders
    const rows = node.tableRows ?? defaults.tableRows
    const showTitle = shouldShowCaption(node)

    return (
      <DiagramNodeChrome
        {...chromeProps}
        className={cn(
          'overflow-visible',
          interactive && 'cursor-pointer',
          selected && 'ring-1 ring-offset-1',
        )}
        style={{
          boxShadow: selected
            ? `0 0 0 1px ${withAlpha(brand.brandCta, 0.35)}`
            : undefined,
        }}
      >
        <CoverTableFramePreview
          themeId={themeId}
          width={node.width}
          height={node.height}
          title={showTitle ? node.label : undefined}
          subtitle={node.subtitle}
          headers={headers}
          rows={rows}
          instanceId={node.id}
        />
      </DiagramNodeChrome>
    )
  }

  const surface = getDiagramNodeSurfaceColors(brand, themeId)

  return (
    <DiagramNodeChrome
      {...chromeProps}
      className={cn(
        'flex items-center gap-3 rounded-xl border px-4 py-3',
        interactive && 'cursor-pointer',
      )}
      style={{
        backgroundColor: surface.fill,
        borderColor: selected ? brand.brandCta : surface.stroke,
        borderWidth: surface.strokeWidth,
        boxShadow: selected
          ? `0 2px 8px ${withAlpha(brand.foreground, 0.05)}, 0 0 0 1px ${withAlpha(brand.brandCta, 0.25)}`
          : `0 1px 3px ${withAlpha(brand.foreground, 0.04)}`,
      }}
    >
      {hasDiagramNodeIcon(node) ? (
        <div
          className="flex size-10 shrink-0 items-center justify-center rounded-lg"
          style={{
            backgroundColor: withAlpha(brand.muted, 0.85),
            color: brand.foreground,
          }}
        >
          <CoverIconPreview
            src={getDiagramNodeIconSrc(node)}
            themeId={themeId}
            colorMode="cover"
            size={24}
          />
        </div>
      ) : null}
      <div className="min-w-0 flex-1">
        <p
          className="truncate text-[14px] font-semibold leading-tight"
          style={{ color: brand.foreground }}
        >
          {node.label}
        </p>
        {node.subtitle ? (
          <p
            className="mt-0.5 truncate text-[12px]"
            style={{ color: brand.mutedForeground }}
          >
            {node.subtitle}
          </p>
        ) : null}
      </div>
    </DiagramNodeChrome>
  )
}
