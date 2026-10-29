import { Bell, Database, HardDrive, Network } from 'lucide-react'
import type { ComponentType } from 'react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateVisual,
} from '@/components/global/shared/ProductEmptyState'
import {
  METRICS,
  formatMetricValue,
  fromBytes,
  suggestThreshold,
  toBytes,
} from '@/lib/alerts/metrics'
import { useT } from '@/lib/i18n/translate'
import { PRESETS, type AlertPreset } from './presets'
import { useRuleSeries } from './RuleChart'

const WEEK = 7 * 86400

/** A template with a threshold taken from the project's last week, when it has any. */
function suggested(
  preset: AlertPreset,
  observed: number | null,
): { form: AlertPreset['form']; threshold: number } {
  const metric = preset.form.metric!
  const bytes = METRICS[metric].unit === 'bytes'
  const fallback = bytes
    ? toBytes(preset.form.threshold ?? 0, preset.form.byteUnit ?? 'GB')
    : (preset.form.threshold ?? 0)
  const threshold =
    observed === null || observed <= 0 ? fallback : suggestThreshold(observed)
  if (!bytes) return { form: { ...preset.form, threshold }, threshold }
  const { amount, unit } = fromBytes(threshold)
  return {
    form: { ...preset.form, threshold: amount, byteUnit: unit },
    threshold,
  }
}

const PRESET_ICONS: Record<string, ComponentType<{ className?: string }>> = {
  'egress-spike': Network,
  'read-surge': Database,
  'database-storage': Database,
  'file-storage': HardDrive,
}

/** Decorative firing monitor card. */
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
        <div className="space-y-2 px-3 py-3">
          <div className="text-[11px] text-foreground">
            {t('Outbound bandwidth')} &gt; 5 GB {t('within')} {t('1 hour')}
          </div>
          <svg viewBox="0 0 240 48" className="h-12 w-full" aria-hidden>
            <line
              x1="0"
              y1="18"
              x2="240"
              y2="18"
              stroke="currentColor"
              strokeDasharray="4 4"
              className="text-red-500/70"
            />
            <polyline
              fill="none"
              strokeWidth="1.5"
              stroke="var(--chart-brand)"
              points="0,40 30,38 60,41 90,35 120,37 150,30 180,24 200,14 220,10 240,12"
            />
            <circle cx="196" cy="18" r="3.5" className="fill-red-500" />
          </svg>
          <div
            dir="ltr"
            className="text-[10px] font-mono text-muted-foreground"
          >
            6.2 GB
          </div>
        </div>
      </div>
    </ProductEmptyStateVisual>
  )
}

function PresetCard({
  projectId,
  preset,
  disabled,
  onPick,
}: {
  projectId: string
  preset: AlertPreset
  disabled: boolean
  onPick: (preset: AlertPreset) => void
}) {
  const t = useT()
  const metric = preset.form.metric!
  const counter = METRICS[metric].counter
  const window = preset.form.window ?? 3600
  const { data } = useRuleSeries(
    projectId,
    {
      metric,
      resourceType: 'project',
      resourceId: '',
      window,
      comparator: 'greaterThan',
      threshold: 0,
    },
    true,
    WEEK,
  )
  const observed =
    data && data.length > 0
      ? counter
        ? Math.max(...data.map((point) => point.value))
        : data[data.length - 1].value
      : null
  const { form, threshold } = suggested(preset, observed)
  const Icon = PRESET_ICONS[preset.id] ?? Bell
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => onPick({ ...preset, form })}
      className="group flex items-start gap-3 rounded-lg border border-border bg-card px-4 py-3 text-start transition-colors hover:border-foreground/20 hover:bg-accent/40 disabled:cursor-not-allowed disabled:opacity-60"
    >
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        <Icon className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[13px] font-medium text-foreground">
          {t(preset.title)}
        </span>
        <span className="mt-0.5 block text-[12px] text-muted-foreground">
          {observed === null || observed <= 0
            ? t(preset.description)
            : `${counter ? t('Peak hour') : t('Now')} ${formatMetricValue(metric, observed)}`}
        </span>
        <span
          dir="ltr"
          className="mt-1.5 inline-block rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground"
        >
          &gt; {formatMetricValue(metric, threshold)}
          {counter && ' / 1 h'}
        </span>
      </span>
    </button>
  )
}

export function MonitorsEmptyState({
  projectId,
  onCreate,
  onPreset,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  onCreate: () => void
  onPreset: (preset: AlertPreset) => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-4 sm:py-6">
      <ProductEmptyStateHero
        visual={<AlertsVisual />}
        icon={Bell}
        title={t('Know when usage crosses a line')}
        description={t(
          'Monitors watch your project usage and notify you when a metric crosses the threshold you set.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onCreate}
            disabled={createDisabled}
            disabledTooltip={createDisabledTooltip}
          >
            {t('Create monitor')}
          </ProductEmptyStateCreateButton>
        }
      />
      <div className="mt-8">
        <p className="mb-3 text-[12px] font-medium text-muted-foreground">
          {t('Suggested from your last 7 days of usage')}
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          {PRESETS.map((preset) => (
            <PresetCard
              key={preset.id}
              projectId={projectId}
              preset={preset}
              disabled={createDisabled}
              onPick={onPreset}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
