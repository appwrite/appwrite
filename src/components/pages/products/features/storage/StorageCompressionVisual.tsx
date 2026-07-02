import { ArrowRight } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const OUTPUT_FORMATS = ['jpg', 'png', 'webp', 'avif', 'heic'] as const

export function StorageCompressionVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'files', label: t('Files') },
        { id: 'settings', label: t('Settings'), active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[12px] font-medium leading-6 text-foreground">
            {t('Store less, transfer less, load faster. One upload, optimized on every delivery.')}
          </p>
        </div>

        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="px-4 py-3">
            <p className="text-[13px] font-semibold text-foreground">{t('Bucket compression')}</p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
              {t('gzip or zstd shrinks stored files and outbound traffic automatically.')}
            </p>
          </div>
          <div className="border-t border-border px-4 py-3">
            <p className="text-[11px] font-medium text-foreground">{t('Algorithm')}</p>
            <div className="mt-2 flex h-9 max-w-[12rem] items-center rounded-md border border-border bg-background px-3 text-[12px] text-foreground">
              Zstd
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="text-[12px] font-semibold text-foreground">{t('Modern formats on delivery')}</p>
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {t('Serve WebP or AVIF without storing duplicate files.')}
              </p>
            </div>
            <Badge variant="success" className="shrink-0 text-[10px]">
              {t('67% smaller')}
            </Badge>
          </div>

          <div className="mt-3 flex items-center gap-2">
            <div className="min-w-0 flex-1 rounded-md border border-border bg-muted/20 px-2.5 py-2">
              <p className="text-[10px] uppercase tracking-wider text-muted-foreground">{t('Original')}</p>
              <p className="mt-0.5 text-[12px] font-medium text-foreground">hero.png · 842 KB</p>
            </div>
            <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
            <div className="min-w-0 flex-1 rounded-md border border-border bg-muted/30 px-2.5 py-2">
              <p className="text-[10px] uppercase tracking-wider text-muted-foreground">{t('Preview')}</p>
              <p className="mt-0.5 text-[12px] font-medium text-foreground">hero.webp · 278 KB</p>
            </div>
          </div>

          <div className="mt-3 flex flex-wrap gap-1.5">
            {OUTPUT_FORMATS.map((format) => (
              <span
                key={format}
                className="rounded-md border border-border bg-muted/30 px-2 py-0.5 font-mono text-[10px] uppercase text-muted-foreground"
              >
                {format}
              </span>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
