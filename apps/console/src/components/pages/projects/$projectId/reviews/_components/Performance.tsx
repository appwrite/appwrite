import { useMemo, useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import {
  ArrowDown,
  ArrowUp,
  BadgeCheck,
  Bug,
  GitPullRequest,
  Loader2,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
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
import type { ReviewAuthor, ReviewAuthorRange } from '@/lib/appwrite/reviews'
import {
  fetchRepository,
  useReviewAuthors,
  useReviewRepositories,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ReviewTierBadge } from './ReviewBadges'

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

/** Tiers worst first, so a tier sorts by its index. */
const TIERS = ['F', 'D', 'C', 'B', 'A', 'S']

type SortKey =
  | 'pulls'
  | 'approval'
  | 'approvalFirst'
  | 'findingsPerPull'
  | 'severe'
  | 'fixRate'
  | 'tier'
  | 'lastReviewAt'

function ratio(part: number, whole: number): number | null {
  return whole > 0 ? part / whole : null
}

function formatPercent(value: number | null): string {
  return value === null ? '-' : `${Math.round(value * 100)}%`
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

function AuthorCell({ author }: { author: string }) {
  const t = useT()
  if (!author) {
    return (
      <span className="text-[13px] text-muted-foreground">
        {t('Unknown author')}
      </span>
    )
  }
  return (
    <a
      href={`https://github.com/${encodeURIComponent(author)}`}
      target="_blank"
      rel="noreferrer"
      className="flex min-w-0 items-center gap-2 hover:underline"
    >
      <Avatar className="h-6 w-6">
        <AvatarImage
          src={`https://github.com/${encodeURIComponent(author)}.png?size=48`}
          alt=""
        />
        <AvatarFallback className="text-[10px]">
          {author.slice(0, 2).toUpperCase()}
        </AvatarFallback>
      </Avatar>
      <span className="truncate text-[13px] font-medium text-foreground">
        @{author}
      </span>
    </a>
  )
}

/**
 * The performance tab: how the authors of the project's pull requests fare in
 * review. Who ships pull requests that are approved, how much the reviewer
 * finds in them, and how much of it they fix.
 */
export function Performance({ projectId }: { projectId: string }) {
  const t = useT()
  const [range, setRange] = useState<ReviewAuthorRange>('30d')
  const [repositoryId, setRepositoryId] = useState('')
  const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({
    key: 'pulls',
    desc: true,
  })

  const { authors, isLoading, isFetching } = useReviewAuthors(
    projectId,
    range,
    repositoryId,
  )

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
        }),
        {
          pulls: 0,
          approved: 0,
          approvedFirst: 0,
          findings: 0,
          critical: 0,
          major: 0,
          resolved: 0,
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Select
          value={repositoryId || ALL_REPOSITORIES}
          onValueChange={(value) =>
            setRepositoryId(value === ALL_REPOSITORIES ? '' : value)
          }
        >
          <SelectTrigger
            size="sm"
            className="w-[260px] border-border bg-background text-[13px]"
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

        <div className="flex items-center gap-3">
          {isFetching && !isLoading && (
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          )}
          <ToggleGroup
            type="single"
            variant="outline"
            value={range}
            onValueChange={(next) => {
              if (next) setRange(next as ReviewAuthorRange)
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
        </div>
      </div>

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
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                  {header('tier', t('Confidence'))}
                  {header('lastReviewAt', t('Last review'))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {sorted.map((author) => (
                  <TableRow
                    key={author.author || 'unknown'}
                    className="border-b border-border/50"
                  >
                    <TableCell className="px-4 py-3 max-w-[240px]">
                      <AuthorCell author={author.author} />
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
                      {formatPercent(ratio(author.approvedFirst, author.pulls))}
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
                ))}
              </TableBody>
            </Table>
          </div>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Counts completed reviews in the range. Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.',
            )}
          </p>
        </>
      )}
    </div>
  )
}
