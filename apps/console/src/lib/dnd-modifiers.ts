import type { Modifier } from '@dnd-kit/core'

export const restrictToHorizontalAxis: Modifier = ({ transform }) => ({
  ...transform,
  y: 0,
})

export const restrictToVerticalAxis: Modifier = ({ transform }) => ({
  ...transform,
  x: 0,
})

export const restrictToParentElement: Modifier = ({
  containerNodeRect,
  draggingNodeRect,
  transform,
}) => {
  if (!containerNodeRect || !draggingNodeRect) {
    return transform
  }

  const value = { ...transform }

  if (draggingNodeRect.top + transform.y <= containerNodeRect.top) {
    value.y = containerNodeRect.top - draggingNodeRect.top
  } else if (
    draggingNodeRect.bottom + transform.y >= containerNodeRect.bottom
  ) {
    value.y = containerNodeRect.bottom - draggingNodeRect.bottom
  }

  if (draggingNodeRect.left + transform.x <= containerNodeRect.left) {
    value.x = containerNodeRect.left - draggingNodeRect.left
  } else if (draggingNodeRect.right + transform.x >= containerNodeRect.right) {
    value.x = containerNodeRect.right - draggingNodeRect.right
  }

  return value
}

export function getAxisRestrictedDragModifiers(
  axis: 'horizontal' | 'vertical',
): Modifier[] {
  const axisModifier =
    axis === 'horizontal' ? restrictToHorizontalAxis : restrictToVerticalAxis
  return [axisModifier, restrictToParentElement]
}

export function sortableAxisTransform(
  transform: { x: number; y: number } | null,
  axis: 'horizontal' | 'vertical',
): string | undefined {
  if (!transform) return undefined
  if (axis === 'horizontal') {
    return `translate3d(${transform.x}px, 0, 0)`
  }
  return `translate3d(0, ${transform.y}px, 0)`
}
