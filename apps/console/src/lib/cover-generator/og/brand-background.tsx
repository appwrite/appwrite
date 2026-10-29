import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { buildCoverOgBackgroundDataUri } from '@/lib/cover-generator/og/og-background'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

type CoverOgBrandBackgroundProps = {
  themeId: CoverThemeId
  width?: number
  height?: number
  className?: string
}

export function CoverOgBrandBackground({
  themeId,
  width = COVER_WIDTH,
  height = COVER_HEIGHT,
  className,
}: CoverOgBrandBackgroundProps) {
  const backgroundSrc = buildCoverOgBackgroundDataUri(themeId, width, height)

  return (
    <img
      src={backgroundSrc}
      alt=""
      aria-hidden
      width={width}
      height={height}
      className={className}
      style={{
        position: 'absolute',
        inset: 0,
        width,
        height,
      }}
    />
  )
}
