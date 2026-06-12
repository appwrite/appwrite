import { Link } from '@tanstack/react-router'
import { formatDate } from '@/lib/date-utils'
import type { ChangelogEntry } from '@/lib/changelog/types'
import { ChangelogMarkdown } from './ChangelogMarkdown'

type ChangelogEntryCardProps = {
  entry: ChangelogEntry
}

export function ChangelogEntryCard({ entry }: ChangelogEntryCardProps) {
  return (
    <article className="grid min-w-0 gap-5">
      <time
        className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
        dateTime={entry.date}
      >
        {formatDate(entry.date)}
      </time>

      {entry.cover ? (
        <Link
          to="/changelog/entry/$entry"
          params={{ entry: entry.slug }}
          className="block overflow-hidden rounded-xl border border-border bg-card/40"
        >
          <img
            src={entry.cover}
            alt=""
            loading="lazy"
            className="aspect-video w-full object-cover"
          />
        </Link>
      ) : null}

      <div className="min-w-0 overflow-x-clip px-4 sm:px-0">
        <h2 className="mb-8 font-aeonik-pro text-[22px] font-normal leading-tight text-foreground sm:text-[24px]">
          <Link
            to="/changelog/entry/$entry"
            params={{ entry: entry.slug }}
            className="transition-colors hover:text-foreground/80"
          >
            {entry.title}
          </Link>
        </h2>
        <ChangelogMarkdown content={entry.content} />
      </div>
    </article>
  )
}
