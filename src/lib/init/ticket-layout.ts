/** Init ticket frame assets — 1024×682 with outer padding baked in. */
export const INIT_TICKET_BG_SRC_LIGHT = '/images/init/ticket-bg-light.png'
export const INIT_TICKET_BG_SRC_DARK = '/images/init/ticket-bg-dark.png'
export const INIT_TICKET_BG_SRC_GOLD = '/images/init/ticket-bg-gold.png'
export const INIT_TICKET_BG_SRC_SILVER = '/images/init/ticket-bg-silver.png'

export const INIT_TICKET_IMAGE_WIDTH = 1024
export const INIT_TICKET_IMAGE_HEIGHT = 682
export const INIT_TICKET_ASPECT_RATIO =
  INIT_TICKET_IMAGE_WIDTH / INIT_TICKET_IMAGE_HEIGHT
export const INIT_TICKET_MAX_WIDTH_PX = 820
/** Width of the ticket when the section is collapsed (scale = this / max width). */
export const INIT_TICKET_COLLAPSED_WIDTH_PX = 240

/**
 * Transparent padding baked into the bottom of ticket PNGs (percent of image height).
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

/** Vertical perforation line — main body vs stub (percent of image width). */
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
  left: 70,
  right: 6,
  bottom: 6,
} as const

/** Grid columns for main body vs stub inside the content inset. */
export function initTicketContentGridStyle(): { gridTemplateColumns: string } {
  const { main, stub } = initTicketColumnSplit()
  return { gridTemplateColumns: `${main}fr ${stub}fr` }
}
