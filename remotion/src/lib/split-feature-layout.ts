import { getHeroBrowserFrameHeight } from './hero-browser-frame'
import { VIDEO } from './video-config'

export const SPLIT_FEATURE_PADDING_X = 80
export const SPLIT_FEATURE_GAP = 56
export const SPLIT_TITLE_COLUMN_RATIO = 0.42
export const SPLIT_FEATURE_TITLE_FONT_SIZE = 72
export const SPLIT_FEATURE_LINE_HEIGHT = 1.08
/** Longer horizontal enter/exit for title and browser frame. */
export const SPLIT_FEATURE_ENTER_FRAMES = 52
export const SPLIT_FEATURE_EXIT_FRAMES = 52
/** Default inner content aspect for split feature clips (height / width). */
export const SPLIT_FEATURE_CONTENT_ASPECT = 0.68
/** Browser card extends past the scene edge; this much is clipped on the right. */
export const SPLIT_BROWSER_CLIP_RIGHT = 100

export function getSplitFeatureBrowserZoneLeft() {
  return (
    SPLIT_FEATURE_PADDING_X +
    getSplitFeatureTitleColumnWidth() +
    SPLIT_FEATURE_GAP
  )
}

export function getSplitFeatureBrowserZoneWidth() {
  return VIDEO.width - getSplitFeatureBrowserZoneLeft()
}

export function getSplitFeatureTitleColumnWidth() {
  const contentWidth = VIDEO.width - SPLIT_FEATURE_PADDING_X * 2
  return Math.floor(contentWidth * SPLIT_TITLE_COLUMN_RATIO)
}

/** Full browser card width (wider than the visible zone so the right edge clips at the scene). */
export function getSplitFeatureBrowserWidth() {
  return getSplitFeatureBrowserZoneWidth() + SPLIT_BROWSER_CLIP_RIGHT
}

export function getSplitFeatureBrowserShellHeight(
  frameWidth?: number,
  contentAspectRatio: number = SPLIT_FEATURE_CONTENT_ASPECT,
) {
  const width = frameWidth ?? getSplitFeatureBrowserWidth()
  return getHeroBrowserFrameHeight(
    width,
    true,
    false,
    contentAspectRatio,
  )
}

export function getSplitFeatureBrowserTop(
  frameWidth?: number,
  contentAspectRatio: number = SPLIT_FEATURE_CONTENT_ASPECT,
) {
  const shellHeight = getSplitFeatureBrowserShellHeight(
    frameWidth,
    contentAspectRatio,
  )
  return Math.round((VIDEO.height - shellHeight) / 2)
}

export function getSplitFeatureTitleTravelDistanceX() {
  return getSplitFeatureTitleColumnWidth() + SPLIT_FEATURE_PADDING_X + 96
}

export function getSplitFeatureBrowserTravelDistanceX(frameWidth?: number) {
  const width = frameWidth ?? getSplitFeatureBrowserWidth()
  return getSplitFeatureBrowserZoneWidth() + width * 0.35
}
