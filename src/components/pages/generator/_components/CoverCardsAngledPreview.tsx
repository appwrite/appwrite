import { useMemo } from 'react'
import { CoverIntegrationGlassCard } from '@/components/global/shared/CoverIntegrationGlassCard'
import { PerspectiveScreenshotScene } from '@/components/global/shared/PerspectiveScreenshotCard'
import { CoverBrandBackgroundPreview } from '@/lib/cover-generator/cover-brand-background-preview'
import {
  buildCoverCardsAngledHaloGrid,
  COVER_CARDS_ANGLED_FADED_ICON_OPACITY,
  COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY,
  getCoverCardsAngledIconCardMetrics,
  getCoverCardsAngledIconSlots,
  normalizeCoverCardsAngledData,
} from '@/lib/cover-generator/cards-angled/constants'
import {
  computeCoverScreenshotAngledLayoutOffset,
  getCoverScreenshotAngledCanvasScale,
  getCoverScreenshotAngledCardTransform,
  scaleCoverScreenshotAngledCardTransform,
  scaleCoverScreenshotAngledPerspective,
  buildPerspectiveScreenshotCardTransform,
  PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM,
} from '@/lib/perspective-screenshot-card/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverCardsAngledPreviewProps = {
  data: Extract<CoverRenderData, { template: 'cards-angled' }>
  width: number
  height: number
}

export function CoverCardsAngledPreview({
  data,
  width,
  height,
}: CoverCardsAngledPreviewProps) {
  const normalizedData = useMemo(() => normalizeCoverCardsAngledData(data), [data])
  const canvasScale = useMemo(() => getCoverScreenshotAngledCanvasScale(width), [width])

  const iconSlots = useMemo(
    () => getCoverCardsAngledIconSlots(normalizedData),
    [normalizedData],
  )

  const haloGrid = useMemo(
    () =>
      buildCoverCardsAngledHaloGrid(normalizedData.columns, normalizedData.rows),
    [normalizedData.columns, normalizedData.rows],
  )

  const layoutOffset = useMemo(
    () =>
      computeCoverScreenshotAngledLayoutOffset(width, height, {
        posXRatio: normalizedData.posXRatio,
        posYRatio: normalizedData.posYRatio,
      }),
    [width, height, normalizedData.posXRatio, normalizedData.posYRatio],
  )

  const cardTransform = useMemo(
    () =>
      scaleCoverScreenshotAngledCardTransform(
        getCoverScreenshotAngledCardTransform(normalizedData),
        canvasScale,
      ),
    [normalizedData, canvasScale],
  )

  const scenePerspective = useMemo(
    () =>
      scaleCoverScreenshotAngledPerspective(
        PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.perspective,
        canvasScale,
      ),
    [canvasScale],
  )

  const layoutStyle = useMemo(() => {
    const iconSize = Math.round(normalizedData.iconSize * canvasScale)
    const gap = Math.round(normalizedData.gap * canvasScale)
    const { cardSize } = getCoverCardsAngledIconCardMetrics(iconSize)

    return { iconSize, gap, cardSize }
  }, [canvasScale, normalizedData.gap, normalizedData.iconSize])

  const planeTransform = buildPerspectiveScreenshotCardTransform(cardTransform)

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
          themeId={normalizedData.theme}
          width={width}
          height={height}
          templateId={normalizedData.template}
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
            contentScale={normalizedData.displayScale}
          >
            <div
              style={{
                display: 'grid',
                gap: layoutStyle.gap,
                gridTemplateColumns: `repeat(${haloGrid.columns}, ${layoutStyle.cardSize}px)`,
                gridTemplateRows: `repeat(${haloGrid.rows}, ${layoutStyle.cardSize}px)`,
                transform: planeTransform,
                transformStyle: 'preserve-3d',
              }}
            >
              {haloGrid.cells.map((cell, index) => {
                if (cell.kind === 'empty') {
                  return (
                    <CoverIntegrationGlassCard
                      key={`empty-${index}`}
                      iconSize={layoutStyle.iconSize}
                      themeId={normalizedData.theme}
                      style={{ opacity: COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY }}
                    />
                  )
                }

                const slot = iconSlots[cell.iconIndex]

                if (!slot || slot.visibility === 'hidden') {
                  return (
                    <CoverIntegrationGlassCard
                      key={`hidden-${index}`}
                      iconSize={layoutStyle.iconSize}
                      themeId={normalizedData.theme}
                      style={{ opacity: COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY }}
                    />
                  )
                }

                return (
                  <CoverIntegrationGlassCard
                    key={`filled-${index}-${slot.src}`}
                    src={slot.src}
                    iconSize={layoutStyle.iconSize}
                    themeId={normalizedData.theme}
                    style={
                      slot.visibility === 'fade'
                        ? { opacity: COVER_CARDS_ANGLED_FADED_ICON_OPACITY }
                        : undefined
                    }
                  />
                )
              })}
            </div>
          </PerspectiveScreenshotScene>
        </div>
      </div>
    </div>
  )
}
