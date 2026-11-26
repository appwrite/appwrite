import { useMemo, useRef, useState } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import {
  ArrowDown,
  ArrowUp,
  Copy,
  ExternalLink,
  FolderGit2,
  GitPullRequest,
  Loader2,
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
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type {
  ReviewAuthor,
  ReviewAuthorRange,
  ReviewCategory,
  ReviewPeriod,
  ReviewRepositorySummary,
} from '@/lib/appwrite/reviews'
import {
  useReviewAuthors,
  useReviewCategories,
  useReviewSummary,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { mapToQueryParam, type CompactFilterKey } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { VcsIcon } from '@/lib/vcs/providers'
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
import { useRepositoryOptions } from './RepositorySelect'
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

type SortKey =
  | 'pulls'
  | 'approval'
  | 'findingsPerPull'
  | 'severe'
  | 'fixRate'
  | 'useful'
  | 'tier'
  | 'lastReviewAt'

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

function sortValue(author: ReviewAuthor, key: SortKey): number | string {
  switch (key) {
    case 'pulls':
      return author.pulls
    case 'approval':
      return ratio(author.approved, author.pulls) ?? -1
    case 'findingsPerPull':
      return ratio(author.findings, author.pulls) ?? -1
    case 'severe':
      return author.critical + author.major
    case 'fixRate':
      return ratio(author.resolved, author.findings) ?? -1
    case 'useful':
      return usefulRate(author) ?? -1
    case 'tier':
      return TIERS.indexOf(author.tier)
    case 'lastReviewAt':
      return author.lastReviewAt
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
  const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({
    key: 'pulls',
    desc: true,
  })

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

  const sorted = useMemo(() => {
    const direction = sort.desc ? -1 : 1
    return [...authors].sort((a, b) => {
      const left = sortValue(a, sort.key)
      const right = sortValue(b, sort.key)
      if (left === right) return b.pulls - a.pulls
      return (left < right ? -1 : 1) * direction
    })
  }, [authors, sort])

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

  const authorActions = (login: string): RowActions =>
    login
      ? {
          tabs: [
            {
              icon: GitPullRequest,
              label: t('Reviews'),
              onSelect: () => showReviews(login),
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

  const header = (key: SortKey, label: string, className = 'text-end') => {
    const active = sort.key === key
    const Icon = sort.desc ? ArrowDown : ArrowUp
    return (
      <TableHead className={cn(AUTHOR_HEAD_CLASS, className)}>
        <button
          type="button"
          className={cn(
            'inline-flex items-center gap-1 uppercase tracking-wider hover:text-foreground',
            active && 'text-foreground',
          )}
          onClick={() =>
            setSort((current) => ({
              key,
              desc: current.key === key ? !current.desc : true,
            }))
          }
        >
          {label}
          {active && <Icon className="h-3 w-3" />}
        </button>
      </TableHead>
    )
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

          <div className="@container overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-1 px-6 py-4">
              <div className="min-w-0">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Authors')}
                </h3>
                <p className="mt-2 text-[13px] text-muted-foreground">
                  {t(
                    'How each author’s pull requests fared in review. Select an author to see their reviews.',
                  )}
                </p>
              </div>
              <span className="text-[13px] text-muted-foreground">
                {t('{count} authors').replace(
                  '{count}',
                  authors.length.toLocaleString(),
                )}
              </span>
            </div>
            <div className="border-t border-border" />
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={cn(AUTHOR_HEAD_CLASS, 'w-full')}>
                    {t('Author')}
                  </TableHead>
                  {header('pulls', t('Pull requests'))}
                  {header('approval', t('Approved'))}
                  {header('findingsPerPull', t('Findings / PR'))}
                  {header(
                    'severe',
                    t('Critical / major'),
                    'text-end hidden @[60rem]:table-cell',
                  )}
                  {header('fixRate', t('Fixed'))}
                  {header('useful', t('Useful'))}
                  {header('tier', t('Confidence'))}
                  {header(
                    'lastReviewAt',
                    t('Last review'),
                    'text-end hidden @[72rem]:table-cell',
                  )}
                  <TableHead className="w-[52px] pe-4" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {sorted.map((author) => {
                  const actions = authorActions(author.author)
                  return (
                    <RowContextMenu
                      key={author.author || 'unknown'}
                      actions={actions}
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
                          showReviews(author.author)
                        }}
                      >
                        <TableCell className="max-w-0 min-w-[180px] py-2.5 ps-6 pe-3">
                          <ReviewAuthorLink author={author.author} />
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          {author.pulls.toLocaleString()}
                          <span className={SUBLINE_CLASS}>
                            {t('{count} reviews').replace(
                              '{count}',
                              author.reviews.toLocaleString(),
                            )}
                          </span>
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          {formatPercent(ratio(author.approved, author.pulls))}
                          <span className={SUBLINE_CLASS}>
                            {t('{rate} first try').replace(
                              '{rate}',
                              formatPercent(
                                ratio(author.approvedFirst, author.pulls),
                              ),
                            )}
                          </span>
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          {formatNumber(ratio(author.findings, author.pulls))}
                        </TableCell>
                        <TableCell
                          className={cn(
                            AUTHOR_CELL_CLASS,
                            'hidden @[60rem]:table-cell',
                          )}
                        >
                          <span
                            className={cn(
                              author.critical > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-muted-foreground',
                            )}
                          >
                            {author.critical}
                          </span>
                          <span className="text-muted-foreground"> / </span>
                          {author.major}
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          {formatPercent(
                            ratio(author.resolved, author.findings),
                          )}
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          {formatPercent(usefulRate(author))}
                        </TableCell>
                        <TableCell className={AUTHOR_CELL_CLASS}>
                          <div className="flex justify-end">
                            <ReviewTierBadge tier={author.tier} />
                          </div>
                        </TableCell>
                        <TableCell
                          className={cn(
                            AUTHOR_CELL_CLASS,
                            'hidden @[72rem]:table-cell',
                          )}
                        >
                          {author.lastReviewAt && (
                            <DateTooltip
                              date={author.lastReviewAt}
                              className="text-[12px] text-muted-foreground font-mono"
                            />
                          )}
                        </TableCell>
                        <TableCell className="py-2.5 pe-4 ps-1 text-end">
                          {author.author && (
                            <RowActionsDropdown
                              actions={actions}
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
              </TableBody>
            </Table>
          </div>

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
  // Names come with the summary; providers from the repositories still reviewed.
  const options = useRepositoryOptions(projectId, () => false)
  const providers = new Map(
    options.map((option) => [option.id, option.provider]),
  )

  return (
    <div className="@container overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-1 px-6 py-4">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Repositories')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'How each repository’s pull requests fared in review. Select a repository to narrow the tab to it.',
            )}
          </p>
        </div>
        <span className="text-[13px] text-muted-foreground">
          {t('{count} repositories').replace(
            '{count}',
            repositories.length.toLocaleString(),
          )}
        </span>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'w-full')}>
              {t('Repository')}
            </TableHead>
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'text-end')}>
              {t('Pull requests')}
            </TableHead>
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'text-end')}>
              {t('Approved')}
            </TableHead>
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'text-end')}>
              {t('Findings / PR')}
            </TableHead>
            <TableHead
              className={cn(
                AUTHOR_HEAD_CLASS,
                'hidden text-end @[60rem]:table-cell',
              )}
            >
              {t('Critical / major')}
            </TableHead>
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'text-end')}>
              {t('Fixed')}
            </TableHead>
            <TableHead className={cn(AUTHOR_HEAD_CLASS, 'text-end')}>
              {t('Useful')}
            </TableHead>
            <TableHead
              className={cn(
                AUTHOR_HEAD_CLASS,
                'hidden text-end @[72rem]:table-cell',
              )}
            >
              {t('Last review')}
            </TableHead>
            <TableHead className="w-[52px] pe-4" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {repositories.map((repository) => {
            const name =
              repository.providerRepositoryOwner &&
              repository.providerRepositoryName
                ? `${repository.providerRepositoryOwner}/${repository.providerRepositoryName}`
                : repository.providerRepositoryName
            const provider = providers.get(repository.repositoryId) ?? ''
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
                ...(name
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
                          name
                            ? 'text-[13px] font-medium text-foreground'
                            : 'font-mono text-[12px] text-muted-foreground',
                        )}
                      >
                        {name || repository.repositoryId}
                      </span>
                    </span>
                  </TableCell>
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    {repository.pulls.toLocaleString()}
                    <span className={SUBLINE_CLASS}>
                      {t('{count} reviews').replace(
                        '{count}',
                        repository.reviews.toLocaleString(),
                      )}
                    </span>
                  </TableCell>
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    {formatPercent(
                      ratio(repository.approved, repository.pulls),
                    )}
                    <span className={SUBLINE_CLASS}>
                      {t('{rate} first try').replace(
                        '{rate}',
                        formatPercent(
                          ratio(repository.approvedFirst, repository.pulls),
                        ),
                      )}
                    </span>
                  </TableCell>
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    {formatNumber(ratio(repository.findings, repository.pulls))}
                  </TableCell>
                  <TableCell
                    className={cn(
                      AUTHOR_CELL_CLASS,
                      'hidden @[60rem]:table-cell',
                    )}
                  >
                    <span
                      className={cn(
                        repository.critical > 0
                          ? 'text-red-600 dark:text-red-400'
                          : 'text-muted-foreground',
                      )}
                    >
                      {repository.critical}
                    </span>
                    <span className="text-muted-foreground"> / </span>
                    {repository.major}
                  </TableCell>
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    {formatPercent(
                      ratio(repository.resolved, repository.findings),
                    )}
                  </TableCell>
                  <TableCell className={AUTHOR_CELL_CLASS}>
                    {formatPercent(usefulRate(repository))}
                  </TableCell>
                  <TableCell
                    className={cn(
                      AUTHOR_CELL_CLASS,
                      'hidden @[72rem]:table-cell',
                    )}
                  >
                    {repository.lastReviewAt && (
                      <DateTooltip
                        date={repository.lastReviewAt}
                        className="text-[12px] text-muted-foreground font-mono"
                      />
                    )}
                  </TableCell>
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
        </TableBody>
      </Table>
    </div>
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
