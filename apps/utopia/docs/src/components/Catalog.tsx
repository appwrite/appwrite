import { Link } from '@tanstack/react-router'
import { Search, X } from 'lucide-react'
import { LayoutToggle, LibraryList, Section } from './Page'
import { StatusDot } from './Status'
import { STATUS, STATUSES, formatCount, progressLine, type Index, type LibrarySummary, type Status } from '@/lib/docs'
import { epoch } from '@/lib/time'

/**
 * The libraries, as the home page ends and the Libraries page shows them: the
 * search and filters (kept in the URL), and the list by category or in the
 * chosen order.
 */

/** How the list is ordered; anything but `category` shows one list instead of a section per category. */
type Sort = 'category' | 'name' | 'released' | 'size' | 'lines' | 'examples' | 'status'

const SORTS: { id: Sort; label: string }[] = [
  { id: 'category', label: 'Category' },
  { id: 'name', label: 'Name' },
  { id: 'released', label: 'Recently released' },
  { id: 'size', label: 'Size (API items)' },
  { id: 'lines', label: 'Size (lines of Rust)' },
  { id: 'examples', label: 'Examples' },
  { id: 'status', label: 'Status' },
]

export interface Filters {
  q?: string
  status?: Status
  category?: string
  sort?: Sort
}

/** The filters from a route's search params. */
export function filtersFrom(search: Record<string, unknown>): Filters {
  return {
    q: typeof search.q === 'string' && search.q ? search.q : undefined,
    status: STATUSES.includes(search.status as Status) ? (search.status as Status) : undefined,
    category: typeof search.category === 'string' && search.category ? search.category : undefined,
    sort: SORTS.some((o) => o.id === search.sort && o.id !== 'category') ? (search.sort as Sort) : undefined,
  }
}

/** Whether a library matches the search text: its name, crate, PHP package, description or category. */
function matches(l: LibrarySummary, term: string, category: string) {
  if (!term) return true
  return [l.title, l.slug, l.description, l.php?.package, l.rust?.crate, category].join(' ').toLowerCase().includes(term)
}

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[12.5px] transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

/** Libraries in the chosen order: largest or furthest along first; name breaks ties. */
function sorted(libs: LibrarySummary[], sort: Sort): LibrarySummary[] {
  const by = (key: (l: LibrarySummary) => number) => (a: LibrarySummary, b: LibrarySummary) => key(b) - key(a) || a.title.localeCompare(b.title)
  const order = {
    category: by(() => 0),
    name: (a: LibrarySummary, b: LibrarySummary) => a.title.localeCompare(b.title),
    released: by((l) => epoch(l.released)),
    size: by((l) => (l.rust ? l.counts.items : -1)),
    lines: by((l) => l.size.rust?.total ?? -1),
    examples: by((l) => l.counts.examples),
    status: by((l) => -STATUSES.indexOf(l.status)),
  }[sort]
  return [...libs].sort(order)
}
const BAR: Record<Status, string> = { rust: 'bg-ok', partial: 'bg-progress', switchable: 'bg-started', planned: 'bg-planned/40' }

