import { useEffect, useMemo, useState } from 'react'
import {
  Copy,
  ExternalLink,
  FolderGit2,
  Lightbulb,
  ListFilter,
  Loader2,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type { ReviewLearning } from '@/lib/appwrite/reviews'
import {
  useDeleteReviewLearning,
  useReviewLearnings,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { VcsIcon } from '@/lib/vcs/providers'
import { ReviewAuthorLink } from './ReviewBadges'
import { useRepositoryOptions } from './RepositorySelect'
import { RowActionsDropdown, RowContextMenu, type RowActions } from './RowMenus'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider first:ps-6 last:pe-6'

/**
 * `owner/name` of the GitHub comment a learning was asked in. A learning only
 * stores the repository's ID, and the comment link already names it.
 */
function repositoryName(learning: ReviewLearning): string | null {
  try {
    const url = new URL(learning.sourceUrl)
    if (url.hostname !== 'github.com') return null
    const [owner, name] = url.pathname.split('/').filter(Boolean)
    return owner && name ? `${owner}/${name}` : null
  } catch {
    return null
  }
}

type LearningsProps = {
  projectId: string
  canWrite: boolean
  /** Lists only this repository's learnings; empty for all of them. */
  repositoryId: string
  onRepositoryChange: (repositoryId: string) => void
}

/**
 * The learnings tab: what people asked the reviewer to remember in a
 * conversation on their Git provider. Later reviews and answers of the
 * repository follow them, so a wrong one is deleted here.
 */
export function Learnings({
  projectId,
  canWrite,
  repositoryId,
  onRepositoryChange,
}: LearningsProps) {
  const t = useT()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  useEffect(() => setPage(1), [repositoryId])
  const { learnings, total, isLoading, isPlaceholderData } = useReviewLearnings(
    projectId,
    page - 1,
    pageSize,
    repositoryId,
  )

  // Name the repositories on this page, with their provider's icon. The
  // comment a GitHub learning was asked in names its repository already, so
  // only the others wait on the provider.
  const unnamed = useMemo(
    () =>
      new Set(
        learnings
          .filter((learning) => !repositoryName(learning))
          .map((learning) => learning.repositoryId),
      ),
    [learnings],
  )
  const options = useRepositoryOptions(projectId, (repository) =>
    unnamed.has(repository.$id),
  )
  const repositories = new Map(options.map((option) => [option.id, option]))
  const deleteMutation = useDeleteReviewLearning(projectId)
  const [learningToDelete, setLearningToDelete] =
    useState<ReviewLearning | null>(null)

  // Step back when the last learning of the last page is deleted.
  useEffect(() => {
    if (
      !isLoading &&
      !isPlaceholderData &&
      learnings.length === 0 &&
      total > 0 &&
      page > 1
    ) {
      setPage(Math.max(1, Math.ceil(total / pageSize)))
    }
  }, [isLoading, isPlaceholderData, learnings.length, total, pageSize, page])

  const handleDelete = () => {
    if (!learningToDelete) return
    const learningId = learningToDelete.$id
    deleteMutation.mutate(learningId, {
      onSuccess: () => {
        closeDialogBeforeOverlayUnmount(() => setLearningToDelete(null))
        toast.success(t('Learning has been deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete learning'))
      },
    })
  }

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (total === 0) {
    return repositoryId ? (
      <EmptyState
        icon={Lightbulb}
        variant="card"
        hasFilters
        title={t('No learnings for this repository')}
        description={t(
          'Ask the reviewer to remember a preference in one of its pull requests, and it shows up here.',
        )}
        action={
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onRepositoryChange('')}
          >
            {t('Show all repositories')}
          </Button>
        }
      />
    ) : (
      <EmptyState
        icon={Lightbulb}
        variant="card"
        title={t('No learnings yet')}
        description={t(
          'When someone asks the reviewer to remember a preference in a pull request conversation, it shows up here. Later reviews of the repository follow it.',
        )}
      />
    )
  }

  return (
    <div className="space-y-3">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-1 px-6 py-4">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Learnings')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                'What the reviewer was asked to remember in pull request conversations. Later reviews and answers of the repository follow them.',
              )}
            </p>
          </div>
          <span className="text-[13px] text-muted-foreground">
            {t('{count} learnings').replace('{count}', total.toLocaleString())}
          </span>
        </div>
        <div className="border-t border-border" />
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={HEAD_CLASS}>{t('Learning')}</TableHead>
              <TableHead className={HEAD_CLASS}>{t('Repository')}</TableHead>
              <TableHead className={HEAD_CLASS}>{t('Asked by')}</TableHead>
              <TableHead className={`${HEAD_CLASS} text-end`}>
                {t('Created')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} w-[56px]`} />
            </TableRow>
          </TableHeader>
          <TableBody>
            {learnings.map((learning) => {
              const repository = repositories.get(learning.repositoryId)
              const name =
                (repository?.named ? repository.name : null) ??
                repositoryName(learning)
              const actions: RowActions = {
                copy: [
                  {
                    icon: Copy,
                    label: t('Copy ID'),
                    onSelect: () => void copyToClipboard('ID', learning.$id),
                  },
                  {
                    icon: Copy,
                    label: t('Copy text'),
                    onSelect: () => void copyToClipboard('Text', learning.body),
                  },
                ],
                links: [
                  ...(learning.repositoryId !== repositoryId
                    ? [
                        {
                          icon: ListFilter,
                          label: t('Show this repository only'),
                          onSelect: () =>
                            onRepositoryChange(learning.repositoryId),
                        },
                      ]
                    : []),
                  ...(learning.sourceUrl
                    ? [
                        {
                          icon: ExternalLink,
                          label: t('Open conversation'),
                          onSelect: () => openInNewTab(learning.sourceUrl),
                        },
                      ]
                    : []),
                ],
                remove: canWrite
                  ? [
                      {
                        icon: Trash2,
                        label: t('Delete'),
                        onSelect: () =>
                          openDialogAfterOverlayCloses(() =>
                            setLearningToDelete(learning),
                          ),
                      },
                    ]
                  : [],
              }
              return (
                <RowContextMenu key={learning.$id} actions={actions}>
                  <TableRow className="border-b border-border/50 last:border-b-0 hover:bg-transparent">
                    <TableCell className="max-w-[480px] py-3 ps-6 pe-4">
                      <p className="whitespace-pre-wrap break-words border-s-2 border-border ps-3 text-[13px] leading-5 text-foreground">
                        {learning.body}
                      </p>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <button
                        type="button"
                        onClick={() =>
                          onRepositoryChange(learning.repositoryId)
                        }
                        disabled={learning.repositoryId === repositoryId}
                        title={
                          learning.repositoryId === repositoryId
                            ? undefined
                            : t('Show this repository only')
                        }
                        className="group flex min-w-0 max-w-full items-center gap-2 text-start disabled:cursor-default"
                      >
                        {repository?.provider ? (
                          <VcsIcon
                            type={repository.provider}
                            className="h-4 w-4 shrink-0 text-muted-foreground"
                          />
                        ) : (
                          <FolderGit2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                        )}
                        <span
                          className={cn(
                            'truncate group-enabled:group-hover:underline',
                            name
                              ? 'text-[13px] text-foreground'
                              : 'font-mono text-[12px] text-muted-foreground',
                          )}
                        >
                          {name ?? learning.repositoryId}
                        </span>
                      </button>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <ReviewAuthorLink author={learning.author} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DateTooltip
                        date={learning.$createdAt}
                        className="text-[12px] text-muted-foreground font-mono"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <RowActionsDropdown
                        actions={actions}
                        trigger={
                          <RowActionsMenuTrigger
                            aria-label={t('Learning actions')}
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

      <Pagination
        currentPage={page}
        totalItems={total}
        pageSize={pageSize}
        pageSizeOptions={[10, 25, 50, 100]}
        onPageChange={setPage}
        onPageSizeChange={(size) => {
          setPageSize(size)
          setPage(1)
        }}
        itemLabel={t('learnings')}
      />

      <ConfirmActionDialog
        open={!!learningToDelete}
        onOpenChange={(open) => {
          if (!open) setLearningToDelete(null)
        }}
        title={t('Delete learning')}
        description={t(
          'Later reviews and answers will no longer follow this learning. This action cannot be undone.',
        )}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
