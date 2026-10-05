import { Check, Globe, RotateCcw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type RollbackDeployment = {
  id: string
  label: string
  detail: string
  state: 'active' | 'selected' | 'ready'
  offset: string
}

const DEPLOYMENTS: RollbackDeployment[] = [
  { id: 'current', label: 'v1.4.2 · main', detail: 'Active now · 2h ago', state: 'active', offset: 'sm:me-8' },
  { id: 'previous', label: 'v1.4.1 · main', detail: 'Ready · yesterday', state: 'selected', offset: 'sm:ms-4 sm:me-4' },
  { id: 'older', label: 'v1.4.0 · main', detail: 'Ready · 3 days ago', state: 'ready', offset: 'sm:ms-8' },
]

export function SitesRollbacksVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] pb-16 pt-4">
      <div className="grid items-center gap-5 sm:grid-cols-[auto_minmax(2rem,1fr)_minmax(0,17rem)] sm:gap-0">
        <ArtPanel
          className="w-fit"
          innerClassName="flex items-center gap-2.5 px-3 py-2.5"
          delayMs={60}
          float
        >
          <ArtIconBadge icon={Globe} />
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Production')}
            </p>
            <p className="mt-0.5 flex items-center gap-1.5">
              <span dir="ltr" className="font-mono text-[12px] font-medium text-foreground">acme.io</span>
              <ArtLiveDot />
            </p>
          </div>
        </ArtPanel>

        <ArtConnector travel travelDelayMs={900} className="hidden sm:block" />

        <div className="relative space-y-2.5">
          {DEPLOYMENTS.map((deployment, index) => {
            const selected = deployment.state === 'selected'
            return (
              <ArtPanel
                key={deployment.id}
                className={deployment.offset}
                innerClassName={cn(
                  'flex items-center justify-between gap-3 px-3 py-2.5',
                  deployment.state === 'ready' && 'opacity-60',
                  selected &&
                    'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                )}
                delayMs={200 + index * 140}
              >
                <div className="min-w-0">
                  <p dir="ltr" className="truncate text-start text-[11px] font-medium text-foreground">
                    {deployment.label}
                  </p>
                  <p className="text-[10px] text-muted-foreground">{t(deployment.detail)}</p>
                </div>
                {deployment.state === 'active' ? (
                  <Badge variant="active" className="shrink-0 text-[10px]">
                    {t('Active')}
                  </Badge>
                ) : selected ? (
                  <span className="inline-flex shrink-0 items-center gap-1 rounded-md bg-foreground px-2 py-1 text-[10px] font-medium text-background">
                    <RotateCcw className="size-3" aria-hidden />
                    {t('Rollback')}
                  </span>
                ) : (
                  <Badge variant="deploymentReady" className="shrink-0 text-[10px]">
                    {t('Ready')}
                  </Badge>
                )}
              </ArtPanel>
            )
          })}

          <span
            className="product-hero-rise pointer-events-none absolute -end-3 top-[14%] hidden h-[36%] w-10 sm:block"
            style={riseStyle(900)}
            aria-hidden
          >
            <svg viewBox="0 0 40 60" className="size-full rtl:-scale-x-100" fill="none">
              <path
                d="M4 4 C 30 4, 36 18, 36 30 C 36 44, 30 56, 15 56"
                className="stroke-[var(--tone-ink)]"
                strokeWidth="1.5"
                strokeDasharray="3 3"
                vectorEffect="non-scaling-stroke"
              />
              <path
                d="M21 51 L15 56 L21 60"
                className="stroke-[var(--tone-ink)]"
                strokeWidth="1.5"
                vectorEffect="non-scaling-stroke"
              />
            </svg>
          </span>
        </div>
      </div>

      <ArtChip className="bottom-0 end-0 max-w-[260px]" delayMs={1100} floatDelayMs={600}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Check} tone="success" />
          <p className="text-[11px] leading-4 text-foreground">
            {t('Promote a previous deployment without rebuilding.')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
