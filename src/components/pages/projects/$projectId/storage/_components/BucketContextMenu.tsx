import { useState } from 'react'
import {
  Copy,
  Pencil,
  Plus,
  ShieldCheck,
  Trash2,
  ExternalLink,
  Square,
  Link2,
  FileJson,
} from 'lucide-react'
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
import { Dependencies } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

export type BucketContextMenuBucket = {
  id: string
  name?: string | null
}

interface BucketContextMenuProps {
  projectId: string
  bucket: BucketContextMenuBucket
  children: React.ReactNode
}

export function BucketContextMenu({
  projectId,
  bucket,
  children,
}: BucketContextMenuProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.storage.deleteBucket({ bucketId: bucket.id })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: Dependencies.BUCKETS })
      toast.success('Bucket deleted')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete bucket')
    },
  })

  const handleUpdate = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/settings',
      params: { projectId, bucketId: bucket.id },
    })
  }

  const handleCreateFile = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId, bucketId: bucket.id },
      search: { create: 'file' },
    })
  }

  const handleManagePermissions = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/security',
      params: { projectId, bucketId: bucket.id },
    })
  }

  const bucketHref = buildConsoleUrl(
    `/projects/${projectId}/storage/${bucket.id}/`,
  )

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  const hasName = !!bucket.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={handleUpdate}>
            <ContextMenuIcon icon={Pencil} />
            Update
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleCreateFile}>
            <ContextMenuIcon icon={Plus} />
            Create file
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleManagePermissions}>
            <ContextMenuIcon icon={ShieldCheck} />
            Manage permissions
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', bucket.id)}>
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', bucket.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem onSelect={() => copyToClipboard('Link', bucketHref)}>
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard(
                    'JSON',
                    toPrettyJson(
                      {
                        id: bucket.id,
                        name: bucket.name ?? null,
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
            onSelect={() => openInNewTab(bucketHref)}
          >
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(bucketHref)}
          >
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
            className="text-destructive focus:text-destructive"
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete bucket</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this bucket? This action cannot be
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
