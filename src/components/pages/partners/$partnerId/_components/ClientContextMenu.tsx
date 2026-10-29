import { useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pin,
  PinOff,
  Square,
  Unlink,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  deletePartnerManager,
  fetchOrganizationById,
  partnerManagerDeleteErrorMessage,
} from '@/lib/react-query/hooks'
import type { PartnerAwareOrganization } from '@/lib/partners'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

type ClientContextMenuProps = {
  partnerId: string
  client: PartnerAwareOrganization
  isPinned: boolean
  isPinPending?: boolean
  onPinClient: (clientId: string) => void
  children: ReactNode
}

export function ClientContextMenu({
  partnerId,
  client,
  isPinned,
  isPinPending,
  onPinClient,
  children,
}: ClientContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const clientName = client.name || client.$id
  const clientHref = `/partners/${partnerId}/clients/${encodeURIComponent(client.$id)}`

  const disconnectMutation = useMutation({
    mutationFn: () => deletePartnerManager(client.$id),
    onSuccess: async () => {
      toast.success(t('Partner disconnected'))
      setDisconnectOpen(false)
      await queryClient.refetchQueries({
        queryKey: ['partners', partnerId, 'clients'],
      })
      await queryClient.refetchQueries({
        queryKey: ['organizations', 'console'],
      })
      void navigate({
        to: '/partners/$partnerId/clients',
        params: { partnerId },
      })
    },
    onError: (error) => {
      toast.error(t(partnerManagerDeleteErrorMessage(error)))
    },
  })

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem
            disabled={isPinPending}
            onSelect={() => onPinClient(client.$id)}
            {...analyticsAttrs('partner-pin-client')}
          >
            <ContextMenuIcon icon={isPinned ? PinOff : Pin} />
            {isPinned ? t('Unpin') : t('Pin')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => void copyToClipboard('ID', client.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => void copyToClipboard('Name', clientName)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyToClipboard('Link', buildConsoleUrl(clientHref))
                }
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(
                    () => fetchOrganizationById(client.$id),
                    { fallback: client },
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
            onSelect={() => openInNewTab(buildConsoleUrl(clientHref))}
          >
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(buildConsoleUrl(clientHref))}
          >
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => setDisconnectOpen(true)}>
            <ContextMenuIcon icon={Unlink} />
            {t('Disconnect')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
      <Dialog open={disconnectOpen} onOpenChange={setDisconnectOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Disconnect partner')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Disconnect')} "{clientName}"?{' '}
              {t(
                'This organization will no longer be managed by this partner.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDisconnectOpen(false)}
              disabled={disconnectMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={disconnectMutation.isPending}
              onClick={() => disconnectMutation.mutate()}
            >
              {t('Disconnect')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
