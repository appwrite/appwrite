import {
  ArrowRight,
  CalendarClock,
  Cookie,
  EyeOff,
  Hand,
  Link2Off,
  MapPinned,
  Trash2,
  UserX,
  type LucideIcon,
} from 'lucide-react'
import { ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * Privacy section companion: one pageview as it arrives and as it is stored,
 * then the guarantees as an open spec list. Statements describe the intended
 * launch behavior of ingestion (see `lib/products/content/analytics.ts`); keep
 * them in step with the backend before publishing.
 */

type PayloadRow = { field: string; received: string; stored: string; note: string }

const PAYLOAD: PayloadRow[] = [
  { field: 'ip', received: '203.0.113.42', stored: 'DE · Berlin', note: 'Location only' },
  {
    field: 'user_agent',
    received: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15',
    stored: 'Chrome · macOS · Desktop',
    note: 'Device only',
  },
  { field: 'url', received: '/reset?token=9f2c41&email=ana@example.com', stored: '/reset', note: 'Query stripped' },
  { field: 'referrer', received: 'https://news.example.com/item?id=4012', stored: 'news.example.com', note: 'Domain only' },
  { field: 'visitor', received: 'cookie, device ID, fingerprint', stored: 'a91f…e03', note: 'Expires at midnight' },
]

const GUARANTEES: { title: string; description: string; icon: LucideIcon }[] = [
  { title: 'No cookies, no storage', description: 'Nothing is written to the browser, and no fingerprinting scripts run.', icon: Cookie },
  { title: 'IP addresses never stored', description: 'IP and user agent derive country and device, then are discarded.', icon: EyeOff },
  { title: 'Identifiers expire daily', description: 'Visitor hashes use a salt that rotates every 24 hours.', icon: CalendarClock },
  { title: 'Clean URLs', description: 'Query strings are stripped from pages and referrers, except campaign tags.', icon: Link2Off },
  { title: 'Opt-outs respected', description: 'Visitors who send Do Not Track or Global Privacy Control are skipped.', icon: Hand },
  { title: 'No profiles, no resale', description: 'No cross-site tracking or ad profiles. Your data is never sold.', icon: UserX },
  { title: 'Stays in your region', description: 'Events live in the same Appwrite Cloud region as your project.', icon: MapPinned },
  { title: 'Retention you control', description: 'History follows your plan, and deleting a property purges it.', icon: Trash2 },
]

function PayloadDiff() {
  const t = useT()
  return (
    <ArtWindow
      className="product-hero-rise mx-auto max-w-4xl text-start"
      style={riseStyle(40)}
      title={<span dir="ltr" className="font-mono">POST /v1/analytics/events</span>}
      trailing={
        <span className="inline-flex items-center gap-1.5 text-[10.5px] font-medium text-[var(--tone-ink)]">
          <span className="size-1.5 rounded-full bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))]" aria-hidden />
          {t('One pageview')}
        </span>
      }
      bodyClassName="p-0"
    >
      <div className="hidden grid-cols-[6.5rem_minmax(0,1fr)_1.5rem_minmax(0,0.8fr)] gap-x-3 border-b border-border px-4 py-2 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground sm:grid">
        <span>{t('Field')}</span>
        <span>{t('Received')}</span>
        <span aria-hidden />
        <span>{t('Stored')}</span>
      </div>
      <dl className="divide-y divide-border/70">
        {PAYLOAD.map((row, index) => (
          <div
            key={row.field}
            className="product-hero-rise grid gap-x-3 gap-y-1.5 px-4 py-3 sm:grid-cols-[6.5rem_minmax(0,1fr)_1.5rem_minmax(0,0.8fr)] sm:items-center"
            style={riseStyle(160 + index * 110)}
          >
            <dt dir="ltr" className="font-mono text-[11.5px] text-muted-foreground">
              {row.field}
            </dt>
            <dd
              dir="ltr"
              className="min-w-0 truncate font-mono text-[11.5px] text-muted-foreground/70 line-through decoration-rose-500/60"
            >
              {row.received}
            </dd>
            <ArrowRight className="hidden size-3.5 text-muted-foreground/40 sm:block rtl:rotate-180" aria-hidden />
            <dd className="flex min-w-0 items-center justify-between gap-3">
              <span
                dir="ltr"
                className="product-hero-rise truncate font-mono text-[12px] font-medium text-[var(--tone-ink)]"
                style={riseStyle(520 + index * 110)}
              >
                {row.stored}
              </span>
              <span className="hidden shrink-0 text-[10.5px] text-muted-foreground md:inline">{t(row.note)}</span>
            </dd>
          </div>
        ))}
      </dl>
      <p className="border-t border-border bg-muted/20 px-4 py-2.5 text-[11.5px] text-muted-foreground">
        {t('Raw values are used in memory to derive what is stored, then dropped. Nothing personal reaches the database.')}
      </p>
    </ArtWindow>
  )
}

export function AnalyticsPrivacyCatalog() {
  const t = useT()

  return (
    <div className="space-y-14">
      <PayloadDiff />

      <ul className="mx-auto grid max-w-5xl gap-x-8 gap-y-8 text-start sm:grid-cols-2 lg:grid-cols-4">
        {GUARANTEES.map((item, index) => {
          const Icon = item.icon
          return (
            <li
              key={item.title}
              className="product-hero-rise border-t border-border pt-4"
              style={riseStyle(200 + index * 60)}
            >
              <Icon
                className={cn('size-4', index % 2 === 0 ? 'text-[var(--tone-ink)]' : 'text-foreground/70')}
                strokeWidth={1.75}
                aria-hidden
              />
              <h4 className="mt-3 text-[14px] font-medium text-foreground">{t(item.title)}</h4>
              <p className="mt-1 text-[13px] leading-5 text-muted-foreground">{t(item.description)}</p>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
