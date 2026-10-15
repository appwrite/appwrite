import type { CSSProperties, ReactNode } from 'react'
import { CoverHeroBrowserFrame } from '@/components/global/shared/CoverHeroBrowserFrame'
import { getCoverScreenshotAngledShellDimensions } from '@/lib/cover-generator/cover-screenshot-angled-frame'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import {
  buildPerspectiveScreenshotCardTransform,
  PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO,
  PERSPECTIVE_SCREENSHOT_CARD_SURFACE,
  PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM,
} from '@/lib/perspective-screenshot-card/constants'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type PerspectiveScreenshotSceneLayout =
  | 'center'
  | 'clip-bottom-right'
  | 'cover-end-edge'
  | 'fill-bottom-right'

export type PerspectiveScreenshotSceneProps = {
  children: ReactNode
  className?: string
  /** Perspective distance in px. */
  perspective?: number
  style?: CSSProperties
  /** When false, only the grid overlay is rendered (for use over another background). */
  showBaseBackground?: boolean
  /** When false, skips the pink scene glow. Defaults to `showBaseBackground`. */
  showSceneGlow?: boolean
  /** When false, skips the dot grid. Defaults to `showBaseBackground`. */
  showSceneGrid?: boolean
  /** `clip-bottom-right` anchors the card to the corner and pushes it past the edge. */
  layout?: PerspectiveScreenshotSceneLayout
  /** Extra push past the bottom-right edge (used with `clip-bottom-right`). */
  clipOffset?: { x: number; y: number }
  /** Scales content from `contentTransformOrigin` (used with `clip-bottom-right`). */
  contentScale?: number
  contentTransformOrigin?: string
}

export function PerspectiveScreenshotScene({
  children,
  className,
  perspective = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.perspective,
  style,
  showBaseBackground = true,
  showSceneGlow,
  showSceneGrid,
  layout = 'center',
  clipOffset = { x: 0, y: 0 },
  contentScale = 1,
  contentTransformOrigin = '20% 20%',
}: PerspectiveScreenshotSceneProps) {
  const clipBottomRight = layout === 'clip-bottom-right' || layout === 'fill-bottom-right'
  const coverEndEdge = layout === 'cover-end-edge'
  const sceneGlowVisible = showSceneGlow ?? showBaseBackground
  const sceneGridVisible = showSceneGrid ?? showBaseBackground

  const transformOrigin = coverEndEdge
    ? '100% 50%'
    : clipBottomRight
      ? contentTransformOrigin
      : undefined

  const contentTransform =
    clipBottomRight || coverEndEdge
      ? [
          coverEndEdge
            ? `translate(calc(0px + ${clipOffset.x}px), calc(-50% + ${clipOffset.y}px))`
            : `translate(${clipOffset.x}px, ${clipOffset.y}px)`,
          contentScale !== 1 ? `scale(${contentScale})` : null,
        ]
          .filter(Boolean)
          .join(' ')
      : undefined
  return (
    <div
      className={cn(
        'relative overflow-hidden',
        showBaseBackground ? 'bg-[#09090b]' : 'bg-transparent',
        className,
      )}
      style={style}
    >
      {sceneGlowVisible ? (
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 z-0"
          style={{
            background:
              'radial-gradient(ellipse 70% 55% at 18% 88%, rgba(255, 46, 107, 0.28), transparent 68%)',
          }}
        />
      ) : null}
      {sceneGridVisible ? (
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 z-0 opacity-80"
          style={{
            background:
              'radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.03) 1px, transparent 1px)',
            backgroundSize: '18px 18px',
          }}
        />
      ) : null}
      <div
        className="absolute inset-0 z-[1]"
        style={{
          perspective,
          transformStyle: 'preserve-3d',
        }}
      >
        <div
          className={cn(
            'relative flex h-full w-full',
            coverEndEdge
              ? 'block'
              : clipBottomRight
                ? 'items-end justify-end'
                : 'items-center justify-center',
          )}
          style={{ transformStyle: 'preserve-3d' }}
        >
          <div
            className={coverEndEdge ? 'absolute end-0 top-1/2' : undefined}
            style={{
              transformStyle: 'preserve-3d',
              transform: contentTransform
                ? `${contentTransform} translateZ(1px)`
                : 'translateZ(1px)',
              transformOrigin,
            }}
          >
            {children}
          </div>
        </div>
      </div>
    </div>
  )
}

export type PerspectiveScreenshotCardProps = {
  src?: string
  alt?: string
  /** Sets card width; height follows aspect ratio. */
  width: number
  aspectRatio?: number
  rotateX?: number
  rotateY?: number
  rotateZ?: number
  translateX?: number
  translateY?: number
  focusX?: number
  focusY?: number
  zoom?: number
  className?: string
  cardClassName?: string
  placeholder?: ReactNode
  /** Renders homepage-style browser chrome around the screenshot. */
  browserFrame?: boolean
  themeId?: CoverThemeId
}

export function PerspectiveScreenshotCard({
  src,
  alt = 'Screenshot',
  width,
  aspectRatio = PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO,
  rotateX = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateX,
  rotateY = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateY,
  rotateZ = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateZ,
  translateX = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateX,
  translateY = PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateY,
  focusX = 0,
  focusY = 0,
  zoom = 1,
  className,
  cardClassName,
  placeholder,
  browserFrame = false,
  themeId,
}: PerspectiveScreenshotCardProps) {
  const t = useT()
  const cardTransform = buildPerspectiveScreenshotCardTransform({
    rotateX,
    rotateY,
    rotateZ,
    translateX,
    translateY,
  })

  const shellDimensions = browserFrame
    ? getCoverScreenshotAngledShellDimensions(width)
    : null

  return (
    <div
      className={cn('flex items-center justify-center', className)}
      style={{ transformStyle: 'preserve-3d' }}
    >
      <div
        className={cn(!browserFrame && 'overflow-hidden', cardClassName)}
        style={{
          width: browserFrame ? shellDimensions?.shellWidth : width,
          ...(browserFrame
            ? { height: shellDimensions?.shellHeight }
            : { aspectRatio }),
          ...(!browserFrame
            ? {
                borderRadius: PERSPECTIVE_SCREENSHOT_CARD_SURFACE.borderRadius,
                border: PERSPECTIVE_SCREENSHOT_CARD_SURFACE.border,
                background: PERSPECTIVE_SCREENSHOT_CARD_SURFACE.background,
                boxShadow: PERSPECTIVE_SCREENSHOT_CARD_SURFACE.boxShadow,
              }
            : {}),
          transform: cardTransform,
          transformStyle: 'preserve-3d',
        }}
      >
        {browserFrame && themeId ? (
          <CoverHeroBrowserFrame
            frameWidth={width}
            frameHeight={shellDimensions!.shellHeight}
            themeId={themeId}
            src={src}
            alt={t(alt)}
            focusX={focusX}
            focusY={focusY}
            zoom={zoom}
            placeholder={placeholder}
          />
        ) : src ? (
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
            <div className="flex h-full w-full items-center justify-center text-[13px] text-white/45">
              {t('Screenshot preview')}
            </div>
          )
        )}
      </div>
    </div>
  )
}
