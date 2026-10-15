import { useState } from 'react'
import {
  Copy,
  Trash2,
  ExternalLink,
  Square,
  Link2,
  FileJson,
  Folder,
  Shield,
  Settings,
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
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import {
  Dependencies,
  fetchBucket,
  getCachedBucketListsFromQueryClient,
  pickNextBucketIdAfterDelete,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.storage.deleteBucket({ bucketId: bucket.id })
    },
    onSuccess: async () => {
      const deletedId = bucket.id
      const lists = getCachedBucketListsFromQueryClient(queryClient, projectId)
      const nextBucketId = pickNextBucketIdAfterDelete(lists, deletedId)
      await queryClient.refetchQueries({ queryKey: Dependencies.BUCKETS })
      toast.success(t('Bucket deleted'))
      if (nextBucketId) {
        navigate({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId, bucketId: nextBucketId },
        })
      } else {
        navigate({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId, bucketId: '-' },
        })
      }
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete bucket'))
    },
  })

  const navigateToTab = (tab: string) => {
    if (tab === 'files') {
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId, bucketId: bucket.id },
      })
      return
    }
    if (tab === 'security') {
      navigate({
        to: '/projects/$projectId/storage/$bucketId/security',
        params: { projectId, bucketId: bucket.id },
      })
      return
    }
    navigate({
      to: '/projects/$projectId/storage/$bucketId/settings',
      params: { projectId, bucketId: bucket.id },
    })
  }

  const bucketHref = buildConsoleUrl(
    `/projects/${projectId}/storage/${bucket.id}/`,
  )

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const hasName = !!bucket.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => navigateToTab('files')}>
            <ContextMenuIcon icon={Folder} />
            {t('Files')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('security')}>
            <ContextMenuIcon icon={Shield} />
            {t('Security')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('settings')}>
            <ContextMenuIcon icon={Settings} />
            {t('Settings')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', bucket.id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', bucket.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', bucketHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchBucket(projectId, bucket.id),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(bucketHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(bucketHref)}>
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

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete bucket"
        description={
          <>
            {t(
              'Are you sure you want to delete this bucket? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={bucket.name?.trim() || bucket.id}
        confirmPlaceholder="Enter bucket name"
        onConfirm={() => {
          closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
          deleteMutation.mutate()
        }}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
