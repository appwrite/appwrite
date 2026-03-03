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
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

export type FileContextMenuFile = {
  id: string
  name?: string | null
  pending?: boolean
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

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/storage/${bucketId}/files/${file.id}`
    const path = tab === 'overview' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/storage/$bucketId/files/$fileId',
      params: { projectId, bucketId, fileId: file.id },
    })
  }

  const fileHref = buildConsoleUrl(
    `/projects/${projectId}/storage/${bucketId}/files/${file.id}`,
  )

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  const hasName = !!file.name
  const disabled = !!file.pending

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={LayoutList} />
              Tabs
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => navigateToTab('overview')}>
                <ContextMenuIcon icon={LayoutList} />
                Overview
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('security')}>
                <ContextMenuIcon icon={Shield} />
                Security
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', file.id)}>
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', file.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem onSelect={() => copyToClipboard('Link', fileHref)}>
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard(
                    'JSON',
                    toPrettyJson(
                      {
                        id: file.id,
                        name: file.name ?? null,
                      },
                    ),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(fileHref)}
          >
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(fileHref)}
          >
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
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
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteMutation.mutate()}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
