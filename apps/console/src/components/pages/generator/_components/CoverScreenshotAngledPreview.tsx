import { useMemo } from 'react'
import {
  PerspectiveScreenshotCard,
  PerspectiveScreenshotScene,
} from '@/components/global/shared/PerspectiveScreenshotCard'
import { CoverBrandBackgroundPreview } from '@/lib/cover-generator/cover-brand-background-preview'
import {
  computeCoverScreenshotAngledLayoutOffset,
  getCoverScreenshotAngledCanvasScale,
  getCoverScreenshotAngledCardTransform,
  scaleCoverScreenshotAngledCardTransform,
  scaleCoverScreenshotAngledPerspective,
  PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM,
} from '@/lib/perspective-screenshot-card/constants'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverScreenshotAngledPreviewProps = {
  data: Extract<CoverRenderData, { template: 'screenshot-angled' }>
  width: number
  height: number
}

export function CoverScreenshotAngledPreview({
  data,
  width,
  height,
}: CoverScreenshotAngledPreviewProps) {
  const canvasScale = useMemo(() => getCoverScreenshotAngledCanvasScale(width), [width])

  const cardWidth = useMemo(() => {
    return Math.round(
      getCoverFrameWidthPx(data.frameWidthPercent, {
        width: data.width,
        height: data.height,
      }) * canvasScale,
    )
  }, [data.frameWidthPercent, data.width, data.height, canvasScale])

  const layoutOffset = useMemo(
    () =>
      computeCoverScreenshotAngledLayoutOffset(width, height, {
        posXRatio: data.posXRatio,
        posYRatio: data.posYRatio,
      }),
    [width, height, data.posXRatio, data.posYRatio],
  )

  const cardTransform = useMemo(
    () =>
      scaleCoverScreenshotAngledCardTransform(
        getCoverScreenshotAngledCardTransform(data),
        canvasScale,
      ),
    [data, canvasScale],
  )

  const scenePerspective = useMemo(
    () =>
      scaleCoverScreenshotAngledPerspective(
        PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.perspective,
        canvasScale,
      ),
    [canvasScale],
  )

  return (
    <div
      className="relative isolate overflow-hidden"
      style={{ width, height }}
    >
      <div
        className="absolute inset-0"
        style={{
          isolation: 'isolate',
          transform: 'translateZ(0)',
        }}
      >
        <CoverBrandBackgroundPreview
          themeId={data.theme}
          width={width}
          height={height}
          templateId={data.template}
        />
        <div className="relative z-[1] h-full w-full">
          <PerspectiveScreenshotScene
            className="h-full w-full"
            perspective={scenePerspective}
            showBaseBackground={false}
            showSceneGlow={false}
            showSceneGrid={false}
            layout="fill-bottom-right"
            clipOffset={layoutOffset}
            contentScale={data.displayScale}
          >
            <PerspectiveScreenshotCard
              src={data.screenshot}
              width={cardWidth}
              focusX={data.focusX}
              focusY={data.focusY}
              zoom={data.zoom}
              rotateX={cardTransform.rotateX}
              rotateZ={cardTransform.rotateZ}
              rotateY={cardTransform.rotateY}
              translateX={cardTransform.translateX}
              translateY={cardTransform.translateY}
              browserFrame
              themeId={data.theme}
            />
          </PerspectiveScreenshotScene>
        </div>
      </div>
    </div>
  )
}
