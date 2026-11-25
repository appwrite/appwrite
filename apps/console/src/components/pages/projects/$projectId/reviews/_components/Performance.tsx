import { useMemo, useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import {
  ArrowDown,
  ArrowUp,
  Copy,
  ExternalLink,
  BadgeCheck,
  Bug,
  GitPullRequest,
  Loader2,
  Target,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type {
  ReviewAuthor,
  ReviewAuthorRange,
  ReviewCategory,
} from '@/lib/appwrite/reviews'
import {
  fetchRepository,
  useReviewAuthors,
  useReviewCategories,
  useReviewRepositories,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { ReviewAuthorLink, ReviewTierBadge } from './ReviewBadges'
import { RowContextMenu, type RowActions } from './RowMenus'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

const TOGGLE_ITEM_CLASS =
  'h-full min-w-[3rem] px-3 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground'

const RANGES: { value: ReviewAuthorRange; label: string }[] = [
  { value: '7d', label: '7d' },
  { value: '30d', label: '30d' },
  { value: '90d', label: '90d' },
]

/** Repositories offered in the filter: the first page the project reviews. */
const REPOSITORY_FILTER_LIMIT = 100

const ALL_REPOSITORIES = 'all'

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
  | 'approvalFirst'
  | 'findingsPerPull'
  | 'severe'
  | 'fixRate'
  | 'useful'
  | 'tier'
  | 'lastReviewAt'

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

function sortValue(author: ReviewAuthor, key: SortKey): number | string {
  switch (key) {
    case 'pulls':
      return author.pulls
    case 'approval':
      return ratio(author.approved, author.pulls) ?? -1
    case 'approvalFirst':
      return ratio(author.approvedFirst, author.pulls) ?? -1
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

function StatCard({
  label,
  value,
  hint,
  icon: Icon,
}: {
  label: string
  value: string
  hint: string
  icon: LucideIcon
}) {
  return (
    <div className="flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3">
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted/50">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <div className="min-w-0">
        <p className="text-[12px] text-muted-foreground">{label}</p>
        <p className="text-[18px] font-semibold text-foreground">{value}</p>
        <p className="truncate text-[11px] text-muted-foreground">{hint}</p>
      </div>
    </div>
  )
}

/**
 * The performance tab: how the authors of the project's pull requests fare in
 * review. Who ships pull requests that are approved, how much the reviewer
 * finds in them, and how much of it they fix.
 */
/** Which repository and range the Performance tab covers. */
export function usePerformanceFilters() {
  const [range, setRange] = useState<ReviewAuthorRange>('30d')
  const [repositoryId, setRepositoryId] = useState('')
  return { range, setRange, repositoryId, setRepositoryId }
}

export type PerformanceFilters = ReturnType<typeof usePerformanceFilters>

/** The toolbar's repository filter. */
export function PerformanceRepositorySelect({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  // A repository row stores GitHub's ID only; its name comes from GitHub, as
  // on the repositories tab, whose cache this shares.
  const { repositories } = useReviewRepositories(
    projectId,
    0,
    REPOSITORY_FILTER_LIMIT,
  )
  const names = useQueries({
    queries: repositories.map((repository) => ({
      queryKey: [
        'vcs',
        'repository',
        projectId,
        repository.installationId,
        repository.providerRepositoryId,
      ],
      queryFn: () =>
        fetchRepository(
          projectId,
          repository.installationId,
          repository.providerRepositoryId,
        ),
      staleTime: DEFAULT_STALE_TIME,
      retry: false,
    })),
  })

  return (
    <Select
      value={filters.repositoryId || ALL_REPOSITORIES}
      onValueChange={(value) =>
        filters.setRepositoryId(value === ALL_REPOSITORIES ? '' : value)
      }
    >
      <SelectTrigger
        className="h-9 w-[260px] border-border bg-background text-[13px]"
        aria-label={t('Repository')}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent align="start">
        <SelectItem value={ALL_REPOSITORIES} className="text-[13px]">
          {t('All repositories')}
        </SelectItem>
        {repositories.map((repository, index) => {
          const provider = names[index]?.data
          return (
            <SelectItem
              key={repository.$id}
              value={repository.$id}
              className="text-[13px]"
            >
              {provider
                ? `${provider.organization}/${provider.name}`
                : repository.providerRepositoryId}
            </SelectItem>
          )
        })}
      </SelectContent>
    </Select>
  )
}

/** The toolbar's date range, with a spinner while a new range loads. */
export function PerformanceRangeToggle({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const { isLoading, isFetching } = useReviewAuthors(
    projectId,
    filters.range,
    filters.repositoryId,
  )

  return (
    <>
      {isFetching && !isLoading && (
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

export function Performance({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const { range, repositoryId } = filters
  const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({
    key: 'pulls',
    desc: true,
  })

  // An author is a GitHub login; reviews queued before logins were kept have none.
  const authorActions = (login: string): RowActions =>
    login
      ? {
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
              onSelect: () =>
                openInNewTab(`https://github.com/${encodeURIComponent(login)}`),
            },
          ],
        }
      : { copy: [], links: [] }

  const { authors, isLoading } = useReviewAuthors(
    projectId,
    range,
    repositoryId,
  )
  const { categories } = useReviewCategories(projectId, range, repositoryId)

  const totals = useMemo(
    () =>
      authors.reduce(
        (sum, author) => ({
          pulls: sum.pulls + author.pulls,
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

  const sorted = useMemo(() => {
    const direction = sort.desc ? -1 : 1
    return [...authors].sort((a, b) => {
      const left = sortValue(a, sort.key)
      const right = sortValue(b, sort.key)
      if (left === right) return b.pulls - a.pulls
      return (left < right ? -1 : 1) * direction
    })
  }, [authors, sort])

  const header = (key: SortKey, label: string, className = 'text-end') => {
    const active = sort.key === key
    const Icon = sort.desc ? ArrowDown : ArrowUp
    return (
      <TableHead className={cn(HEAD_CLASS, className)}>
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

  return (
    <div className="space-y-4">
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
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <StatCard
              icon={GitPullRequest}
              label={t('Pull requests reviewed')}
              value={String(totals.pulls)}
              hint={t('{count} authors').replace(
                '{count}',
                String(authors.length),
              )}
            />
            <StatCard
              icon={BadgeCheck}
              label={t('Approval rate')}
              value={formatPercent(ratio(totals.approved, totals.pulls))}
              hint={t('{rate} approved by the first review').replace(
                '{rate}',
                formatPercent(ratio(totals.approvedFirst, totals.pulls)),
              )}
            />
            <StatCard
              icon={Bug}
              label={t('Findings per pull request')}
              value={formatNumber(ratio(totals.findings, totals.pulls))}
              hint={t('{critical} critical, {major} major')
                .replace('{critical}', String(totals.critical))
                .replace('{major}', String(totals.major))}
            />
            <StatCard
              icon={Wrench}
              label={t('Fix rate')}
              value={formatPercent(ratio(totals.resolved, totals.findings))}
              hint={t('{count} findings fixed by a later commit').replace(
                '{count}',
                String(totals.resolved),
              )}
            />
            <StatCard
              icon={Target}
              label={t('Useful findings')}
              value={formatPercent(usefulRate(totals))}
              hint={t('{resolved} fixed, {dismissed} dismissed')
                .replace('{resolved}', String(totals.resolved))
                .replace('{dismissed}', String(totals.dismissed))}
            />
          </div>

          <div className="rounded-lg border border-border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={HEAD_CLASS}>{t('Author')}</TableHead>
                  {header('pulls', t('Pull requests'))}
                  {header('approval', t('Approved'))}
                  {header('approvalFirst', t('First try'))}
                  {header('findingsPerPull', t('Findings / PR'))}
                  {header('severe', t('Critical / major'))}
                  {header('fixRate', t('Fixed'))}
                  {header('useful', t('Useful'))}
                  {header('tier', t('Confidence'))}
                  {header('lastReviewAt', t('Last review'))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {sorted.map((author) => (
                  <RowContextMenu
                    key={author.author || 'unknown'}
                    actions={authorActions(author.author)}
                  >
                    <TableRow className="border-b border-border/50">
                      <TableCell className="px-4 py-3 max-w-[240px]">
                        <ReviewAuthorLink author={author.author} />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {author.pulls}
                        <span className="ms-1 text-[12px] text-muted-foreground">
                          ({author.reviews} {t('reviews')})
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {formatPercent(ratio(author.approved, author.pulls))}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {formatPercent(
                          ratio(author.approvedFirst, author.pulls),
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {formatNumber(ratio(author.findings, author.pulls))}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        <span
                          className={cn(
                            author.critical > 0 && 'text-destructive',
                          )}
                        >
                          {author.critical}
                        </span>
                        <span className="text-muted-foreground"> / </span>
                        {author.major}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {formatPercent(ratio(author.resolved, author.findings))}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                        {formatPercent(usefulRate(author))}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <div className="flex justify-end">
                          <ReviewTierBadge tier={author.tier} />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        {author.lastReviewAt && (
                          <DateTooltip
                            date={author.lastReviewAt}
                            className="text-[12px] text-muted-foreground font-mono"
                          />
                        )}
                      </TableCell>
                    </TableRow>
                  </RowContextMenu>
                ))}
              </TableBody>
            </Table>
          </div>
          {categories.length > 0 && <Categories categories={categories} />}
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Counts completed reviews in the range. Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.',
            )}{' '}
            {t(
              'Useful findings is the share of settled findings that were fixed rather than dismissed; findings still open count for neither.',
            )}
          </p>
        </>
      )}
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
    <div className="space-y-2">
      <div>
        <h3 className="text-[14px] font-semibold text-foreground">
          {t('Findings by category')}
        </h3>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Categories that are often dismissed are candidates for a learning that tells the reviewer to flag them less.',
          )}
        </p>
      </div>
      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={HEAD_CLASS}>{t('Category')}</TableHead>
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
              const noisy =
                settled >= NOISY_MIN_SETTLED &&
                category.dismissed / settled >= NOISY_SHARE
              return (
                <TableRow
                  key={category.category}
                  className="border-b border-border/50"
                >
                  <TableCell className="px-4 py-3 text-[13px] font-medium text-foreground">
                    {category.category}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                    {category.findings}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                    {formatPercent(usefulRate(category))}
                  </TableCell>
                  <TableCell
                    className={cn(
                      'px-4 py-3 text-end text-[13px] tabular-nums',
                      noisy && 'text-destructive',
                    )}
                  >
                    {category.dismissed}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
