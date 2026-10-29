import type { DiagramNode } from '@/lib/diagram-generator/types'

export type DiagramScreenshotGravity =
  | 'top-left'
  | 'top'
  | 'top-right'
  | 'left'
  | 'center'
  | 'right'
  | 'bottom-left'
  | 'bottom'
  | 'bottom-right'

export const DEFAULT_DIAGRAM_SCREENSHOT_FOCUS = {
  focusX: 50,
  focusY: 50,
} as const

export const DIAGRAM_SCREENSHOT_GRAVITY_GRID_ROWS: readonly [
  readonly [DiagramScreenshotGravity, DiagramScreenshotGravity, DiagramScreenshotGravity],
  readonly [DiagramScreenshotGravity, DiagramScreenshotGravity, DiagramScreenshotGravity],
  readonly [DiagramScreenshotGravity, DiagramScreenshotGravity, DiagramScreenshotGravity],
] = [
  ['top-left', 'top', 'top-right'],
  ['left', 'center', 'right'],
  ['bottom-left', 'bottom', 'bottom-right'],
]

const GRAVITY_FOCUS: Record<
  DiagramScreenshotGravity,
  { focusX: number; focusY: number }
> = {
  'top-left': { focusX: 0, focusY: 0 },
  top: { focusX: 50, focusY: 0 },
  'top-right': { focusX: 100, focusY: 0 },
  left: { focusX: 0, focusY: 50 },
  center: { focusX: 50, focusY: 50 },
  right: { focusX: 100, focusY: 50 },
  'bottom-left': { focusX: 0, focusY: 100 },
  bottom: { focusX: 50, focusY: 100 },
  'bottom-right': { focusX: 100, focusY: 100 },
}

export function clampDiagramScreenshotFocus(
  value: number,
  axis: 'x' | 'y' = 'x',
): number {
  const fallback =
    axis === 'x'
      ? DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusX
      : DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusY
  if (!Number.isFinite(value)) return fallback
  return Math.min(100, Math.max(0, Math.round(value)))
}

export function getDiagramScreenshotFocus(
  node: Pick<DiagramNode, 'focusX' | 'focusY'>,
): { focusX: number; focusY: number } {
  return {
    focusX: clampDiagramScreenshotFocus(
      node.focusX ?? DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusX,
      'x',
    ),
    focusY: clampDiagramScreenshotFocus(
      node.focusY ?? DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusY,
      'y',
    ),
  }
}

export function getDiagramScreenshotGravityFromFocus(
  focusX: number,
  focusY: number,
): DiagramScreenshotGravity {
  let closest: DiagramScreenshotGravity = 'center'
  let minDistance = Number.POSITIVE_INFINITY

  for (const [gravity, position] of Object.entries(GRAVITY_FOCUS) as Array<
    [DiagramScreenshotGravity, { focusX: number; focusY: number }]
  >) {
    const distance = Math.hypot(position.focusX - focusX, position.focusY - focusY)
    if (distance < minDistance) {
      minDistance = distance
      closest = gravity
    }
  }

  return closest
}

export function getDiagramScreenshotFocusForGravity(
  gravity: DiagramScreenshotGravity,
): { focusX: number; focusY: number } {
  return GRAVITY_FOCUS[gravity]
}

export function formatDiagramScreenshotGravityLabel(
  gravity: DiagramScreenshotGravity,
): string {
  return gravity.replace('-', ' ')
}
