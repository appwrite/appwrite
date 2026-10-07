import { useState } from 'react'
import { LayoutTemplate } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { getSiteTemplateScreenshotUrl } from '@/lib/sites/site-template-wizard'
import { cn } from '@/lib/utils'

function TemplateScreenshot({
  src,
  alt,
  className,
}: {
  src: string
  alt: string
  className?: string
}) {
  const [loaded, setLoaded] = useState(false)

  return (
    <img
      src={src}
      alt={alt}
      className={cn(
        className,
        'transition-opacity duration-300',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
      onLoad={() => setLoaded(true)}
    />
  )
}

type SiteTemplateCardProps = {
  template: Models.TemplateSite
  isDark: boolean
  interactive?: boolean
  onSelect?: () => void
  size?: 'default' | 'compact'
  className?: string
}

export function SiteTemplateCard({
  template,
  isDark,
  interactive = false,
  onSelect,
  size = 'default',
  className,
}: SiteTemplateCardProps) {
  const screenshotUrl = getSiteTemplateScreenshotUrl(template, isDark)
  const Tag = interactive ? 'button' : 'div'
  const isCompact = size === 'compact'

  return (
    <Tag
      type={interactive ? 'button' : undefined}
      onClick={interactive ? onSelect : undefined}
      className={cn(
        'group/template flex flex-col overflow-hidden rounded-2xl border border-border bg-card text-start transition-all',
        isCompact ? 'h-[176px]' : 'h-[180px]',
        interactive &&
          'cursor-pointer hover:border-border/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
        className,
      )}
    >
      <div className={cn('px-4 pb-2 pt-4', isCompact ? 'h-[76px]' : 'h-[80px]')}>
        <h3
          className={cn(
            'line-clamp-1 font-semibold leading-tight text-foreground',
            isCompact ? 'text-[13px]' : 'text-[14px]',
            interactive && 'transition-colors group-hover/template:text-primary',
          )}
        >
          {template.name}
        </h3>
        {template.tagline ? (
          <p
            className={cn(
              'mt-1 line-clamp-2 leading-snug text-muted-foreground',
              isCompact ? 'text-[11px]' : 'text-[12px] leading-snug',
            )}
          >
            {template.tagline}
          </p>
        ) : null}
      </div>
      <div className="relative min-h-0 flex-1 overflow-hidden">
        {screenshotUrl ? (
          <div className="absolute -end-4 start-8 top-4 aspect-video -rotate-3 transition-transform duration-300 group-hover/visual:-rotate-2 group-hover/template:-rotate-2 motion-reduce:transform-none">
            <div className="relative h-full w-full overflow-hidden rounded-lg bg-muted/30 ring-1 ring-border">
              <TemplateScreenshot
                src={screenshotUrl}
                alt={template.name}
                className="absolute inset-0 h-full w-full object-cover object-top"
              />
            </div>
          </div>
        ) : (
          <div className="absolute -end-4 start-8 top-4 flex aspect-video -rotate-3 items-center justify-center rounded-lg bg-muted/50 ring-1 ring-border">
            <LayoutTemplate className="size-8 text-muted-foreground/30" aria-hidden />
          </div>
        )}
      </div>
    </Tag>
  )
}

export function SiteTemplateCardSkeleton({ compact = false }: { compact?: boolean }) {
  return (
    <div
      className={cn(
        'flex flex-col overflow-hidden rounded-2xl border border-border bg-card',
        compact ? 'h-[176px]' : 'h-[180px]',
      )}
    >
      <div className={cn('px-4 pb-2 pt-4', compact ? 'h-[76px]' : 'h-[80px]')}>
        <div className="mb-1.5 h-3.5 w-24 rounded bg-muted animate-pulse" />
        <div className="h-3 w-full rounded bg-muted animate-pulse" />
        <div className="mt-0.5 h-3 w-3/4 rounded bg-muted animate-pulse" />
      </div>
      <div className="relative flex-1 overflow-hidden">
        <div className="absolute inset-x-3 top-4 -rotate-3">
          <div className="h-[120px] w-full rounded-lg bg-muted animate-pulse" />
        </div>
      </div>
    </div>
  )
}
