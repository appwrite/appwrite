import { AlertTriangle, ChevronRight } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  BEEP_SEVERITY_CLASS,
  BEEP_SEVERITY_LABEL,
  BeepCard,
  BeepFiringPill,
  BeepPhone,
  BeepScreenHeader,
  type BeepSeverity,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Alerts fired per day over two weeks, stacked by severity (bottom first). */
const ACTIVITY: Partial<Record<BeepSeverity, number>>[] = [
  { info: 1 },
  {},
  { warning: 1 },
  {},
  { info: 1 },
  {},
  { warning: 1, info: 1 },
  { error: 1 },
  { info: 1 },
  {},
  { warning: 1 },
  { error: 1, warning: 1, info: 1 },
  { critical: 2, warning: 2, info: 1 },
  { critical: 2, error: 1, warning: 2 },
]

const STACK_ORDER: BeepSeverity[] = ['info', 'warning', 'error', 'critical']

const PROJECTS = [
  {
    name: 'storefront-prod',
    detail: 'Frankfurt · 379K requests in 24h',
    firing: true,
  },
  {
    name: 'checkout-api',
    detail: 'New York · 432K requests in 24h',
    firing: true,
  },
  {
    name: 'marketing-site',
    detail: 'Sydney · 58K requests in 24h',
    firing: false,
  },
] as const

/** The Home tab: what is firing, Appwrite Cloud status, two weeks of alerts, and every project. */
export function BeepHomeVisual() {
  const t = useT()
  const unit = 7

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone tab="home" alertCount={3}>
          <BeepScreenHeader title="Home" />
          <div className="mt-3 space-y-2.5 px-3.5">
            <BeepCard>
              <p className="text-[10px] font-medium text-muted-foreground">
                {t('Alerts')}
              </p>
              <p className="mt-1 flex items-center gap-1.5 text-[17px] font-semibold text-foreground">
                <AlertTriangle className="size-4 text-rose-500" aria-hidden />
                {t('3 firing')}
              </p>
              <p className="mt-0.5 text-[10px] text-muted-foreground">
                {t('2 critical · 1 error · 2 acknowledged')}
              </p>
            </BeepCard>

            <p className="flex items-center gap-1.5 rounded-xl border border-border/80 bg-background px-3 py-2 text-[10px] text-muted-foreground dark:bg-card">
              <span
                className="size-1.5 rounded-full bg-amber-500"
                aria-hidden
              />
              {t('Appwrite Cloud · Delayed Realtime events')}
            </p>

            <BeepCard>
              <p className="text-[10px] font-medium text-muted-foreground">
                {t('Alerts fired · last 14 days')}
              </p>
              <p className="mt-0.5 text-[20px] font-semibold tabular-nums leading-none text-foreground">
                19
              </p>
              <p className="mt-1 text-[9.5px] text-muted-foreground">
                {t('4 critical · busiest day Nov 19, 8 alerts')}
              </p>
              <div
                className="mt-2 flex h-[52px] items-end gap-[5px]"
                aria-hidden
              >
                {ACTIVITY.map((day, index) => (
                  <div
                    key={index}
                    className="flex flex-1 flex-col-reverse gap-px"
                  >
                    {STACK_ORDER.map((severity) =>
                      day[severity] ? (
                        <span
                          key={severity}
                          className={cn(
                            'product-hero-rise w-full rounded-[2px]',
                            BEEP_SEVERITY_CLASS[severity].bar,
                          )}
                          style={{
                            ...riseStyle(300 + index * 35),
                            height: (day[severity] ?? 0) * unit,
                          }}
                        />
                      ) : null,
                    )}
                    {Object.keys(day).length === 0 ? (
                      <span className="h-[3px] w-full rounded-[2px] bg-muted" />
                    ) : null}
                  </div>
                ))}
              </div>
              <div className="mt-2 flex flex-wrap gap-x-2.5 gap-y-1">
                {(['critical', 'error', 'warning', 'info'] as const).map(
                  (severity) => (
                    <span
                      key={severity}
                      className="flex items-center gap-1 text-[8.5px] text-muted-foreground"
                    >
                      <span
                        className={cn(
                          'size-1.5 rounded-[2px]',
                          BEEP_SEVERITY_CLASS[severity].bar,
                        )}
                        aria-hidden
                      />
                      {t(BEEP_SEVERITY_LABEL[severity])}
                    </span>
                  ),
                )}
              </div>
            </BeepCard>

            <div>
              <p className="flex items-center justify-between px-0.5 text-[11px] font-semibold text-foreground">
                {t('Projects')}
                <span className="text-[9.5px] font-medium text-[var(--tone-ink)]">
                  {t('See all')}
                </span>
              </p>
              <div className="mt-1.5 divide-y divide-border/70 overflow-hidden rounded-2xl border border-border/80 bg-background dark:bg-card">
                {PROJECTS.map((project) => (
                  <div
                    key={project.name}
                    className="flex items-center gap-2 px-3 py-2"
                  >
                    <span className="min-w-0 flex-1">
                      <span
                        dir="ltr"
                        className="block truncate text-[10.5px] font-medium text-foreground"
                      >
                        {project.name}
                      </span>
                      <span className="block truncate text-[9px] text-muted-foreground">
                        {t(project.detail)}
                      </span>
                    </span>
                    {project.firing ? (
                      <BeepFiringPill />
                    ) : (
                      <ChevronRight
                        className="size-3 text-muted-foreground"
                        aria-hidden
                      />
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </BeepPhone>
      </div>
    </div>
  )
}
