import { GitBranch, Globe, QrCode } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtConnector,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LANES = [
  {
    id: 'production',
    branch: 'main',
    commit: '4e1f2a9',
    host: 'acme.io',
    label: 'Production',
    production: true,
  },
  {
    id: 'preview',
    branch: 'feat/pricing',
    commit: '9f8e7d6',
    host: 'feat-pricing-9f8e7d6.appwrite.network',
    label: 'Preview',
    production: false,
  },
] as const

const PROVIDERS = [
  { id: 'github', icon: '/icons/github.svg' },
  { id: 'origin', icon: '/icons/origin.svg' },
] as const

const highlightedSurfaceClassName =
  'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]'

export function SitesGitPreviewsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-2">
      <div className="space-y-3">
        {LANES.map((lane, index) => (
          <div
            key={lane.id}
            className="grid grid-cols-[minmax(0,0.8fr)_minmax(1rem,0.35fr)_minmax(0,1.3fr)] items-center"
          >
            <ArtPanel delayMs={80 + index * 160} innerClassName="flex items-center gap-2 px-2.5 py-2">
              <ArtIconBadge icon={GitBranch} tone={lane.production ? 'primary' : 'secondary'} />
              <div dir="ltr" className="min-w-0 text-start">
                <p className="truncate font-mono text-[11px] font-medium text-foreground">{lane.branch}</p>
                <p className="font-mono text-[10px] text-muted-foreground">{lane.commit}</p>
              </div>
            </ArtPanel>

            <ArtConnector travel travelDelayMs={400 + index * 700} />

            <ArtPanel
              delayMs={260 + index * 160}
              float
              floatDelayMs={index * 600}
              innerClassName={cn('px-3 py-2.5', lane.production && highlightedSurfaceClassName)}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="truncate text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(lane.label)}
                </span>
                {lane.production ? (
                  <Badge variant="active" className="shrink-0 text-[10px]">
                    {t('Active')}
                  </Badge>
                ) : (
                  <Badge variant="deploymentReady" className="shrink-0 text-[10px]">
                    {t('Ready')}
                  </Badge>
                )}
              </div>
              <div className="mt-1.5 flex min-w-0 items-center gap-1.5">
                {lane.production ? (
                  <ArtLiveDot className="shrink-0" />
                ) : (
                  <Globe className="size-3 shrink-0 text-muted-foreground" aria-hidden />
                )}
                <p dir="ltr" className="min-w-0 truncate font-mono text-[11px] text-foreground">
                  {lane.host}
                </p>
              </div>
            </ArtPanel>
          </div>
        ))}
      </div>

      <div className="relative mt-7 sm:ps-16">
        <div className="absolute start-0 top-2 hidden flex-col gap-2 sm:flex" aria-hidden>
          {PROVIDERS.map((provider, index) => (
            <span key={provider.id} className="product-hero-rise" style={riseStyle(900 + index * 120)}>
              <span
                className="product-hero-float flex size-11 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card"
                style={floatStyle(index * 500)}
              >
                <ProductFeaturePublicIcon src={provider.icon} className="size-[18px]" />
              </span>
            </span>
          ))}
        </div>

        <ArtPanel delayMs={600} innerClassName="p-3.5">
          <div className="flex items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-background">
              <img src="/icons/appwrite.svg" alt="" className="size-3.5" aria-hidden />
            </span>
            <p className="min-w-0 truncate text-[11px] text-muted-foreground">
              <span className="font-semibold text-foreground">appwrite</span> {t('bot')} · {t('2m ago')}
            </p>
            <span className="ms-auto shrink-0 text-[10px] text-muted-foreground">
              {t('Pull request')} <span dir="ltr" className="font-mono">#42</span>
            </span>
          </div>

          <div className="mt-3 rounded-lg border border-border bg-muted/20">
            <div className="flex items-center gap-2 border-b border-border px-3 py-2">
              <img src="/icons/appwrite.svg" alt="" className="size-3.5 shrink-0" aria-hidden />
              <p className="text-[12px] font-semibold text-foreground">{t('Appwrite Sites')}</p>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
              <p dir="ltr" className="font-mono text-[11px] font-medium text-foreground">marketing-site</p>
              <Badge variant="deploymentReady" className="text-[10px]">
                {t('Ready')}
              </Badge>
              <span className="flex items-center gap-3 sm:ms-auto">
                <span className="text-[11px] text-muted-foreground">{t('View Logs')}</span>
                <span className="text-[11px] font-medium text-[var(--tone-ink)]">{t('Preview URL')}</span>
                <span className="flex size-7 items-center justify-center rounded-md border border-border bg-background text-muted-foreground dark:bg-card">
                  <QrCode className="size-3.5" aria-hidden />
                </span>
              </span>
            </div>
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}
