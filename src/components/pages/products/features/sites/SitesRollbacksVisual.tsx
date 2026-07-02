import { History, RotateCcw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const DEPLOYMENTS = [
  {
    id: 'current',
    label: 'v1.4.2 · main',
    detail: 'Active now · 2h ago',
    active: true,
  },
  {
    id: 'previous',
    label: 'v1.4.1 · main',
    detail: 'Ready · yesterday',
    active: false,
    selected: true,
  },
  {
    id: 'older',
    label: 'v1.4.0 · main',
    detail: 'Ready · 3 days ago',
    active: false,
    selected: false,
  },
] as const

export function SitesRollbacksVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview'), active: true },
        { id: 'deployments', label: t('Deployments') },
        { id: 'settings', label: t('Settings') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Marketing site')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Promote a previous deployment without rebuilding.')}
            </p>
          </div>
          <Button size="sm" variant="outline" className="h-8 shrink-0 text-[12px]">
            <RotateCcw className="me-1.5 size-3.5" aria-hidden />
            {t('Instant Rollback')}
          </Button>
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2.5">
            <History className="size-3.5 text-muted-foreground" aria-hidden />
            <p className="text-[12px] font-semibold text-foreground">{t('Select deployment')}</p>
          </div>
          <div className="divide-y divide-border">
            {DEPLOYMENTS.map((deployment) => (
              <div
                key={deployment.id}
                className={cn(
                  'flex items-center justify-between gap-3 px-3 py-2.5 transition-colors',
                  deployment.selected && 'bg-muted/25',
                )}
              >
                <div className="min-w-0">
                  <p className="truncate text-[11px] font-medium text-foreground">
                    {deployment.label}
                  </p>
                  <p className="text-[10px] text-muted-foreground">{t(deployment.detail)}</p>
                </div>
                {deployment.active ? (
                  <Badge variant="success" className="shrink-0 text-[10px]">
                    {t('Active')}
                  </Badge>
                ) : deployment.selected ? (
                  <Badge variant="info" className="shrink-0 text-[10px]">
                    {t('Selected')}
                  </Badge>
                ) : (
                  <Badge variant="outline" className="shrink-0 text-[10px]">
                    {t('Ready')}
                  </Badge>
                )}
              </div>
            ))}
          </div>
        </div>

        <div className="flex items-center justify-end gap-2">
          <Button size="sm" variant="outline" className="h-8 text-[12px]">
            {t('Cancel')}
          </Button>
          <Button size="sm" className="h-8 text-[12px]">
            {t('Rollback')}
          </Button>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/10 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Rollbacks switch which deployment is served. No code is deleted, modified, or rebuilt, so recovery is near-instant with zero downtime.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
