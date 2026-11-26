import { useMemo, useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import {
  ArrowDown,
  ArrowUp,
  Check,
  ChevronDown,
  Copy,
  ExternalLink,
  FolderGit2,
  GitPullRequest,
  Loader2,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
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
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { VcsIcon } from '@/lib/vcs/providers'
import { OverviewStatTiles } from '../../shared/OverviewStatTiles'
import { authorUrl, ReviewAuthorLink, ReviewTierBadge } from './ReviewBadges'
import { RowContextMenu, type RowActions } from './RowMenus'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** The authors table packs nine columns, so it pads less and never wraps. */
const AUTHOR_HEAD_CLASS =
  'px-3 py-3 whitespace-nowrap text-[12px] font-semibold text-muted-foreground uppercase tracking-wider first:ps-4 last:pe-4'

const AUTHOR_CELL_CLASS =
  'px-3 py-2.5 text-end text-[13px] tabular-nums whitespace-nowrap last:pe-4'

const SUBLINE_CLASS = 'block text-[11px] text-muted-foreground'

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

/** The toolbar's repository filter: a searchable list with each provider's icon. */
export function PerformanceRepositorySelect({
  projectId,
  filters,
}: {
  projectId: string
  filters: PerformanceFilters
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  // A repository row stores GitHub's ID only; its name comes from GitHub, as
  // on the repositories tab, whose cache this shares.
  const { repositories } = useReviewRepositories(
    projectId,
    0,
    REPOSITORY_FILTER_LIMIT,
  )
  const { data: installationsData } = useVcsInstallations(projectId, 0, 100)
  const providers = useMemo(
    () =>
      new Map(
        (installationsData?.installations ?? []).map((installation) => [
          installation.$id,
          installation.provider,
        ]),
      ),
    [installationsData],
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

  const options = repositories.map((repository, index) => {
    const provider = names[index]?.data
    return {
      id: repository.$id,
      provider: providers.get(repository.installationId) ?? '',
      name: provider
        ? `${provider.organization}/${provider.name}`
        : repository.providerRepositoryId,
    }
  })
  const selected = options.find((option) => option.id === filters.repositoryId)

  const choose = (repositoryId: string) => {
    filters.setRepositoryId(repositoryId)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-label={t('Repository')}
          className="h-9 w-[260px] justify-between gap-2 bg-background px-3 text-[13px] font-normal"
        >
          <span className="flex min-w-0 items-center gap-2">
            {selected ? (
              <VcsIcon type={selected.provider} className="h-4 w-4 shrink-0" />
            ) : (
              <FolderGit2 className="h-4 w-4 shrink-0 text-muted-foreground" />
            )}
            <span className="truncate">
              {selected ? selected.name : t('All repositories')}
            </span>
          </span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] min-w-[300px] p-0"
        align="start"
      >
        <Command>
          <CommandInput
            placeholder={t('Search repositories...')}
            className="h-9 text-[13px]"
          />
          <CommandList className="max-h-[280px]">
            <CommandEmpty className="py-6 text-center text-[12px] text-muted-foreground">
              {t('No repositories found')}
            </CommandEmpty>
            <CommandGroup>
              <CommandItem
                value={ALL_REPOSITORIES}
                keywords={[t('All repositories')]}
                onSelect={() => choose('')}
                className="text-[13px]"
              >
                <FolderGit2 className="h-4 w-4" />
                <span className="truncate">{t('All repositories')}</span>
                {!selected && <Check className="ms-auto h-4 w-4" />}
              </CommandItem>
              {options.map((option) => (
                <CommandItem
                  key={option.id}
                  value={option.id}
                  keywords={[option.name]}
                  onSelect={() => choose(option.id)}
                  className="text-[13px]"
                >
                  <VcsIcon type={option.provider} className="h-4 w-4" />
                  <span className="truncate">{option.name}</span>
                  {selected?.id === option.id && (
                    <Check className="ms-auto h-4 w-4" />
                  )}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
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
              onSelect: () => openInNewTab(authorUrl(login)),
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
          <OverviewStatTiles
            items={[
              {
                label: t('Pull requests'),
                value: totals.pulls.toLocaleString(),
                hint: t('{count} authors').replace(
                  '{count}',
                  authors.length.toLocaleString(),
                ),
              },
              {
                label: t('Approval rate'),
                value: formatPercent(ratio(totals.approved, totals.pulls)),
                hint: t('{rate} first try').replace(
                  '{rate}',
                  formatPercent(ratio(totals.approvedFirst, totals.pulls)),
                ),
              },
              {
                label: t('Findings / PR'),
                value: formatNumber(ratio(totals.findings, totals.pulls)),
                hint: t('{count} critical').replace(
                  '{count}',
                  totals.critical.toLocaleString(),
                ),
              },
              {
                label: t('Fix rate'),
                value: formatPercent(ratio(totals.resolved, totals.findings)),
                hint: t('{count} fixed').replace(
                  '{count}',
                  totals.resolved.toLocaleString(),
                ),
              },
              {
                label: t('Useful'),
                value: formatPercent(usefulRate(totals)),
                hint: t('{count} dismissed').replace(
                  '{count}',
                  totals.dismissed.toLocaleString(),
                ),
              },
            ]}
          />

          <div className="@container rounded-lg border border-border bg-card overflow-hidden">
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
                </TableRow>
              </TableHeader>
              <TableBody>
                {sorted.map((author) => (
                  <RowContextMenu
                    key={author.author || 'unknown'}
                    actions={authorActions(author.author)}
                  >
                    <TableRow className="border-b border-border/50 last:border-b-0">
                      <TableCell className="max-w-0 min-w-[180px] py-2.5 ps-4 pe-3">
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
                              ? 'text-destructive'
                              : 'text-muted-foreground',
                          )}
                        >
                          {author.critical}
                        </span>
                        <span className="text-muted-foreground"> / </span>
                        {author.major}
                      </TableCell>
                      <TableCell className={AUTHOR_CELL_CLASS}>
                        {formatPercent(ratio(author.resolved, author.findings))}
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
    <div className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
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
        <div className="flex items-center gap-4 text-[12px] text-muted-foreground">
          <OutcomeLegend className="bg-emerald-500" label={t('Fixed')} />
          <OutcomeLegend
            className="bg-muted-foreground/30"
            label={t('Still open')}
          />
          <OutcomeLegend className="bg-red-500" label={t('Dismissed')} />
        </div>
      </div>
      <div className="rounded-lg border border-border bg-card overflow-hidden">
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
              const noisy =
                settled >= NOISY_MIN_SETTLED &&
                category.dismissed / settled >= NOISY_SHARE
              const share = (count: number) =>
                category.findings > 0
                  ? `${(count / category.findings) * 100}%`
                  : '0%'
              return (
                <TableRow
                  key={category.category}
                  className="border-b border-border/50 last:border-b-0"
                >
                  <TableCell className="px-4 py-3 text-[13px] font-medium text-foreground capitalize">
                    {category.category}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div
                      className="flex h-1.5 w-full overflow-hidden rounded-full bg-muted-foreground/30"
                      role="img"
                      aria-label={t('{resolved} fixed, {dismissed} dismissed')
                        .replace('{resolved}', String(category.resolved))
                        .replace('{dismissed}', String(category.dismissed))}
                    >
                      <div
                        className="h-full bg-emerald-500"
                        style={{ width: share(category.resolved) }}
                      />
                      <div className="h-full flex-1" />
                      <div
                        className="h-full bg-red-500"
                        style={{ width: share(category.dismissed) }}
                      />
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                    {category.findings.toLocaleString()}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                    {formatPercent(usefulRate(category))}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                    {noisy ? (
                      <Badge
                        variant="error"
                        className="text-[11px] tabular-nums"
                      >
                        {category.dismissed.toLocaleString()}
                      </Badge>
                    ) : (
                      category.dismissed.toLocaleString()
                    )}
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

function OutcomeLegend({
  className,
  label,
}: {
  className: string
  label: string
}) {
  return (
    <span className="flex items-center gap-1.5">
      <span className={cn('h-2 w-2 rounded-full', className)} />
      {label}
    </span>
  )
}
