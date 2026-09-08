import type { CSSProperties } from 'react'
import { cn } from '@/lib/utils'

export function productFeaturePublicIconMaskStyle(iconSrc: string): CSSProperties {
  return {
    maskImage: `url(${iconSrc})`,
    maskMode: 'alpha',
    maskRepeat: 'no-repeat',
    maskPosition: 'center',
    maskSize: 'contain',
    WebkitMaskImage: `url(${iconSrc})`,
    WebkitMaskRepeat: 'no-repeat',
    WebkitMaskPosition: 'center',
    WebkitMaskSize: 'contain',
  }
}

type ProductFeaturePublicIconProps = {
  src: string
  className?: string
  tone?: 'foreground' | 'muted-foreground'
  /** Muted opacity that restores on section hover (disabled / compact provider tiles). */
  inactive?: boolean
}

/** Theme-aware monochrome icon from /public/icons (CSS mask + foreground fill). */
export function ProductFeaturePublicIcon({
  src,
  className,
  tone = 'foreground',
  inactive = false,
}: ProductFeaturePublicIconProps) {
  return (
    <span
      className={cn(
        'size-4 shrink-0',
        tone === 'foreground' ? 'bg-foreground' : 'bg-muted-foreground',
        inactive &&
          'opacity-60 transition-opacity duration-300 group-hover/visual:opacity-100 motion-reduce:opacity-100',
        className,
      )}
      style={productFeaturePublicIconMaskStyle(src)}
      aria-hidden
    />
  )
}
