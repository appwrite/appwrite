import type { ReactNode } from 'react'
import {
  buildCoverScreenshotFrameShellLayout,
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotFrameRadii,
  getCoverScreenshotGlassPreviewStyles,
} from '@/lib/cover-generator/cover-screenshot-frame'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { useT } from '@/lib/i18n/translate'
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
  /** When true, the shell is fully enclosed with a bottom border and inset. */
  closed?: boolean
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
  closed = false,
  className,
  placeholder,
}: CoverHeroBrowserFrameProps) {
  const t = useT()
  const glass = getCoverScreenshotGlassPreviewStyles(themeId)
  const layout = buildCoverScreenshotFrameShellLayout(
    0,
    0,
    frameWidth,
    frameHeight,
    { closed },
  )
  const {
    screenshot: shotRect,
    outerRadius,
    paddingX,
    paddingTop,
    chromeHeight,
    borderWidth,
  } = layout
  const { paddingBottom } = COVER_HERO_SCREENSHOT_FRAME
  const radii = getCoverScreenshotFrameRadii(closed)
  const shellRadius = closed
    ? outerRadius
    : `${outerRadius}px ${outerRadius}px 0 0`

  return (
    <div
      className={cn('relative flex flex-col overflow-hidden', className)}
      style={{
        width: frameWidth,
        height: frameHeight,
        borderRadius: shellRadius,
        borderWidth,
        borderStyle: 'solid',
        borderColor: glass.shellBorder,
        borderBottomWidth: closed ? borderWidth : 0,
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
          marginInlineStart: paddingX,
          marginBottom: closed ? paddingBottom : 0,
          borderTopLeftRadius: radii.topLeft,
          borderTopRightRadius: radii.topRight,
          borderBottomLeftRadius: radii.bottomLeft,
          borderBottomRightRadius: radii.bottomRight,
          opacity: COVER_HERO_SCREENSHOT_FRAME.imageOpacity,
        }}
      >
        {src ? (
          <img
            src={src}
            alt={t(alt)}
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
              {t('Screenshot preview')}
            </div>
          )
        )}
      </div>
    </div>
  )
}
