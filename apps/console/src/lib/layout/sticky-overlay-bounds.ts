export type StickyOverlayBounds = {
  top: number
  shellLeft: number
  shellWidth: number
  /** Physical viewport left edge of the article column. */
  contentLeft: number
  contentWidth: number
}

export function measureStickyOverlayBounds(
  shellRect: DOMRect,
  contentRect: DOMRect,
  shellTop: number,
): StickyOverlayBounds {
  return {
    top: shellTop,
    shellLeft: shellRect.left,
    shellWidth: shellRect.width,
    contentLeft: contentRect.left,
    contentWidth: contentRect.width,
  }
}
