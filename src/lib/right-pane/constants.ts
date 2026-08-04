/** Shared console right pane (docs preview, assistant, future panels). */
export const RIGHT_PANE_MIN_WIDTH_PX = 400
export const RIGHT_PANE_MAX_WIDTH_PX = 800
export const RIGHT_PANE_DEFAULT_WIDTH_PX = 560

export function clampRightPaneWidthPx(px: number): number {
  return Math.min(
    RIGHT_PANE_MAX_WIDTH_PX,
    Math.max(RIGHT_PANE_MIN_WIDTH_PX, Math.round(px)),
  )
}
