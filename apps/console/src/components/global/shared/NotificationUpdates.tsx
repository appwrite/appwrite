import { useEffect, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowRight, Sparkles, TriangleAlert, Wrench } from 'lucide-react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { changelogCount, getAllChangelogEntries } from '@/lib/changelog/content'
import {
  getStoredChangelogSeenCount,
  markChangelogSeen,
} from '@/lib/changelog/nav-badge'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks/status'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LATEST = 6

const STATUS_PAGE_URL = 'https://appwrite.online'

/** Ongoing Cloud incidents and maintenance, as the status page reports them. */
function CloudIncident() {
  const t = useT()
  const { isCloud, features } = useConsoleProfile()
  const { data } = useAppwriteCloudStatus(isCloud && features.systemStatus)
  const report = data?.activeReport
  if (!report || data.aggregateState === 'operational') return null

  const maintenance = data.aggregateState === 'maintenance'
  const Icon = maintenance ? Wrench : TriangleAlert
  return (
    <a
      href={STATUS_PAGE_URL}
      target="_blank"
      rel="noopener noreferrer"
      className={cn(
        'flex gap-3 border-b border-border px-4 py-3 transition-colors hover:bg-accent/60',
        maintenance ? 'bg-muted/30' : 'bg-amber-500/5',
      )}
    >
      <div
        className={cn(
          'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border',
          maintenance
            ? 'border-border bg-background text-muted-foreground'
            : 'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-400',
        )}
      >
        <Icon className="h-4 w-4" aria-hidden />
      </div>
      <div className="min-w-0 flex-1 space-y-1">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {maintenance ? t('Scheduled maintenance') : t('Ongoing incident')}
        </p>
        <p className="text-[13px] font-medium leading-snug text-foreground">
          {report.title}
        </p>
        {data.regionsLine ? (
          <p className="text-[12px] text-muted-foreground">
            {data.regionsLine}
          </p>
        ) : null}
      </div>
    </a>
  )
}

/**
 * What's new: Cloud incidents, then the latest changelog entries. Opening it
 * counts the changelog as seen, as visiting the changelog does.
 */
export function NotificationUpdates({
  onNavigate,
}: {
  onNavigate: () => void
}) {
  const t = useT()
  // Read before marking seen, so the entries that were new stay marked.
  const [unseen] = useState(() => {
    const seen = getStoredChangelogSeenCount()
    return seen === null ? 0 : Math.max(0, changelogCount - seen)
  })
  const entries = getAllChangelogEntries().slice(0, LATEST)

  useEffect(() => {
    markChangelogSeen()
  }, [])

  const dateFormat = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    // Entry dates are calendar days, not instants.
    timeZone: 'UTC',
  })

  return (
    <div>
      <CloudIncident />
      <div className="divide-y divide-border">
        {entries.map((entry, index) => (
          <Link
            key={entry.slug}
            to="/changelog/entry/$entry"
            params={{ entry: entry.slug }}
            onClick={onNavigate}
            className="flex gap-3 px-4 py-3 transition-colors hover:bg-accent/60"
          >
            <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/5 text-primary">
              <Sparkles className="h-4 w-4" aria-hidden />
            </div>
            <div className="min-w-0 flex-1 space-y-1">
              <p className="text-[13px] font-medium leading-snug text-foreground">
                {entry.title}
              </p>
              {entry.description ? (
                <p className="line-clamp-2 text-[12px] leading-relaxed text-muted-foreground">
                  {entry.description}
                </p>
              ) : null}
              <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                {entry.date ? (
                  <span>{dateFormat.format(new Date(entry.date))}</span>
                ) : null}
                {index < unseen ? (
                  <span className="rounded-full bg-primary/10 px-1.5 py-px text-[10px] font-semibold text-primary">
                    {t('New')}
                  </span>
                ) : null}
              </div>
            </div>
          </Link>
        ))}
      </div>
      <Link
        to="/changelog"
        onClick={onNavigate}
        className="flex items-center justify-center gap-1.5 border-t border-border px-4 py-2.5 text-[12px] font-medium text-foreground transition-colors hover:bg-accent/60"
      >
        {t('View changelog')}
        <ArrowRight className="h-3.5 w-3.5 rtl:rotate-180" />
      </Link>
    </div>
  )
}
