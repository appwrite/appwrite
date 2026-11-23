import { useMemo } from 'react'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { useT } from '@/lib/i18n/translate'

type MarketplaceAppImagesProps = {
  images: string[]
  appName: string
}

/** Horizontally scrollable screenshot strip for the marketplace detail page. */
export function MarketplaceAppImages({
  images,
  appName,
}: MarketplaceAppImagesProps) {
  const t = useT()
  const resolved = useMemo(
    () =>
      images
        .map((image) => image.trim())
        .filter(Boolean)
        .map(
          (image) =>
            resolveAppLogoDisplayUrl(image, { width: 1280, height: 720 }) ??
            image,
        ),
    [images],
  )

  if (resolved.length === 0) return null

  return (
    <div className="flex snap-x gap-3 overflow-x-auto pb-1">
      {resolved.map((src, index) => (
        <img
          key={`${src}-${index}`}
          src={src}
          alt={t('Screenshot {number} of {name}')
            .replace('{number}', String(index + 1))
            .replace('{name}', appName)}
          loading="lazy"
          decoding="async"
          className="aspect-[16/10] h-44 shrink-0 snap-start rounded-lg border border-border bg-muted object-cover sm:h-56"
        />
      ))}
    </div>
  )
}
