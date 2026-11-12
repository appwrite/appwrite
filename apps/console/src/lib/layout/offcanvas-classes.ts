/** Hide a start-anchored panel (inline-start edge) when off-screen. */
export const OFFCANVAS_START_CLOSED =
  'ltr:-translate-x-full rtl:translate-x-full'

/** Hide an end-anchored panel (inline-end edge) when off-screen. */
export const OFFCANVAS_END_CLOSED =
  'ltr:translate-x-full rtl:-translate-x-full'

/** Radix Sheet enter/exit for a start-anchored panel (side=left). */
export const OFFCANVAS_START_SHEET_MOTION =
  'data-[state=open]:slide-in-from-start data-[state=closed]:slide-out-to-start'

/** Radix Sheet enter/exit for an end-anchored panel (side=right). */
export const OFFCANVAS_END_SHEET_MOTION =
  'data-[state=open]:slide-in-from-end data-[state=closed]:slide-out-to-end'

/** Shared duration + easing for horizontal off-canvas sheets. */
export const OFFCANVAS_SHEET_MOTION_TIMING =
  'ease-in-out data-[state=closed]:duration-300 data-[state=open]:duration-300'

/** Half-outside toggle sitting on the inline-end edge of a sidebar. */
export const SIDEBAR_EDGE_TOGGLE_OVERFLOW =
  'ltr:translate-x-1/2 rtl:-translate-x-1/2'
