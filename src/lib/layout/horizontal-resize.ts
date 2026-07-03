import type { CSSProperties } from 'react'
import { cn } from '@/lib/utils'

let bodyResizeDragLockCount = 0
let savedBodyUserSelect = ''
let savedBodyCursor = ''

/** Disable text selection (and optionally set cursor) while dragging a resize handle. */
export function setBodyResizeDragActive(
  active: boolean,
  cursor: string | null = 'col-resize',
): void {
  if (typeof document === 'undefined') return

  if (active) {
    if (bodyResizeDragLockCount === 0) {
      savedBodyUserSelect = document.body.style.userSelect
      savedBodyCursor = document.body.style.cursor
      document.body.style.userSelect = 'none'
      if (cursor) document.body.style.cursor = cursor
    }
    bodyResizeDragLockCount += 1
    return
  }

  bodyResizeDragLockCount = Math.max(0, bodyResizeDragLockCount - 1)
  if (bodyResizeDragLockCount === 0) {
    document.body.style.userSelect = savedBodyUserSelect
    document.body.style.cursor = savedBodyCursor
  }
}

export const COLUMN_RESIZE_RAIL_WIDTH_PX = 8

export const SPLIT_PANE_RESIZE_HANDLE_WIDTH_PX = 6

/** Centers a w-2 (8px) pseudo-element on its parent using logical start. */
export const RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X =
  'before:start-1/2 before:-ms-1'

export const RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X =
  'after:start-1/2 after:-ms-1'

/** @deprecated Use {@link RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X}. */
export const RESIZE_HANDLE_PSEUDO_BEFORE_PHYSICAL_X =
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X

/** @deprecated Use {@link RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X}. */
export const RESIZE_HANDLE_PSEUDO_AFTER_PHYSICAL_X =
  RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X

/** @deprecated Use logical inset helpers instead. */
export const RESIZE_HANDLE_CENTER_PHYSICAL_X = '-translate-x-1/2'

/** @deprecated Use {@link RESIZE_HANDLE_CENTER_PHYSICAL_X}. */
export const RESIZE_HANDLE_CENTER_X = RESIZE_HANDLE_CENTER_PHYSICAL_X

/** @deprecated Use {@link RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X}. */
export const RESIZE_HANDLE_PSEUDO_BEFORE_X = RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X

/** @deprecated Use {@link RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X}. */
export const RESIZE_HANDLE_PSEUDO_AFTER_X = RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X

export function isRtlElement(element: HTMLElement | null | undefined): boolean {
  if (!element || typeof window === 'undefined') return false
  return window.getComputedStyle(element).direction === 'rtl'
}

/** Distance from a layer's inline-start edge to a column's inline-end border. */
export function columnBorderInsetInlineStartPx(
  layerRect: DOMRect,
  columnRect: DOMRect,
  isRtl: boolean,
): number {
  return isRtl
    ? layerRect.right - columnRect.right
    : columnRect.right - layerRect.left
}

/** Place a handle's center on a border measured from inline-start. */
export function insetInlineStartCenteredOnBorderPx(
  borderFromInlineStartPx: number,
  handleWidthPx: number,
): number {
  return borderFromInlineStartPx - handleWidthPx / 2
}

export function applyColumnResizeRailPosition(
  rail: HTMLElement,
  layer: HTMLElement,
  columnHeader: HTMLElement,
  options?: {
    handleWidthPx?: number
    maxInsetInlineStartPx?: number
  },
): void {
  const handleWidthPx = options?.handleWidthPx ?? COLUMN_RESIZE_RAIL_WIDTH_PX
  const layerRect = layer.getBoundingClientRect()
  const columnRect = columnHeader.getBoundingClientRect()
  const isRtl = isRtlElement(layer)
  let insetInlineStart = insetInlineStartCenteredOnBorderPx(
    columnBorderInsetInlineStartPx(layerRect, columnRect, isRtl),
    handleWidthPx,
  )
  if (options?.maxInsetInlineStartPx != null) {
    insetInlineStart = Math.min(
      insetInlineStart,
      Math.max(0, options.maxInsetInlineStartPx - handleWidthPx),
    )
  }
  rail.style.left = ''
  rail.style.right = ''
  rail.style.insetInlineStart = `${insetInlineStart}px`
}

/** Shared chrome for vertical `ResizableHandle` between horizontal panels. */
export function verticalPanelResizeHandleClass(
  ...extra: (string | undefined)[]
): string {
  return cn(
    'relative z-[45] w-[0.5px] bg-border',
    'before:pointer-events-none before:absolute before:inset-y-0 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
    RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
    'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
    'after:pointer-events-none after:absolute after:inset-y-0 after:w-2',
    RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X,
    ...extra,
  )
}

/** Pointer delta for resizing the inline-start pane in a horizontal split. */
export function horizontalResizeDeltaPx(
  startX: number,
  currentX: number,
  isRtl: boolean,
): number {
  return isRtl ? startX - currentX : currentX - startX
}

/**
 * Position a split handle so its center sits on the pane border.
 * Pair with `w-1.5` (or pass a matching `handleWidthPx`).
 */
export function horizontalSplitHandleStyle(
  firstPanePx: number,
  handleWidthPx = SPLIT_PANE_RESIZE_HANDLE_WIDTH_PX,
): CSSProperties {
  return {
    insetInlineStart: insetInlineStartCenteredOnBorderPx(
      firstPanePx,
      handleWidthPx,
    ),
  }
}

/** Center a handle on the inline-start edge of its offset parent (e.g. right pane). */
export function resizeHandleOnInlineStartEdgeStyle(
  handleWidthPx = SPLIT_PANE_RESIZE_HANDLE_WIDTH_PX,
): CSSProperties {
  return {
    insetInlineStart: -handleWidthPx / 2,
  }
}

/**
 * Width of a pane docked to the inline-end of the viewport while dragging its
 * inline-start edge handle (e.g. assistant/docs pane).
 */
export function inlineEndPaneWidthFromPointer(
  clientX: number,
  viewportWidth: number,
  isRtl: boolean,
): number {
  return isRtl ? clientX : viewportWidth - clientX
}
