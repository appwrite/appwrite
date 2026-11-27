import {
  ChevronLeft,
  Database,
  Folder,
  Globe,
  Megaphone,
  Users,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { AnalyticsAreaChart } from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import {
  BeepCard,
  BeepPhone,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Bandwidth, Requests, Storage, Executions, and Compute, each against the previous period. */
const TILES = [
  { label: 'Bandwidth', value: '5.4 GB', change: '+1%', active: false },
  { label: 'Requests', value: '379K', change: '+1%', active: true },
  { label: 'Storage', value: '85.9 GB', change: '+2%', active: false },
  { label: 'Executions', value: '1.2M', change: '+4%', active: false },
  { label: 'Compute', value: '312 GB-h', change: '+3%', active: false },
] as const

/** Requests per hour over the last day, and the day before (dashed). */
const REQUESTS = [
  16, 13, 11, 10, 11, 14, 19, 24, 27, 29, 30, 28, 26, 25, 24, 22, 20, 17, 15,
  13, 14, 18, 23, 26,
] as const
const PREVIOUS = [
  15, 13, 12, 11, 12, 14, 18, 22, 25, 27, 28, 27, 25, 24, 23, 22, 21, 18, 16,
  14, 15, 17, 21, 24,
] as const

const RESOURCES: { icon: LucideIcon; value: string; label: string }[] = [
  { icon: Database, value: '3', label: 'Databases' },
  { icon: Zap, value: '12', label: 'Functions' },
  { icon: Globe, value: '2', label: 'Sites' },
  { icon: Folder, value: '5', label: 'Buckets' },
  { icon: Users, value: '18.4K', label: 'Users' },
  { icon: Megaphone, value: '4', label: 'Topics' },
]

/** A project opened from the Projects tab: usage against the previous period and what it holds. */
export function BeepProjectVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone tab="projects" alertCount={3}>
          <div className="flex items-center gap-2 px-3.5 pt-1">
            <span className="flex items-center gap-0.5 rounded-full border border-border bg-background px-2 py-1 text-[10px] font-medium text-foreground dark:bg-card">
              <ChevronLeft className="size-3" aria-hidden />
              {t('Projects')}
            </span>
            <span
              dir="ltr"
              className="text-[11px] font-semibold text-foreground"
            >
              storefront-prod
            </span>
          </div>
          <div className="mt-3 flex gap-1 px-3.5">
            {['24h', '7d', '30d'].map((range, index) => (
              <span
                key={range}
                className={cn(
                  'rounded-full px-2.5 py-0.5 text-[10px] font-medium',
                  index === 0
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground',
                )}
              >
                {range}
              </span>
            ))}
          </div>
          <div className="mt-2.5 flex gap-1.5 overflow-hidden ps-3.5">
            {TILES.map((tile) => (
              <div
                key={tile.label}
                className={cn(
                  'w-[78px] shrink-0 rounded-xl border bg-background px-2 py-1.5 dark:bg-card',
                  tile.active
                    ? 'border-[rgb(var(--tone-rgb)/0.6)]'
                    : 'border-border/80',
                )}
              >
                <p className="truncate text-[8.5px] text-muted-foreground">
                  {t(tile.label)}
                </p>
                <p
                  dir="ltr"
                  className="mt-0.5 truncate text-[12px] font-semibold leading-4 text-foreground"
                >
                  {tile.value}
                </p>
                <p
                  dir="ltr"
                  className="text-[8px] font-medium text-emerald-600 dark:text-emerald-400"
                >
                  {tile.change}
                </p>
              </div>
            ))}
          </div>
          <div className="mt-2.5 px-3.5">
            <BeepCard>
              <p className="text-[10px] text-muted-foreground">
                {t('Requests · 24h')}
              </p>
              <p
                dir="ltr"
                className="mt-0.5 flex items-baseline gap-1.5 text-[20px] font-semibold leading-none text-foreground"
              >
                379K
                <span className="text-[9px] font-medium text-emerald-600 dark:text-emerald-400">
                  +1%
                </span>
              </p>
              <p className="mt-1 text-[9px] text-muted-foreground">
                {t('375K previous 24h')}
              </p>
              <div className="mt-2 text-foreground">
                <AnalyticsAreaChart
                  values={REQUESTS}
                  previous={PREVIOUS}
                  height={70}
                  className="h-[70px]"
                />
              </div>
            </BeepCard>
          </div>
          <p className="mt-3 px-4 text-[11px] font-semibold text-foreground">
            {t('In this project')}
          </p>
          <div className="mt-1.5 grid grid-cols-3 gap-1.5 px-3.5">
            {RESOURCES.map((resource, index) => {
              const Icon = resource.icon
              return (
                <div
                  key={resource.label}
                  className="product-hero-rise rounded-xl border border-border/80 bg-background px-2 py-1.5 dark:bg-card"
                  style={riseStyle(300 + index * 60)}
                >
                  <Icon className="size-3 text-muted-foreground" aria-hidden />
                  <p
                    dir="ltr"
                    className="mt-1 text-[12px] font-semibold leading-none text-foreground"
                  >
                    {resource.value}
                  </p>
                  <p className="mt-0.5 truncate text-[8.5px] text-muted-foreground">
                    {t(resource.label)}
                  </p>
                </div>
              )
            })}
          </div>
        </BeepPhone>
      </div>
    </div>
  )
}
