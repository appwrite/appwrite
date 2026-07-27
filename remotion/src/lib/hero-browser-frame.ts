/** Homepage hero browser chrome (`src/routes/home.tsx`). */
export const HERO_BROWSER_FRAME = {
  outerRadius: 28,
  innerRadius: 8,
  borderWidth: 2,
  paddingX: 16,
  paddingBottom: 24,
  paddingTop: 4,
  chromeHeight: 56,
  chromeDotSize: 10,
  chromeDotGap: 6,
  chromeDotMarginLeft: 8,
  screenshotAspect: 148 / 65,
  imageOpacity: 0.95,
  shellFill: 'rgba(63, 67, 79, 0.1)',
  shellBorder: 'rgba(63, 67, 79, 0.3)',
  chromeDotFill: 'rgba(161, 161, 170, 0.3)',
  breadcrumbColor: '#a8a8b3',
  breadcrumbActiveColor: '#fafafa',
} as const

export function getScreenshotContentWidth(frameWidth: number) {
  return frameWidth - HERO_BROWSER_FRAME.paddingX * 2
}

export function getScreenshotHeight(frameWidth: number) {
  return getScreenshotContentWidth(frameWidth) * (65 / 148)
}

/** Inner content height. `aspectRatio` is height divided by width (1 = square). */
export function getFrameContentHeight(
  frameWidth: number,
  aspectRatio = 65 / 148,
) {
  return getScreenshotContentWidth(frameWidth) * aspectRatio
}

/** Inner clip radii - bottom corners align concentrically with the closed shell. */
export function getScreenshotRadii(closed: boolean) {
  const { outerRadius, innerRadius, paddingX } = HERO_BROWSER_FRAME
  const bottomRadius = closed
    ? Math.max(innerRadius, outerRadius - paddingX)
    : 0

  return {
    topLeft: innerRadius,
    topRight: innerRadius,
    bottomLeft: bottomRadius,
    bottomRight: bottomRadius,
  }
}

export function getHeroBrowserFrameHeight(
  frameWidth: number,
  closed = false,
  hideChrome = false,
  contentAspectRatio?: number,
) {
  const { paddingTop, chromeHeight, paddingX, paddingBottom } = HERO_BROWSER_FRAME
  const contentHeight =
    contentAspectRatio !== undefined
      ? getFrameContentHeight(frameWidth, contentAspectRatio)
      : getScreenshotHeight(frameWidth)

  if (hideChrome) {
    return contentHeight + (closed ? paddingX + paddingBottom : paddingX)
  }

  return (
    paddingTop +
    chromeHeight +
    contentHeight +
    (closed ? paddingBottom : 0)
  )
}
