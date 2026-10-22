import { useState } from 'react'
import {
  Copy,
  Trash2,
  Link2,
  ExternalLink,
  Square,
  FileJson,
  LayoutList,
  Shield,
  KeyRound} from 'lucide-react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger} from '@/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { fetchFile, removeCachedFile, Dependencies } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow} from '@/lib/utils/context-menu'
import {
  ContextMenuIcon,
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

export type FileContextMenuFile = {
  id: string
  name?: string | null
  pending?: boolean
}

function useFileActions(
  projectId: string,
  bucketId: string,
  file: FileContextMenuFile,
) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.storage.deleteFile({ bucketId, fileId: file.id })
    },
    onSuccess: async () => {
      removeCachedFile(queryClient, projectId, bucketId, file.id)
      await queryClient.refetchQueries({
        queryKey: Dependencies.FILES,
      })
      toast.success(t('File deleted'))
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId, bucketId },
        search: (prev: Record<string, unknown>) => {
          if (prev.file !== file.id) return prev
          const next = { ...prev }
          delete next.file
          delete next.filePanel
          return next
        },
        replace: true,
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete file'))
    }})

  const navigateToTab = (tab: 'overview' | 'permissions' | 'tokens') => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId },
      search: (prev: Record<string, unknown>) => {
        const next: Record<string, unknown> = { ...prev, file: file.id }
        if (tab === 'overview') {
          delete next.filePanel
        } else {
          next.filePanel = tab
        }
        return next
      }})
  }

  const fileHref = buildConsoleUrl(
    `/projects/${projectId}/storage/${bucketId}?file=${encodeURIComponent(file.id)}`,
  )

  return {
    hasName: !!file.name,
    fileHref,
    navigateToTab,
    handleDeleteClick: () =>
      openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true)),
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation}
}

function FileDeleteDialog({
  open,
  onOpenChange,
  deleteMutation}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  deleteMutation: ReturnType<typeof useFileActions>['deleteMutation']
}) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Delete file')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Are you sure you want to delete this file? This action cannot be undone.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={deleteMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={() => {
              closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
              deleteMutation.mutate()
            }}
            disabled={deleteMutation.isPending}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

interface FileContextMenuProps {
  projectId: string
  bucketId: string
  file: FileContextMenuFile
  children: React.ReactNode
}

export function FileContextMenu({
  projectId,
  bucketId,
  file,
  children}: FileContextMenuProps) {
  const {
    hasName,
    fileHref,
    navigateToTab,
    handleDeleteClick,
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation} = useFileActions(projectId, bucketId, file)
  const t = useT()

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => navigateToTab('overview')}>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('permissions')}>
            <ContextMenuIcon icon={Shield} />
            {t('Permissions')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('tokens')}>
            <ContextMenuIcon icon={KeyRound} />
            {t('Tokens')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', file.id)}>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', file.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', fileHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchFile(projectId, bucketId, file.id),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(fileHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(fileHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <FileDeleteDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        deleteMutation={deleteMutation}
      />
    </>
  )
}

type FileRowActionsMenuProps = {
  projectId: string
  bucketId: string
  file: FileContextMenuFile
}

/** Always-visible ⋯ menu for file rows in the bucket files table. */
export function FileRowActionsMenu({
  projectId,
  bucketId,
  file}: FileRowActionsMenuProps) {
  const {
    hasName,
    fileHref,
    navigateToTab,
    handleDeleteClick,
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation} = useFileActions(projectId, bucketId, file)
  const t = useT()

  if (file.pending) {
    return null
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <RowActionsMenuTrigger onClick={(e) => e.stopPropagation()} />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-56">
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('overview')
            }}
          >
            <MenuItemContent icon={LayoutList}>{t('Overview')}</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('permissions')
            }}
          >
            <MenuItemContent icon={Shield}>{t('Permissions')}</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('tokens')
            }}
          >
            <MenuItemContent icon={KeyRound}>{t('Tokens')}</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuSub>
            <DropdownMenuSubTrigger>
              <MenuItemIcon icon={Copy} />
              {t('Copy')}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  copyToClipboard('ID', file.id)
                }}
              >
                <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
              </DropdownMenuItem>
              {hasName && (
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation()
                    copyToClipboard('Name', file.name)
                  }}
                >
                  <MenuItemContent icon={Copy}>{t('Copy name')}</MenuItemContent>
                </DropdownMenuItem>
              )}
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  copyToClipboard('Link', fileHref)
                }}
              >
                <MenuItemContent icon={Link2}>{t('Copy link')}</MenuItemContent>
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  void copyResourceAsJson(() =>
                    fetchFile(projectId, bucketId, file.id),
                  )
                }}
              >
                <MenuItemContent icon={FileJson}>{t('Copy as JSON')}</MenuItemContent>
              </DropdownMenuItem>
            </DropdownMenuSubContent>
          </DropdownMenuSub>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              openInNewTab(fileHref)
            }}
          >
            <MenuItemContent icon={ExternalLink}>{t('Open in new tab')}</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              openInNewWindow(fileHref)
            }}
          >
            <MenuItemContent icon={Square}>{t('Open in new window')}</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              handleDeleteClick()
            }}
          >
            <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <FileDeleteDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        deleteMutation={deleteMutation}
      />
    </>
  )
}
