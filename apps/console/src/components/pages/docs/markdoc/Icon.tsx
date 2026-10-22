import {
  MARKDOC_BRAND_ICON_CLASS,
  MARKDOC_ICON_SIZE_CLASSES,
  MARKDOC_INLINE_LUCIDE_ICON_CLASS,
  resolveMarkdocIconByName,
  resolveMarkdocIconImageSrc,
} from '@/lib/docs/markdoc-icons'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

export function MarkdocIcon({ icon, size }: { icon?: string; size?: string }) {
  const resolved = resolveMarkdocIconByName(icon)
  if (!resolved) return null

  const brandSizeClass =
    size === 's' ? MARKDOC_ICON_SIZE_CLASSES.s : MARKDOC_BRAND_ICON_CLASS

  if (resolved.type === 'lucide') {
    return (
      <span className="inline-flex align-middle">
        <resolved.Icon
          className={cn(MARKDOC_INLINE_LUCIDE_ICON_CLASS, 'text-muted-foreground')}
          aria-hidden
        />
      </span>
    )
  }

  return (
    <img
      src={resolved.src}
      alt=""
      className={cn(
        'inline-block align-middle',
        brandSizeClass,
        PUBLIC_ICON_MUTED_CLASSES,
      )}
    />
  )
}

export function MarkdocIconImage({
  src,
  alt,
}: {
  src?: string
  alt?: string
  size?: string
}) {
  const resolvedSrc = resolveMarkdocIconImageSrc(src)
  if (!resolvedSrc) return null

  return (
    <img
      src={resolvedSrc}
      alt={alt ?? ''}
      className={cn(
        'inline-block align-middle',
        MARKDOC_BRAND_ICON_CLASS,
        PUBLIC_ICON_MUTED_CLASSES,
      )}
    />
  )
}
