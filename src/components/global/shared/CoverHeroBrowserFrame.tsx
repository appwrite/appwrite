import type { ReactNode } from 'react'
import {
  buildCoverScreenshotFrameShellLayout,
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassPreviewStyles,
} from '@/lib/cover-generator/cover-screenshot-frame'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverHeroBrowserFrameProps = {
  frameWidth: number
  frameHeight: number
  themeId: CoverThemeId
  src?: string
  alt?: string
  focusX?: number
  focusY?: number
  zoom?: number
  className?: string
  placeholder?: ReactNode
}

function BrowserChromeDots({
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
        <span
          key={index}
          aria-hidden
          className="absolute rounded-full"
          style={{
            left: dotStartX + index * (chromeDotSize + chromeDotGap),
            top: dotY,
            width: chromeDotSize,
            height: chromeDotSize,
            backgroundColor: dotFill,
          }}
        />
      ))}
    </>
  )
}

export function CoverHeroBrowserFrame({
  frameWidth,
  frameHeight,
  themeId,
  src,
  alt = 'Screenshot',
  focusX = 0,
  focusY = 0,
  zoom = 1,
  className,
  placeholder,
}: CoverHeroBrowserFrameProps) {
  const glass = getCoverScreenshotGlassPreviewStyles(themeId)
  const layout = buildCoverScreenshotFrameShellLayout(0, 0, frameWidth, frameHeight)
  const { screenshot: shotRect, outerRadius, innerRadius, paddingX, paddingTop, chromeHeight } =
    layout

  return (
    <div
      className={cn('relative flex flex-col overflow-hidden', className)}
      style={{
        width: frameWidth,
        height: frameHeight,
        borderTopLeftRadius: outerRadius,
        borderTopRightRadius: outerRadius,
        borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
        borderStyle: 'solid',
        borderColor: glass.shellBorder,
        borderBottomWidth: 0,
        backgroundColor: glass.shellFill,
      }}
    >
      <div
        className="relative shrink-0"
        style={{ height: paddingTop + chromeHeight, width: '100%' }}
      >
        <BrowserChromeDots
          paddingX={paddingX}
          paddingTop={paddingTop}
          chromeHeight={chromeHeight}
          dotFill={glass.chromeDotFill}
        />
      </div>
      <div
        className="overflow-hidden"
        style={{
          width: shotRect.width,
          height: shotRect.height,
          marginLeft: paddingX,
          borderTopLeftRadius: innerRadius,
          borderTopRightRadius: innerRadius,
          opacity: COVER_HERO_SCREENSHOT_FRAME.imageOpacity,
        }}
      >
        {src ? (
          <img
            src={src}
            alt={alt}
            draggable={false}
            className="block h-full w-full object-cover"
            style={{
              objectPosition: `${focusX}% ${focusY}%`,
              transform: zoom > 1 ? `scale(${zoom})` : undefined,
              transformOrigin: `${focusX}% ${focusY}%`,
            }}
          />
        ) : (
          placeholder ?? (
            <div className="flex h-full w-full items-center justify-center bg-[#17171c] text-[13px] text-white/45">
              Screenshot preview
            </div>
          )
        )}
      </div>
    </div>
  )
}
