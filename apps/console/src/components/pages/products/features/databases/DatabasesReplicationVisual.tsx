import { ArrowUpFromLine, ShieldCheck, Timer, Waypoints } from 'lucide-react'
import type { CSSProperties } from 'react'
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

type ClusterNode = {
  name: string
  primary: boolean
  cpu: number
  memory: number
}

const NODES: ClusterNode[] = [
  { name: 'orders-1', primary: false, cpu: 28, memory: 47 },
  { name: 'orders-0', primary: true, cpu: 42, memory: 61 },
  { name: 'orders-2', primary: false, cpu: 31, memory: 44 },
]

const SYNC_MODES = [
  { value: 'async', label: 'Asynchronous', selected: true },
  { value: 'sync', label: 'Synchronous', selected: false },
  { value: 'quorum', label: 'Quorum', selected: false },
] as const

function Meter({ label, value, delayMs }: { label: string; value: number; delayMs: number }) {
  return (
    <div>
      <div className="flex items-center justify-between gap-1 text-[10px] text-muted-foreground">
        <span>{label}</span>
        <span dir="ltr" className="font-mono tabular-nums text-foreground/80">
          {value}%
        </span>
      </div>
      <div className="mt-0.5 h-1 overflow-hidden rounded-full bg-foreground/[0.07]">
        <div className="h-full" style={{ width: `${value}%` }}>
          <div
            className="product-hero-fill h-full w-full rounded-full bg-foreground/35"
            style={{ '--fill-delay': `${delayMs}ms` } as CSSProperties}
          />
        </div>
      </div>
    </div>
  )
}

function NodePanel({ node, index }: { node: ClusterNode; index: number }) {
  const t = useT()
  return (
    <ArtPanel
      className="min-w-0 flex-1"
      innerClassName={cn(
        'px-2.5 py-2.5 sm:px-3',
        node.primary &&
          'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
      )}
      delayMs={node.primary ? 300 : 450 + index * 80}
      float={!node.primary}
      floatDelayMs={index * 600}
    >
      <div className="flex items-center gap-1.5">
        <ArtLiveDot className="size-1.5" />
        <span dir="ltr" className="truncate font-mono text-[11px] font-medium text-foreground">
          {node.name}
        </span>
      </div>
      <span
        className={cn(
          'mt-2 inline-block max-w-full truncate rounded-md px-1.5 py-0.5 align-top text-[10px] font-medium',
          node.primary
            ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
            : 'bg-muted text-muted-foreground',
        )}
      >
        {node.primary ? t('Primary instance') : t('Read replica')}
      </span>
      <p className="mt-1.5 text-[10px] text-muted-foreground">{node.primary ? t('Writes') : t('Reads')}</p>
      <div className="mt-2 space-y-1.5">
        <Meter label={t('CPU')} value={node.cpu} delayMs={700 + index * 120} />
        <Meter label={t('Memory')} value={node.memory} delayMs={800 + index * 120} />
      </div>
      {index === 2 ? (
        <span className="mt-2.5 hidden items-center gap-1 rounded-md border border-border px-1.5 py-1 text-[10px] font-medium text-foreground sm:inline-flex">
          <ArrowUpFromLine className="size-3 text-muted-foreground" aria-hidden />
          {t('Promote to primary')}
        </span>
      ) : null}
    </ArtPanel>
  )
}

export function DatabasesReplicationVisual() {
  const t = useT()
  const [startReplica, primary, endReplica] = NODES

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-4">
      <ArtPanel
        className="relative z-[1] mx-auto w-fit"
        innerClassName="flex items-center gap-2.5 px-3.5 py-2.5"
        delayMs={60}
      >
        <ArtIconBadge icon={Waypoints} tone="secondary" className="size-8" />
        <div className="min-w-0">
          <p className="text-[12px] font-semibold text-foreground">PgDog</p>
          <p className="text-[10px] text-muted-foreground">{t('Connection pooler')}</p>
        </div>
        <div className="ms-3 border-s border-border ps-3">
          <p className="text-[10px] text-muted-foreground">{t('Connections')}</p>
          <p dir="ltr" className="font-mono text-[11px] font-medium text-foreground">
            48 / 200
          </p>
        </div>
      </ArtPanel>

      <div className="relative mx-auto h-10 w-2/3" aria-hidden>
        <span className="absolute start-1/2 top-0 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute inset-x-0 top-1/2 border-t border-dashed border-foreground/25" />
        <span className="absolute start-0 top-1/2 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute start-1/2 top-1/2 h-1/2 border-s border-dashed border-[rgb(var(--tone-rgb)/0.6)]" />
        <span className="absolute end-0 top-1/2 h-1/2 border-e border-dashed border-foreground/25" />
      </div>

      <div className="flex items-center gap-2 sm:gap-0">
        <NodePanel node={startReplica} index={0} />
        <div className="hidden w-8 shrink-0 -scale-x-100 sm:block">
          <ArtConnector travel travelDelayMs={300} />
        </div>
        <NodePanel node={primary} index={1} />
        <div className="hidden w-8 shrink-0 sm:block">
          <ArtConnector travel travelDelayMs={900} />
        </div>
        <NodePanel node={endReplica} index={2} />
      </div>

      <div
        className="product-hero-rise mx-auto mt-8 flex w-fit max-w-full flex-wrap items-center justify-center gap-1.5"
        style={riseStyle(900)}
      >
        <span className="me-1 text-[11px] font-medium text-muted-foreground">{t('Sync mode')}</span>
        {SYNC_MODES.map((mode) => (
          <span
            key={mode.value}
            className={cn(
              'rounded-full border px-2.5 py-1 text-[11px] font-medium',
              mode.selected
                ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]'
                : 'border-border bg-background text-muted-foreground dark:bg-card',
            )}
          >
            {t(mode.label)}
          </span>
        ))}
      </div>

      <ArtChip className="start-0 top-2 hidden sm:block" delayMs={1100} floatDelayMs={300}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Timer} tone="neutral" />
          <div>
            <p className="text-[10px] text-muted-foreground">{t('Replication lag')}</p>
            <p dir="ltr" className="font-mono text-[11px] font-medium text-foreground">
              8 ms
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-4 hidden sm:block" delayMs={1250} floatDelayMs={1100}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ShieldCheck} tone="success" />
          <p className="text-[11px] font-medium text-foreground">{t('HA enabled')}</p>
        </div>
      </ArtChip>
    </div>
  )
}
