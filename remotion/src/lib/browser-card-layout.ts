import { VIDEO } from './video-config'
import {
  HERO_BROWSER_FRAME,
  getHeroBrowserFrameHeight,
} from './hero-browser-frame'

const { paddingX, paddingTop, chromeHeight } = HERO_BROWSER_FRAME

export const BROWSER_CARD_MARGIN_X = 72
export const BROWSER_CARD_MARGIN_Y = 56
const CLOSED_CHROME_OFFSET = paddingTop + chromeHeight + paddingX

/** Closed browser card width that fits the 1920×1080 frame with margins. */
export function getBrowserCardWidth() {
  const maxWidth = VIDEO.width - BROWSER_CARD_MARGIN_X * 2
  const availableHeight = VIDEO.height - BROWSER_CARD_MARGIN_Y * 2
  const contentWidthFromHeight =
    ((availableHeight - CLOSED_CHROME_OFFSET) * 148) / 65
  const widthFromHeight = contentWidthFromHeight + paddingX * 2

  return Math.min(maxWidth, widthFromHeight)
}

export function getBrowserCardShellHeight(width: number) {
  return getHeroBrowserFrameHeight(width, true)
}
