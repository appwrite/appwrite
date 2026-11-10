import { useEffect, useState } from 'react'
import { Copy, ExternalLink, Lightbulb, Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type { ReviewLearning } from '@/lib/appwrite/reviews'
import {
  useDeleteReviewLearning,
  useReviewLearnings,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { VcsIcon } from '@/lib/vcs/providers'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

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
}

/**
 * The learnings tab: what people asked the reviewer to remember in a
 * conversation on GitHub. Later reviews and answers of the repository follow
 * them, so a wrong one is deleted here.
 */
export function Learnings({ projectId, canWrite }: LearningsProps) {
  const t = useT()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const { learnings, total, isLoading, isPlaceholderData } = useReviewLearnings(
    projectId,
    page - 1,
    pageSize,
  )
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
    return (
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
      <div className="rounded-lg border border-border bg-card overflow-hidden">
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
              const name = repositoryName(learning)
              return (
                <TableRow
                  key={learning.$id}
                  className="border-b border-border/50"
                >
                  <TableCell className="px-4 py-3 max-w-[480px]">
                    <p className="whitespace-pre-wrap break-words text-[13px] text-foreground">
                      {learning.body}
                    </p>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex min-w-0 items-center gap-2">
                      <VcsIcon
                        type="github"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                      />
                      <span
                        className={
                          name
                            ? 'truncate text-[13px] text-foreground'
                            : 'truncate font-mono text-[12px] text-muted-foreground'
                        }
                      >
                        {name ?? learning.repositoryId}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {learning.author ? (
                      <a
                        href={`https://github.com/${encodeURIComponent(learning.author)}`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-[13px] text-foreground hover:underline"
                      >
                        @{learning.author}
                      </a>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <DateTooltip
                      date={learning.$createdAt}
                      className="text-[12px] text-muted-foreground font-mono"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger
                          aria-label={t('Learning actions')}
                        />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-56">
                        <DropdownMenuItem
                          onClick={() =>
                            void copyToClipboard('ID', learning.$id)
                          }
                        >
                          <MenuItemContent icon={Copy}>
                            {t('Copy ID')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        {learning.sourceUrl && (
                          <DropdownMenuItem asChild>
                            <a
                              href={learning.sourceUrl}
                              target="_blank"
                              rel="noreferrer"
                            >
                              <MenuItemContent icon={ExternalLink}>
                                {t('Open conversation on GitHub')}
                              </MenuItemContent>
                            </a>
                          </DropdownMenuItem>
                        )}
                        {canWrite && (
                          <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() =>
                                openDialogAfterOverlayCloses(() =>
                                  setLearningToDelete(learning),
                                )
                              }
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
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
