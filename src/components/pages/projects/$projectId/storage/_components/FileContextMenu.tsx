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
  KeyRound,
} from 'lucide-react'
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { fetchFile } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  ContextMenuIcon,
  MenuItemContent,
} from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'

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
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.storage.deleteFile({ bucketId, fileId: file.id })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['files', 'project', projectId, 'bucket', bucketId],
      })
      toast.success('File deleted')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete file')
    },
  })

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
      },
    })
  }

  const fileHref = buildConsoleUrl(
    `/projects/${projectId}/storage/${bucketId}?file=${encodeURIComponent(file.id)}`,
  )

  return {
    hasName: !!file.name,
    fileHref,
    navigateToTab,
    handleDeleteClick: () => setDeleteDialogOpen(true),
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation,
  }
}

function FileDeleteDialog({
  open,
  onOpenChange,
  deleteMutation,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  deleteMutation: ReturnType<typeof useFileActions>['deleteMutation']
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
        data-analytics-surface="file_delete_dialog"
        data-analytics-resource="file"
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>Delete file</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Are you sure you want to delete this file? This action cannot be
            undone.
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={deleteMutation.isPending}
            data-analytics-id="file_delete_cancel"
            data-analytics-surface="file_delete_dialog"
            data-analytics-resource="file"
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={() => deleteMutation.mutate()}
            disabled={deleteMutation.isPending}
            data-analytics-id="file_delete_confirm"
            data-analytics-surface="file_delete_dialog"
            data-analytics-resource="file"
          >
            Delete
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
  children,
}: FileContextMenuProps) {
  const {
    hasName,
    fileHref,
    navigateToTab,
    handleDeleteClick,
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation,
  } = useFileActions(projectId, bucketId, file)

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
          data-analytics-surface="file_context_menu"
          data-analytics-resource="file"
        >
          <ContextMenuItem
            onSelect={() => navigateToTab('overview')}
            data-analytics-id="file_context_tab"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
            data-analytics-prop-tab="overview"
          >
            <ContextMenuIcon icon={LayoutList} />
            Overview
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => navigateToTab('permissions')}
            data-analytics-id="file_context_tab"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
            data-analytics-prop-tab="permissions"
          >
            <ContextMenuIcon icon={Shield} />
            Permissions
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => navigateToTab('tokens')}
            data-analytics-id="file_context_tab"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
            data-analytics-prop-tab="tokens"
          >
            <ContextMenuIcon icon={KeyRound} />
            Tokens
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', file.id)}
                data-analytics-id="file_context_copy_id"
                data-analytics-surface="file_context_menu"
                data-analytics-resource="file"
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', file.name)}
                  data-analytics-id="file_context_copy_name"
                  data-analytics-surface="file_context_menu"
                  data-analytics-resource="file"
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', fileHref)}
                data-analytics-id="file_context_copy_link"
                data-analytics-surface="file_context_menu"
                data-analytics-resource="file"
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchFile(projectId, bucketId, file.id),
                  )
                }
                data-analytics-id="file_context_copy_json"
                data-analytics-surface="file_context_menu"
                data-analytics-resource="file"
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(fileHref)}
            data-analytics-id="file_context_open_new_tab"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
          >
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(fileHref)}
            data-analytics-id="file_context_open_new_window"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
          >
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
            data-analytics-id="file_context_delete_open"
            data-analytics-surface="file_context_menu"
            data-analytics-resource="file"
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
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
  file,
}: FileRowActionsMenuProps) {
  const {
    hasName,
    fileHref,
    navigateToTab,
    handleDeleteClick,
    deleteDialogOpen,
    setDeleteDialogOpen,
    deleteMutation,
  } = useFileActions(projectId, bucketId, file)

  if (file.pending) {
    return null
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <RowActionsMenuTrigger onClick={(e) => e.stopPropagation()} />
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-56"
          data-analytics-surface="file_row_actions"
          data-analytics-resource="file"
        >
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('overview')
            }}
            data-analytics-id="file_row_action_tab"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
            data-analytics-prop-tab="overview"
          >
            <MenuItemContent icon={LayoutList}>Overview</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('permissions')
            }}
            data-analytics-id="file_row_action_tab"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
            data-analytics-prop-tab="permissions"
          >
            <MenuItemContent icon={Shield}>Permissions</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              navigateToTab('tokens')
            }}
            data-analytics-id="file_row_action_tab"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
            data-analytics-prop-tab="tokens"
          >
            <MenuItemContent icon={KeyRound}>Tokens</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuSub>
            <DropdownMenuSubTrigger>
              <MenuItemContent icon={Copy}>Copy</MenuItemContent>
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  copyToClipboard('ID', file.id)
                }}
                data-analytics-id="file_row_action_copy_id"
                data-analytics-surface="file_row_actions"
                data-analytics-resource="file"
              >
                <MenuItemContent icon={Copy}>Copy ID</MenuItemContent>
              </DropdownMenuItem>
              {hasName && (
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation()
                    copyToClipboard('Name', file.name)
                  }}
                  data-analytics-id="file_row_action_copy_name"
                  data-analytics-surface="file_row_actions"
                  data-analytics-resource="file"
                >
                  <MenuItemContent icon={Copy}>Copy name</MenuItemContent>
                </DropdownMenuItem>
              )}
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  copyToClipboard('Link', fileHref)
                }}
                data-analytics-id="file_row_action_copy_link"
                data-analytics-surface="file_row_actions"
                data-analytics-resource="file"
              >
                <MenuItemContent icon={Link2}>Copy link</MenuItemContent>
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation()
                  void copyResourceAsJson(() =>
                    fetchFile(projectId, bucketId, file.id),
                  )
                }}
                data-analytics-id="file_row_action_copy_json"
                data-analytics-surface="file_row_actions"
                data-analytics-resource="file"
              >
                <MenuItemContent icon={FileJson}>Copy as JSON</MenuItemContent>
              </DropdownMenuItem>
            </DropdownMenuSubContent>
          </DropdownMenuSub>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              openInNewTab(fileHref)
            }}
            data-analytics-id="file_row_action_open_new_tab"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
          >
            <MenuItemContent icon={ExternalLink}>Open in new tab</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              openInNewWindow(fileHref)
            }}
            data-analytics-id="file_row_action_open_new_window"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
          >
            <MenuItemContent icon={Square}>Open in new window</MenuItemContent>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onClick={(e) => {
              e.stopPropagation()
              handleDeleteClick()
            }}
            data-analytics-id="file_row_action_delete_open"
            data-analytics-surface="file_row_actions"
            data-analytics-resource="file"
          >
            <MenuItemContent icon={Trash2}>Delete</MenuItemContent>
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
