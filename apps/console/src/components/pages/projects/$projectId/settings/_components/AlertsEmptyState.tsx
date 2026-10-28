import { Bell, BellRing, Gauge, Send } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Gauge,
    title: 'Pick a metric',
    description:
      'Watch bandwidth, database operations, storage, or the load on a dedicated database.',
  },
  {
    icon: BellRing,
    title: 'Set a threshold',
    description:
      'The rule fires when the metric crosses it and resolves when it comes back.',
  },
  {
    icon: Send,
    title: 'Choose who hears about it',
    description:
      'Project owners get an email and a console notification, and your webhook gets a signed request.',
  },
]

/** Decorative firing rule card. */
function AlertsVisual() {
  const t = useT()
  return (
    <ProductEmptyStateVisual className="flex items-center pb-4">
      <div className="w-72 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Bell className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
          <span className="ms-auto rounded-full bg-red-500/10 px-2 py-0.5 text-[9px] font-medium text-red-600 dark:text-red-400">
            {t('Firing')}
          </span>
        </div>
        <div dir="ltr" className="space-y-2 px-3 py-3 font-mono text-[10px]">
          <div className="text-foreground">bandwidth.outbound &gt; 5 GB</div>
          <div className="h-1.5 overflow-hidden rounded-full bg-muted">
            <div className="h-full w-4/5 rounded-full bg-red-500/70" />
          </div>
          <div className="text-muted-foreground">6.2 GB</div>
        </div>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function AlertsEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<AlertsVisual />}
        icon={Bell}
        title={t('Know when usage crosses a line')}
        description={t(
          'Alert rules watch your project usage and notify you when a metric crosses the threshold you set.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onCreate}
            disabled={createDisabled}
            disabledTooltip={createDisabledTooltip}
          >
            {t('Create alert rule')}
          </ProductEmptyStateCreateButton>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
