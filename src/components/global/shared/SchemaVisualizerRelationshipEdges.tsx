import { useId, useMemo } from 'react'
import { cn } from '@/lib/utils'
import type { SchemaVisualizerRelationshipPath } from '@/lib/schema-visualizer-relationship-paths'

const RELATIONSHIP_EDGE_OPACITY = 0.45

type SchemaVisualizerRelationshipEdgesProps = {
  paths: SchemaVisualizerRelationshipPath[]
  /** Layout extent (px) so lines are not clipped; should match node positions. */
  extent?: { width: number; height: number }
  className?: string
  /** When false, connectors are plain lines with no arrowheads. Defaults to true. */
  showArrowHeads?: boolean
}

function resolveEdgeCanvasExtent(
  paths: SchemaVisualizerRelationshipPath[],
  extent?: { width: number; height: number },
) {
  const padding = 160
  let maxX = extent?.width ?? padding
  let maxY = extent?.height ?? padding

  for (const path of paths) {
    maxX = Math.max(maxX, path.from.x + padding, path.to.x + padding)
    maxY = Math.max(maxY, path.from.y + padding, path.to.y + padding)
  }

  return {
    width: Math.max(maxX, 1),
    height: Math.max(maxY, 1),
  }
}

/**
 * Renders FK relationship connectors on the schema visualizer canvas.
 * Shares the same origin (0,0) and pixel coordinates as absolutely positioned table nodes.
 */
export function SchemaVisualizerRelationshipEdges({
  paths,
  extent,
  className,
  showArrowHeads = true,
}: SchemaVisualizerRelationshipEdgesProps) {
  const reactId = useId()
  const markerId = `schema-visualizer-arrow-${reactId.replace(/:/g, '')}`

  const canvas = useMemo(
    () => resolveEdgeCanvasExtent(paths, extent),
    [paths, extent],
  )

  if (paths.length === 0) return null

  return (
    <svg
      className={cn('absolute pointer-events-none z-[8] text-foreground', className)}
      style={{
        left: 0,
        top: 0,
        width: canvas.width,
        height: canvas.height,
        overflow: 'visible',
      }}
      aria-hidden
    >
      {showArrowHeads ? (
        <defs>
          <marker
            id={markerId}
            markerWidth="10"
            markerHeight="10"
            refX="9"
            refY="5"
            orient="auto"
            markerUnits="userSpaceOnUse"
          >
            <polygon
              points="0 0, 10 5, 0 10"
              className="fill-foreground"
              opacity={RELATIONSHIP_EDGE_OPACITY}
            />
          </marker>
        </defs>
      ) : null}
      <g>
        {paths.map((path, index) => (
          <path
            key={`schema-edge-${index}`}
            d={path.d}
            fill="none"
            stroke="currentColor"
            strokeWidth="1.25"
            strokeLinejoin="round"
            strokeLinecap="round"
            strokeDasharray="3 5"
            markerEnd={showArrowHeads ? `url(#${markerId})` : undefined}
            opacity={RELATIONSHIP_EDGE_OPACITY}
          />
        ))}
      </g>
    </svg>
  )
}

type SchemaVisualizerRelationshipEdgesMinimapProps = {
  paths: SchemaVisualizerRelationshipPath[]
  isDarkMode: boolean
}

export function SchemaVisualizerRelationshipEdgesMinimap({
  paths,
  isDarkMode,
}: SchemaVisualizerRelationshipEdgesMinimapProps) {
  const stroke = isDarkMode ? 'oklch(0.985 0 0)' : 'oklch(0.141 0.005 285.823)'

  return (
    <>
      {paths.map((path, index) => (
        <path
          key={`minimap-edge-${index}`}
          d={path.d}
          fill="none"
          stroke={stroke}
          strokeWidth="1.5"
          strokeLinejoin="round"
          strokeDasharray="3 5"
          opacity={RELATIONSHIP_EDGE_OPACITY}
        />
      ))}
    </>
  )
}
