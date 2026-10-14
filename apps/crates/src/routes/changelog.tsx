import { createFileRoute } from '@tanstack/react-router'
import { Markdown } from '@/components/Markdown'
import { PageHeader } from '@/components/Page'
import { ENTRIES } from '@/lib/changelog'
import { day } from '@/lib/time'

export const Route = createFileRoute('/changelog')({
  head: () => ({ meta: [{ title: 'Changelog · Utopia for Rust' }] }),
  component: Changelog,
})

function Changelog() {
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <PageHeader eyebrow="Updates" title="Changelog">
        What changed in the crates, the docs, and the move from PHP to Rust.
      </PageHeader>
      <ol className="grid">
        {ENTRIES.map((entry, i) => (
          <li
            key={entry.slug}
            id={entry.slug}
            className={`grid scroll-mt-20 gap-x-10 gap-y-3 py-8 sm:grid-cols-[9rem_minmax(0,1fr)] ${i ? 'trace-rule border-t border-border' : ''}`}
          >
            <time dateTime={entry.date} className="label pt-1.5 text-muted-foreground">
              {day(entry.date)}
            </time>
            <article className="grid min-w-0 gap-3">
              <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 className="group flex items-baseline gap-3 text-[26px]">
                  {entry.title}
                  <a href={`#${entry.slug}`} className="font-mono text-[13px] text-transparent transition-colors group-hover:text-muted-foreground hover:text-highlight" aria-label={`Link to ${entry.title}`}>
                    #
                  </a>
                </h2>
                {entry.tag ? (
                  <span className={entry.tag === 'Migration' ? 'label border border-migration-border bg-migration px-1.5 py-0.5 text-migration-ink' : 'label border border-border px-1.5 py-0.5 text-muted-foreground'}>
                    {entry.tag}
                  </span>
                ) : null}
              </div>
              <Markdown text={entry.body} />
            </article>
          </li>
        ))}
      </ol>
    </div>
  )
}
