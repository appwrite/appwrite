import type { ReactNode } from 'react'
import { BarChart2, Code2, Globe, MousePointerClick, Users } from 'lucide-react'
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
    icon: Globe,
    title: 'Create a property',
    description: 'One property per website or app you want to measure.',
  },
  {
    icon: Code2,
    title: 'Add the snippet',
    description:
      'Drop in the web script, use the Flutter SDK, or send events over REST.',
  },
  {
    icon: BarChart2,
    title: 'Watch your traffic',
    description:
      'Visitors, pages, sources, locations, and bots, plus any custom events you track.',
  },
]

/** Brand purple, as on the property page's charts and humans bar. */
const ACCENT = '#7c67fe'

/** Smooth-ish visitors line for the illustration (viewBox 0 0 240 64). */
const SPARK_PATH =
  'M0 52 C20 50 30 44 48 46 S78 30 96 34 S126 22 144 26 S174 12 192 16 S222 6 240 8'

const SOURCES = [
  { label: 'google', width: 'w-full' },
  { label: 'github.com', width: 'w-3/5' },
  { label: 'direct', width: 'w-2/5' },
] as const

/** Decorative property overview: a stat, a trend line and top sources. */
function AnalyticsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[400px] pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <BarChart2 className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
        </div>

        <div className="flex items-end justify-between px-3 pt-3">
          <div>
            <span className="block h-1.5 w-10 rounded-full bg-muted-foreground/25" />
            <div className="mt-2 flex items-baseline gap-1.5" dir="ltr">
              <span className="text-[18px] font-semibold leading-none tabular-nums text-foreground">
                12.4k
              </span>
              <span className="text-[10px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
                +18%
              </span>
            </div>
          </div>
          <Users className="h-3.5 w-3.5 text-muted-foreground" />
        </div>

        <svg viewBox="0 0 240 64" className="mt-2 h-16 w-full" preserveAspectRatio="none">
          <defs>
            <linearGradient id="analytics-empty-fill" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor={ACCENT} stopOpacity="0.28" />
              <stop offset="100%" stopColor={ACCENT} stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={`${SPARK_PATH} L240 64 L0 64 Z`} fill="url(#analytics-empty-fill)" />
          <path d={SPARK_PATH} fill="none" stroke={ACCENT} strokeWidth="1.75" />
        </svg>

        <ul dir="ltr" className="space-y-1 border-t border-border px-3 py-2.5">
          {SOURCES.map((source) => (
            <li key={source.label} className="flex items-center gap-2 font-mono text-[10px]">
              <span className="relative h-4 flex-1 overflow-hidden rounded">
                <span
                  className={`absolute inset-y-0 start-0 rounded ${source.width}`}
                  style={{ backgroundColor: `color-mix(in oklch, ${ACCENT} 16%, transparent)` }}
                />
                <span className="relative px-1.5 leading-4 text-foreground">
                  {source.label}
                </span>
              </span>
            </li>
          ))}
        </ul>
      </div>

      {/* Live visitors chip */}
      <Chip className="absolute -end-20 top-10">
        <span className="relative flex h-1.5 w-1.5">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
          <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" />
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          24 online
        </span>
      </Chip>

      {/* Custom event chip */}
      <Chip className="absolute -start-16 -bottom-3">
        <MousePointerClick className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          event
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          signup_click
        </span>
      </Chip>
    </ProductEmptyStateVisual>
  )
}

function Chip({ className, children }: { className: string; children: ReactNode }) {
  return (
    <div
      className={`flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg ${className}`}
    >
      {children}
    </div>
  )
}

/** First-run state for Analytics: no properties in this project yet. */
export function AnalyticsEmptyState({
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
        visual={<AnalyticsVisual />}
        icon={BarChart2}
        title={t('Understand your traffic')}
        description={t(
          'Analytics for your websites and apps. See who visits, where they come from, what they do, and how much of your traffic is bots.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onCreate}
            disabled={createDisabled}
            disabledTooltip={createDisabledTooltip}
          >
            {t('Create property')}
          </ProductEmptyStateCreateButton>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
