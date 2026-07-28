import { Img, OffthreadVideo, staticFile } from 'remotion'
import {
  HERO_BROWSER_FRAME,
  getHeroBrowserFrameHeight,
  getFrameContentHeight,
  getScreenshotHeight,
  getScreenshotRadii,
} from '../lib/hero-browser-frame'

type HomeHeroBrowserFrameProps = {
  width: number
  /** When set, plays video inside the frame instead of the static screenshot. */
  videoSrc?: string
  /** Static image under public/. Used when videoSrc is not set. */
  imageSrc?: string
  /** Shown when no video or image is provided. */
  placeholderLabel?: string
  /** Closed card with border on all sides (open bottom matches homepage hero). */
  closed?: boolean
  /** Hide traffic-light chrome and title bar. */
  hideChrome?: boolean
  /** Centered title in the chrome bar. */
  pageTitle?: string
  /** Inner content aspect ratio (height / width). Use 1 for square. */
  contentAspectRatio?: number
  /** Render an empty content area when no video or image is set. */
  emptyContent?: boolean
  /** Omit the default shell border (e.g. when an outer shine ring is used). */
  hideShellBorder?: boolean
  /** Trim from the left of the source video (composition frames). */
  videoTrimBefore?: number
  /** Crop pixels from the top of video content. */
  videoTrimTop?: number
  /** Scale video to full content width; excess height is clipped at the bottom. */
  videoFitWidth?: boolean
  /** Scale video to full content height; excess width is clipped horizontally. */
  videoFitHeight?: boolean
  /** Fill the content area with cover (width + height), optional left crop via overflow clip. */
  videoFitCard?: boolean
  /** Pixels of video content clipped on the left (overflow hidden inside the content area). */
  videoClipLeft?: number
  /** @deprecated Use videoClipLeft - shift via object-position (less reliable with cover). */
  videoTrimLeft?: number
}

function ChromeDots() {
  const { chromeDotSize, chromeDotFill } = HERO_BROWSER_FRAME

  return (
    <div className="flex items-center gap-1.5" aria-hidden>
      {Array.from({ length: 3 }, (_, index) => (
        <span
          key={index}
          className="rounded-full"
          style={{
            width: chromeDotSize,
            height: chromeDotSize,
            backgroundColor: chromeDotFill,
          }}
        />
      ))}
    </div>
  )
}

function screenshotClipPath(radii: {
  topLeft: number
  topRight: number
  bottomLeft: number
  bottomRight: number
}) {
  return `inset(0 round ${radii.topLeft}px ${radii.topRight}px ${radii.bottomRight}px ${radii.bottomLeft}px)`
}

