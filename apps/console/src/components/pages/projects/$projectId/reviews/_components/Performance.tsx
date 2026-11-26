import { useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearch } from '@tanstack/react-router'
import {
  ArrowDown,
  ArrowUp,
  Copy,
  ExternalLink,
  FolderGit2,
  GitPullRequest,
  Loader2,
  Search,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  SearchableSelect,
  type SearchableSelectItem,
} from '@/components/global/shared/SearchableSelect'
import type {
  ReviewAuthor,
  ReviewAuthorRange,
  ReviewCategory,
  ReviewPeriod,
  ReviewRepositorySummary,
} from '@/lib/appwrite/reviews'
import {
  reviewSummaryQueryOptions,
  useReviewAuthors,
  useReviewCategories,
  useReviewSummary,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { mapToQueryParam, type CompactFilterKey } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { getVcsProvider, VcsIcon } from '@/lib/vcs/providers'
import {
  OverviewStatTiles,
  type OverviewStatTile,
} from '../../shared/OverviewStatTiles'
import { ActivityChart } from './ActivityChart'
import {
  authorUrl,
  formatReviewTime,
  ReviewAuthorLink,
  ReviewTierBadge,
} from './ReviewBadges'
import {
  RepositorySelect,
  useRepositoryOptions,
  type RepositoryOption,
} from './RepositorySelect'
import { RowActionsDropdown, RowContextMenu, type RowActions } from './RowMenus'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider first:ps-6 last:pe-6'

/** The authors table packs ten columns, so it pads less and never wraps. */
const AUTHOR_HEAD_CLASS =
  'px-3 py-3 whitespace-nowrap text-[12px] font-semibold text-muted-foreground uppercase tracking-wider first:ps-6'

const AUTHOR_CELL_CLASS =
  'px-3 py-2.5 text-end text-[13px] tabular-nums whitespace-nowrap'

const SUBLINE_CLASS = 'block text-[11px] text-muted-foreground'

const TOGGLE_ITEM_CLASS =
  'h-full min-w-[3rem] px-3 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground'

const RANGES: { value: ReviewAuthorRange; label: string }[] = [
  { value: '7d', label: '7d' },
  { value: '30d', label: '30d' },
  { value: '90d', label: '90d' },
]

/**
 * Settled findings a category needs before its dismissed share is flagged:
 * below it, one dismissal reads as a trend.
 */
const NOISY_MIN_SETTLED = 3

/** Share of settled findings at or above which a category is flagged noisy. */
const NOISY_SHARE = 0.5

/** Tiers worst first, so a tier sorts by its index. */
const TIERS = ['F', 'D', 'C', 'B', 'A', 'S']

/**
 * Rows per page of the authors and repositories, the first of them what a
 * table opens on. A busy project has hundreds of each.
 */
const PAGE_SIZES = [10, 25, 50, 100]

const ALL_REPOSITORIES = 'all'

type SortKey =
  | 'pulls'
  | 'approval'
  | 'findingsPerPull'
  | 'severe'
  | 'fixRate'
  | 'useful'
  | 'tier'
  | 'lastReviewAt'

type Sort = { key: SortKey; desc: boolean }

/** What an author and a repository both count, which their tables sort by. */
type Figures = Pick<
  ReviewAuthor,
  | 'pulls'
  | 'reviews'
  | 'approved'
  | 'approvedFirst'
  | 'findings'
  | 'critical'
  | 'major'
  | 'resolved'
  | 'dismissed'
  | 'lastReviewAt'
> & { tier?: string }

/** What the summary tiles are computed from: a period, or the authors summed. */
type Totals = Pick<
  ReviewPeriod,
  | 'pulls'
  | 'reviews'
  | 'approved'
  | 'approvedFirst'
  | 'findings'
  | 'critical'
  | 'major'
  | 'resolved'
  | 'dismissed'
> & { duration?: number }

function ratio(part: number, whole: number): number | null {
  return whole > 0 ? part / whole : null
}

function formatPercent(value: number | null): string {
  return value === null ? '-' : `${Math.round(value * 100)}%`
}

/**
 * Share of the settled findings that a later commit fixed rather than someone
 * dismissed. Findings still open count for neither side.
 */
function usefulRate(item: {
  resolved: number
  dismissed: number
}): number | null {
  return ratio(item.resolved, item.resolved + item.dismissed)
}

function formatNumber(value: number | null): string {
  return value === null ? '-' : value.toFixed(1)
}

/**
 * Percent change from the period before, to one decimal. None without a
 * figure on both sides, or from zero, where any change reads as infinite.
 */
function percentChange(
  current: number | null | undefined,
  previous: number | null | undefined,
): number | undefined {
  if (current == null || previous == null || previous === 0) return undefined
  return Number((((current - previous) / previous) * 100).toFixed(1))
}

function sortValue(row: Figures, key: SortKey): number | string {
  switch (key) {
    case 'pulls':
      return row.pulls
    case 'approval':
      return ratio(row.approved, row.pulls) ?? -1
    case 'findingsPerPull':
      return ratio(row.findings, row.pulls) ?? -1
    case 'severe':
      return row.critical + row.major
    case 'fixRate':
      return ratio(row.resolved, row.findings) ?? -1
    case 'useful':
      return usefulRate(row) ?? -1
    case 'tier':
      return TIERS.indexOf(row.tier ?? '')
    case 'lastReviewAt':
      return row.lastReviewAt
  }
}

/** An author's login; empty for reviews queued before logins were kept. */
function authorName(author: ReviewAuthor): string {
  return author.author
}

/**
 * `owner/name`, as the repository's latest review named it, or its ID for
 * reviews queued before names were kept.
 */
function repositoryName(repository: ReviewRepositorySummary): string {
  if (repository.providerRepositoryOwner && repository.providerRepositoryName) {
    return `${repository.providerRepositoryOwner}/${repository.providerRepositoryName}`
  }
  return repository.providerRepositoryName || repository.repositoryId
}

/** What a table shows of its rows, and how its controls change that. */
type Breakdown<Row> = {
  search: string
  setSearch: (search: string) => void
  sort: Sort
  setSort: (key: SortKey) => void
  page: number
  setPage: (page: number) => void
  pageSize: number
  setPageSize: (pageSize: number) => void
  /** Whether the rows outgrow the first page, which brings the search and pages. */
  paged: boolean
  /** The rows the search matches, sorted. */
  matches: Row[]
  /** The page of them shown. */
  rows: Row[]
}

/**
 * The authors or repositories table's search, sort and page. Sorting covers
 * every match before a page of them is shown, so the busiest or the worst
 * come first whichever page they started on.
 */
function useBreakdown<Row extends Figures>(
  rows: Row[],
  name: (row: Row) => string,
): Breakdown<Row> {
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<Sort>({ key: 'pulls', desc: true })
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(PAGE_SIZES[0])
  const paged = rows.length > PAGE_SIZES[0]

  const matches = useMemo(() => {
    // A range with fewer rows hides the search, so it stops filtering too.
    const term = paged ? search.trim().toLowerCase() : ''
    const direction = sort.desc ? -1 : 1
    return rows
      .filter((row) => !term || name(row).toLowerCase().includes(term))
      .sort((a, b) => {
        const left = sortValue(a, sort.key)
        const right = sortValue(b, sort.key)
        if (left === right) return b.pulls - a.pulls
        return (left < right ? -1 : 1) * direction
      })
  }, [rows, name, paged, search, sort])

  // Another range or repository can hold fewer pages than the one open.
  const current = Math.min(
    page,
    Math.max(1, Math.ceil(matches.length / pageSize)),
  )

  return {
    search,
    setSearch: (next) => {
      setSearch(next)
      setPage(1)
    },
    sort,
    setSort: (key) => {
      setSort((previous) => ({
        key,
        desc: previous.key === key ? !previous.desc : true,
      }))
      setPage(1)
    },
    page: current,
    setPage,
    pageSize,
    setPageSize: (next) => {
      setPageSize(next)
      setPage(1)
    },
    paged,
    matches,
    rows: matches.slice((current - 1) * pageSize, current * pageSize),
  }
}

/** The range the tab opens on, as the route loader prefetches it. */
const DEFAULT_RANGE: ReviewAuthorRange = '30d'

/** The Performance tab's search params: which range and repository it covers. */
export type PerformanceSearch = {
  range?: ReviewAuthorRange
  repositoryId?: string
}

/**
 * Which repository and range the Performance tab covers. Both live in the URL,
 * so another tab can link to one repository's performance.
 */
export function usePerformanceFilters(projectId: string) {
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as PerformanceSearch
  const update = (next: PerformanceSearch) =>
    navigate({
      to: '/projects/$projectId/reviews/performance',
      params: { projectId },
      search: (prev) => {
        const merged = { ...(prev as PerformanceSearch), ...next }
        return {
          range:
            merged.range && merged.range !== DEFAULT_RANGE
              ? merged.range
              : undefined,
          repositoryId: merged.repositoryId || undefined,
        }
      },
      replace: true,
    })

  return {
    range: search.range ?? DEFAULT_RANGE,
    setRange: (range: ReviewAuthorRange) => update({ range }),
    repositoryId: search.repositoryId ?? '',
    setRepositoryId: (repositoryId: string) => update({ repositoryId }),
  }
}

export type PerformanceFilters = ReturnType<typeof usePerformanceFilters>

/** The toolbar's date range, with a spinner while a new range loads. */
export function PerformanceRangeToggle({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const authors = useReviewAuthors(
    projectId,
    filters.range,
    filters.repositoryId,
  )
  const summary = useReviewSummary(
    projectId,
    filters.range,
    filters.repositoryId,
  )
  const refreshing =
    (authors.isFetching && !authors.isLoading) ||
    (summary.isFetching && !summary.isLoading)

  return (
    <>
      {refreshing && (
        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
      )}
      <ToggleGroup
        type="single"
        variant="outline"
        value={filters.range}
        onValueChange={(next) => {
          if (next) filters.setRange(next as ReviewAuthorRange)
        }}
        className="h-9 gap-0"
        aria-label={t('Date range')}
      >
        {RANGES.map((option, index) => (
          <ToggleGroupItem
            key={option.value}
            value={option.value}
            className={cn(
              TOGGLE_ITEM_CLASS,
              '!rounded-none shadow-none',
              index === 0 && '!rounded-s-md',
              index === RANGES.length - 1 && '!rounded-e-md',
              index !== 0 && '!border-s-0',
            )}
          >
            {t(option.label)}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>
    </>
  )
}

/**
 * The Git provider of a reviewed repository, for its mark. A summary row
 * names no installation, so the first page of reviewed repositories names
 * theirs, and past it a project whose installations are all on one provider
 * names that one. Empty when neither tells.
 */
function useRepositoryProviders(
  projectId: string,
  options: RepositoryOption[],
): (repositoryId: string) => string {
  const { data } = useVcsInstallations(projectId, 0, 100)
  const providers = new Set(
    (data?.installations ?? []).map((installation) => installation.provider),
  )
  const only = providers.size === 1 ? [...providers][0] : ''
  const byRepository = new Map(
    options.map((option) => [option.id, option.provider]),
  )
  return (repositoryId) => byRepository.get(repositoryId) || only
}

/**
 * The toolbar's repository filter: every repository reviewed in the range,
 * busiest first, named by its reviews, so a project with hundreds lists them
 * all without asking the Git provider for each name. While one is selected,
 * the others load when the list opens. A server without the summary lists the
 * reviewed repositories instead.
 */
export function PerformanceRepositorySelect({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const { range, repositoryId, setRepositoryId } = filters
  const [open, setOpen] = useState(false)
  // With no repository selected, this is the summary the tab shows.
  const reviewed = useQuery({
    ...reviewSummaryQueryOptions(projectId, range),
    enabled: !repositoryId || open,
  })
  const selected = useReviewSummary(projectId, range, repositoryId)
  const named = selected.repositories.find(
    (repository) => repository.repositoryId === repositoryId,
  )
  // A repository with no reviews in the range is named by its provider.
  const options = useRepositoryOptions(
    projectId,
    (repository) =>
      !selected.isLoading && !named && repository.$id === repositoryId,
  )
  const providerOf = useRepositoryProviders(projectId, options)

  if (reviewed.error || selected.error) {
    return (
      <RepositorySelect
        projectId={projectId}
        value={repositoryId}
        onChange={setRepositoryId}
      />
    )
  }

  const items: SearchableSelectItem[] = [
    { value: ALL_REPOSITORIES, label: t('All repositories'), icon: FolderGit2 },
    ...(reviewed.data?.repositories ?? []).map((repository) => ({
      value: repository.repositoryId,
      label: repositoryName(repository),
      description: t(
        repository.pulls === 1
          ? '{count} pull request'
          : '{count} pull requests',
      ).replace('{count}', repository.pulls.toLocaleString()),
      inlineDescription: true,
      icon: getVcsProvider(providerOf(repository.repositoryId)).Icon,
    })),
  ]
  const option = options.find((candidate) => candidate.id === repositoryId)

  return (
    <SearchableSelect
      value={repositoryId || ALL_REPOSITORIES}
      onValueChange={(next) =>
        setRepositoryId(next === ALL_REPOSITORIES ? '' : next)
      }
      items={items}
      onOpenChange={setOpen}
      isFetching={reviewed.isFetching}
      ariaLabel={t('Repository')}
      searchPlaceholder={t('Search repositories...')}
      emptyMessage={t('No repositories found')}
      selectedName={
        named
          ? repositoryName(named)
          : option?.named
            ? option.name
            : repositoryId
      }
      triggerClassName="w-[260px]"
      contentClassName="w-[300px]"
      listClassName="min-h-0"
    />
  )
}

/**
 * The performance tab: how the project's pull requests fare in review. The
 * tiles compare the range with the same number of days before it, the chart
 * shows it day by day, and the tables break it down by author and by the
 * category of what the reviewer found.
 */
export function Performance({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const navigate = useNavigate()
  const { range, repositoryId } = filters

  const { authors, isLoading } = useReviewAuthors(
    projectId,
    range,
    repositoryId,
  )
  const summary = useReviewSummary(projectId, range, repositoryId)
  // Loads after the tab opens, below the authors, so it never holds navigation.
  const { categories, isLoading: categoriesLoading } = useReviewCategories(
    projectId,
    range,
    repositoryId,
  )

  // The summary carries the tiles; until it answers, the authors add up to
  // the same range without the comparison or the review time.
  const authorTotals = useMemo<Totals>(
    () =>
      authors.reduce<Totals>(
        (sum, author) => ({
          pulls: sum.pulls + author.pulls,
          reviews: sum.reviews + author.reviews,
          approved: sum.approved + author.approved,
          approvedFirst: sum.approvedFirst + author.approvedFirst,
          findings: sum.findings + author.findings,
          critical: sum.critical + author.critical,
          major: sum.major + author.major,
          resolved: sum.resolved + author.resolved,
          dismissed: sum.dismissed + author.dismissed,
        }),
        {
          pulls: 0,
          reviews: 0,
          approved: 0,
          approvedFirst: 0,
          findings: 0,
          critical: 0,
          major: 0,
          resolved: 0,
          dismissed: 0,
        },
      ),
    [authors],
  )
  const totals: Totals = summary.current ?? authorTotals
  const previous =
    summary.current && summary.previous && summary.previous.pulls > 0
      ? summary.previous
      : undefined
  const days = summary.days.length

  // The reviews tab, filtered to what a row stands for.
  const openReviews = (keys: CompactFilterKey[]) =>
    navigate({
      to: '/projects/$projectId/reviews',
      params: { projectId },
      search: {
        query: mapToQueryParam(new Map(keys.map((key) => [key, '']))),
      },
    })

  // An author is a VCS login; reviews queued before logins were kept have none.
  const showReviews = (login: string) =>
    openReviews([
      { c: 'providerPullRequestAuthor', o: 'equal', v: login },
      ...(repositoryId
        ? [{ c: 'repositoryId', o: 'equal', v: repositoryId }]
        : []),
    ])

  // Narrowing to a repository from its row brings the tiles back into view.
  const topRef = useRef<HTMLDivElement>(null)
  const selectRepository = (id: string) => {
    filters.setRepositoryId(id)
    topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const compared = t('Compared with the {count} days before').replace(
    '{count}',
    String(days),
  )
  const tile = (
    item: Omit<OverviewStatTile, 'change' | 'changeTitle'>,
    current: number | null | undefined,
    before: number | null | undefined,
  ): OverviewStatTile => ({
    ...item,
    change: previous ? percentChange(current, before) : undefined,
    changeTitle: compared,
  })

  const tiles: OverviewStatTile[] = [
    tile(
      {
        label: t('Pull requests'),
        value: totals.pulls.toLocaleString(),
        hint: t('{count} reviews').replace(
          '{count}',
          totals.reviews.toLocaleString(),
        ),
      },
      totals.pulls,
      previous?.pulls,
    ),
    tile(
      {
        label: t('Approved'),
        value: formatPercent(ratio(totals.approved, totals.pulls)),
        hint: t('{rate} first try').replace(
          '{rate}',
          formatPercent(ratio(totals.approvedFirst, totals.pulls)),
        ),
      },
      ratio(totals.approved, totals.pulls),
      previous && ratio(previous.approved, previous.pulls),
    ),
    tile(
      {
        label: t('Findings / PR'),
        value: formatNumber(ratio(totals.findings, totals.pulls)),
        hint: t('{count} critical').replace(
          '{count}',
          totals.critical.toLocaleString(),
        ),
        // Fewer findings per pull request means cleaner pull requests.
        invert: true,
      },
      ratio(totals.findings, totals.pulls),
      previous && ratio(previous.findings, previous.pulls),
    ),
    tile(
      {
        label: t('Useful'),
        value: formatPercent(usefulRate(totals)),
        hint: t('{count} fixed').replace(
          '{count}',
          totals.resolved.toLocaleString(),
        ),
      },
      usefulRate(totals),
      previous && usefulRate(previous),
    ),
    tile(
      {
        label: t('Review time'),
        value: totals.duration ? formatReviewTime(totals.duration) : '-',
        hint: t('median'),
        loading: summary.isLoading && !summary.current,
        invert: true,
      },
      totals.duration || null,
      previous?.duration || null,
    ),
  ]

  return (
    <div ref={topRef} className="scroll-mt-6 space-y-6">
      {isLoading ? (
        <div className="flex justify-center py-12">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      ) : authors.length === 0 ? (
        <EmptyState
          icon={GitPullRequest}
          variant="card"
          title={t('No completed reviews in this range')}
          description={t(
            'Performance shows how the authors of your pull requests fare in review once reviews complete. Try a longer range or another repository.',
          )}
        />
      ) : (
        <>
          <OverviewStatTiles items={tiles} />

          {summary.days.length > 0 && <ActivityChart days={summary.days} />}

          <Authors authors={authors} onShowReviews={showReviews} />

          {!repositoryId && summary.repositories.length > 1 && (
            <Repositories
              projectId={projectId}
              repositories={summary.repositories}
              onSelect={selectRepository}
              onShowReviews={(id) =>
                openReviews([{ c: 'repositoryId', o: 'equal', v: id }])
              }
            />
          )}

          {categoriesLoading ? (
            <div className="flex justify-center rounded-xl border border-border bg-card/50 py-8">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          ) : (
            categories.length > 0 && <Categories categories={categories} />
          )}

          <p className="text-[12px] leading-5 text-muted-foreground">
            {t(
              'Ranges are whole days in UTC, today included, and changes compare each figure with the same number of days before.',
            )}{' '}
            {t(
              'Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.',
            )}{' '}
            {t(
              'Useful findings is the share of settled findings that were fixed rather than dismissed; findings still open count for neither.',
            )}{' '}
            {t(
              'Review time is the median from a review being queued to it completing.',
            )}
          </p>
        </>
      )}
    </div>
  )
}

/**
 * How each author's pull requests fared in review, the busiest first.
 * Selecting one opens their reviews.
 */
function Authors({
  authors,
  onShowReviews,
}: {
  authors: ReviewAuthor[]
  onShowReviews: (login: string) => void
}) {
  const t = useT()
  const view = useBreakdown(authors, authorName)

  const actions = (login: string): RowActions =>
    login
      ? {
          tabs: [
            {
              icon: GitPullRequest,
              label: t('Reviews'),
              onSelect: () => onShowReviews(login),
            },
          ],
          copy: [
            {
              icon: Copy,
              label: t('Copy name'),
              onSelect: () => void copyToClipboard('Name', login),
            },
          ],
          links: [
            {
              icon: ExternalLink,
              label: t('Open profile on GitHub'),
              onSelect: () => openInNewTab(authorUrl(login)),
            },
          ],
        }
      : { copy: [], links: [] }

  return (
    <BreakdownCard
      title={t('Authors')}
      description={t(
        'How each author’s pull requests fared in review. Select an author to see their reviews.',
      )}
      count={t('{count} authors').replace(
        '{count}',
        authors.length.toLocaleString(),
      )}
      searchPlaceholder={t('Search authors...')}
      itemLabel={t('authors')}
      view={view}
    >
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'w-full')}>
              {t('Author')}
            </TableHead>
            <SortableHead
              view={view}
              sortKey="pulls"
              label={t('Pull requests')}
            />
            <SortableHead
              view={view}
              sortKey="approval"
              label={t('Approved')}
            />
            <SortableHead
              view={view}
              sortKey="findingsPerPull"
              label={t('Findings / PR')}
            />
            <SortableHead
              view={view}
              sortKey="severe"
              label={t('Critical / major')}
              className="text-end hidden @[60rem]:table-cell"
            />
            <SortableHead view={view} sortKey="fixRate" label={t('Fixed')} />
            <SortableHead view={view} sortKey="useful" label={t('Useful')} />
            <SortableHead view={view} sortKey="tier" label={t('Confidence')} />
            <SortableHead
              view={view}
              sortKey="lastReviewAt"
              label={t('Last review')}
              className="text-end hidden @[72rem]:table-cell"
            />
            <TableHead className="w-[52px] pe-4" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {view.rows.map((author) => {
            const rowActions = actions(author.author)
            return (
              <RowContextMenu
                key={author.author || 'unknown'}
                actions={rowActions}
              >
                <TableRow
                  className={cn(
                    'border-b border-border/50 last:border-b-0',
                    author.author
                      ? 'cursor-pointer transition-colors hover:bg-muted/30'
                      : 'hover:bg-transparent',
                  )}
                  onClick={(event) => {
                    if (!author.author) return
                    const target = event.target as HTMLElement
                    // Clicks in the row's menus bubble here from their portals.
                    if (!event.currentTarget.contains(target)) return
                    if (target.closest('a, button')) return
                    onShowReviews(author.author)
                  }}
                >
                  <TableCell className="max-w-0 min-w-[180px] py-2.5 ps-6 pe-3">
                    <ReviewAuthorLink author={author.author} />
                  </TableCell>
                  <FigureCells row={author} />
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    <div className="flex justify-end">
                      <ReviewTierBadge tier={author.tier} />
                    </div>
                  </TableCell>
                  <LastReviewCell date={author.lastReviewAt} />
                  <TableCell className="py-2.5 pe-4 ps-1 text-end">
                    {author.author && (
                      <RowActionsDropdown
                        actions={rowActions}
                        trigger={
                          <RowActionsMenuTrigger
                            aria-label={t('Author actions')}
                          />
                        }
                      />
                    )}
                  </TableCell>
                </TableRow>
              </RowContextMenu>
            )
          })}
          {view.rows.length === 0 && (
            <NoMatches message={t('No authors match your search')} />
          )}
        </TableBody>
      </Table>
    </BreakdownCard>
  )
}

/**
 * How each repository's pull requests fared, busiest first. Selecting one
 * narrows the tab to it.
 */
function Repositories({
  projectId,
  repositories,
  onSelect,
  onShowReviews,
}: {
  projectId: string
  repositories: ReviewRepositorySummary[]
  onSelect: (repositoryId: string) => void
  onShowReviews: (repositoryId: string) => void
}) {
  const t = useT()
  const view = useBreakdown(repositories, repositoryName)
  const providerOf = useRepositoryProviders(
    projectId,
    useRepositoryOptions(projectId, () => false),
  )

  return (
    <BreakdownCard
      title={t('Repositories')}
      description={t(
        'How each repository’s pull requests fared in review. Select a repository to narrow the tab to it.',
      )}
      count={t('{count} repositories').replace(
        '{count}',
        repositories.length.toLocaleString(),
      )}
      searchPlaceholder={t('Search repositories...')}
      itemLabel={t('repositories')}
      view={view}
    >
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'w-full')}>
              {t('Repository')}
            </TableHead>
            <SortableHead
              view={view}
              sortKey="pulls"
              label={t('Pull requests')}
            />
            <SortableHead
              view={view}
              sortKey="approval"
              label={t('Approved')}
            />
            <SortableHead
              view={view}
              sortKey="findingsPerPull"
              label={t('Findings / PR')}
            />
            <SortableHead
              view={view}
              sortKey="severe"
              label={t('Critical / major')}
              className="text-end hidden @[60rem]:table-cell"
            />
            <SortableHead view={view} sortKey="fixRate" label={t('Fixed')} />
            <SortableHead view={view} sortKey="useful" label={t('Useful')} />
            <SortableHead
              view={view}
              sortKey="lastReviewAt"
              label={t('Last review')}
              className="text-end hidden @[72rem]:table-cell"
            />
            <TableHead className="w-[52px] pe-4" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {view.rows.map((repository) => {
            // Reviews queued before names were kept name a repository by its ID.
            const named = !!repository.providerRepositoryName
            const name = repositoryName(repository)
            const provider = providerOf(repository.repositoryId)
            const actions: RowActions = {
              tabs: [
                {
                  icon: GitPullRequest,
                  label: t('Reviews'),
                  onSelect: () => onShowReviews(repository.repositoryId),
                },
              ],
              copy: [
                {
                  icon: Copy,
                  label: t('Copy ID'),
                  onSelect: () =>
                    void copyToClipboard('ID', repository.repositoryId),
                },
                ...(named
                  ? [
                      {
                        icon: Copy,
                        label: t('Copy name'),
                        onSelect: () => void copyToClipboard('Name', name),
                      },
                    ]
                  : []),
              ],
              links: [],
            }
            return (
              <RowContextMenu key={repository.repositoryId} actions={actions}>
                <TableRow
                  className="cursor-pointer border-b border-border/50 transition-colors last:border-b-0 hover:bg-muted/30"
                  onClick={(event) => {
                    const target = event.target as HTMLElement
                    // Clicks in the row's menus bubble here from their portals.
                    if (!event.currentTarget.contains(target)) return
                    if (target.closest('a, button')) return
                    onSelect(repository.repositoryId)
                  }}
                >
                  <TableCell className="max-w-0 min-w-[200px] py-2.5 ps-6 pe-3">
                    <span className="flex min-w-0 items-center gap-2">
                      {provider ? (
                        <VcsIcon
                          type={provider}
                          className="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                      ) : (
                        <FolderGit2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                      )}
                      <span
                        className={cn(
                          'truncate',
                          named
                            ? 'text-[13px] font-medium text-foreground'
                            : 'font-mono text-[12px] text-muted-foreground',
                        )}
                      >
                        {name}
                      </span>
                    </span>
                  </TableCell>
                  <FigureCells row={repository} />
                  <LastReviewCell date={repository.lastReviewAt} />
                  <TableCell className="py-2.5 pe-4 ps-1 text-end">
                    <RowActionsDropdown
                      actions={actions}
                      trigger={
                        <RowActionsMenuTrigger
                          aria-label={t('Repository actions')}
                        />
                      }
                    />
                  </TableCell>
                </TableRow>
              </RowContextMenu>
            )
          })}
          {view.rows.length === 0 && (
            <NoMatches message={t('No repositories match your search')} />
          )}
        </TableBody>
      </Table>
    </BreakdownCard>
  )
}

/**
 * A card that breaks the range down by author or by repository. Once its
 * rows outgrow the first page, a search takes the count's place and the
 * rows come a page at a time.
 */
function BreakdownCard({
  title,
  description,
  count,
  searchPlaceholder,
  itemLabel,
  view,
  children,
}: {
  title: string
  description: string
  count: string
  searchPlaceholder: string
  itemLabel: string
  view: Breakdown<unknown>
  children: ReactNode
}) {
  const cardRef = useRef<HTMLElement>(null)
  const titleId = useId()

  // A page turned at the bottom of a long page starts at the card's top.
  const turnPage = (page: number) => {
    view.setPage(page)
    const card = cardRef.current
    if (card && card.getBoundingClientRect().top < 0) {
      card.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  }

  return (
    <section
      ref={cardRef}
      aria-labelledby={titleId}
      className="@container scroll-mt-6 overflow-hidden rounded-xl border border-border bg-card/50"
    >
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 px-6 py-4">
        <div className="min-w-0">
          <h3
            id={titleId}
            className="text-[15px] font-semibold text-foreground"
          >
            {title}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {description}
          </p>
        </div>
        {view.paged ? (
          <div className="relative w-full @[40rem]:w-64">
            <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
              type="text"
              value={view.search}
              onChange={(event) => view.setSearch(event.target.value)}
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              className="h-9 w-full min-w-0 rounded-md border border-border bg-accent/50 ps-10 pe-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
          </div>
        ) : (
          <span className="text-[13px] text-muted-foreground">{count}</span>
        )}
      </div>
      <div className="border-t border-border" />
      {children}
      {view.paged && (
        <div className="border-t border-border px-6">
          <Pagination
            currentPage={view.page}
            totalItems={view.matches.length}
            pageSize={view.pageSize}
            pageSizeOptions={PAGE_SIZES}
            onPageChange={turnPage}
            onPageSizeChange={view.setPageSize}
            itemLabel={itemLabel}
            scrollToTopOnPageChange={false}
          />
        </div>
      )}
    </section>
  )
}

/** A column header that sorts its table, again to flip the direction. */
function SortableHead({
  view,
  sortKey,
  label,
  className = 'text-end',
}: {
  view: Breakdown<unknown>
  sortKey: SortKey
  label: string
  className?: string
}) {
  const active = view.sort.key === sortKey
  const Icon = view.sort.desc ? ArrowDown : ArrowUp
  return (
    <TableHead
      className={cn(AUTHOR_HEAD_CLASS, className)}
      aria-sort={
        active ? (view.sort.desc ? 'descending' : 'ascending') : undefined
      }
    >
      <button
        type="button"
        className={cn(
          'inline-flex items-center gap-1 uppercase tracking-wider hover:text-foreground',
          active && 'text-foreground',
        )}
        onClick={() => view.setSort(sortKey)}
      >
        {label}
        {active && <Icon className="h-3 w-3" />}
      </button>
    </TableHead>
  )
}

/**
 * The figures an author's and a repository's rows share, from pull requests
 * to findings fixed and found useful.
 */
function FigureCells({ row }: { row: Figures }) {
  const t = useT()
  return (
    <>
      <TableCell className={AUTHOR_CELL_CLASS}>
        {row.pulls.toLocaleString()}
        <span className={SUBLINE_CLASS}>
          {t('{count} reviews').replace(
            '{count}',
            row.reviews.toLocaleString(),
          )}
        </span>
      </TableCell>
      <TableCell className={AUTHOR_CELL_CLASS}>
        {formatPercent(ratio(row.approved, row.pulls))}
        <span className={SUBLINE_CLASS}>
          {t('{rate} first try').replace(
            '{rate}',
            formatPercent(ratio(row.approvedFirst, row.pulls)),
          )}
        </span>
      </TableCell>
      <TableCell className={AUTHOR_CELL_CLASS}>
        {formatNumber(ratio(row.findings, row.pulls))}
      </TableCell>
      <TableCell
        className={cn(AUTHOR_CELL_CLASS, 'hidden @[60rem]:table-cell')}
      >
        <span
          className={cn(
            row.critical > 0
              ? 'text-red-600 dark:text-red-400'
              : 'text-muted-foreground',
          )}
        >
          {row.critical}
        </span>
        <span className="text-muted-foreground"> / </span>
        {row.major}
      </TableCell>
      <TableCell className={AUTHOR_CELL_CLASS}>
        {formatPercent(ratio(row.resolved, row.findings))}
      </TableCell>
      <TableCell className={AUTHOR_CELL_CLASS}>
        {formatPercent(usefulRate(row))}
      </TableCell>
    </>
  )
}

function LastReviewCell({ date }: { date: string }) {
  return (
    <TableCell className={cn(AUTHOR_CELL_CLASS, 'hidden @[72rem]:table-cell')}>
      {date && (
        <DateTooltip
          date={date}
          className="text-[12px] text-muted-foreground font-mono"
        />
      )}
    </TableCell>
  )
}

/** The only row of a table whose search matches nothing. */
function NoMatches({ message }: { message: string }) {
  return (
    <TableRow className="hover:bg-transparent">
      <TableCell
        colSpan={10}
        className="py-10 text-center text-[13px] text-muted-foreground"
      >
        {message}
      </TableCell>
    </TableRow>
  )
}

/**
 * How the findings of each category landed, most dismissed first: the signal for
 * which kinds of findings the reviewer should flag less, or a learning should
 * narrow.
 */
function Categories({ categories }: { categories: ReviewCategory[] }) {
  const t = useT()
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2 px-6 py-4">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Findings by category')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Categories that are often dismissed are candidates for a learning that tells the reviewer to flag them less.',
            )}
          </p>
        </div>
        <ul className="flex items-center gap-4 text-[12px] text-muted-foreground">
          <OutcomeLegend className="bg-emerald-600" label={t('Fixed')} />
          <OutcomeLegend
            className="bg-muted-foreground/30"
            label={t('Still open')}
          />
          <OutcomeLegend className="bg-red-500" label={t('Dismissed')} />
        </ul>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Category')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[40%]')}>
              {t('Outcome')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Findings')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Useful')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Dismissed')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {categories.map((category) => {
            const settled = category.resolved + category.dismissed
            const open = Math.max(0, category.findings - settled)
            const noisy =
              settled >= NOISY_MIN_SETTLED &&
              category.dismissed / settled >= NOISY_SHARE
            const outcome = t(
              '{resolved} fixed, {open} open, {dismissed} dismissed',
            )
              .replace('{resolved}', category.resolved.toLocaleString())
              .replace('{open}', open.toLocaleString())
              .replace('{dismissed}', category.dismissed.toLocaleString())
            return (
              <TableRow
                key={category.category}
                className="border-b border-border/50 last:border-b-0 hover:bg-transparent"
              >
                <TableCell className="py-3 ps-6 pe-4">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-[13px] font-medium text-foreground capitalize">
                      {category.category}
                    </span>
                    {noisy && (
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Badge
                            variant="warning"
                            className="shrink-0 text-[10px]"
                          >
                            {t('Noisy')}
                          </Badge>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-[260px]">
                          {t(
                            'Most settled findings in this category were dismissed. A learning can tell the reviewer to flag fewer of them.',
                          )}
                        </TooltipContent>
                      </Tooltip>
                    )}
                  </span>
                </TableCell>
                <TableCell className="px-4 py-3">
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <div
                        className="flex h-2 w-full gap-0.5"
                        role="img"
                        aria-label={outcome}
                      >
                        <OutcomeSegment
                          className="bg-emerald-600"
                          count={category.resolved}
                        />
                        <OutcomeSegment
                          className="bg-muted-foreground/30"
                          count={open}
                        />
                        <OutcomeSegment
                          className="bg-red-500"
                          count={category.dismissed}
                        />
                      </div>
                    </TooltipTrigger>
                    <TooltipContent>{outcome}</TooltipContent>
                  </Tooltip>
                </TableCell>
                <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                  {category.findings.toLocaleString()}
                </TableCell>
                <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                  {formatPercent(usefulRate(category))}
                </TableCell>
                <TableCell className="py-3 ps-4 pe-6 text-end text-[13px] tabular-nums">
                  {category.dismissed.toLocaleString()}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

/** One outcome's share of a category's findings; nothing when it has none. */
function OutcomeSegment({
  className,
  count,
}: {
  className: string
  count: number
}) {
  if (count <= 0) return null
  return (
    <div
      className={cn('h-full rounded-full', className)}
      style={{ flexGrow: count, flexBasis: 0 }}
    />
  )
}

function OutcomeLegend({
  className,
  label,
}: {
  className: string
  label: string
}) {
  return (
    <li className="flex items-center gap-1.5">
      <span className={cn('h-2 w-2 rounded-full', className)} aria-hidden />
      {label}
    </li>
  )
}
