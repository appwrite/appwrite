import {
  PUBLIC_CATALOG_LOGO_COLOR_CLASSES,
  PUBLIC_CATALOG_LOGO_IMAGE_CLASSES,
} from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

const SIZE_CLASSNAMES = {
  sm: 'h-8 w-8 rounded-lg p-1.5',
  md: 'h-10 w-10 rounded-lg p-2',
  lg: 'h-12 w-12 rounded-xl p-2.5',
  consent: 'h-16 w-16 rounded-xl p-3',
  xl: 'h-20 w-20 rounded-xl p-4',
} as const

type MarketplaceAppLogoProps = {
  src: string
  size?: keyof typeof SIZE_CLASSNAMES
  className?: string
  alt?: string
  /** When the primary `src` fails to load (e.g. missing OAuth provider icon). */
  fallbackSrc?: string
  onImageError?: () => void
  /**
   * Official marketplace catalog artwork uses grayscale + invert. User-published
   * app logos render in full color on consent and My apps.
   */
  monochrome?: boolean
}

/**
 * Catalog logos are black artwork on a transparent background; uploaded
 * logos may be colorful, so grayscale forces everything monochrome. The
 * partial invert remaps black artwork onto the theme's muted-foreground
 * gray (and flips it light in dark mode) so tiles match the category-icon
 * fallback contrast instead of sitting at full black/white.
 */
export function MarketplaceAppLogo({
  src,
  size = 'md',
  className,
  alt = '',
  fallbackSrc,
  onImageError,
  monochrome = true,
}: MarketplaceAppLogoProps) {
  return (
    <div
      className={cn(
        'flex shrink-0 items-center justify-center border border-border/60 bg-muted',
        SIZE_CLASSNAMES[size],
        className,
      )}
    >
      <img
        src={src}
        alt={alt}
        decoding="async"
        className={
          monochrome
            ? PUBLIC_CATALOG_LOGO_IMAGE_CLASSES
            : PUBLIC_CATALOG_LOGO_COLOR_CLASSES
        }
        onError={(e) => {
          onImageError?.()
          if (!fallbackSrc) return
          const img = e.currentTarget
          if (img.dataset.fallbackApplied === 'true') return
          img.dataset.fallbackApplied = 'true'
          img.src = fallbackSrc
        }}
      />
    </div>
  )
}
