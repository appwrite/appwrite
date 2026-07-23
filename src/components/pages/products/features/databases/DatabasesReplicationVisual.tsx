import { Badge } from '@/components/ui/badge'
import { DatabaseClusterPreview } from '@/components/pages/projects/$projectId/databases/_components/DatabaseClusterPreview'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SYNC_MODES = [
  {
    value: 'async',
    label: 'Asynchronous',
    selected: true,
  },
  {
    value: 'sync',
    label: 'Synchronous',
    selected: false,
  },
  {
    value: 'quorum',
    label: 'Quorum',
    selected: false,
  },
] as const

export function DatabasesReplicationVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'general', label: 'General' },
        { id: 'replication', label: 'Replication', active: true },
        { id: 'pitr', label: 'PITR' },
      ]}
      contentClassName="space-y-3"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-[11px] font-medium text-muted-foreground">
            {t('Sync mode')}
          </span>
          {SYNC_MODES.map((mode) => (
            <span
              key={mode.value}
              className={cn(
                'rounded-md border px-2 py-0.5 text-[11px] font-medium',
                mode.selected
                  ? 'border-foreground/15 bg-muted/50 text-foreground'
                  : 'border-border bg-background/60 text-muted-foreground',
              )}
            >
              {t(mode.label)}
            </span>
          ))}
        </div>
        <Badge variant="success" className="text-[10px] shrink-0">
          {t('HA enabled')}
        </Badge>
      </div>

      <div className="overflow-hidden rounded-xl border border-border">
        <DatabaseClusterPreview
          replicaCount={2}
          nodeStatuses={['active', 'active', 'active']}
          nodeMetrics={[
            { cpu: 42, memory: 61 },
            { cpu: 28, memory: 47 },
            { cpu: 31, memory: 44 },
          ]}
          proxy={{
            label: 'PgDog',
            status: 'active',
            connections: { current: 48, max: 200 },
          }}
          previewHeight={210}
          withSectionDivider={false}
          className="bg-muted/20"
        />
      </div>

      <p className="text-[11px] leading-5 text-muted-foreground">
        {t('Proxy')} · {t('Primary')} · {t('Read replicas')}
      </p>
    </ProductFeatureVisualFrame>
  )
}
