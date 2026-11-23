import { useState } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  LayoutList,
  Link2,
  Square,
  Trash2,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import {
  fetchFunctionExecution,
  fetchSiteLog,
  useDeleteFunctionExecution,
  useDeleteSiteLog,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export type ExecutionRowContextMenuVariant = 'function' | 'site'

interface ExecutionRowContextMenuProps {
  variant: ExecutionRowContextMenuVariant
  projectId: string
  resourceId: string
  execution: Pick<Models.Execution, '$id'>
  onOpenDetails: () => void
  onDeleted?: (executionId: string) => void
  children: React.ReactNode
}

function executionPermalink(
  variant: ExecutionRowContextMenuVariant,
  projectId: string,
  resourceId: string,
  executionId: string,
) {
  const path =
    variant === 'function'
      ? `/projects/${projectId}/functions/${resourceId}/executions?executionId=${encodeURIComponent(executionId)}`
      : `/projects/${projectId}/sites/${resourceId}/logs?executionId=${encodeURIComponent(executionId)}`
  return buildConsoleUrl(path)
}

export function ExecutionRowContextMenu({
  variant,
  projectId,
  resourceId,
  execution,
  onOpenDetails,
  onDeleted,
  children,
}: ExecutionRowContextMenuProps) {
  const t = useT()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteFunctionMutation = useDeleteFunctionExecution(
    variant === 'function' ? projectId : null,
    variant === 'function' ? resourceId : null,
  )
  const deleteSiteLogMutation = useDeleteSiteLog(
    variant === 'site' ? projectId : null,
    variant === 'site' ? resourceId : null,
  )
  const deleteMutation =
    variant === 'function' ? deleteFunctionMutation : deleteSiteLogMutation

  if (!execution?.$id || !projectId || !resourceId) {
    return <>{children}</>
  }

  const executionHref = executionPermalink(
    variant,
    projectId,
    resourceId,
    execution.$id,
  )

  const fetchExecution = () =>
    variant === 'function'
      ? fetchFunctionExecution(projectId, resourceId, execution.$id)
      : fetchSiteLog(projectId, resourceId, execution.$id)

  const deleteTitle =
    variant === 'function' ? 'Delete execution' : 'Delete log'
  const deleteDescription =
    variant === 'function'
      ? t(
          'Are you sure you want to delete this execution? This action cannot be undone.',
        )
      : t(
          'Are you sure you want to delete this log? This action cannot be undone.',
        )

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(execution.$id, {
      onSuccess: () => {
        toast.success(
          variant === 'function' ? t('Execution deleted') : t('Log deleted'),
        )
        onDeleted?.(execution.$id)
      },
      onError: (error: Error) => {
        toast.error(
          getErrorMessage(error) ||
            (variant === 'function'
              ? t('Failed to delete execution')
              : t('Failed to delete log')),
        )
      },
    })
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => onOpenDetails()}>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', execution.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', executionHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => void copyResourceAsJson(fetchExecution)}
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(executionHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(executionHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
            }
          >
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmActionDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title={deleteTitle}
        description={deleteDescription}
        confirmLabel="Delete"
        confirmVariant="destructive"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
