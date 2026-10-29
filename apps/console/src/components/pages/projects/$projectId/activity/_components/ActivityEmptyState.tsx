import {
  Activity,
  Filter,
  GitBranch,
  Globe,
  KeyRound,
  ListChecks,
  MapPin,
  Monitor,
  ScanSearch,
  UserPlus,
} from 'lucide-react'
import {
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: ListChecks,
    title: 'Every change is recorded',
    description:
      'Creates, updates, deletes, and sign-ins from the Console, SDKs, CLI, and API all land here.',
  },
  {
    icon: Filter,
    title: 'Filter what matters',
    description:
      'Narrow the log by actor, event, resource, or time range to answer who changed what.',
  },
  {
    icon: ScanSearch,
    title: 'Inspect any entry',
    description:
      'Open an event to see the IP address, location, device, and the full payload.',
  },
]

const EVENTS = [
  {
    icon: UserPlus,
    event: 'users.create',
    actor: 'ana@acme.dev',
    time: '2m',
    active: true,
  },
  {
    icon: GitBranch,
    event: 'deployments.create',
    actor: 'GitHub',
    time: '14m',
    active: false,
  },
  {
    icon: KeyRound,
    event: 'keys.update',
    actor: 'leo@acme.dev',
    time: '1h',
    active: false,
  },
  {
    icon: Globe,
    event: 'proxy.create',
    actor: 'CLI',
    time: '3h',
    active: false,
  },
]

const DETAILS = [
  { icon: Globe, value: '192.168.24.7' },
  { icon: MapPin, value: 'Lisbon, PT' },
  { icon: Monitor, value: 'Chrome 140 · macOS' },
]

/** Decorative audit log, an inspected entry, and an active filter. */
function AuditTrailVisual() {
  return (
    <ProductEmptyStateVisual className="w-[420px]">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Activity className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
          <span className="ms-auto flex items-center gap-1">
            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
            <span className="h-1.5 w-8 rounded-full bg-muted-foreground/20" />
          </span>
        </div>
        <ul className="divide-y divide-border">
          {EVENTS.map((item) => {
            const Icon = item.icon
            return (
              <li
                key={item.event}
                className={cn(
                  'flex items-center gap-3 px-3 py-2.5',
                  item.active && 'bg-muted/50',
                )}
              >
                <span
                  className={cn(
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground',
                    item.active &&
                      'border-[color-mix(in_oklch,var(--brand-cta)_45%,transparent)] text-[var(--brand-cta)]',
                  )}
                >
                  <Icon className="h-3 w-3" />
                </span>
                <span
                  dir="ltr"
                  className="min-w-0 flex-1 truncate font-mono text-[10px] text-foreground"
                >
                  {item.event}
                </span>
                <span
                  dir="ltr"
                  className="w-20 truncate font-mono text-[10px] text-muted-foreground"
                >
                  {item.actor}
                </span>
                <span
                  dir="ltr"
                  className="w-6 text-end font-mono text-[10px] tabular-nums text-muted-foreground"
                >
                  {item.time}
                </span>
              </li>
            )
          })}
        </ul>
      </div>

      <div className="absolute -end-40 top-6 w-48 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <UserPlus className="h-3 w-3 text-[var(--brand-cta)]" />
          <span dir="ltr" className="font-mono text-[10px] text-foreground">
            users.create
          </span>
        </div>
        <div className="mt-2.5 space-y-1.5">
          {DETAILS.map((detail) => {
            const Icon = detail.icon
            return (
              <div key={detail.value} className="flex items-center gap-1.5">
                <Icon className="h-3 w-3 shrink-0 text-muted-foreground" />
                <span
                  dir="ltr"
                  className="truncate font-mono text-[10px] text-muted-foreground"
                >
                  {detail.value}
                </span>
              </div>
            )
          })}
        </div>
      </div>

      <div className="absolute -start-16 -bottom-4 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Filter className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          actor
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          = leo@acme.dev
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function ActivityEmptyState() {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 sm:py-12">
      <ProductEmptyStateHero
        visual={<AuditTrailVisual />}
        icon={Activity}
        title={t('Your project audit trail')}
        description={t(
          'Every change to this project is logged here: who made it, from where, and when. New activity shows up as soon as you or your apps start working with the project.',
        )}
        actions={
          <ProductEmptyStateDocsButton path="/docs/advanced/security/audit-logs" />
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
