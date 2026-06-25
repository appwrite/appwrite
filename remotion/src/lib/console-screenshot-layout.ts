import { getHeroBrowserFrameHeight } from './hero-browser-frame'
import { VIDEO } from './video-config'

export const CONSOLE_SCREENSHOT_HORIZONTAL_PADDING = 80
export const CONSOLE_SCREENSHOT_BOTTOM_CLIP = 72
export const CONSOLE_SCREENSHOT_TITLE_FONT_SIZE = 84
/** Title lands after the drop animation (matches opening screenshot scene). */
export const CONSOLE_SCREENSHOT_TYPE_DELAY = 44 + 26
/** Video playback begins once the title has landed. */
export const CONSOLE_SCREENSHOT_VIDEO_START = CONSOLE_SCREENSHOT_TYPE_DELAY

export function getConsoleScreenshotFrameWidth() {
  return VIDEO.width - CONSOLE_SCREENSHOT_HORIZONTAL_PADDING * 2
}

export function getConsoleScreenshotShellMetrics(frameWidth?: number) {
  const width = frameWidth ?? getConsoleScreenshotFrameWidth()
  const shellHeight = getHeroBrowserFrameHeight(width)
  const restTranslateY = CONSOLE_SCREENSHOT_BOTTOM_CLIP
  const browserTopY = VIDEO.height - shellHeight + restTranslateY
  const titleZoneHeight = browserTopY
  const titleHiddenY = -(
    titleZoneHeight / 2 +
    CONSOLE_SCREENSHOT_TITLE_FONT_SIZE +
    40
  )
  const browserHiddenY = shellHeight + 48

  return {
    frameWidth: width,
    shellHeight,
    restTranslateY,
    titleZoneHeight,
    titleHiddenY,
    browserHiddenY,
  }
}
