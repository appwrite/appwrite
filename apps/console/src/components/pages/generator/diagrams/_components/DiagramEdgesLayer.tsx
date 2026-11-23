import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import {
  buildDiagramEdgeArrowheadPath,
  type DiagramEdgePath,
} from '@/lib/diagram-generator/edge-paths'
import {
  getDiagramEdgeDash,
  getDiagramEdgeOpacity,
  getDiagramEdgeStroke,
} from '@/lib/diagram-generator/edge-appearance'
import { getDiagramEdgeLabelMetrics } from '@/lib/diagram-generator/edge-label'
import type { DiagramEdgeStrokeTone } from '@/lib/diagram-generator/types'

type DiagramEdgesLayerProps = {
  paths: DiagramEdgePath[]
  brand: CoverBrandTheme
  layer: 'strokes' | 'arrowheads' | 'labels' | 'hit'
  selectedEdgeId?: string | null
  onEdgeClick?: (edgeId: string) => void
}

function stopEdgePointerEvent(event: React.MouseEvent | React.PointerEvent) {
  event.stopPropagation()
}

export function DiagramEdgesLayer({
  paths,
  brand,
  layer,
  selectedEdgeId,
  onEdgeClick,
}: DiagramEdgesLayerProps) {
  if (layer === 'hit') {
    return (
      <>
        {paths.map((path) => (
          <g key={path.id}>
            <path
              d={path.d}
              fill="none"
              stroke="transparent"
              strokeWidth={18}
              data-diagram-edge=""
              className="pointer-events-auto cursor-pointer"
              onMouseDown={stopEdgePointerEvent}
              onClick={(event) => {
                stopEdgePointerEvent(event)
                onEdgeClick?.(path.id)
              }}
            />
            {path.label ? (
              <rect
                x={path.labelX - 40}
                y={path.labelY - 14}
                width={80}
                height={28}
                rx={14}
                fill="transparent"
                data-diagram-edge=""
                className="pointer-events-auto cursor-pointer"
                onMouseDown={stopEdgePointerEvent}
                onClick={(event) => {
                  stopEdgePointerEvent(event)
                  onEdgeClick?.(path.id)
                }}
              />
            ) : null}
          </g>
        ))}
      </>
    )
  }

  if (layer === 'arrowheads') {
    return (
      <>
        {paths.map((path) => {
          const selected = selectedEdgeId === path.id
          const stroke = getDiagramEdgeStroke(
            path.strokeTone as DiagramEdgeStrokeTone,
            brand,
            selected,
          )
          const strokeOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
            selected,
            part: 'stroke',
          })

          return (
            <g key={path.id} pointerEvents="none">
              {path.forwardArrow ? (
                <path
                  d={buildDiagramEdgeArrowheadPath(path.forwardArrow)}
                  fill={stroke}
                  stroke="none"
                  opacity={strokeOpacity}
                />
              ) : null}
              {path.backwardArrow ? (
                <path
                  d={buildDiagramEdgeArrowheadPath(path.backwardArrow)}
                  fill={stroke}
                  stroke="none"
                  opacity={strokeOpacity}
                />
              ) : null}
            </g>
          )
        })}
      </>
    )
  }

  if (layer === 'labels') {
    return (
      <>
        {paths.map((path) => {
          if (!path.label) return null

          const selected = selectedEdgeId === path.id
          const labelOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
            selected,
            part: 'label',
          })
          const metrics = getDiagramEdgeLabelMetrics(path.label)

          return (
            <g key={path.id} pointerEvents="none" opacity={labelOpacity}>
              <rect
                x={path.labelX - metrics.offsetX}
                y={path.labelY - metrics.offsetY}
                width={metrics.width}
                height={metrics.height}
                rx={metrics.rx}
                fill={brand.background}
                stroke={selected ? brand.brandCta : brand.border}
                strokeWidth={selected ? 1.5 : 1}
              />
              <text
                x={path.labelX}
                y={path.labelY + 5}
                textAnchor="middle"
                fill={selected ? brand.brandCta : brand.mutedForeground}
                fontSize={12}
                fontFamily="Inter, system-ui, sans-serif"
                fontWeight={500}
              >
                {path.label}
              </text>
            </g>
          )
        })}
      </>
    )
  }

  return (
    <>
      {paths.map((path) => {
        const selected = selectedEdgeId === path.id
        const stroke = getDiagramEdgeStroke(
          path.strokeTone as DiagramEdgeStrokeTone,
          brand,
          selected,
        )
        const strokeOpacity = getDiagramEdgeOpacity(path.lineStyle, path.strokeTone, {
          selected,
          part: 'stroke',
        })

        return (
          <g key={path.id} pointerEvents="none">
            <path
              d={path.d}
              fill="none"
              stroke={stroke}
              strokeWidth={selected ? 2.5 : 2}
              strokeLinecap="butt"
              strokeLinejoin="miter"
              strokeMiterlimit={4}
              strokeDasharray={getDiagramEdgeDash(path.lineStyle)}
              opacity={strokeOpacity}
            />
          </g>
        )
      })}
    </>
  )
}
