import { Link, createFileRoute, getRouteApi } from '@tanstack/react-router'
import { Search, X } from 'lucide-react'
import { LayoutToggle, LibraryList, Section } from '@/components/Page'
import { Hero } from '@/components/Hero'
import { StatusDot } from '@/components/Status'
import { MigrationCard } from '@/components/Migration'
import { useMigration } from '@/lib/prefs'
import { CONVERSION, STATUS, conversion, conversionLine, formatCount, type Index, type LibrarySummary, type Status } from '@/lib/docs'
import { epoch } from '@/lib/time'

const root = getRouteApi('__root__')

const STATUSES: Status[] = ['complete', 'progress', 'started', 'planned', 'retired']

/** How the list is ordered; anything but `category` shows one list instead of a section per category. */
type Sort = 'category' | 'name' | 'updated' | 'speed' | 'size' | 'lines' | 'examples' | 'status' | 'cases'

const SORTS: { id: Sort; label: string; migration?: boolean }[] = [
  { id: 'category', label: 'Category' },
  { id: 'name', label: 'Name' },
  { id: 'updated', label: 'Recently updated' },
  { id: 'size', label: 'Size (API items)' },
  { id: 'lines', label: 'Size (lines of Rust)' },
  { id: 'examples', label: 'Examples' },
  { id: 'speed', label: 'Speed boost vs PHP', migration: true },
  { id: 'status', label: 'Conversion status', migration: true },
  { id: 'cases', label: 'Test cases vs PHP', migration: true },
]

interface Filters {
  q?: string
  status?: Status
  category?: string
  behind?: boolean
  sort?: Sort
}

export const Route = createFileRoute('/')({
  validateSearch: (search: Record<string, unknown>): Filters => ({
    q: typeof search.q === 'string' && search.q ? search.q : undefined,
    status: STATUSES.includes(search.status as Status) ? (search.status as Status) : undefined,
    category: typeof search.category === 'string' && search.category ? search.category : undefined,
    behind: search.behind === true || search.behind === 'true' ? true : undefined,
    sort: SORTS.some((o) => o.id === search.sort && o.id !== 'category') ? (search.sort as Sort) : undefined,
  }),
  component: Home,
})

/** Whether a library matches the search text: its name, crate, description or category, and its PHP package in migration mode. */
function matches(l: LibrarySummary, term: string, category: string, migration: boolean) {
  if (!term) return true
  return [l.title, l.slug, l.description, migration ? l.php?.package : '', l.rust?.crate, category].join(' ').toLowerCase().includes(term)
}

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[12.5px] transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

const ORDER: Status[] = ['complete', 'progress', 'started', 'planned', 'retired']

/** Libraries in the chosen order: largest, fastest or most tested first; name breaks ties. */
function sorted(libs: LibrarySummary[], sort: Sort): LibrarySummary[] {
  const by = (key: (l: LibrarySummary) => number) => (a: LibrarySummary, b: LibrarySummary) => key(b) - key(a) || a.title.localeCompare(b.title)
  const order = {
    category: by(() => 0),
    name: (a: LibrarySummary, b: LibrarySummary) => a.title.localeCompare(b.title),
    updated: by((l) => epoch(l.updated?.at)),
    size: by((l) => (l.rust ? l.counts.items : -1)),
    lines: by((l) => l.size?.rust?.total ?? -1),
    examples: by((l) => l.counts.examples ?? 0),
    speed: by((l) => l.bench?.speedup ?? -1),
    status: by((l) => -ORDER.indexOf(l.status)),
    cases: by((l) => l.compat?.cases ?? -1),
  }[sort]
  return [...libs].sort(order)
}
const BAR: Record<Status, string> = { complete: 'bg-ok', progress: 'bg-progress', started: 'bg-started', planned: 'bg-planned/40', retired: '' }

