import { ArrowRight, Globe } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const DELIVERY_STEPS = [
  {
    id: 'transform',
    label: 'Transform',
    title: 'preview?width=640',
    detail: 'Processed once',
    highlight: false,
  },
  {
    id: 'region',
    label: 'Region cache',
    title: 'fra · region cache',
    detail: 'Repeat transforms stay fast',
    highlight: true,
  },
  {
    id: 'edge',
    label: 'CDN edge',
    title: 'Nearest PoP',
    detail: 'Low latency worldwide',
    highlight: false,
  },
] as const

export function StorageCdnVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame eyebrow={t('Network')} title={t('Storage delivery')}>
      <div className="space-y-4">
        <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[12px] font-medium leading-6 text-foreground">
            {t('Global CDN included. Transformed images cached in your project region before they reach the edge.')}
          </p>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <span className="flex size-7 items-center justify-center rounded-md border border-border bg-background">
              <Globe className="size-3.5 text-muted-foreground" aria-hidden />
            </span>
            <p className="text-[13px] font-semibold text-foreground">{t('Delivery path')}</p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('120+ edge locations')}
          </Badge>
        </div>

        <div className="flex flex-col gap-2 sm:flex-row sm:items-stretch">
          {DELIVERY_STEPS.map((step, index) => (
            <div key={step.id} className="flex min-w-0 flex-1 items-stretch gap-2">
              <div
                className={
                  step.highlight
                    ? 'min-w-0 flex-1 rounded-lg border border-primary/20 bg-primary/[0.06] p-3'
                    : 'min-w-0 flex-1 rounded-lg border border-border bg-background/80 p-3'
                }
              >
                <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(step.label)}
                </p>
                <p className="mt-1 text-[12px] font-medium text-foreground">{t(step.title)}</p>
                <p className="mt-1 text-[11px] text-muted-foreground">{t(step.detail)}</p>
                {step.highlight ? (
                  <Badge variant="success" className="mt-2 text-[10px]">
                    {t('Cache hit')}
                  </Badge>
                ) : null}
              </div>
              {index < DELIVERY_STEPS.length - 1 ? (
                <div className="hidden shrink-0 items-center sm:flex">
                  <ArrowRight className="size-3.5 text-muted-foreground" aria-hidden />
                </div>
              ) : null}
            </div>
          ))}
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/10 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Storage files and transformed previews inherit Appwrite CDN delivery and regional caching automatically. No separate CDN setup required.')} {/* pragma: allowlist secret */}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
