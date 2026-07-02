import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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
import {
  Globe,
  Settings,
  RefreshCw,
  Copy,
  Link2,
  FileJson,
  ExternalLink,
  Square,
  Trash2,
} from 'lucide-react'
import {
  fetchDomain,
  useDeleteOrganizationDomain,
  useRetryDomainVerification,
} from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface DomainContextMenuProps {
  orgId: string
  domain: Models.Domain
  children: React.ReactNode
}

export function DomainContextMenu({
  orgId,
  domain,
  children,
}: DomainContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
  const domainHref = buildConsoleUrl(
    `/organizations/${orgId}/domains/${domain.$id}`,
  )

  const deleteDomain = useDeleteOrganizationDomain(orgId)
  const retryVerification = useRetryDomainVerification(orgId)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteDomain.mutateAsync(domain.$id)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success(`${domain.domain} ${t('has been deleted')}`)
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete domain'))
    },
  })

  const handleRetryVerification = () => {
    retryVerification.mutate(domain.$id, {
      onSuccess: (updatedDomain) => {
        const verified = updatedDomain.nameservers?.toLowerCase() === 'appwrite'
        if (verified) {
          toast.success(t('Domain verification successful'))
        } else {
          toast.success(
            t('Nameservers updated. Please wait for DNS propagation.'),
          )
        }
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  const navigateToTab = (
    path:
      | '/organizations/$orgId/domains/$domainId'
      | '/organizations/$orgId/domains/$domainId/settings',
  ) => {
    navigate({
      to: path,
      params: { orgId, domainId: domain.$id },
    })
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
>
          <ContextMenuItem
            onSelect={() =>
              navigateToTab('/organizations/$orgId/domains/$domainId')
            }
>
            <ContextMenuIcon icon={Globe} />
            {t('DNS Records')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() =>
              navigateToTab('/organizations/$orgId/domains/$domainId/settings')
            }
>
            <ContextMenuIcon icon={Settings} />
            {t('Settings')}
          </ContextMenuItem>
          {!isVerified && (
            <ContextMenuItem
              onSelect={handleRetryVerification}
>
              <ContextMenuIcon icon={RefreshCw} />
              {t('Retry verification')}
            </ContextMenuItem>
          )}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', domain.$id)}
>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Domain', domain.domain)}
>
                <ContextMenuIcon icon={Copy} />
                {t('Copy domain')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', domainHref)}
>
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() => fetchDomain(domain.$id))
                }
>
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(domainHref)}
>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(domainHref)}
>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => setDeleteDialogOpen(true)}
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
            <DialogTitle>{t('Delete domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete this domain?')}{' '}
              {t('This action cannot be undone.')}
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
              onClick={() => deleteMutation.mutate()}
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
