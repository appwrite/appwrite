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

/** Full-table overlay so column resize rails sit above sticky cells for hit-testing. */
export const COLUMN_RESIZE_RAILS_LAYER_CLASS =
  'pointer-events-none absolute inset-0 z-50'

export const SPLIT_PANE_RESIZE_HANDLE_WIDTH_PX = 6

/** Centers a w-2 (8px) pseudo-element on its parent using logical start. */
export const RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X =
  'before:start-1/2 before:-ms-1'

export const RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X =
  'after:start-1/2 after:-ms-1'

/**
 * Centers a 0.5px hairline pseudo on its parent using logical start.
 * Do not use {@link RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X} for hairlines: that
 * constant offsets by 4px (half of w-2) and draws a second border beside the cell.
 */
export const RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X =
  'after:start-1/2 after:-ms-[0.25px]'

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

/** Place a handle's center on a border measured from inline-start. */
export function insetInlineStartCenteredOnBorderPx(
  borderFromInlineStartPx: number,
  handleWidthPx: number,
): number {
  return borderFromInlineStartPx - handleWidthPx / 2
}

/**
 * Position a column resize rail on the column's inline-end border (`border-e`).
 *
 * Same model as submenu / split-pane handles: the rail sits on the inline-end
 * edge and width changes use {@link horizontalResizeDeltaPx} so drag direction
 * matches the pointer in both LTR and RTL. Uses physical `left` (not
 * `inset-inline-start`) so placement does not depend on the layer's direction.
 */
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
  const isRtl = isRtlElement(document.documentElement)
  // Inline-end border: physical right in LTR, physical left in RTL.
  const borderX = isRtl ? columnRect.left : columnRect.right
  let left = borderX - layerRect.left - handleWidthPx / 2
  if (options?.maxInsetInlineStartPx != null) {
    left = Math.min(
      left,
      Math.max(0, options.maxInsetInlineStartPx - handleWidthPx),
    )
  }
  left = Math.max(0, left)
  rail.style.insetInlineStart = ''
  rail.style.right = ''
  rail.style.left = `${left}px`
}

/** Shared chrome for spreadsheet column resize rail buttons. */
export function columnResizeRailHandleClass(
  ...extra: (string | undefined)[]
): string {
  return cn(
    'group pointer-events-auto absolute top-0 bottom-0 z-[41] w-2 cursor-col-resize touch-none border-0 bg-transparent p-0 outline-none',
    'after:pointer-events-none after:absolute after:inset-y-0 after:w-[0.5px] after:bg-border',
    RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
    'before:pointer-events-none before:absolute before:inset-y-0 before:z-10 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
    RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
    'hover:before:opacity-100',
    ...extra,
  )
}

export function verticalPanelResizeHandleClass(
  ...extra: (string | undefined)[]
): string {
  return cn(
    'relative z-[45] w-[0.5px] bg-border',
    'before:pointer-events-none before:absolute before:inset-y-0 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
    RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
    // Library sets hover via hit-area margins; CSS :hover alone misses the 0.5px rail.
    'hover:before:opacity-100 data-[resize-handle-state=hover]:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
    'after:pointer-events-none after:absolute after:inset-y-0 after:w-2',
    RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X,
    ...extra,
  )
}

/**
 * Pointer delta when resizing from an inline-end edge (submenu sidebars,
 * document splits, spreadsheet columns).
 *
 * Drag toward inline-end grows: right in LTR, left in RTL. Matches
 * `ResizablePanelGroup` with `dir="rtl"`.
 */
export function horizontalResizeDeltaPx(
  startX: number,
  currentX: number,
  isRtl: boolean = isRtlElement(
    typeof document !== 'undefined' ? document.documentElement : null,
  ),
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
