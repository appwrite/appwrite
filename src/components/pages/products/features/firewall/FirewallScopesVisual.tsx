import { Code2, Globe, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SCOPES = [
  {
    id: 'api',
    title: 'API',
    description: 'Project REST and GraphQL endpoints.',
    resource: 'project',
    icon: Code2,
    active: true,
  },
  {
    id: 'functions',
    title: 'Functions',
    description: 'A specific Function execution endpoint.',
    resource: 'fn_checkout',
    icon: Zap,
    active: false,
  },
  {
    id: 'sites',
    title: 'Sites',
    description: 'A specific Site deployment hostname.',
    resource: 'site_storefront',
    icon: Globe,
    active: false,
  },
] as const

export function FirewallScopesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Resource scope')}>
      <div className="space-y-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Choose where the rule evaluates matching traffic.')}
        </p>
        <div className="grid gap-2 sm:grid-cols-3">
          {SCOPES.map((scope) => {
            const Icon = scope.icon as LucideIcon
            return (
              <div
                key={scope.id}
                className={cn(
                  'rounded-lg border border-border bg-background/80 p-3 transition-colors',
                  scope.active && 'border-foreground/15 bg-muted/40',
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                    <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                  </span>
                  {scope.active ? (
                    <Badge variant="success" className="text-[10px]">
                      {t('Selected')}
                    </Badge>
                  ) : null}
                </div>
                <p className="mt-3 text-[13px] font-semibold text-foreground">
                  {t(scope.title)}
                </p>
                <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
                  {t(scope.description)}
                </p>
                <code className="mt-2 block truncate font-mono text-[10px] text-muted-foreground">
                  {scope.resource}
                </code>
              </div>
            )
          })}
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
