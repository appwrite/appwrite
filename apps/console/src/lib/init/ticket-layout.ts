/** Init ticket frame assets - 1024×682 with outer padding baked in. */
export const INIT_TICKET_BG_SRC_LIGHT = '/images/init/ticket-bg-light.avif'
export const INIT_TICKET_BG_SRC_DARK = '/images/init/ticket-bg-dark.avif'
export const INIT_TICKET_BG_SRC_GOLD = '/images/init/ticket-bg-gold.avif'
export const INIT_TICKET_BG_SRC_SILVER = '/images/init/ticket-bg-silver.avif'

export const INIT_TICKET_IMAGE_WIDTH = 1024
export const INIT_TICKET_IMAGE_HEIGHT = 682
/** Standard Open Graph export size (1.91:1). */
export const INIT_TICKET_OG_EXPORT_WIDTH = 1200
export const INIT_TICKET_OG_EXPORT_HEIGHT = 630
export const INIT_TICKET_ASPECT_RATIO =
  INIT_TICKET_IMAGE_WIDTH / INIT_TICKET_IMAGE_HEIGHT
export const INIT_TICKET_MAX_WIDTH_PX = 820
/** OG renders at native image width; card UI maxes at INIT_TICKET_MAX_WIDTH_PX. */
export const INIT_TICKET_OG_SCALE =
  INIT_TICKET_IMAGE_WIDTH / INIT_TICKET_MAX_WIDTH_PX

export function initTicketOgScalePx(value: number): number {
  return Math.round(value * INIT_TICKET_OG_SCALE)
}
/** Width of the ticket when the section is collapsed (scale = this / max width). */
export const INIT_TICKET_COLLAPSED_WIDTH_PX = 240

/**
 * Transparent padding baked into the bottom of ticket frame images (percent of image height).
 * Cropped from layout so the drop shadow sits against the visible card edge.
 */
export const INIT_TICKET_BOTTOM_TRIM_PERCENT = 5

/** Visible ticket aspect ratio after bottom transparent padding is cropped. */
export function initTicketDisplayAspectRatio(): number {
  const trimmedHeight =
    INIT_TICKET_IMAGE_HEIGHT * (1 - INIT_TICKET_BOTTOM_TRIM_PERCENT / 100)
  return INIT_TICKET_IMAGE_WIDTH / trimmedHeight
}

/**
 * Safe content inset (percent of image box).
 * Includes outer black padding plus a small inset from the neon frame.
 */
export const INIT_TICKET_CONTENT_INSET = {
  top: 25,
  right: 12.5,
  bottom: 21.5,
  left: 12.5,
} as const

/** Vertical perforation line - main body vs stub (percent of image width). */
export const INIT_TICKET_STUB_SPLIT = 57

export function initTicketInsetStyle(): {
  top: string
  right: string
  bottom: string
  left: string
} {
  return {
    top: `${INIT_TICKET_CONTENT_INSET.top}%`,
    right: `${INIT_TICKET_CONTENT_INSET.right}%`,
    bottom: `${INIT_TICKET_CONTENT_INSET.bottom}%`,
    left: `${INIT_TICKET_CONTENT_INSET.left}%`,
  }
}

/** Main body vs stub columns inside the safe inset area. */
export function initTicketColumnSplit(): { main: number; stub: number } {
  const contentWidth =
    100 - INIT_TICKET_CONTENT_INSET.left - INIT_TICKET_CONTENT_INSET.right
  const main =
    ((INIT_TICKET_STUB_SPLIT - INIT_TICKET_CONTENT_INSET.left) / contentWidth) *
    100
  const stub =
    ((100 - INIT_TICKET_STUB_SPLIT - INIT_TICKET_CONTENT_INSET.right) /
      contentWidth) *
    100
  return { main, stub }
}

/** Stub label padding inside the stub grid column (% of stub cell). */
export const INIT_TICKET_STUB_LABEL_INSET = {
  /**
   * Anchor for rotated stub copy. Pre-rotation column height becomes horizontal
   * extent after -90° rotation, so leave (100 - left - right)% of stub width.
   */
  left: 52,
  right: 3,
  bottom: 8,
} as const

/** Stub title - smaller type when the line is long (pre-rotation width). */
export function initTicketStubTitleClass(title: string): string {
  const len = title.trim().length
  if (len > 36) return 'text-[clamp(5px,0.9vw,8px)]'
  if (len > 28) return 'text-[clamp(6px,1vw,9px)]'
  return 'text-[clamp(7px,1.2vw,10px)]'
}

/** Grid columns for main body vs stub inside the content inset. */
export function initTicketContentGridStyle(): { gridTemplateColumns: string } {
  const { main, stub } = initTicketColumnSplit()
  return { gridTemplateColumns: `${main}fr ${stub}fr` }
}

/** Pixel anchor box for OG stub (Satori handles % + transform poorly). */
export function initTicketOgStubAnchorBox(stubW: number, contentH: number) {
  const { right, bottom } = INIT_TICKET_STUB_LABEL_INSET
  return {
    // Bottom-left origin + -90° rotation: higher left pushes copy toward the stub's right edge.
    left: Math.round(stubW * 0.86),
    right: Math.round((right / 100) * stubW),
    bottom: Math.round((bottom / 100) * contentH),
  }
}

/** Pixel content box for OG/Satori rendering (percent insets are unreliable there). */
export function initTicketOgContentBox() {
  const contentX =
    (INIT_TICKET_CONTENT_INSET.left / 100) * INIT_TICKET_IMAGE_WIDTH
  const contentY =
    (INIT_TICKET_CONTENT_INSET.top / 100) * INIT_TICKET_IMAGE_HEIGHT
  const contentW =
    INIT_TICKET_IMAGE_WIDTH *
    ((100 - INIT_TICKET_CONTENT_INSET.left - INIT_TICKET_CONTENT_INSET.right) /
      100)
  const contentH =
    INIT_TICKET_IMAGE_HEIGHT *
    ((100 - INIT_TICKET_CONTENT_INSET.top - INIT_TICKET_CONTENT_INSET.bottom) /
      100)
  const { main, stub } = initTicketColumnSplit()
  const mainW = (contentW * main) / (main + stub)
  const stubW = contentW - mainW

  return {
    contentX,
    contentY,
    contentW,
    contentH,
    mainW,
    stubW,
  }
}

/** Front-face holder name - smaller clamp when the display name is long. */
export function initTicketHolderNameFontSizeClass(name: string): string {
  const len = name.trim().length
  if (len > 32) return 'text-[clamp(16px,3.8vw,28px)]'
  if (len > 24) return 'text-[clamp(20px,4.5vw,34px)]'
  if (len > 16) return 'text-[clamp(24px,5.5vw,40px)]'
  return 'text-[clamp(28px,6.5vw,48px)]'
}
