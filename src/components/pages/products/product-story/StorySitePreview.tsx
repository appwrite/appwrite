import { CheckCircle2, ExternalLink, Lock } from 'lucide-react'
import { StoryAnimated } from '@/components/pages/products/product-story/StoryAnimated'
import { cn } from '@/lib/utils'

type StorySitePreviewProps = {
  productionUrl: string
  previewUrl: string
  siteName?: string
  className?: string
}

export function StorySitePreview({
  productionUrl,
  previewUrl,
  siteName = 'Storefront',
  className,
}: StorySitePreviewProps) {
  return (
    <div className={cn('flex h-full flex-col gap-3', className)}>
      <StoryAnimated delayMs={0}>
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-[10px] font-medium text-emerald-700 dark:text-emerald-400 sm:text-[11px]">
            <CheckCircle2 className="size-3.5" aria-hidden />
            Preview ready
          </span>
          <span className="font-mono text-[10px] text-muted-foreground sm:text-[11px]">
            {previewUrl}
          </span>
        </div>
      </StoryAnimated>

      <StoryAnimated delayMs={120} className="min-h-0 flex-1">
        <div className="flex h-full min-h-[12rem] flex-col overflow-hidden rounded-xl border border-border bg-background shadow-sm sm:min-h-[14rem]">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2">
            <div className="flex items-center gap-1" aria-hidden>
              <span className="size-2 rounded-full bg-muted-foreground/25" />
              <span className="size-2 rounded-full bg-muted-foreground/25" />
              <span className="size-2 rounded-full bg-muted-foreground/25" />
            </div>
            <div className="flex min-w-0 flex-1 items-center gap-1.5 rounded-md border border-border bg-background px-2 py-1 font-mono text-[10px] text-muted-foreground sm:text-[11px]">
              <Lock className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
              <span className="truncate text-foreground">{productionUrl}</span>
            </div>
            <ExternalLink className="size-3.5 shrink-0 text-muted-foreground/50" aria-hidden />
          </div>

          <div className="flex flex-1 flex-col bg-muted/[0.04] p-4 sm:p-5">
            <div className="product-story-site-hero mx-auto w-full max-w-md space-y-3">
              <div className="flex items-center justify-between">
                <div className="h-2 w-16 rounded-sm bg-foreground/15" />
                <div className="flex gap-2">
                  <div className="h-2 w-8 rounded-sm bg-muted-foreground/15" />
                  <div className="h-2 w-8 rounded-sm bg-muted-foreground/15" />
                  <div className="h-2 w-8 rounded-sm bg-muted-foreground/15" />
                </div>
              </div>
              <div className="space-y-2 pt-2">
                <div className="h-3 w-3/4 max-w-[14rem] rounded-sm bg-foreground/20" />
                <div className="h-2 w-full max-w-[18rem] rounded-sm bg-muted-foreground/12" />
                <div className="h-2 w-5/6 max-w-[16rem] rounded-sm bg-muted-foreground/10" />
              </div>
              <div className="flex gap-2 pt-1">
                <div className="h-7 w-20 rounded-md bg-[var(--brand-cta)]/25" />
                <div className="h-7 w-20 rounded-md border border-border bg-background/80" />
              </div>
              <div className="grid grid-cols-3 gap-2 pt-3">
                {[0, 1, 2].map((i) => (
                  <div
                    key={i}
                    className="product-story-site-card aspect-[4/3] rounded-lg border border-border bg-background/90 p-2"
                    style={{ animationDelay: `${240 + i * 100}ms` }}
                  >
                    <div className="h-2 w-2/3 rounded-sm bg-muted-foreground/15" />
                    <div className="mt-2 h-1.5 w-full rounded-sm bg-muted-foreground/8" />
                    <div className="mt-1 h-1.5 w-4/5 rounded-sm bg-muted-foreground/8" />
                  </div>
                ))}
              </div>
            </div>
            <p className="mt-auto pt-3 text-center text-[10px] text-muted-foreground sm:text-[11px]">
              {siteName} deployed on Appwrite Sites
            </p>
          </div>
        </div>
      </StoryAnimated>
    </div>
  )
}
