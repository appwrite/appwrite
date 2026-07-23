import { Cloud, Server } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const COMPUTE_OPTIONS = [
  {
    id: 'serverless',
    title: 'Serverless',
    description: 'Shared pool. Fast to create, no capacity planning.',
    icon: Cloud,
    badge: 'Default',
    badgeVariant: 'info' as const,
    highlights: ['Shared compute pool', 'Instant provisioning', 'Ideal for prototypes'],
    selected: true,
  },
  {
    id: 'dedicated',
    title: 'Dedicated',
    description: 'Isolated compute with replicas, HA, and PITR.',
    icon: Server,
    badge: 'Production',
    badgeVariant: 'success' as const,
    highlights: ['Isolated resources', 'Read replicas & HA', 'Point-in-time recovery'],
    selected: false,
  },
] as const

export function DatabasesServerlessVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'type', label: 'Database type' },
        { id: 'specs', label: 'Specifications', active: true },
        { id: 'options', label: 'Options' },
      ]}
    >
      <div className="space-y-3">
        <div>
          <p className="text-[13px] font-semibold text-foreground">{t('Compute model')}</p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {t('Choose how this database is provisioned.')}
          </p>
        </div>

        <div className="grid gap-2.5 sm:grid-cols-2">
          {COMPUTE_OPTIONS.map((option) => {
            const Icon = option.icon
            return (
              <div
                key={option.id}
                className={cn(
                  'rounded-xl border border-border bg-background/80 p-3.5 transition-colors',
                  option.selected && 'border-foreground/15 bg-muted/35',
                )}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                    <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                  </span>
                  <Badge variant={option.badgeVariant} className="text-[10px] shrink-0">
                    {t(option.badge)}
                  </Badge>
                </div>
                <p className="mt-3 text-[13px] font-semibold text-foreground">
                  {t(option.title)}
                </p>
                <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
                  {t(option.description)}
                </p>
                <ul className="mt-3 space-y-1.5">
                  {option.highlights.map((item) => (
                    <li
                      key={item}
                      className="flex items-center gap-2 text-[11px] text-muted-foreground"
                    >
                      <span className="size-1 shrink-0 rounded-full bg-foreground/40" />
                      {t(item)}
                    </li>
                  ))}
                </ul>
              </div>
            )
          })}
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
