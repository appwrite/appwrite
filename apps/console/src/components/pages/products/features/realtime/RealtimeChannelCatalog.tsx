import { Database, Folder, type LucideIcon, Users, Zap } from 'lucide-react'
import { floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type ChannelEntry = {
  helper: string
  channel: string
}

type ChannelGroup = {
  id: string
  title: string
  icon: LucideIcon
  /** First groups take the product tone, the rest use the secondary tone. */
  toned: boolean
  channels: ChannelEntry[]
}

const CHANNEL_GROUPS: ChannelGroup[] = [
  {
    id: 'databases',
    title: 'Databases',
    icon: Database,
    toned: true,
    channels: [
      {
        helper: "Channel.tablesdb('main').table('orders').row()",
        channel: 'tablesdb.<ID>.tables.<ID>.rows',
      },
      {
        helper: "Channel.tablesdb('main').table('orders').row('8f2a')",
        channel: 'tablesdb.<ID>.tables.<ID>.rows.<ID>',
      },
      { helper: 'Channel.rows()', channel: 'rows' },
    ],
  },
  {
    id: 'storage',
    title: 'Storage',
    icon: Folder,
    toned: true,
    channels: [
      { helper: "Channel.bucket('media').file()", channel: 'buckets.<ID>.files' },
      { helper: "Channel.bucket('media').file('invoice')", channel: 'buckets.<ID>.files.<ID>' },
      { helper: 'Channel.files()', channel: 'files' },
    ],
  },
  {
    id: 'functions',
    title: 'Functions',
    icon: Zap,
    toned: false,
    channels: [
      { helper: 'Channel.executions()', channel: 'executions' },
      { helper: "Channel.function('checkout')", channel: 'functions.<ID>' },
      { helper: 'Channel.executions().create()', channel: 'executions.create' },
    ],
  },
  {
    id: 'auth',
    title: 'Auth, teams, and presence',
    icon: Users,
    toned: false,
    channels: [
      { helper: 'Channel.account()', channel: 'account' },
      { helper: 'Channel.teams()', channel: 'teams.*' },
      { helper: 'Channel.presences()', channel: 'presences' },
    ],
  },
]

function ChannelRow({ entry, delayMs }: { entry: ChannelEntry; delayMs: number }) {
  return (
    <li className="product-hero-rise" style={riseStyle(delayMs)}>
      <p
        dir="ltr"
        className="break-all rounded-md border border-border/80 bg-muted/30 px-2 py-1 font-mono text-[11px] leading-5 text-foreground/90 dark:bg-white/[0.03]"
      >
        {entry.helper}
      </p>
      <p dir="ltr" className="mt-1.5 break-all font-mono text-[10px] text-muted-foreground">
        {entry.channel}
      </p>
    </li>
  )
}

function ChannelGroupColumn({ group, index }: { group: ChannelGroup; index: number }) {
  const t = useT()
  const Icon = group.icon

  return (
    <div className="relative text-start">
      <div
        className={cn(
          'pointer-events-none absolute -inset-x-6 -top-10 -z-10 h-40 rounded-full blur-3xl',
          group.toned
            ? 'bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.14),transparent)]'
            : 'bg-[radial-gradient(closest-side,rgb(var(--tone2-rgb)/0.14),transparent)]',
        )}
        aria-hidden
      />

      <div className="flex items-center gap-2.5">
        <span
          className={cn(
            'product-hero-float flex size-9 shrink-0 items-center justify-center rounded-xl border bg-background shadow-[0_14px_36px_-20px_rgb(0_0_0/0.45)] dark:bg-card',
            group.toned
              ? 'border-[rgb(var(--tone-rgb)/0.4)] text-[var(--tone-ink)]'
              : 'border-[rgb(var(--tone2-rgb)/0.35)] text-foreground',
          )}
          style={floatStyle(index * 420)}
        >
          <Icon className="size-4" aria-hidden />
        </span>
        <h3 className="font-aeonik-pro text-[17px] tracking-tight text-foreground">
          {t(group.title)}
        </h3>
      </div>

      <ul className="mt-5 space-y-3">
        {group.channels.map((entry, entryIndex) => (
          <ChannelRow
            key={entry.channel}
            entry={entry}
            delayMs={120 + index * 90 + entryIndex * 90}
          />
        ))}
      </ul>
    </div>
  )
}

type RealtimeChannelCatalogProps = {
  className?: string
}

export function RealtimeChannelCatalog({ className }: RealtimeChannelCatalogProps) {
  return (
    <div className={cn('text-start', className)}>
      <div className="mx-auto grid max-w-5xl gap-10 sm:grid-cols-2 sm:gap-x-12 lg:gap-x-20">
        {CHANNEL_GROUPS.map((group, index) => (
          <ChannelGroupColumn key={group.id} group={group} index={index} />
        ))}
      </div>
    </div>
  )
}
