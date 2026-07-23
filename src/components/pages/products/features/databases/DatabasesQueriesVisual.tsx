import { ArrowRightLeft, Filter, GitBranch } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const QUERY_FILTERS = [
  { label: 'status', operator: 'equal', value: 'active' },
  { label: 'teamId', operator: 'equal', value: 'acme' },
] as const

const RESULT_ROWS = [
  { id: 'row_01', title: 'Launch checklist', status: 'active' },
  { id: 'row_02', title: 'Billing migration', status: 'active' },
  { id: 'row_03', title: 'Onboarding emails', status: 'active' },
] as const

export function DatabasesQueriesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'rows', label: 'Rows', active: true },
        { id: 'indexes', label: 'Indexes' },
        { id: 'relationships', label: 'Relationships' },
      ]}
    >
      <div className="space-y-4">
        <div className="overflow-hidden rounded-xl border border-border bg-background/80">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2">
            <Filter className="size-3.5 text-muted-foreground" aria-hidden />
            <span className="text-[11px] font-medium text-foreground">{t('Query')}</span>
            <Badge variant="info" className="ms-auto text-[10px]">
              {t('2 filters')}
            </Badge>
          </div>
          <div className="space-y-1.5 p-3">
            {QUERY_FILTERS.map((filter) => (
              <div
                key={filter.label}
                className="flex flex-wrap items-center gap-1.5 rounded-md border border-border bg-muted/20 px-2.5 py-1.5 text-[11px]"
              >
                <span className="font-medium text-foreground">{filter.label}</span>
                <span className="text-muted-foreground">{filter.operator}</span>
                <span className="rounded bg-background px-1.5 py-0.5 font-mono text-foreground">
                  {filter.value}
                </span>
              </div>
            ))}
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-border">
          <div className="grid grid-cols-[1fr_auto] gap-2 border-b border-border bg-muted/15 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            <span>{t('Title')}</span>
            <span>{t('Status')}</span>
          </div>
          <div className="divide-y divide-border">
            {RESULT_ROWS.map((row) => (
              <div
                key={row.id}
                className="grid grid-cols-[1fr_auto] items-center gap-2 px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="truncate text-[12px] font-medium text-foreground">
                    {row.title}
                  </p>
                  <p className="truncate font-mono text-[10px] text-muted-foreground">
                    {row.id}
                  </p>
                </div>
                <Badge variant="success" className="text-[10px]">
                  {t(row.status)}
                </Badge>
              </div>
            ))}
          </div>
        </div>

        <div className="grid gap-2 sm:grid-cols-2">
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <div className="flex items-center gap-2">
              <GitBranch className="size-3.5 text-muted-foreground" aria-hidden />
              <p className="text-[12px] font-medium text-foreground">{t('Relationships')}</p>
            </div>
            <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
              {t('Link related tables without custom joins.')}
            </p>
          </div>
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <div className="flex items-center gap-2">
              <ArrowRightLeft className="size-3.5 text-muted-foreground" aria-hidden />
              <p className="text-[12px] font-medium text-foreground">{t('Transactions')}</p>
            </div>
            <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
              {t('Commit multi-step writes atomically.')}
            </p>
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
