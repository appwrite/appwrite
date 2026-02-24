import { useState } from 'react'
import { Copy, Pencil, Download, ShieldCheck, Trash2, Link2 } from 'lucide-react'
import { toast } from 'sonner'
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

async function copyToClipboard(label: string, value?: string | null) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied to clipboard`)
  } catch {
    toast.error('Failed to copy')
  }
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

  const handleUpdate = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/files/$fileId',
      params: { projectId, bucketId, fileId: file.id },
    })
  }

  const handleDownload = () => {
    const projectSdk = sdk.forProject(projectId)
    const url = projectSdk.storage.getFileDownload({
      bucketId,
      fileId: file.id,
    })
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank', 'noopener,noreferrer')
  }

  const handleManagePermissions = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/files/$fileId/security',
      params: { projectId, bucketId, fileId: file.id },
    })
  }

  const handleCopyLink = () => {
    const url = `${window.location.origin}/projects/${projectId}/storage/${bucketId}/files/${file.id}`
    copyToClipboard('Link', url)
  }

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
          <ContextMenuItem onSelect={handleUpdate} disabled={disabled}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Pencil className="size-4" />
            </span>
            Update
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleDownload} disabled={disabled}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Download className="size-4" />
            </span>
            Download
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleManagePermissions} disabled={disabled}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <ShieldCheck className="size-4" />
            </span>
            Manage permissions
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Copy className="size-4" />
              </span>
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', file.id)}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', file.name)}
                >
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                    <Copy className="size-4" />
                  </span>
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem onSelect={handleCopyLink}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Link2 className="size-4" />
                </span>
                Copy link
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
            className="text-destructive focus:text-destructive"
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Trash2 className="size-4" />
            </span>
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
