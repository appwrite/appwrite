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
import { fetchProvider } from '@/lib/react-query/hooks'
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

export type ProviderContextMenuProvider = {
  $id: string
  name?: string | null
}

interface ProviderContextMenuProps {
  projectId: string
  provider: ProviderContextMenuProvider
  children: React.ReactNode
}

export function ProviderContextMenu({
  projectId,
  provider,
  children,
}: ProviderContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!provider?.$id) return
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteProvider({ providerId: provider.$id })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', projectId],
      })
      toast.success(t('Provider deleted'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete provider'))
    },
  })

  if (!provider?.$id) {
    return <>{children}</>
  }

  const navigateToOverview = () => {
    navigate({
      to: '/projects/$projectId/messaging/providers/$providerId',
      params: { projectId, providerId: provider.$id },
    })
  }

  const navigateToSettings = () => {
    navigate({
      to: '/projects/$projectId/messaging/providers/$providerId/settings',
      params: { projectId, providerId: provider.$id },
    })
  }

  const providerHref = buildConsoleUrl(
    `/projects/${projectId}/messaging/providers/${provider.$id}`,
  )

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const hasName = !!provider.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
>
          <ContextMenuItem
            onSelect={navigateToOverview}
>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={navigateToSettings}
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
                onSelect={() => copyToClipboard('ID', provider.$id)}
>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', provider.name)}
>
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', providerHref)}
>
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProvider(projectId, provider.$id),
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
            onSelect={() => openInNewTab(providerHref)}
>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(providerHref)}
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
            <DialogTitle>{t('Delete provider')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this provider? This action cannot be undone.',
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
