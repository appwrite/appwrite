import { ArrowLeft, ArrowRight } from 'lucide-react'
import { getDocsStepNeighbors } from '@/lib/docs/navigation'
import { DocsRouteLink } from './DocsRouteLink'

const CARD_CLASS =
  'group flex min-w-0 flex-1 flex-col gap-1 rounded-lg border border-border bg-card px-4 py-3 transition-colors duration-150 hover:border-foreground/20 hover:bg-accent/40'

type DocsStepNavProps = {
  slug: string
}

/** Previous/next chapter links for multi-step tutorial pages. */
export function DocsStepNav({ slug }: DocsStepNavProps) {
  const { previous, next } = getDocsStepNeighbors(slug)
  if (!previous && !next) return null

  return (
    <nav
      aria-label="Tutorial steps"
      className="mt-10 flex flex-col gap-3 @[600px]:flex-row"
    >
      {previous ? (
        <DocsRouteLink href={previous.href} className={CARD_CLASS}>
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <ArrowLeft className="size-3.5" aria-hidden />
            Previous
          </span>
          <span className="truncate text-[14px] font-medium text-foreground">
            {previous.label}
          </span>
        </DocsRouteLink>
      ) : (
        <div className="hidden flex-1 @[600px]:block" aria-hidden />
      )}

      {next ? (
        <DocsRouteLink href={next.href} className={`${CARD_CLASS} @[600px]:items-end`}>
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            Next
            <ArrowRight className="size-3.5" aria-hidden />
          </span>
          <span className="max-w-full truncate text-[14px] font-medium text-foreground">
            {next.label}
          </span>
        </DocsRouteLink>
      ) : (
        <div className="hidden flex-1 @[600px]:block" aria-hidden />
      )}
    </nav>
  )
}
