import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const EXAMPLE_CONDITIONS = [
  { id: '1', label: 'IP address', operator: 'Equals', value: '203.0.113.10' },
  { id: '2', label: 'Path', operator: 'Starts with', value: '/v1/account' },
  { id: '3', label: 'Country', operator: 'Not equal', value: 'Unresolved' },
] as const

export function FirewallConditionsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Rule conditions')}>
      <div className="space-y-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Every condition on a rule must match before the action runs.')}
        </p>

        <div className="space-y-2">
          {EXAMPLE_CONDITIONS.map((condition, index) => (
            <div key={condition.id} className="space-y-2">
              {index > 0 ? (
                <div className="flex items-center gap-2 px-1">
                  <span className="h-px flex-1 bg-border" />
                  <Badge variant="inactive" className="text-[10px]">
                    {t('AND')}
                  </Badge>
                  <span className="h-px flex-1 bg-border" />
                </div>
              ) : null}
              <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-background/80 px-3 py-2.5">
                <span className="text-[12px] font-medium text-foreground">
                  {t(condition.label)}
                </span>
                <Badge variant="inactive" className="text-[10px]">
                  {t(condition.operator)}
                </Badge>
                <code className="rounded-md border border-border bg-muted/30 px-2 py-0.5 font-mono text-[11px] text-foreground">
                  {condition.value}
                </code>
              </div>
            </div>
          ))}
        </div>

        <p className="text-[11px] leading-5 text-muted-foreground">
          {t(
            'Match on request properties such as identity, location, path, and client signals.',
          )}
        </p>
      </div>
    </ProductFeatureVisualFrame>
  )
}
