import type { ReactElement, ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  getVcsProviderColumns,
  vcsProviderButtonIconClassName,
  type VcsProviderMeta,
} from '@/lib/vcs/providers'

type Columns = ReturnType<typeof getVcsProviderColumns>

// Tiles are about 10rem wide, so the group is as wide as its columns.
const groupWidthClassName: Record<Columns, string> = {
  1: 'max-w-[10rem]',
  2: 'max-w-[20.5rem]',
  3: 'max-w-[31rem]',
}

// Fewer columns when the space around the group can't fit them at 8-9rem a
// tile; the tiles always fill their columns.
const tileBasisClassName: Record<Columns, string> = {
  1: 'basis-full',
  2: 'basis-full @[16.5rem]:basis-[calc((100%-0.5rem)/2)]',
  3: 'basis-full @[16.5rem]:basis-[calc((100%-0.5rem)/2)] @[28rem]:basis-[calc((100%-1rem)/3)]',
}

/**
 * Connect buttons for the Git providers a project can add, as equal tiles in
 * balanced rows, so a list of any length lines up instead of wrapping into
 * uneven rows. Narrow space drops to two columns, or one, and a short last row
 * stays centered.
 *
 * `link` renders each tile's link around the content it is given: an `<a>` to
 * the provider's authorize URL, or a router `Link`. `className` lands on the
 * outer box, which also aligns the group (`justify-start` to left-align it).
 * The columns follow that box's width, so it must not shrink to its content.
 */
export function VcsProviderButtons({
  providers,
  link,
  variant = 'secondary',
  className,
}: {
  providers: VcsProviderMeta[]
  link: (provider: VcsProviderMeta, content: ReactNode) => ReactElement
  variant?: 'secondary' | 'outline'
  className?: string
}) {
  const t = useT()
  if (providers.length === 0) return null

  const columns = getVcsProviderColumns(providers.length)

  return (
    <div className={cn('@container flex w-full justify-center', className)}>
      <div
        className={cn(
          'flex w-full flex-wrap justify-center gap-2',
          groupWidthClassName[columns],
        )}
      >
        {providers.map((provider) => (
          <Button
            key={provider.id}
            variant={variant}
            size="sm"
            className={cn(
              'h-9 min-w-0 text-[13px]',
              tileBasisClassName[columns],
            )}
            aria-label={t(`Connect ${provider.label}`)}
            title={provider.label}
            asChild
          >
            {link(
              provider,
              <>
                <provider.Icon className={vcsProviderButtonIconClassName} />
                <span className="min-w-0 truncate">{provider.label}</span>
              </>,
            )}
          </Button>
        ))}
      </div>
    </div>
  )
}

/**
 * "Add account" links at the foot of a Git organization picker, two to a row so
 * the picker stays short however many providers the server configures.
 */
export function VcsAccountLinks({
  providers,
  href,
}: {
  providers: VcsProviderMeta[]
  href: (provider: VcsProviderMeta) => string
}) {
  const t = useT()
  if (providers.length === 0) return null

  return (
    <div className="mt-1 border-t border-border px-2 pt-2 pb-1">
      <p className="text-[11px] font-medium text-muted-foreground">
        {t('Add account')}
      </p>
      <div
        className={cn(
          'mt-0.5 grid gap-x-3',
          providers.length > 1 && 'grid-cols-2',
        )}
      >
        {providers.map((provider) => (
          <a
            key={provider.id}
            href={href(provider)}
            aria-label={t(`Add ${provider.label} account`)}
            className="flex min-w-0 items-center gap-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
          >
            <provider.Icon className="h-3 w-3 shrink-0" />
            <span className="min-w-0 truncate">{provider.label}</span>
          </a>
        ))}
      </div>
    </div>
  )
}
