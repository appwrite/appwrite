/** Hide a start-anchored panel (inline-start edge) when off-screen. */
export const OFFCANVAS_START_CLOSED =
  'ltr:-translate-x-full rtl:translate-x-full'

/** Hide an end-anchored panel (inline-end edge) when off-screen. */
export const OFFCANVAS_END_CLOSED =
  'ltr:translate-x-full rtl:-translate-x-full'

/** Radix Sheet / dialog closed state for a start-anchored panel. */
export const OFFCANVAS_START_CLOSED_STATE =
  'data-[state=closed]:ltr:-translate-x-full data-[state=closed]:rtl:translate-x-full'

/** Radix Sheet / dialog closed state for an end-anchored panel. */
export const OFFCANVAS_END_CLOSED_STATE =
  'data-[state=closed]:ltr:translate-x-full data-[state=closed]:rtl:-translate-x-full'

/** Shared open + motion classes for horizontal off-canvas panels. */
export const OFFCANVAS_HORIZONTAL_MOTION =
  'transition-transform duration-300 ease-in-out data-[state=open]:translate-x-0'

/** Half-outside toggle sitting on the inline-end edge of a sidebar. */
export const SIDEBAR_EDGE_TOGGLE_OVERFLOW =
  'ltr:translate-x-1/2 rtl:-translate-x-1/2'
