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
import { fetchMessage } from '@/lib/react-query/hooks'
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

export type MessageContextMenuMessage = {
  $id: string
  providerType?: string
}

interface MessageContextMenuProps {
  projectId: string
  message: MessageContextMenuMessage
  children: React.ReactNode
}

export function MessageContextMenu({
  projectId,
  message,
  children,
}: MessageContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!message?.$id) return
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.delete({ messageId: message.$id })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      toast.success(t('Message deleted'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete message'))
    },
  })

  if (!message?.$id) {
    return <>{children}</>
  }

  const navigateToCompose = () => {
    navigate({
      to: '/projects/$projectId/messaging/$messageId',
      params: { projectId, messageId: message.$id },
    })
  }

  const navigateToSettings = () => {
    navigate({
      to: '/projects/$projectId/messaging/$messageId/settings',
      params: { projectId, messageId: message.$id },
    })
  }

  const hasComposeSettingsTabs =
    message.providerType === 'email' ||
    message.providerType === 'sms' ||
    message.providerType === 'push'

  const messageHref = buildConsoleUrl(
    `/projects/${projectId}/messaging/${message.$id}`,
  )

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
>
          <ContextMenuItem
            onSelect={navigateToCompose}
>
            <ContextMenuIcon icon={LayoutList} />
            {t('Compose')}
          </ContextMenuItem>
          {hasComposeSettingsTabs ? (
            <ContextMenuItem
              onSelect={navigateToSettings}
>
              <ContextMenuIcon icon={Settings} />
              {t('Settings')}
            </ContextMenuItem>
          ) : null}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', message.$id)}
>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', messageHref)}
>
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchMessage(projectId, message.$id),
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
            onSelect={() => openInNewTab(messageHref)}
>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(messageHref)}
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
            <DialogTitle>{t('Delete message')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this message? This action cannot be undone.',
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
