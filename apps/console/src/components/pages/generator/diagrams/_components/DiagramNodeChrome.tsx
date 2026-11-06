import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import { getDiagramNodeAnchor } from '@/lib/diagram-generator/edge-paths'
import type { DiagramAnchorSide } from '@/lib/diagram-generator/types'
import { cn } from '@/lib/utils'

const ANCHOR_SIDES: DiagramAnchorSide[] = ['top', 'right', 'bottom', 'left']

type DiagramNodeChromeProps = {
  nodeId: string
  node: { x: number; y: number; width: number; height: number }
  brand: CoverBrandTheme
  stackIndex?: number
  selected?: boolean
  interactive?: boolean
  connectDraftSide?: DiagramAnchorSide | null
  showPorts?: boolean
  className?: string
  style?: React.CSSProperties
  children: React.ReactNode
  onPointerDown?: (event: React.PointerEvent<HTMLDivElement>) => void
  onClick?: (event: React.MouseEvent<HTMLDivElement>) => void
  onPortPointerDown?: (side: DiagramAnchorSide, event: React.PointerEvent<HTMLButtonElement>) => void
  onResizePointerDown?: (event: React.PointerEvent<HTMLButtonElement>) => void
}

function stopCanvasPan(event: React.MouseEvent | React.PointerEvent) {
  event.stopPropagation()
}

function getPortPosition(
  side: DiagramAnchorSide,
  width: number,
  height: number,
): { left: number; top: number } {
  const size = 12
  switch (side) {
    case 'top':
      return { left: width / 2 - size / 2, top: -size / 2 }
    case 'bottom':
      return { left: width / 2 - size / 2, top: height - size / 2 }
    case 'left':
      return { left: -size / 2, top: height / 2 - size / 2 }
    case 'right':
      return { left: width - size / 2, top: height / 2 - size / 2 }
  }
}

export function DiagramNodeChrome({
  nodeId,
  node,
  brand,
  stackIndex = 0,
  selected = false,
  interactive = false,
  connectDraftSide = null,
  showPorts = false,
  className,
  style,
  children,
  onPointerDown,
  onClick,
  onPortPointerDown,
  onResizePointerDown,
}: DiagramNodeChromeProps) {
  return (
    <div
      data-diagram-node=""
      className={cn('absolute', interactive && 'pointer-events-auto', className)}
      style={{
        left: node.x,
        top: node.y,
        width: node.width,
        height: node.height,
        zIndex: stackIndex + 1,
        ...style,
      }}
      onMouseDown={interactive ? stopCanvasPan : undefined}
      onPointerDown={onPointerDown}
      onClick={(event) => {
        event.stopPropagation()
        onClick?.(event)
      }}
    >
      {children}

      {interactive && showPorts
        ? ANCHOR_SIDES.map((side) => {
            const position = getPortPosition(side, node.width, node.height)
            const isActive = connectDraftSide === side

            return (
              <button
                key={side}
                type="button"
                data-diagram-port=""
                data-node-id={nodeId}
                data-port-side={side}
                aria-label={`Connect from ${side}`}
                className={cn(
                  'absolute z-30 size-3 rounded-full border-2 transition-transform before:absolute before:-inset-1.5 before:content-[""] hover:scale-125',
                  isActive ? 'scale-125' : '',
                )}
                style={{
                  left: position.left,
                  top: position.top,
                  backgroundColor: isActive ? brand.brandCta : brand.background,
                  borderColor: isActive ? brand.brandCta : brand.foreground,
                }}
                onMouseDown={stopCanvasPan}
                onPointerDown={(event) => {
                  event.stopPropagation()
                  onPortPointerDown?.(side, event)
                }}
                onClick={(event) => event.stopPropagation()}
              />
            )
          })
        : null}

      {interactive && selected && onResizePointerDown ? (
        <button
          type="button"
          data-diagram-resize=""
          aria-label="Resize"
          className="absolute z-20 size-3 cursor-se-resize rounded-sm border"
          style={{
            right: -6,
            bottom: -6,
            backgroundColor: brand.background,
            borderColor: brand.brandCta,
          }}
          onMouseDown={stopCanvasPan}
          onPointerDown={(event) => {
            event.stopPropagation()
            onResizePointerDown(event)
          }}
          onClick={(event) => event.stopPropagation()}
        />
      ) : null}
    </div>
  )
}

export function getDiagramConnectPreviewPath(
  node: { x: number; y: number; width: number; height: number },
  side: DiagramAnchorSide,
  pointer: { x: number; y: number },
): string {
  const anchor = getDiagramNodeAnchor(node, side)
  return `M ${anchor.x} ${anchor.y} L ${pointer.x} ${pointer.y}`
}
