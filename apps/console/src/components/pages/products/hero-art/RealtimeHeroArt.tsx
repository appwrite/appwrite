import {
  Database as DatabaseIcon,
  Folder as FolderIcon,
  LayoutDashboard,
  type LucideIcon,
  Monitor,
  Radio,
  Smartphone,
  UserRound,
  Users,
  Zap,
} from 'lucide-react'
import {
  ArtConnector,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

const SOURCES: { icon: LucideIcon; label: string; channel: string }[] = [
  { icon: DatabaseIcon, label: 'Databases', channel: 'rows' },
  { icon: FolderIcon, label: 'Storage', channel: 'files' },
  { icon: Zap, label: 'Functions', channel: 'executions' },
  { icon: UserRound, label: 'Auth', channel: 'account' },
  { icon: Users, label: 'Presence', channel: 'presences' },
]

const CLIENTS: {
  icon: LucideIcon
  label: string
  delayMs: number
}[] = [
  { icon: Monitor, label: 'Web app', delayMs: 520 },
  { icon: Smartphone, label: 'Mobile app', delayMs: 680 },
  { icon: LayoutDashboard, label: 'Dashboard', delayMs: 840 },
]

function SourcesPanel() {
  const t = useT()

  return (
    <ArtPanel innerClassName="product-tone-shadow p-3.5" delayMs={80}>
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('Appwrite events')}
      </p>
      <ul className="mt-2.5 space-y-1.5">
        {SOURCES.map((source, index) => (
          <li
            key={source.channel}
            className="product-hero-rise flex items-center gap-2.5 rounded-lg border border-border/70 bg-muted/20 px-2.5 py-2"
            style={riseStyle(200 + index * 110)}
          >
            <ArtIconBadge icon={source.icon} tone="neutral" />
            <span className="min-w-0 flex-1 truncate text-[12px] font-semibold text-foreground">
              {t(source.label)}
            </span>
            <span dir="ltr" className="shrink-0 font-mono text-[10px] text-muted-foreground">
              {source.channel}
            </span>
          </li>
        ))}
      </ul>
      {/* Matches the heading above the list so the middle row sits on the connector. */}
      <p
        className="mt-2.5 invisible text-[10px] font-semibold uppercase tracking-wider"
        aria-hidden
      >
        {t('Appwrite events')}
      </p>
    </ArtPanel>
  )
}

function SocketNode() {
  const t = useT()

  return (
    <div className="relative z-[1] isolate mx-auto w-[min(220px,100%)] lg:mx-0 lg:w-[220px]">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 -z-10 size-[260px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.2),transparent)] blur-2xl"
        aria-hidden
      />
      <ArtPanel
        className="relative"
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] p-3.5 text-center dark:border-[rgb(var(--tone-rgb)/0.4)]"
        delayMs={0}
      >
        <span className="mx-auto flex size-10 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
          <Radio className="size-5" aria-hidden />
        </span>
        <p className="mt-2.5 text-[13px] font-semibold text-foreground">{t('Realtime')}</p>
        <div className="mt-2 flex items-center justify-center gap-1.5">
          <ArtLiveDot />
          <Badge variant="success" className="text-[10px]">
            {t('1 WebSocket')}
          </Badge>
        </div>
      </ArtPanel>
    </div>
  )
}

function ClientCard({ client }: { client: (typeof CLIENTS)[number] }) {
  const t = useT()
  const Icon = client.icon

  return (
    <ArtPanel
      innerClassName="flex items-center gap-2.5 px-3 py-2.5"
      delayMs={client.delayMs}
    >
      <ArtIconBadge icon={Icon} tone="secondary" />
      <span className="min-w-0 flex-1 truncate text-[12px] font-semibold text-foreground">
        {t(client.label)}
      </span>
      <ArtLiveDot className="size-1.5" />
    </ArtPanel>
  )
}

export function RealtimeHeroArt() {
  return (
    <div className="relative mx-auto max-w-5xl text-start">
      <div className="grid items-center gap-6 lg:grid-cols-[minmax(0,1fr)_3.5rem_220px_3.5rem_minmax(0,1fr)] lg:gap-0">
        <div className="relative z-[1] min-w-0">
          <SourcesPanel />
        </div>

        <div className="relative z-0 hidden lg:block">
          <ArtConnector travel travelDelayMs={400} className="-mx-px w-[calc(100%+2px)]" />
        </div>

        <SocketNode />

        <div className="relative z-0 hidden lg:block">
          <ArtConnector travel travelDelayMs={900} className="-mx-px w-[calc(100%+2px)]" />
        </div>

        <div className="relative z-[1] min-w-0 space-y-2.5">
          {CLIENTS.map((client) => (
            <ClientCard key={client.label} client={client} />
          ))}
        </div>
      </div>
    </div>
  )
}
