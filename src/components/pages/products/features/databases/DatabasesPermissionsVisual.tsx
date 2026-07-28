import { Shield, Users } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const PERMISSION_ROWS = [
  {
    id: 'team',
    label: 'team:acme',
    scope: 'Table',
    actions: ['Read', 'Create', 'Update'],
  },
  {
    id: 'role',
    label: 'role:admin',
    scope: 'Table',
    actions: ['Read', 'Create', 'Update', 'Delete'],
  },
  {
    id: 'user',
    label: 'user:owner',
    scope: 'Row',
    actions: ['Read', 'Update'],
  },
] as const

export function DatabasesPermissionsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'rows', label: 'Rows' },
        { id: 'columns', label: 'Columns' },
        { id: 'security', label: 'Security', active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Permissions')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Scope access with Auth users, teams, and roles.')}
            </p>
          </div>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Auth linked')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-xl border border-border">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2">
            <Shield className="size-3.5 text-muted-foreground" aria-hidden />
            <span className="text-[11px] font-medium text-foreground">{t('orders')}</span>
            <span className="ms-auto inline-flex items-center gap-1 text-[10px] text-muted-foreground">
              <Users className="size-3" aria-hidden />
              {t('3 rules')}
            </span>
          </div>
          <div className="divide-y divide-border">
            {PERMISSION_ROWS.map((row) => (
              <div
                key={row.id}
                className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0">
                  <p className="truncate font-mono text-[12px] text-foreground">{row.label}</p>
                  <p className="mt-0.5 text-[11px] text-muted-foreground">{t(row.scope)}</p>
                </div>
                <div className="flex flex-wrap gap-1">
                  {row.actions.map((action) => (
                    <Badge key={action} variant="info" className="text-[10px]">
                      {t(action)}
                    </Badge>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
