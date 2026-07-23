import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const RULES = [
  {
    id: 'bypass-office',
    priority: -10,
    name: 'Office IP allowlist',
    action: 'Bypass',
    actionVariant: 'processing' as const,
    match: true,
  },
  {
    id: 'deny-account',
    priority: 0,
    name: 'Deny account mutations',
    action: 'Deny',
    actionVariant: 'error' as const,
    match: false,
  },
  {
    id: 'rate-api',
    priority: 10,
    name: 'Rate limit public API',
    action: 'Rate limit',
    actionVariant: 'warning' as const,
    match: false,
  },
] as const

export function FirewallPriorityVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Rule priority')}>
      <div className="space-y-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Lower numbers evaluate first. The first match stops the chain.')}
        </p>
        <div className="space-y-2">
          {RULES.map((rule, index) => (
            <div
              key={rule.id}
              className={cn(
                'flex flex-wrap items-center gap-3 rounded-lg border border-border bg-background/80 px-3 py-2.5',
                rule.match && 'border-foreground/15 bg-muted/40',
              )}
            >
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 font-mono text-[11px] font-semibold text-foreground">
                {index + 1}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-[12px] font-medium text-foreground">{t(rule.name)}</p>
                  <Badge variant={rule.actionVariant} className="text-[10px]">
                    {t(rule.action)}
                  </Badge>
                  {rule.match ? (
                    <Badge variant="success" className="text-[10px]">
                      {t('First match')}
                    </Badge>
                  ) : (
                    <Badge variant="inactive" className="text-[10px]">
                      {t('Skipped')}
                    </Badge>
                  )}
                </div>
                <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
                  {t('Priority')}: {rule.priority}
                </p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
