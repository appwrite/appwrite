import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { getCoverScreenshotAngledFrameLayout } from '@/lib/cover-generator/cover-screenshot-angled-frame'
import {
  buildPerspectiveScreenshotCardOgTransform,
  computeCoverScreenshotAngledOgFrameBox,
  getCoverScreenshotAngledCardTransform,
} from '@/lib/perspective-screenshot-card/constants'
import type { PreparedCoverData } from '@/lib/cover-generator/og/prepare-cover-data'
import type { CoverScreenshotAngledData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

type ScreenshotAngledOgProps = {
  data: CoverScreenshotAngledData
  themeId: CoverThemeId
  prepared: PreparedCoverData
}

function ChromeDots({
  paddingX,
  paddingTop,
  chromeHeight,
  dotFill,
}: {
  paddingX: number
  paddingTop: number
  chromeHeight: number
  dotFill: string
}) {
  const { chromeDotSize, chromeDotGap, chromeDotMarginLeft } = COVER_HERO_SCREENSHOT_FRAME
  const dotY = paddingTop + chromeHeight / 2 - chromeDotSize / 2
  const dotStartX = paddingX + chromeDotMarginLeft

  return (
    <>
      {Array.from({ length: 3 }, (_, index) => (
        <div
          key={index}
          style={{
            position: 'absolute',
            left: dotStartX + index * (chromeDotSize + chromeDotGap),
            top: dotY,
            width: chromeDotSize,
            height: chromeDotSize,
            borderRadius: chromeDotSize / 2,
            backgroundColor: dotFill,
          }}
        />
      ))}
    </>
  )
}

export function ScreenshotAngledOg({ data, prepared }: ScreenshotAngledOgProps) {
  const layout = getCoverScreenshotAngledFrameLayout(data)
  const glass = getCoverScreenshotGlassColors(data.theme)
  const cardTransform = buildPerspectiveScreenshotCardOgTransform(
    getCoverScreenshotAngledCardTransform(data),
  )
  const frameBox = computeCoverScreenshotAngledOgFrameBox({
    containerWidth: COVER_WIDTH,
    containerHeight: COVER_HEIGHT,
    frameWidth: layout.shell.width,
    frameHeight: layout.shell.height,
    displayScale: data.displayScale,
    posXRatio: data.posXRatio,
    posYRatio: data.posYRatio,
  })
  const { shell, screenshot: shotRect, outerRadius, innerRadius, paddingX, paddingTop, chromeHeight } =
    layout

  return (
    <div
      style={{
        position: 'absolute',
        left: frameBox.left,
        top: frameBox.top,
        width: frameBox.width,
        height: frameBox.height,
        display: 'flex',
        transform: `scale(${frameBox.scale})`,
        transformOrigin: frameBox.transformOrigin,
      }}
    >
      <div
        style={{
          width: shell.width,
          height: shell.height,
          display: 'flex',
          transform: cardTransform,
          transformOrigin: '50% 50%',
        }}
      >
        <div
          style={{
            width: shell.width,
            height: shell.height,
            borderTopLeftRadius: outerRadius,
            borderTopRightRadius: outerRadius,
            borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
            borderStyle: 'solid',
            borderColor: glass.shellBorder,
            borderBottomWidth: 0,
            backgroundColor: glass.shellFill,
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
          }}
        >
          <div
            style={{
              height: paddingTop + chromeHeight,
              width: '100%',
              position: 'relative',
              display: 'flex',
            }}
          >
            <ChromeDots
              paddingX={paddingX}
              paddingTop={paddingTop}
              chromeHeight={chromeHeight}
              dotFill={glass.chromeDotFill}
            />
          </div>
          <div
            style={{
              width: shotRect.width,
              height: shotRect.height,
              marginInlineStart: paddingX,
              borderTopLeftRadius: innerRadius,
              borderTopRightRadius: innerRadius,
              overflow: 'hidden',
              opacity: COVER_HERO_SCREENSHOT_FRAME.imageOpacity,
              display: 'flex',
            }}
          >
            {prepared.screenshot ? (
              <img
                src={prepared.screenshot}
                width={shotRect.width}
                height={shotRect.height}
                style={{
                  width: '100%',
                  height: '100%',
                  objectFit: 'cover',
                  objectPosition: `${data.focusX}% ${data.focusY}%`,
                  display: 'block',
                }}
              />
            ) : (
              <div
                style={{
                  width: '100%',
                  height: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: 'rgba(255, 255, 255, 0.45)',
                  fontSize: 22,
                }}
              >
                Screenshot preview
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