export function Catalog({ index, filters, set }: { index: Index; filters: Filters; set: (patch: Filters) => void }) {
  const bySlug = new Map(index.libraries.map((l) => [l.slug, l]))
  const count = (s: Status) => index.libraries.filter((l) => l.status === s).length
  const term = (filters.q ?? '').trim().toLowerCase()
  const categoryTitle = new Map(index.categories.map((c) => [c.id, c.title]))
  // Each filter's counts honour the other filters, so a choice never leads to an empty page unawares.
  const visible = (l: LibrarySummary, ignore?: 'status' | 'category') =>
    matches(l, term, categoryTitle.get(l.category) ?? '') &&
    (ignore === 'status' || !filters.status || l.status === filters.status) &&
    (ignore === 'category' || !filters.category || l.category === filters.category)
  const shown = index.libraries.filter((l) => visible(l))
  const total = index.libraries.length
  const filtered = !!(term || filters.category || filters.status)
  const categories = index.categories.filter((c) => !filters.category || c.id === filters.category)
  const sort: Sort = filters.sort ?? 'category'
  return (
    <>
      <div className="grid min-w-0 gap-3 border border-border bg-card p-4">
        <p className="m-0 text-[13px] text-muted-foreground">
          The libraries are moving from PHP to Rust one at a time. Appwrite loads each Rust crate through its PHP extension, so the classes keep their PHP names and the PHP source is deleted.
        </p>
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <span className="text-[13px] font-medium">From PHP to Rust</span>
          <span className="text-[13px] text-muted-foreground tabular-nums">{progressLine(index.libraries)}</span>
        </div>
        <div className="flex h-2 overflow-hidden rounded-full bg-muted">
          {STATUSES.map((s) => (
            <span key={s} className={BAR[s]} style={{ width: `${(count(s) / (index.libraries.length || 1)) * 100}%` }} />
          ))}
        </div>
        <p className="m-0 text-[12.5px] text-muted-foreground">
          <span className="text-foreground tabular-nums">{formatCount(index.classes.rust)}</span> PHP classes are served from Rust, in{' '}
          <span className="text-foreground tabular-nums">{formatCount(index.size.rust.total)}</span> lines of Rust ({formatCount(index.size.rust.code)} code).{' '}
          <span className="text-foreground tabular-nums">{formatCount(index.classes.php)}</span> classes and {formatCount(index.size.php.total)} lines are still PHP.
        </p>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {STATUSES.map((s) => (
            <span key={s} className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground" title={STATUS[s].note}>
              <StatusDot status={s} />
              {STATUS[s].label} <span className="text-foreground tabular-nums">{count(s)}</span>
            </span>
          ))}
        </div>
      </div>
      <div id="libraries" className="trace-rule trace-bottom sticky top-14 z-10 -mx-2 grid scroll-mt-14 gap-3 border-b border-foreground bg-background px-2 pt-3 pb-3">
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex h-9 min-w-0 flex-[1_1_280px] items-center gap-2 border border-border bg-card px-3 text-muted-foreground transition-colors focus-within:border-foreground">
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
            className="h-9 border border-border bg-card pr-8 pl-2.5 text-[13px] text-foreground"
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
            className="h-9 border border-border bg-card pr-8 pl-2.5 text-[13px] text-foreground"
          >
            {SORTS.map((o) => (
              <option key={o.id} value={o.id}>
                Sort: {o.label}
              </option>
            ))}
          </select>
          <LayoutToggle />
          {filtered ? (
            <button
              type="button"
              onClick={() => set({ q: undefined, status: undefined, category: undefined })}
              className="inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <X className="size-3.5" />
              Clear
            </button>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Status">
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
        </div>
        <span className="justify-self-end font-mono text-[11px] text-muted-foreground tabular-nums">
          {shown.length} of {total} libraries
        </span>
      </div>
      {shown.length ? null : (
        <div className="grid justify-items-center gap-2 rounded-lg border border-dashed border-border px-4 py-10 text-center">
          <span className="text-[15px]">No libraries match</span>
          <span className="text-[13px] text-muted-foreground">Try another search, or clear the filters.</span>
          <button type="button" onClick={() => set({ q: undefined, status: undefined, category: undefined })} className="mt-1 rounded-md border border-border px-3 py-1.5 text-[13px] hover:bg-accent">
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
        <Section key={c.id} id={c.id} title={c.title} description={c.description} number={index.categories.indexOf(c) + 1}>
          <LibraryList libs={libs} />
          <Link to="/categories/$category" params={{ category: c.id }} className="justify-self-start text-[13px] text-muted-foreground transition-colors hover:text-highlight">
            About {c.title.toLowerCase()} →
          </Link>
        </Section>
        ) : null
      })}
    </>
  )
}
