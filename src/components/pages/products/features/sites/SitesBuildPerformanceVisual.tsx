import { Archive, Check, Cpu, Filter, Zap } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Badge } from '@/components/ui/badge'
import { ArtChip, ArtIconBadge, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const BUILDS = [
  { id: '67abc12f', duration: '24s', width: '34%', cached: true, active: true },
  { id: '89def45a', duration: '28s', width: '39%', cached: true, active: false },
  { id: '12ab34cd', duration: '1m 12s', width: '100%', cached: false, active: false },
] as const

const PATH_FILTERS = [
  { path: 'apps/web/**', matched: true },
  { path: 'apps/docs/**', matched: false },
] as const

export function SitesBuildPerformanceVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[440px] w-full max-w-[560px]">
      <ArtPanel
        className="absolute left-1/2 top-1/2 z-[1] w-[min(340px,88%)] -translate-x-1/2 -translate-y-1/2"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <div className="flex items-center justify-between gap-2">
          <p className="text-[12px] font-semibold text-foreground">{t('Deployments')}</p>
          <span className="text-[10px] text-muted-foreground">{t('Duration')}</span>
        </div>
        <div className="mt-3 space-y-3">
          {BUILDS.map((build, index) => (
            <div key={build.id}>
              <div className="flex items-center gap-2">
                <span dir="ltr" className="font-mono text-[11px] text-foreground">{build.id}</span>
                {build.active ? (
                  <Badge variant="active" className="text-[10px]">{t('Active')}</Badge>
                ) : null}
                <span dir="ltr" className="ms-auto font-mono text-[11px] text-foreground">{build.duration}</span>
              </div>
              <div className="mt-1.5 flex items-center gap-2">
                <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                  <div className="h-full" style={{ width: build.width }}>
                    <div
                      className={cn(
                        'product-hero-fill h-full rounded-full',
                        build.cached ? 'bg-[var(--tone-ink)]' : 'bg-muted-foreground/40',
                      )}
                      style={{ '--fill-delay': `${400 + index * 220}ms`, '--fill-duration': '1.1s' } as CSSProperties}
                    />
                  </div>
                </div>
                <span
                  className={cn(
                    'flex min-w-[72px] shrink-0 items-center justify-end gap-1 text-[10px]',
                    build.cached ? 'text-[var(--tone-ink)]' : 'text-muted-foreground',
                  )}
                >
                  {build.cached ? (
                    <>
                      <Zap className="size-3" aria-hidden />
                      {t('Cache hit')}
                    </>
                  ) : (
                    t('Build')
                  )}
                </span>
              </div>
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="start-0 top-[2%]" delayMs={600}>
        <div className="flex items-center gap-1.5">
          <Filter className="size-3 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] font-semibold text-foreground">{t('Path filters')}</p>
        </div>
        <div className="mt-2 space-y-1.5">
          {PATH_FILTERS.map((filter) => (
            <div key={filter.path} className="flex items-center gap-2">
              <span
                dir="ltr"
                className={cn(
                  'font-mono text-[10px]',
                  filter.matched ? 'text-foreground' : 'text-muted-foreground line-through',
                )}
              >
                {filter.path}
              </span>
              {filter.matched ? (
                <Check className="ms-auto size-3 text-emerald-600 dark:text-emerald-400" strokeWidth={3} aria-hidden />
              ) : (
                <Badge variant="inactive" className="ms-auto text-[9px]">{t('Skipped')}</Badge>
              )}
            </div>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[8%] hidden sm:block" delayMs={800} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Zap} tone="success" />
          <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            pnpm store restored <span className="text-foreground">1.2s</span>
          </p>
        </div>
      </ArtChip>

      <ArtChip className="bottom-[3%] start-[2%]" delayMs={1000} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Archive} tone="secondary" />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Deployment retention')}</p>
            <p className="text-[10px] text-muted-foreground">{t('30 days')}</p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-[9%] end-0 hidden sm:block" delayMs={1200} floatDelayMs={400}>
        <div className="flex items-center gap-1.5">
          <Cpu className="size-3 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] font-semibold text-foreground">{t('Specification')}</p>
        </div>
        <div className="mt-1.5 grid grid-cols-[auto_auto] gap-x-4 gap-y-1 text-[10px]">
          <span className="text-muted-foreground">{t('CPU')}</span>
          <span dir="ltr" className="text-end font-mono text-foreground">2 vCPU</span>
          <span className="text-muted-foreground">{t('Memory')}</span>
          <span dir="ltr" className="text-end font-mono text-foreground">4 GB</span>
        </div>
      </ArtChip>
    </div>
  )
}
