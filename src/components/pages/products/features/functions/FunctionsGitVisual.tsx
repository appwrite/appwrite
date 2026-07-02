import { GitBranch, GitCommitHorizontal } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

function MockField({ label, value }: { label: string; value: string }) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t(label)}
      </p>
      <p className="mt-1 truncate font-mono text-[11px] text-foreground">{value}</p>
    </div>
  )
}

export function FunctionsGitVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'deployments', label: t('Deployments') },
        { id: 'settings', label: t('Settings'), active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Git repository')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Auto-build on push with branch and path filters.')}
            </p>
          </div>
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('Connected')}
          </Badge>
        </div>

        <MockField label={t('Repository')} value="appwrite/backend" /> {/* pragma: allowlist secret */}

        <div className="grid gap-2 sm:grid-cols-2">
          <MockField label={t('Production branch')} value="main" />
          <MockField label={t('Root directory')} value="functions/stripe-webhook" />
        </div>

        <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Build triggers')}
          </p>
          <div className="mt-2 space-y-1.5">
            <div className="flex items-center gap-2 text-[11px] text-foreground">
              <GitBranch className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <span className="font-mono">main, release/*</span>
            </div>
            <div className="flex items-center gap-2 text-[11px] text-foreground">
              <span className="font-mono text-muted-foreground">paths:</span>
              <span className="font-mono">functions/**</span>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-between gap-3 rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <div className="flex min-w-0 items-center gap-2">
            <GitCommitHorizontal className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            <div className="min-w-0">
              <p className="truncate text-[11px] font-medium text-foreground">
                feat: add webhook handler
              </p>
              <p className="text-[10px] text-muted-foreground">main · {t('2m ago')}</p>
            </div>
          </div>
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('Active')}
          </Badge>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
