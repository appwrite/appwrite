import { createFileRoute, getRouteApi, Link } from '@tanstack/react-router'
import { Markdown } from '@/components/Markdown'
import { ChangelogFilters } from '@/components/ChangelogFilters'
import { PageHeader } from '@/components/Page'
import { ENTRIES, entryMatchesPackage, packageLabel, packagesWithUpdates } from '@/lib/changelog'
import { share } from '@/lib/share'
import { day } from '@/lib/time'

const root = getRouteApi('__root__')

function changelogSearch(search: Record<string, unknown>): { package?: string } {
  return {
    package: typeof search.package === 'string' && search.package ? search.package : undefined,
  }
}

export const Route = createFileRoute('/changelog')({
  validateSearch: changelogSearch,
  head: () => ({
    meta: share({ title: 'Changelog', description: 'What changed in the crates, the docs, and the move from PHP to Rust.' }),
  }),
  component: Changelog,
})

function Changelog() {
  const index = root.useLoaderData()
  const { package: selectedPackage } = Route.useSearch()
  const titleBySlug = new Map(index?.libraries.map((l) => [l.slug, l.title]) ?? [])
  const filterOptions = packagesWithUpdates(ENTRIES, titleBySlug)
  const visible = ENTRIES.filter((entry) => entryMatchesPackage(entry, selectedPackage))

  return (
    <div className="mx-auto grid max-w-[1100px] gap-10 lg:grid-cols-[11rem_minmax(0,1fr)] lg:gap-x-12">
      <aside className="lg:sticky lg:top-20 lg:self-start">
        <ChangelogFilters options={filterOptions} selected={selectedPackage} />
      </aside>
      <div className="grid min-w-0 gap-10">
        <PageHeader eyebrow="Updates" title="Changelog">
          What changed in the crates, the docs, and the move from PHP to Rust.
        </PageHeader>
        {visible.length ? (
          <ol className="grid">
            {visible.map((entry, i) => (
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
                      <a
                        href={`#${entry.slug}`}
                        className="font-mono text-[13px] text-transparent transition-colors group-hover:text-muted-foreground hover:text-highlight"
                        aria-label={`Link to ${entry.title}`}
                      >
                        #
                      </a>
                    </h2>
                    {entry.tag ? (
                      <span
                        className={
                          entry.tag === 'Migration'
                            ? 'label border border-migration-border bg-migration px-1.5 py-0.5 text-migration-ink'
                            : 'label border border-border px-1.5 py-0.5 text-muted-foreground'
                        }
                      >
                        {entry.tag}
                      </span>
                    ) : null}
                  </div>
                  {entry.packages.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {entry.packages.map((slug) => (
                        <Link
                          key={slug}
                          to="/changelog"
                          search={{ package: slug }}
                          replace
                          resetScroll={false}
                          className="label border border-border px-1.5 py-0.5 text-muted-foreground transition-colors hover:border-foreground/20 hover:text-foreground"
                        >
                          {packageLabel(slug, titleBySlug.get(slug))}
                        </Link>
                      ))}
                    </div>
                  ) : null}
                  <Markdown text={entry.body} />
                </article>
              </li>
            ))}
          </ol>
        ) : selectedPackage ? (
          <p className="text-[14px] text-muted-foreground">
            No updates match this package.{' '}
            <Link to="/changelog" search={{}} replace resetScroll={false} className="text-foreground underline-offset-2 hover:underline">
              Clear the filter
            </Link>
            .
          </p>
        ) : (
          <p className="text-[14px] text-muted-foreground">No changelog entries yet.</p>
        )}
      </div>
    </div>
  )
}
