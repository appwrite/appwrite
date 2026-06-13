import { CalendarClock, Copy, Link2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'

export function StorageFileTokensVisual() {
  return (
    <ProductFeatureVisualFrame eyebrow="File detail" title="report-q4.pdf">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">File tokens</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              Share without session cookies or bucket permissions.
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            Public link
          </Badge>
        </div>

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <div className="flex items-center gap-2">
            <Link2 className="size-3.5 text-muted-foreground" aria-hidden />
            <p className="text-[11px] font-semibold text-foreground">Preview URL</p>
          </div>
          <div className="mt-2 flex items-center gap-2 rounded-md border border-border bg-muted/20 px-2.5 py-2">
            <p className="min-w-0 flex-1 truncate font-mono text-[10px] text-muted-foreground">
              …/files/report-q4/preview?token=8f3a…c21d
            </p>
            <Copy className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          </div>
        </div>

        <div className="grid gap-2 sm:grid-cols-2">
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <div className="flex items-center gap-2">
              <CalendarClock className="size-3.5 text-muted-foreground" aria-hidden />
              <p className="text-[11px] font-medium text-foreground">Expires</p>
            </div>
            <p className="mt-1 text-[12px] font-semibold text-foreground">Apr 30, 2026</p>
          </div>
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <p className="text-[11px] font-medium text-foreground">Access</p>
            <p className="mt-1 text-[12px] font-semibold text-foreground">Preview · View · Download</p>
          </div>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            Works for external viewers without third-party cookie issues.
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
