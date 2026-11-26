import type { ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  ChartColumn,
  Copy,
  ExternalLink,
  GitPullRequest,
  Lock,
} from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type {
  ReviewRepository,
  ReviewRepositorySummary,
} from '@/lib/appwrite/reviews'
import { useT } from '@/lib/i18n/translate'
import { mapToQueryParam } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import {
  getKnownVcsProvider,
  getProviderRepositoryUrl,
  VcsIcon,
} from '@/lib/vcs/providers'
import { ReviewToggle } from './ReviewToggle'
import { RowActionsDropdown, RowContextMenu, type RowActions } from './RowMenus'
import { StrengthSelect } from './StrengthSelect'

const HEAD_CLASS =
  'px-4 py-3 whitespace-nowrap text-[12px] font-semibold text-muted-foreground uppercase tracking-wider first:ps-6'

const STAT_CELL_CLASS =
  'px-4 py-3 text-end text-[13px] tabular-nums whitespace-nowrap'

const SUBLINE_CLASS = 'block text-[11px] text-muted-foreground'

/** One row of the table: a provider's repository and, when reviewed, its row. */
export type RepositoryRowData = {
  installationId: string
  /** The installation's provider, such as `github` or `origin`. */
  provider: string
  providerRepositoryId: string
  /** `owner/name`, or the provider's ID while the name is loading. */
  name: string
  private?: boolean
  /** Shown under the name when the project has several installations. */
  organization?: string
  repository: ReviewRepository | undefined
}

/** Each reviewed repository's last 30 days, by repository ID. */
export type RepositoryActivity = {
  byRepository: Map<string, ReviewRepositorySummary>
  /** Still loading: activity cells hold their place. */
  loading: boolean
  /** Not known, such as from an API that predates it: cells stay empty. */
  unavailable: boolean
}

type RepositoriesTableProps = {
  projectId: string
  rows: RepositoryRowData[]
  canWrite: boolean
  activity: RepositoryActivity
  /** Sits above the table inside the card. */
  header?: ReactNode
}

function ratio(part: number, whole: number): number | null {
  return whole > 0 ? part / whole : null
}

function formatPercent(value: number | null): string {
  return value === null ? '-' : `${Math.round(value * 100)}%`
}

/**
 * Repositories with a switch that turns code review on or off, and how each
 * reviewed one fared over the last 30 days. Both the list of reviewed
 * repositories and the list of an installation's repositories use it.
 */