/** The ecosystem in lines: every crate's Rust, split into code, comments and blank lines, and its tests. */
function Totals({ index }: { index: Index }) {
  const r = index.size?.rust
  if (!r?.libraries) return null
  const item = (value: number, label: string) => (
    <span className="inline-flex items-baseline gap-1.5">
      <span className="font-mono text-[15px] font-medium text-foreground tabular-nums">{formatCount(value)}</span>
      {label}
    </span>
  )
  return (
    <div className="flex flex-wrap items-baseline justify-center gap-x-8 gap-y-1 font-mono text-[11.5px] text-muted-foreground">
      {item(r.libraries, 'crates')}
      {item(r.total, 'lines of Rust')}
      {item(r.code, 'code')}
      {item(r.comments, 'comments')}
      {item(r.tests, 'in tests')}
    </div>
  )
}

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
    matches(l, term, categoryTitle.get(l.category) ?? '', migration) &&
    (ignore === 'behind' || !migration || !filters.behind || behind(l)) &&
    (ignore === 'status' || !migration || !filters.status || l.status === filters.status) &&
    (ignore === 'category' || !filters.category || l.category === filters.category)
  const shown = index.libraries.filter((l) => visible(l))
  const total = index.libraries.filter((l) => migration || l.rust).length
  const filtered = !!(term || filters.category || (migration && (filters.status || filters.behind)))
  const categories = index.categories.filter((c) => !filters.category || c.id === filters.category)
  // Migration-only orders fall back to categories outside the migration view.
  const sort: Sort = filters.sort && (migration || !SORTS.find((o) => o.id === filters.sort)?.migration) ? filters.sort : 'category'
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <Hero index={index} />
      <Totals index={index} />
      <MigrationCard title="Conversion from PHP">
        <p className="m-0 text-[13px] text-muted-foreground">
          The libraries are moving from PHP to Rust one at a time. Each Rust crate keeps its PHP library's behaviour, proven case by case against PHP.
        </p>
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <span className="text-[13px] font-medium">Conversion</span>
          <span className="text-[13px] text-muted-foreground tabular-nums">
            {conversionLine(index.libraries)}
          </span>
        </div>
        {/* Libraries not converted by design are no part of the work left: the bar leaves them out. */}
        <div className="flex h-2 overflow-hidden rounded-full bg-muted">
          {CONVERSION.map((s) => (
            <span key={s} className={BAR[s]} style={{ width: `${(count(s) / (conversion(index.libraries).total || 1)) * 100}%` }} />
          ))}
        </div>
        {index.size?.php_converted.libraries && index.size.rust_converted ? (
          <p className="m-0 text-[12.5px] text-muted-foreground">
            In the {index.size.php_converted.libraries} libraries ported so far,{' '}
            <span className="text-foreground tabular-nums">{formatCount(index.size.php_converted.total)}</span> lines of PHP ({formatCount(index.size.php_converted.code)} code)
            became <span className="text-foreground tabular-nums">{formatCount(index.size.rust_converted.total)}</span> lines of Rust ({formatCount(index.size.rust_converted.code)}{' '}
            code). All {index.size.rust.libraries} crates, php-std included, hold {formatCount(index.size.rust.total)}; all {index.size.php.libraries} PHP packages{' '}
            {formatCount(index.size.php.total)}.
          </p>
        ) : null}
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {ORDER.filter((s) => s !== 'retired' || count(s)).map((s) => (
            <span key={s} className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground" title={STATUS[s].note}>
              <StatusDot status={s} />
              {STATUS[s].label} <span className="text-foreground tabular-nums">{count(s)}</span>
            </span>
          ))}
        </div>
      </MigrationCard>
      <div id="libraries" className="sticky top-14 z-10 -mx-2 grid scroll-mt-14 gap-3 border-b border-border bg-background/80 px-2 py-3 backdrop-blur-xl">
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex h-9 min-w-0 flex-[1_1_280px] items-center gap-2 rounded-lg border border-border bg-card px-3 text-muted-foreground transition-colors focus-within:border-highlight-line focus-within:ring-3 focus-within:ring-highlight-soft">
            <Search className="size-4 flex-none" />
            <input
              type="search"
              value={filters.q ?? ''}
              onChange={(e) => set({ q: e.target.value || undefined })}
              placeholder={migration ? 'Search libraries, packages and crates' : 'Search libraries and crates'}
              aria-label="Search libraries"
              className="min-w-0 flex-1 bg-transparent text-[13.5px] text-foreground outline-none placeholder:text-muted-foreground"
            />
          </label>
          <select
            value={filters.category ?? ''}
            onChange={(e) => set({ category: e.target.value || undefined })}
            aria-label="Category"
            className="h-9 rounded-lg border border-border bg-card pr-8 pl-2.5 text-[13px] text-foreground"
          >
            <option value="">All categories</option>
            {index.categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.title} ({index.libraries.filter((l) => l.category === c.id && visible(l, 'category')).length})
              </option>
            ))}
          </select>
          <select
            value={sort}
            onChange={(e) => set({ sort: e.target.value === 'category' ? undefined : (e.target.value as Sort) })}
            aria-label="Sort by"
            title="Sort by"
            className="h-9 rounded-lg border border-border bg-card pr-8 pl-2.5 text-[13px] text-foreground"
          >
            {SORTS.filter((o) => migration || !o.migration).map((o) => (
              <option key={o.id} value={o.id}>
                Sort: {o.label}
              </option>
            ))}
          </select>
          <LayoutToggle />
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
          {STATUSES.filter((s) => s !== 'retired' || count(s)).map((s) => (
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
        <span className="justify-self-end font-mono text-[11px] text-muted-foreground tabular-nums">
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
      {sort !== 'category' && shown.length ? (
        <Section title={`Sorted by ${SORTS.find((o) => o.id === sort)?.label}`}>
          <LibraryList libs={sorted(shown, sort)} />
        </Section>
      ) : null}
      {sort === 'category' && categories.map((c) => {
        const libs = c.libraries.map((s) => bySlug.get(s)).filter((l): l is LibrarySummary => !!l && visible(l))
        return libs.length ? (
        <Section key={c.id} id={c.id} title={c.title} description={c.description}>
          <LibraryList libs={libs} />
          <Link to="/categories/$category" params={{ category: c.id }} className="justify-self-start text-[13px] text-muted-foreground transition-colors hover:text-highlight">
            About {c.title.toLowerCase()} →
          </Link>
        </Section>
        ) : null
      })}
    </div>
  )
}
