import { Link, createFileRoute, getRouteApi } from '@tanstack/react-router'
import { Search, X } from 'lucide-react'
import { CardGrid, LibraryCard, PageHeader, Section } from '@/components/Page'
import { StatusDot } from '@/components/Status'
import { MigrationCard } from '@/components/Migration'
import { useMigration } from '@/lib/prefs'
import { STATUS, type LibrarySummary, type Status } from '@/lib/docs'

const root = getRouteApi('__root__')

const STATUSES: Status[] = ['complete', 'progress', 'started', 'planned']

interface Filters {
  q?: string
  status?: Status
  category?: string
  behind?: boolean
}

export const Route = createFileRoute('/')({
  validateSearch: (search: Record<string, unknown>): Filters => ({
    q: typeof search.q === 'string' && search.q ? search.q : undefined,
    status: STATUSES.includes(search.status as Status) ? (search.status as Status) : undefined,
    category: typeof search.category === 'string' && search.category ? search.category : undefined,
    behind: search.behind === true || search.behind === 'true' ? true : undefined,
  }),
  component: Home,
})

/** Whether a library matches the search text: its name, package, crate, description or category. */
function matches(l: LibrarySummary, term: string, category: string) {
  if (!term) return true
  return [l.title, l.slug, l.description, l.php?.package, l.rust?.crate, category].join(' ').toLowerCase().includes(term)
}

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[12.5px] transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

const ORDER: Status[] = ['complete', 'progress', 'started', 'planned']
const BAR: Record<Status, string> = { complete: 'bg-ok', progress: 'bg-progress', started: 'bg-started', planned: 'bg-planned/40' }