export function RepositoriesTable({
  projectId,
  rows,
  canWrite,
  activity,
  header,
}: RepositoriesTableProps) {
  const t = useT()

  return (
    <div className="@container overflow-hidden rounded-xl border border-border bg-card/50">
      {header}
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={cn(HEAD_CLASS, 'w-full')}>
              {t('Repository')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Pull requests')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end @[48rem]:table-cell')}
            >
              {t('Approved')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end @[56rem]:table-cell')}
            >
              {t('Findings / PR')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end @[64rem]:table-cell')}
            >
              {t('Last review')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end @[72rem]:table-cell')}
            >
              {t('Reviewed since')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[140px] text-end')}>
              {t('Strength')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[72px] text-end')}>
              {t('Review')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[56px]')} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <RepositoryRow
              key={row.providerRepositoryId}
              projectId={projectId}
              row={row}
              canWrite={canWrite}
              summary={
                row.repository
                  ? activity.byRepository.get(row.repository.$id)
                  : undefined
              }
              activityLoading={activity.loading}
              activityUnavailable={activity.unavailable}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function RepositoryRow({
  projectId,
  row,
  canWrite,
  summary,
  activityLoading,
  activityUnavailable,
}: {
  projectId: string
  row: RepositoryRowData
  canWrite: boolean
  /** The last 30 days; none when the repository had no completed review. */
  summary: ReviewRepositorySummary | undefined
  activityLoading: boolean
  activityUnavailable: boolean
}) {
  const t = useT()
  const navigate = useNavigate()
  const resolved = row.name !== row.providerRepositoryId
  const [organization, ...repositoryName] = row.name.split('/')
  const url = resolved
    ? getProviderRepositoryUrl({
        provider: row.provider,
        organization,
        repositoryName: repositoryName.join('/'),
      })
    : null
  const providerLabel = getKnownVcsProvider(row.provider)?.label ?? ''
  const repositoryId = row.repository?.$id

  const showReviews = (id: string) =>
    navigate({
      to: '/projects/$projectId/reviews',
      params: { projectId },
      search: {
        query: mapToQueryParam(
          new Map([[{ c: 'repositoryId', o: 'equal', v: id }, '']]),
        ),
      },
    })
  const showPerformance = (id: string) =>
    navigate({
      to: '/projects/$projectId/reviews/performance',
      params: { projectId },
      search: { repositoryId: id },
    })

  const actions: RowActions = {
    tabs: repositoryId
      ? [
          {
            icon: GitPullRequest,
            label: t('Reviews'),
            onSelect: () => showReviews(repositoryId),
          },
          {
            icon: ChartColumn,
            label: t('Performance'),
            onSelect: () => showPerformance(repositoryId),
          },
        ]
      : [],
    copy: [
      ...(row.repository
        ? [
            {
              icon: Copy,
              label: t('Copy ID'),
              onSelect: () => void copyToClipboard('ID', row.repository!.$id),
            },
          ]
        : []),
      ...(resolved
        ? [
            {
              icon: Copy,
              label: t('Copy name'),
              onSelect: () => void copyToClipboard('Name', row.name),
            },
          ]
        : []),
    ],
    links: [
      ...(url && providerLabel
        ? [
            {
              icon: ExternalLink,
              label: t('Open on {provider}').replace(
                '{provider}',
                providerLabel,
              ),
              onSelect: () => openInNewTab(url),
            },
          ]
        : []),
    ],
  }

  // Activity is only kept for reviewed repositories.
  const stat = (content: ReactNode) =>
    !repositoryId || activityUnavailable ? (
      <span className="text-muted-foreground">-</span>
    ) : activityLoading && !summary ? (
      <span className="inline-block h-3.5 w-8 animate-pulse rounded bg-muted align-middle" />
    ) : (
      content
    )

  return (
    <RowContextMenu actions={actions}>
      <TableRow
        className={cn(
          'border-b border-border/50 last:border-b-0',
          repositoryId
            ? 'cursor-pointer transition-colors hover:bg-muted/30'
            : 'hover:bg-transparent',
        )}
        onClick={(event) => {
          if (!repositoryId) return
          const target = event.target as HTMLElement
          // Clicks in the strength list, menus and dialogs bubble here
          // through React from their portals.
          if (!event.currentTarget.contains(target)) return
          if (target.closest('a, button, [role="switch"], [role="combobox"]')) {
            return
          }
          showReviews(repositoryId)
        }}
      >
        <TableCell className="max-w-0 min-w-[200px] py-3 ps-6 pe-4">
          <div className="flex min-w-0 items-center gap-2">
            <VcsIcon
              type={row.provider}
              className="h-4 w-4 shrink-0 text-muted-foreground"
            />
            <div className="min-w-0">
              <div className="flex min-w-0 items-center gap-1.5">
                <span className="truncate text-[13px] font-medium text-foreground">
                  {row.name}
                </span>
                {row.private && (
                  <Lock
                    className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    aria-label={t('Private')}
                  />
                )}
              </div>
              {row.organization && (
                <div className="truncate text-[12px] text-muted-foreground">
                  {row.organization}
                </div>
              )}
            </div>
          </div>
        </TableCell>
        <TableCell className={STAT_CELL_CLASS}>
          {stat(
            summary ? (
              <>
                {summary.pulls.toLocaleString()}
                <span className={SUBLINE_CLASS}>
                  {t('{count} reviews').replace(
                    '{count}',
                    summary.reviews.toLocaleString(),
                  )}
                </span>
              </>
            ) : (
              <span className="text-muted-foreground">0</span>
            ),
          )}
        </TableCell>
        <TableCell
          className={cn(STAT_CELL_CLASS, 'hidden @[48rem]:table-cell')}
        >
          {stat(
            summary ? (
              <>
                {formatPercent(ratio(summary.approved, summary.pulls))}
                <span className={SUBLINE_CLASS}>
                  {t('{rate} first try').replace(
                    '{rate}',
                    formatPercent(ratio(summary.approvedFirst, summary.pulls)),
                  )}
                </span>
              </>
            ) : (
              <span className="text-muted-foreground">-</span>
            ),
          )}
        </TableCell>
        <TableCell
          className={cn(STAT_CELL_CLASS, 'hidden @[56rem]:table-cell')}
        >
          {stat(
            summary ? (
              <>
                {(ratio(summary.findings, summary.pulls) ?? 0).toFixed(1)}
                <span className={SUBLINE_CLASS}>
                  <span
                    className={cn(
                      summary.critical > 0 && 'text-red-600 dark:text-red-400',
                    )}
                  >
                    {t('{count} critical').replace(
                      '{count}',
                      summary.critical.toLocaleString(),
                    )}
                  </span>
                </span>
              </>
            ) : (
              <span className="text-muted-foreground">-</span>
            ),
          )}
        </TableCell>
        <TableCell
          className={cn(STAT_CELL_CLASS, 'hidden @[64rem]:table-cell')}
        >
          {stat(
            summary?.lastReviewAt ? (
              <DateTooltip
                date={summary.lastReviewAt}
                className="text-[12px] text-muted-foreground font-mono"
              />
            ) : (
              <span className="text-muted-foreground">-</span>
            ),
          )}
        </TableCell>
        <TableCell
          className={cn(STAT_CELL_CLASS, 'hidden @[72rem]:table-cell')}
        >
          {row.repository ? (
            <DateTooltip
              date={row.repository.$createdAt}
              className="text-[12px] text-muted-foreground font-mono"
            />
          ) : (
            <span className="text-[12px] text-muted-foreground">-</span>
          )}
        </TableCell>
        <TableCell className="px-4 py-3 text-end">
          {row.repository ? (
            <StrengthSelect
              projectId={projectId}
              repository={row.repository}
              name={row.name}
              disabled={!canWrite}
            />
          ) : (
            <span className="text-[12px] text-muted-foreground">-</span>
          )}
        </TableCell>
        <TableCell className="px-4 py-3 text-end">
          <ReviewToggle
            projectId={projectId}
            installationId={row.installationId}
            providerRepositoryId={row.providerRepositoryId}
            repository={row.repository}
            name={row.name}
            disabled={!canWrite}
          />
        </TableCell>
        <TableCell className="px-4 py-3 text-end">
          <RowActionsDropdown
            actions={actions}
            trigger={
              <RowActionsMenuTrigger
                aria-label={`${t('Actions for')} ${row.name}`}
              />
            }
          />
        </TableCell>
      </TableRow>
    </RowContextMenu>
  )
}
