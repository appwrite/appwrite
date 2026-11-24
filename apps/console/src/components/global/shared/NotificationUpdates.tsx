import { useEffect, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowRight, TriangleAlert, Wrench } from 'lucide-react'
import { TAG_LABELS } from '@/components/pages/changelog/ChangelogFilters'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { changelogCount, getAllChangelogEntries } from '@/lib/changelog/content'
import {
  getStoredChangelogSeenCount,
  markChangelogSeen,
} from '@/lib/changelog/nav-badge'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks/status'
import { useT } from '@/lib/i18n/translate'
import { NotificationIcon, UnreadDot } from './NotificationCenterPopover'

const LATEST = 6

const STATUS_PAGE_URL = 'https://appwrite.online'

const dateFormat = new Intl.DateTimeFormat(undefined, {
  month: 'short',
  day: 'numeric',
  // Entry dates are calendar days, not instants.
  timeZone: 'UTC',
})

/** Ongoing Cloud incidents and maintenance, as the status page reports them. */
function CloudIncident() {
  const t = useT()
  const { isCloud, features } = useConsoleProfile()
  const { data } = useAppwriteCloudStatus(isCloud && features.systemStatus)
  const report = data?.activeReport
  if (!report || data.aggregateState === 'operational') return null

  const maintenance = data.aggregateState === 'maintenance'
  return (
    <a
      href={STATUS_PAGE_URL}
      target="_blank"
      rel="noopener noreferrer"
      className="flex gap-3 border-b border-border px-5 py-3 transition-colors hover:bg-accent/50"
    >
      <NotificationIcon
        icon={maintenance ? Wrench : TriangleAlert}
        className={
          maintenance
            ? 'text-muted-foreground'
            : 'text-amber-600 dark:text-amber-500'
        }
      />
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-medium leading-snug text-foreground">
          {report.title}
        </p>
        <p className="mt-1.5 text-[11px] text-muted-foreground">
          {maintenance ? t('Scheduled maintenance') : t('Ongoing incident')}
          {data.regionsLine ? ` · ${data.regionsLine}` : ''}
        </p>
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
    // Nothing stored reads as all unseen, as the changelog badge does.
    return seen === null ? LATEST : Math.max(0, changelogCount - seen)
  })
  const entries = getAllChangelogEntries().slice(0, LATEST)

  useEffect(() => {
    markChangelogSeen()
  }, [])

  return (
    <div>
      <CloudIncident />
      <h4 className="px-5 pb-1.5 pt-3 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('Changelog')}
      </h4>
      <div className="divide-y divide-border/70">
        {entries.map((entry, index) => {
          const meta = [
            entry.date ? dateFormat.format(new Date(entry.date)) : null,
            ...entry.parsedTags.slice(0, 2).map((tag) => TAG_LABELS[tag]),
          ].filter(Boolean)
          return (
            <Link
              key={entry.slug}
              to="/changelog/entry/$entry"
              params={{ entry: entry.slug }}
              onClick={onNavigate}
              className="relative block px-5 py-3 transition-colors hover:bg-accent/50"
            >
              {index < unseen ? <UnreadDot /> : null}
              <p className="text-[13px] font-medium leading-snug text-foreground">
                {entry.title}
              </p>
              {entry.description ? (
                <p className="mt-1 line-clamp-2 text-[12px] leading-relaxed text-muted-foreground">
                  {entry.description}
                </p>
              ) : null}
              {meta.length > 0 ? (
                <p className="mt-1.5 text-[11px] text-muted-foreground">
                  {meta.join(' · ')}
                </p>
              ) : null}
            </Link>
          )
        })}
      </div>
      <Link
        to="/changelog"
        onClick={onNavigate}
        className="flex items-center justify-between border-t border-border px-5 py-2.5 text-[12px] font-medium text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
      >
        {t('View full changelog')}
        <ArrowRight className="size-3.5 rtl:rotate-180" aria-hidden />
      </Link>
    </div>
  )
}
