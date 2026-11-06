import { useState } from 'react'
import {
  Copy,
  Trash2,
  Link2,
  ExternalLink,
  Square,
  FileJson,
  LayoutList,
  Settings,
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
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
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
import { fetchTopic } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'

export type TopicContextMenuTopic = {
  $id: string
  name?: string | null
}

interface TopicContextMenuProps {
  projectId: string
  topic: TopicContextMenuTopic
  children: React.ReactNode
}

export function TopicContextMenu({
  projectId,
  topic,
  children,
}: TopicContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!topic?.$id) return
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteTopic({ topicId: topic.$id })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['topics', 'project', projectId],
      })
      toast.success(t('Topic deleted'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete topic'))
    },
  })

  if (!topic?.$id) {
    return <>{children}</>
  }

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/messaging/topics/${topic.$id}`
    const path = tab === 'overview' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/messaging/topics/$topicId',
      params: { projectId, topicId: topic.$id },
    })
  }

  const topicHref = buildConsoleUrl(
    `/projects/${projectId}/messaging/topics/${topic.$id}`,
  )

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const hasName = !!topic.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
>
          <ContextMenuItem
            onSelect={() => navigateToTab('overview')}
>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => navigateToTab('settings')}
>
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
                onSelect={() => copyToClipboard('ID', topic.$id)}
>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', topic.name)}
>
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', topicHref)}
>
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchTopic(projectId, topic.$id),
                  )
                }
>
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(topicHref)}
>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(topicHref)}
>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent
          className="sm:max-w-md p-0"
>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete topic')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this topic? This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
>
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
                deleteMutation.mutate()
              }}
              disabled={deleteMutation.isPending}
>
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
