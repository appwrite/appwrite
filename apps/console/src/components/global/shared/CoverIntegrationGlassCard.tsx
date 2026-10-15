import type { CSSProperties } from 'react'
import { CoverIconPreview } from '@/components/pages/generator/_components/CoverIconPreview'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { getCoverCardsAngledIconCardMetrics } from '@/lib/cover-generator/cards-angled/constants'
import { COVER_HERO_SCREENSHOT_FRAME } from '@/lib/cover-generator/cover-screenshot-frame'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverIntegrationGlassCardProps = {
  src?: string
  alt?: string
  iconSize: number
  themeId: CoverThemeId
  className?: string
  style?: CSSProperties
}

export function CoverIntegrationGlassCard({
  src,
  alt = '',
  iconSize,
  themeId,
  className,
  style,
}: CoverIntegrationGlassCardProps) {
  const glass = getCoverScreenshotGlassColors(themeId)
  const { padding, cardSize, radius } = getCoverCardsAngledIconCardMetrics(iconSize)

  return (
    <div
      className={cn('shrink-0 overflow-hidden', className)}
      style={{
        width: cardSize,
        height: cardSize,
        borderRadius: radius,
        border: `${COVER_HERO_SCREENSHOT_FRAME.borderWidth}px solid ${glass.shellBorder}`,
        backgroundColor: glass.shellFill,
        ...style,
      }}
    >
      {src ? (
        <div style={{ margin: padding }}>
          <CoverIconPreview src={src} themeId={themeId} size={iconSize} />
        </div>
      ) : (
        <div
          className="bg-muted/35"
          style={{
            width: iconSize,
            height: iconSize,
            margin: padding,
            borderRadius: Math.max(8, Math.round(radius * 0.45)),
          }}
        />
      )}
    </div>
  )
}