/** Homepage hero browser shell with screenshot or video. */
export function HomeHeroBrowserFrame({
  width,
  videoSrc,
  imageSrc,
  placeholderLabel,
  closed = false,
  hideChrome = false,
  pageTitle = 'Overview',
  contentAspectRatio,
  emptyContent = false,
  hideShellBorder = false,
  videoTrimTop = 0,
  videoTrimBefore = 0,
  videoFitWidth = false,
  videoFitHeight = false,
  videoFitCard = false,
  videoClipLeft = 0,
  videoTrimLeft = 0,
}: HomeHeroBrowserFrameProps) {
  const {
    outerRadius,
    borderWidth,
    paddingX,
    paddingBottom,
    paddingTop,
    chromeHeight,
    shellFill,
    shellBorder,
    breadcrumbColor,
    breadcrumbActiveColor,
    imageOpacity,
  } = HERO_BROWSER_FRAME

  const shellHeight = getHeroBrowserFrameHeight(
    width,
    closed,
    hideChrome,
    contentAspectRatio,
  )
  const screenshotHeight =
    contentAspectRatio !== undefined
      ? getFrameContentHeight(width, contentAspectRatio)
      : getScreenshotHeight(width)
  const radii = hideChrome
    ? {
        topLeft: outerRadius,
        topRight: outerRadius,
        bottomLeft: outerRadius,
        bottomRight: outerRadius,
      }
    : getScreenshotRadii(closed)
  const bottomInset = closed ? paddingBottom : 0
  const shellRadius = closed
    ? `${outerRadius}px`
    : `${outerRadius}px ${outerRadius}px 0 0`
  const contentClipLeft = videoClipLeft || videoTrimLeft

  return (
    <div
      className="relative flex w-full flex-col"
      style={{
        width,
        height: shellHeight,
        boxSizing: 'border-box',
        overflow: 'hidden',
        borderRadius: shellRadius,
        borderWidth: hideShellBorder ? 0 : borderWidth,
        borderStyle: 'solid',
        borderColor: hideShellBorder ? 'transparent' : shellBorder,
        borderBottomWidth: hideShellBorder || !closed ? 0 : borderWidth,
        backgroundColor: shellFill,
        isolation: 'isolate',
        backfaceVisibility: 'hidden',
      }}
    >
      {hideChrome ? null : (
        <div
          className="relative shrink-0"
          style={{
            paddingTop,
            paddingLeft: paddingX,
            paddingRight: paddingX,
          }}
        >
          <div
            className="relative flex items-center text-left"
            style={{ height: chromeHeight }}
          >
            <div className="relative z-[1] ml-2 shrink-0">
              <ChromeDots />
            </div>
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center px-14">
              <span
                className="max-w-full truncate font-inter text-[12px] font-medium leading-none"
                style={{ color: breadcrumbActiveColor }}
              >
                {pageTitle}
              </span>
            </div>
          </div>
        </div>
      )}

      <div
        className="relative shrink-0 overflow-hidden"
        style={{
          height: screenshotHeight,
          marginLeft: paddingX,
          marginRight: paddingX,
          marginTop: hideChrome ? paddingX : undefined,
          marginBottom: bottomInset,
          backgroundColor: '#17171c',
          clipPath: screenshotClipPath(radii),
          WebkitClipPath: screenshotClipPath(radii),
        }}
      >
        {videoSrc ? (
          videoFitCard && contentClipLeft > 0 ? (
            <div
              style={{
                width: '100%',
                height: '100%',
                overflow: 'hidden',
                position: 'relative',
              }}
            >
              <OffthreadVideo
                src={staticFile(videoSrc)}
                trimBefore={videoTrimBefore}
                pauseWhenBuffering={false}
                acceptableTimeShiftInSeconds={0.45}
                style={{
                  display: 'block',
                  width: `calc(100% + ${contentClipLeft}px)`,
                  height: '100%',
                  marginLeft: -contentClipLeft,
                  objectFit: 'cover',
                  objectPosition: 'left top',
                  opacity: imageOpacity,
                }}
                muted
                volume={0}
                showInTimeline={false}
              />
            </div>
          ) : (
          <OffthreadVideo
            src={staticFile(videoSrc)}
            trimBefore={videoTrimBefore}
            pauseWhenBuffering={false}
            acceptableTimeShiftInSeconds={0.45}
            style={
              videoFitCard
                ? {
                    display: 'block',
                    width: '100%',
                    height: '100%',
                    objectFit: 'cover',
                    objectPosition: 'left top',
                    opacity: imageOpacity,
                  }
                : videoFitHeight
                ? {
                    display: 'block',
                    height: '100%',
                    width: 'auto',
                    maxWidth: 'none',
                    transform:
                      videoTrimLeft > 0
                        ? `translateX(-${videoTrimLeft}px)`
                        : undefined,
                    opacity: imageOpacity,
                  }
                : videoFitWidth
                ? {
                    display: 'block',
                    width: '100%',
                    height: 'auto',
                    opacity: imageOpacity,
                  }
                : {
                    display: 'block',
                    width: '100%',
                    height:
                      videoTrimTop > 0
                        ? `calc(100% + ${videoTrimTop}px)`
                        : '100%',
                    transform:
                      videoTrimTop > 0
                        ? `translateY(-${videoTrimTop}px)`
                        : undefined,
                    objectFit: 'cover',
                    objectPosition: 'top',
                    opacity: imageOpacity,
                  }
            }
            muted
            volume={0}
            showInTimeline={false}
          />
          )
        ) : imageSrc ? (
          <Img
            src={staticFile(imageSrc)}
            alt={placeholderLabel ?? 'Appwrite console'}
            style={{
              display: 'block',
              width: '100%',
              height: '100%',
              objectFit: 'cover',
              objectPosition: 'top',
              opacity: imageOpacity,
            }}
          />
        ) : emptyContent ? null : placeholderLabel ? (
          <div
            className="flex h-full w-full flex-col items-center justify-center gap-3"
            style={{ backgroundColor: '#17171c' }}
          >
            <div
              className="rounded-lg border border-dashed"
              style={{
                borderColor: 'rgba(161, 161, 170, 0.35)',
                width: '72%',
                aspectRatio: '1 / 1',
              }}
            />
            <span
              className="font-inter text-[13px] font-medium tracking-[0.08em] uppercase"
              style={{ color: breadcrumbColor }}
            >
              {placeholderLabel}
            </span>
          </div>
        ) : (
          <Img
            src={staticFile('images/console-app-dark.avif')}
            alt="Appwrite console overview"
            style={{
              display: 'block',
              width: '100%',
              height: '100%',
              objectFit: 'cover',
              objectPosition: 'top',
              opacity: imageOpacity,
            }}
          />
        )}
      </div>
    </div>
  )
}

export { getHeroBrowserFrameHeight }
