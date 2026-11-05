import type { CSSProperties } from 'react'
import { CheckCircle2, Circle, Cpu, Loader2, Zap } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const BUILD_STEPS = [
  { label: 'Restoring dependency cache', duration: '1.2s', state: 'done' },
  { label: 'pnpm install --frozen-lockfile', duration: '8.1s', state: 'done' },
  { label: 'npm run build', duration: '12s', state: 'running' },
  { label: 'Packaging deployment artifact', duration: '', state: 'pending' },
] as const

const PACKAGE_MANAGERS = [
  { id: 'npm', icon: '/icons/npm.svg' },
  { id: 'pnpm', icon: '/icons/pnpm.svg' },
  { id: 'yarn', icon: '/icons/yarn.svg' },
  { id: 'bun', icon: '/icons/bun.svg' },
] as const

const SPECIFICATIONS = [
  { label: 'Build', value: '2 vCPU · 2 GB', share: 1 },
  { label: 'Runtime', value: '0.5 vCPU · 512 MB', share: 0.3 },
] as const

function StepIcon({ state }: { state: (typeof BUILD_STEPS)[number]['state'] }) {
  if (state === 'done') {
    return <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden />
  }
  if (state === 'running') {
    return (
      <Loader2
        className="size-4 animate-spin text-[var(--tone-ink)] motion-reduce:animate-none"
        aria-hidden
      />
    )
  }
  return <Circle className="size-4 text-muted-foreground/50" aria-hidden />
}

export function FunctionsBuildsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto grid w-full max-w-[560px] items-start gap-6 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)] sm:gap-5">
      <div className="min-w-0">
        <div
          className="product-hero-rise mb-3 flex flex-wrap items-center gap-2"
          style={riseStyle(0)}
        >
          <Badge variant="deploymentBuilding" className="gap-1.5 text-[10px]">
            <Loader2 className="size-3 animate-spin motion-reduce:animate-none" aria-hidden />
            {t('Building')}
          </Badge>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            67abc12f9e2d
          </span>
          <span className="ms-auto text-[11px] text-muted-foreground">
            {t('Duration:')} <span className="font-medium text-foreground">24s</span>
          </span>
        </div>

        <div className="relative">
          <span
            className="absolute bottom-5 start-[27px] top-5 border-s border-dashed border-foreground/25"
            aria-hidden
          />
          <ol className="space-y-2">
            {BUILD_STEPS.map((step, index) => {
              const running = step.state === 'running'
              return (
                <li key={step.label}>
                  <ArtPanel
                    delayMs={150 + index * 140}
                    innerClassName={cn(
                      'relative flex items-center gap-2.5 px-3 py-2.5',
                      running &&
                        'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                      step.state === 'pending' && 'opacity-60',
                    )}
                  >
                    <span className="relative z-[1] flex size-7 shrink-0 items-center justify-center rounded-full bg-background dark:bg-card">
                      <StepIcon state={step.state} />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p
                        dir="ltr"
                        className="truncate text-start font-mono text-[11px] text-foreground"
                      >
                        {step.label}
                      </p>
                      {running ? (
                        <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                          <div
                            className="product-hero-fill h-full w-[72%] rounded-full bg-[var(--tone-ink)]"
                            style={
                              {
                                '--fill-delay': '900ms',
                                '--fill-duration': '3.2s',
                              } as CSSProperties
                            }
                          />
                        </div>
                      ) : null}
                    </div>
                    {step.duration ? (
                      <span dir="ltr" className="shrink-0 font-mono text-[10px] text-muted-foreground">
                        {step.duration}
                      </span>
                    ) : null}
                  </ArtPanel>
                </li>
              )
            })}
          </ol>
        </div>
      </div>

      <div className="min-w-0 space-y-3 sm:pt-10">
        <ArtPanel innerClassName="px-3.5 py-3" delayMs={600} float floatDelayMs={200}>
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={Zap} />
            <p className="text-[12px] font-semibold text-foreground">{t('Cache hit')}</p>
            <span dir="ltr" className="ms-auto font-mono text-[10px] text-emerald-600 dark:text-emerald-400">
              -8.1s
            </span>
          </div>
          <div className="mt-3 grid grid-cols-4 gap-1.5">
            {PACKAGE_MANAGERS.map((manager, index) => (
              <span
                key={manager.id}
                className="product-hero-rise relative flex aspect-square items-center justify-center rounded-lg border border-border bg-muted/30"
                style={riseStyle(800 + index * 110)}
                title={manager.id}
              >
                <ProductFeaturePublicIcon src={manager.icon} className="size-4" />
                <CheckCircle2
                  className="absolute -end-1 -top-1 size-3 rounded-full bg-background text-emerald-600 dark:bg-card dark:text-emerald-400"
                  aria-hidden
                />
              </span>
            ))}
          </div>
        </ArtPanel>

        <ArtPanel
          className="sm:-ms-6"
          innerClassName="px-3.5 py-3"
          delayMs={850}
          float
          floatDelayMs={900}
        >
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={Cpu} tone="secondary" />
            <p className="text-[12px] font-semibold text-foreground">{t('Specifications')}</p>
          </div>
          <div className="mt-2.5 space-y-2">
            {SPECIFICATIONS.map((spec) => (
              <div key={spec.label}>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[11px] text-muted-foreground">{t(spec.label)}</span>
                  <span dir="ltr" className="font-mono text-[10px] text-foreground">
                    {spec.value}
                  </span>
                </div>
                <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
                  <div
                    className="product-hero-fill h-full rounded-full bg-[rgb(var(--tone2-rgb)/0.7)]"
                    style={
                      {
                        width: `${spec.share * 100}%`,
                        '--fill-delay': '1100ms',
                      } as CSSProperties
                    }
                  />
                </div>
              </div>
            ))}
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}
