import { BookOpen, Sparkles } from 'lucide-react'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

export const PARTNERS_PLATFORM_DOCS_HREF = '/docs/partners'

const docsLinkProps = {
  href: PARTNERS_PLATFORM_DOCS_HREF,
  target: '_blank' as const,
  rel: 'noopener noreferrer',
  ...analyticsAttrs('partner-docs'),
}

export function PartnersDocsCard({ compact = false }: { compact?: boolean }) {
  const t = useT()
  const title = t('Partners docs')
  const description = t('Learn to onboard clients and provision their projects.')
  const cta = t('Read the docs')

  if (compact) {
    return (
      <div className="shrink-0 border-t border-border p-1.5">
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <a
                {...docsLinkProps}
                aria-label={title}
                className="flex h-8 w-full cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <BookOpen className="h-3.5 w-3.5" />
              </a>
            </TooltipTrigger>
            <TooltipContent side="right">{title}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    )
  }

  return (
    <div className="shrink-0 border-t border-border p-3">
      <a
        {...docsLinkProps}
        className={cn(
          'block rounded-lg border border-border bg-muted/30 p-3',
          'transition-colors hover:bg-accent/20',
        )}
      >
        <div className="flex items-start gap-2.5">
          <div className="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <BookOpen className="h-3.5 w-3.5" />
            <Sparkles
              className="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 text-muted-foreground"
              aria-hidden
            />
          </div>
          <div className="min-w-0">
            <p className="text-[13px] font-medium leading-tight text-foreground">
              {title}
            </p>
            <p className="mt-1 text-[12px] leading-snug text-muted-foreground">
              {description}
            </p>
            <span className="mt-2 inline-block text-[12px] font-medium text-foreground">
              {cta}
            </span>
          </div>
        </div>
      </a>
    </div>
  )
}
