import { useMemo } from 'react'
import { buildCoverOgBackgroundDataUri } from '@/lib/cover-generator/og/og-background'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

const THUMB_SOURCE_SIZE = 80
const THUMB_DISPLAY_SIZE = 24

type CoverThemePreviewThumbProps = {
  themeId: CoverThemeId
  className?: string
}

export function CoverThemePreviewThumb({
  themeId,
  className,
}: CoverThemePreviewThumbProps) {
  const src = useMemo(
    () =>
      buildCoverOgBackgroundDataUri(
        themeId,
        THUMB_SOURCE_SIZE,
        THUMB_SOURCE_SIZE,
      ),
    [themeId],
  )

  return (
    <img
      src={src}
      alt=""
      aria-hidden
      draggable={false}
      width={THUMB_DISPLAY_SIZE}
      height={THUMB_DISPLAY_SIZE}
      className={cn(
        'size-6 shrink-0 rounded border border-border object-cover',
        className,
      )}
    />
  )
}
