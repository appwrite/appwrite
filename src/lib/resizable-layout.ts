/**
 * Helpers for `react-resizable-panels`, which only accepts % sizes.
 * Define layout in px, convert at runtime from a measured container width.
 */

/** Reference width when migrating legacy sidebar prefs stored as percent (5–60). */
export const LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX = 1280

/** Table / storage workspace left nav (`TableViewResizableLayout`). */
export const TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX = 224
export const TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX = 480
export const TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX = TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX
export const TABLE_VIEW_MAIN_MIN_WIDTH_PX = 360

/** Functions local editor explorer split. */
export const FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX = 200
export const FUNCTIONS_EDITOR_EXPLORER_MAX_WIDTH_PX = 480
export const FUNCTIONS_EDITOR_EXPLORER_DEFAULT_WIDTH_PX = 280
export const FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX = 400

const LEGACY_SIDEBAR_PERCENT_MAX = 60

/** Use when the group has not been measured yet so % ↔ px math stays consistent. */
export function effectivePanelGroupWidthPx(measuredWidth: number): number {
  return measuredWidth > 0
    ? measuredWidth
    : LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX
}

export function panelPercentFromPx(
  px: number,
  containerWidth: number,
  fallbackPercent = 0,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  if (w <= 0) return fallbackPercent
  return Math.min(100, (px / w) * 100)
}

export function panelPxFromPercent(
  percent: number,
  containerWidth: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  if (w <= 0) return 0
  return Math.round((percent / 100) * w)
}

/**
 * Clamps the first (left) pane in a horizontal split so it respects min/max px
 * and leaves at least `secondMinPx` for the right pane.
 */
export function clampSplitFirstPaneWidthPx(
  widthPx: number,
  containerWidth: number,
  firstMinPx: number,
  firstMaxPx: number,
  secondMinPx: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  const maxFirstPx = Math.min(firstMaxPx, Math.max(firstMinPx, w - secondMinPx))
  return Math.min(maxFirstPx, Math.max(firstMinPx, Math.round(widthPx)))
}

/**
 * When a split's container shrinks (e.g. outer sidebar resize), only shrink the
 * first pane if it no longer fits. Do not snap toward min when the container
 * grows — that keeps nested splits independent from sibling resizes.
 */
export function fitSplitFirstPaneWidthOnContainerResize(
  currentWidthPx: number,
  containerWidth: number,
  firstMinPx: number,
  firstMaxPx: number,
  secondMinPx: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  const maxFirstPx = Math.min(firstMaxPx, Math.max(firstMinPx, w - secondMinPx))
  const current = Math.round(currentWidthPx)
  if (current > maxFirstPx) return maxFirstPx
  return current
}

export type TwoPanelHorizontalLayout = {
  firstPercent: number
  secondPercent: number
  firstMinPercent: number
  firstMaxPercent: number
  secondMinPercent: number
  firstPx: number
}

/**
 * Derives `react-resizable-panels` % sizes from px constraints. Ensures mins fit
 * in the group (sidebar + main cannot require more than 100%).
 */
export function computeTwoPanelHorizontalLayout(input: {
  containerWidth: number
  firstPx: number
  firstMinPx: number
  firstMaxPx: number
  secondMinPx: number
}): TwoPanelHorizontalLayout {
  const w = effectivePanelGroupWidthPx(input.containerWidth)
  const firstPx = clampSplitFirstPaneWidthPx(
    input.firstPx,
    w,
    input.firstMinPx,
    input.firstMaxPx,
    input.secondMinPx,
  )

  const secondMinPercent = panelPercentFromPx(input.secondMinPx, w)
  const firstMinPercent = panelPercentFromPx(input.firstMinPx, w)
  const firstMaxPercent = Math.min(
    panelPercentFromPx(input.firstMaxPx, w, 100),
    Math.max(firstMinPercent, 100 - secondMinPercent),
  )
  const effectiveFirstMinPercent = Math.min(
    firstMinPercent,
    Math.max(0, 100 - secondMinPercent),
  )

  let firstPercent = panelPercentFromPx(firstPx, w, effectiveFirstMinPercent)
  firstPercent = Math.min(
    firstMaxPercent,
    Math.max(effectiveFirstMinPercent, firstPercent),
  )

  return {
    firstPercent,
    secondPercent: 100 - firstPercent,
    firstMinPercent: effectiveFirstMinPercent,
    firstMaxPercent,
    secondMinPercent,
    firstPx,
  }
}

export function clampTableViewSidebarWidthPx(px: number): number {
  return Math.min(
    TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
    Math.max(TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX, Math.round(px)),
  )
}

/**
 * Values below {@link TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX} in account prefs were
 * stored as percent (typically 5–60). Convert using a fixed reference width.
 */
export function normalizeLegacySidebarWidthPrefValue(raw: number): number {
  if (
    raw > 0 &&
    raw <= LEGACY_SIDEBAR_PERCENT_MAX &&
    raw < TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX
  ) {
    return clampTableViewSidebarWidthPx(
      (raw / 100) * LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX,
    )
  }
  return clampTableViewSidebarWidthPx(raw)
}