function Home() {
  const index = root.useLoaderData()
  const filters = Route.useSearch()
  const navigate = Route.useNavigate()
  const migration = useMigration()
  if (!index) return null
  const set = (patch: Filters) => navigate({ search: (s) => ({ ...s, ...patch }), replace: true, resetScroll: false })
  const bySlug = new Map(index.libraries.map((l) => [l.slug, l]))
  const count = (s: Status) => index.libraries.filter((l) => l.status === s).length
  const term = (filters.q ?? '').trim().toLowerCase()
  const categoryTitle = new Map(index.categories.map((c) => [c.id, c.title]))
  // Each filter's counts honour the other filters, so a choice never leads to an empty page unawares.
  const behind = (l: LibrarySummary) => (l.sync.behind ?? 0) + (l.sync.upstream ?? 0) > 0
  const visible = (l: LibrarySummary, ignore?: 'status' | 'category' | 'behind') =>
    (migration || !!l.rust) &&
    matches(l, term, categoryTitle.get(l.category) ?? '') &&
    (ignore === 'behind' || !migration || !filters.behind || behind(l)) &&
    (ignore === 'status' || !migration || !filters.status || l.status === filters.status) &&
    (ignore === 'category' || !filters.category || l.category === filters.category)
  const shown = index.libraries.filter((l) => visible(l))
  const total = index.libraries.filter((l) => migration || l.rust).length
  const filtered = !!(term || filters.category || (migration && (filters.status || filters.behind)))
  const categories = index.categories.filter((c) => !filters.category || c.id === filters.category)
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <PageHeader title="Utopia libraries">
        Small, single-purpose Rust libraries for building services: HTTP, validation, authentication, locks, configuration and more. Each library
        solves one problem and documents it with examples you can copy and run.
      </PageHeader>
      <MigrationCard title="Conversion from PHP">
        <p className="m-0 text-[13px] text-muted-foreground">
          The libraries are moving from PHP to Rust one at a time. Each Rust crate keeps its PHP library's behaviour, proven case by case against PHP.
        </p>
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <span className="text-[13px] font-medium">Conversion</span>
          <span className="text-[13px] text-muted-foreground tabular-nums">
            {count('complete')} of {index.libraries.length} libraries converted
          </span>
        </div>
        <div className="flex h-2 overflow-hidden rounded-full bg-muted">
          {ORDER.map((s) => (
            <span key={s} className={BAR[s]} style={{ width: `${(count(s) / index.libraries.length) * 100}%` }} />
          ))}
        </div>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {ORDER.map((s) => (
            <span key={s} className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground" title={STATUS[s].note}>
              <StatusDot status={s} />
              {STATUS[s].label} <span className="text-foreground tabular-nums">{count(s)}</span>
            </span>
          ))}
        </div>
      </MigrationCard>
      <div className="sticky top-14 z-10 -mx-2 grid gap-3 border-b border-border bg-background/95 px-2 py-3 backdrop-blur">
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex h-9 min-w-0 flex-[1_1_280px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring">
            <Search className="size-4 flex-none" />
            <input
              type="search"
              value={filters.q ?? ''}
              onChange={(e) => set({ q: e.target.value || undefined })}
              placeholder="Search libraries, packages and crates"
              aria-label="Search libraries"
              className="min-w-0 flex-1 bg-transparent text-[13.5px] text-foreground outline-none placeholder:text-muted-foreground"
            />
          </label>
          <select
            value={filters.category ?? ''}
            onChange={(e) => set({ category: e.target.value || undefined })}
            aria-label="Category"
            className="h-9 rounded-md border border-border bg-card px-2.5 text-[13px] text-foreground"
          >
            <option value="">All categories</option>
            {index.categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.title} ({index.libraries.filter((l) => l.category === c.id && visible(l, 'category')).length})
              </option>
            ))}
          </select>
          {filtered ? (
            <button
              type="button"
              onClick={() => set({ q: undefined, status: undefined, category: undefined, behind: undefined })}
              className="inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <X className="size-3.5" />
              Clear
            </button>
          ) : null}
        </div>
        {migration ? (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-migration-border bg-migration px-2 py-1.5" role="group" aria-label="Conversion status">
          <span className="pr-1 text-[10.5px] font-semibold tracking-wider text-migration-ink uppercase">Migration</span>
          <button type="button" aria-pressed={!filters.status} onClick={() => set({ status: undefined })} className={`${chip} border-border text-muted-foreground`}>
            All <span className="tabular-nums">{index.libraries.filter((l) => visible(l, 'status')).length}</span>
          </button>
          {STATUSES.map((s) => (
            <button key={s} type="button" aria-pressed={filters.status === s} onClick={() => set({ status: filters.status === s ? undefined : s })} className={`${chip} border-border text-muted-foreground`} title={STATUS[s].note}>
              <StatusDot status={s} />
              {STATUS[s].label}
              <span className="tabular-nums">{index.libraries.filter((l) => l.status === s && visible(l, 'status')).length}</span>
            </button>
          ))}
          <span className="mx-1 h-5 w-px bg-border" />
          <button
            type="button"
            aria-pressed={!!filters.behind}
            onClick={() => set({ behind: filters.behind ? undefined : true })}
            className={`${chip} border-border text-muted-foreground`}
            title="Rust crates whose PHP library changed since they last matched it"
          >
            Behind PHP <span className="tabular-nums">{index.libraries.filter((l) => behind(l) && visible(l, 'behind')).length}</span>
          </button>
        </div>
        ) : null}
        <span className="justify-self-end text-[12.5px] text-muted-foreground tabular-nums">
          {shown.length} of {total} libraries
        </span>
      </div>
      {shown.length ? null : (
        <div className="grid justify-items-center gap-2 rounded-lg border border-dashed border-border px-4 py-10 text-center">
          <span className="text-[15px]">No libraries match</span>
          <span className="text-[13px] text-muted-foreground">Try another search, or clear the filters.</span>
          <button type="button" onClick={() => set({ q: undefined, status: undefined, category: undefined, behind: undefined })} className="mt-1 rounded-md border border-border px-3 py-1.5 text-[13px] hover:bg-accent">
            Clear filters
          </button>
        </div>
      )}
      {categories.map((c) => {
        const libs = c.libraries.map((s) => bySlug.get(s)).filter((l): l is LibrarySummary => !!l && visible(l))
        return libs.length ? (
        <Section key={c.id} id={c.id} title={c.title} description={c.description}>
          <CardGrid>
            {libs.map((lib) => (
              <LibraryCard key={lib.slug} lib={lib} />
            ))}
          </CardGrid>
          <Link to="/categories/$category" params={{ category: c.id }} className="justify-self-start text-[13px] text-muted-foreground hover:text-foreground">
            About {c.title.toLowerCase()} →
          </Link>
        </Section>
        ) : null
      })}
    </div>
  )
}
