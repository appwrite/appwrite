import { ArrowRight, GitBranch } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const DOMAIN_RULES = [
  {
    id: 'generated',
    host: '64d4d22d….appwrite.network',
    rule: 'Generated · active deployment',
    badge: 'success' as const,
    label: 'Live',
  },
  {
    id: 'production',
    host: 'acme.io',
    rule: 'Active deployment',
    badge: 'success' as const,
    label: 'Production',
  },
  {
    id: 'staging',
    host: 'staging.acme.io',
    rule: 'Git branch · staging',
    badge: 'info' as const,
    label: 'Staging',
  },
  {
    id: 'redirect',
    host: 'www.acme.io',
    rule: 'Redirect → acme.io',
    badge: 'outline' as const,
    label: 'Redirect',
  },
] as const

export function SitesDomainRulesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'deployments', label: t('Deployments') },
        { id: 'domains', label: t('Domains'), active: true },
      ]}
    >
      <div className="space-y-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Map each hostname to a deployment, branch, or redirect.')}
        </p>
        <div className="overflow-hidden rounded-lg border border-border bg-background/80">
          <div className="divide-y divide-border">
            {DOMAIN_RULES.map((entry) => (
              <div
                key={entry.id}
                className="flex items-center justify-between gap-3 px-3 py-2.5 transition-colors group-hover/visual:bg-muted/20"
              >
                <div className="min-w-0">
                  <p className="truncate font-mono text-[11px] font-medium text-foreground">
                    {entry.host}
                  </p>
                  <p className="mt-0.5 flex items-center gap-1.5 text-[10px] text-muted-foreground">
                    {entry.rule.includes('Git branch') ? (
                      <GitBranch className="size-3 shrink-0" aria-hidden />
                    ) : entry.rule.startsWith('Redirect') ? (
                      <ArrowRight className="size-3 shrink-0" aria-hidden />
                    ) : null}
                    {t(entry.rule)}
                  </p>
                </div>
                <Badge variant={entry.badge} className="shrink-0 text-[10px]">
                  {t(entry.label)}
                </Badge>
              </div>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
