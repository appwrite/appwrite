import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  getCoverBackgroundGridStyleForTheme,
  getCoverSoftLightCssGradient,
  getCoverSoftLightLayoutsForTheme,
  getCoverSoftLightRect,
} from '@/lib/cover-generator/cover-soft-lights'
import {
  getCoverMilestoneConfettiDomPieces,
  isCoverMilestoneTemplate,
} from '@/lib/cover-generator/milestone/confetti'
import { getCoverBrandThemeForSvgExport, getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverBrandBackgroundPreviewProps = {
  themeId: CoverThemeId
  width: number
  height: number
  templateId?: CoverTemplateId
  className?: string
}

/**
 * Live DOM background for cover previews: solid → pattern grid → soft lights.
 * Kept as flat CSS layers so 3D foreground content always paints on top.
 */
export function CoverBrandBackgroundPreview({
  themeId,
  width,
  height,
  templateId,
  className,
}: CoverBrandBackgroundPreviewProps) {
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const theme = getCoverTheme(themeId)
  const gridStyle = getCoverBackgroundGridStyleForTheme(themeId)
  const backgroundContext = templateId ? { templateId } : undefined
  const confettiPieces = isCoverMilestoneTemplate(templateId)
    ? getCoverMilestoneConfettiDomPieces(width, height, theme.family)
    : []

  return (
    <div
      className={cn('pointer-events-none absolute inset-0 overflow-hidden', className)}
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="absolute inset-0" style={{ backgroundColor: brand.background }} />
      {gridStyle ? <div className="absolute inset-0" style={gridStyle} /> : null}
      {getCoverSoftLightLayoutsForTheme(themeId, backgroundContext).map(([side, layout]) => {
        const rect = getCoverSoftLightRect(layout, width, height)

        return (
          <div
            key={side}
            className="absolute"
            style={{
              left: rect.x,
              top: rect.y,
              width: rect.width,
              height: rect.height,
              background: getCoverSoftLightCssGradient(themeId, layout),
            }}
          />
        )
      })}
      {confettiPieces.length > 0 ? (
        <div className="absolute inset-0">
          {confettiPieces.map((piece, index) => (
            <div
              key={index}
              className="absolute"
              style={{
                left: piece.left,
                top: piece.top,
                width: piece.width,
                height: piece.height,
                backgroundColor: piece.backgroundColor,
                borderRadius: piece.borderRadius,
                transform: `rotate(${piece.rotation}deg)`,
              }}
            />
          ))}
        </div>
      ) : null}
    </div>
  )
}
