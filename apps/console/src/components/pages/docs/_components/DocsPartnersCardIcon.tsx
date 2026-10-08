import type { LucideIcon } from 'lucide-react'
import { OAuthIcon } from '@/components/global/shared/OAuthIcon'
import { cn } from '@/lib/utils'
import type { DocsPartnersHomeCard } from '@/lib/docs/partners-home-content'

type DocsPartnersCardIconProps = {
  item: Pick<DocsPartnersHomeCard, 'icon' | 'customIcon'>
  className?: string
  iconClassName?: string
}

export function DocsPartnersCardIcon({
  item,
  className,
  iconClassName,
}: DocsPartnersCardIconProps) {
  if (item.customIcon === 'oauth') {
    return (
      <span
        className={cn(
          'flex shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40',
          className,
        )}
      >
        <OAuthIcon variant="brand" className={cn('size-3.5', iconClassName)} />
      </span>
    )
  }

  const Icon = item.icon as LucideIcon

  return (
    <span
      className={cn(
        'flex shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40',
        className,
      )}
    >
      <Icon className={cn('size-3.5 text-muted-foreground', iconClassName)} aria-hidden />
    </span>
  )
}
