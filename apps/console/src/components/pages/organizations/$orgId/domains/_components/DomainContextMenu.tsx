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
import { fetchDomain, useDeleteOrganizationDomain } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { RetryVerification } from '../RetryVerification'
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
  const [retryDialogOpen, setRetryDialogOpen] = useState(false)

  const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
  const domainHref = buildConsoleUrl(
    `/organizations/${orgId}/domains/${domain.$id}`,
  )

  const deleteDomain = useDeleteOrganizationDomain(orgId)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteDomain.mutateAsync(domain.$id)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success(`${domain.domain} ${t('has been deleted')}`)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete domain'))
    },
  })

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate()
  }

  const handleRetryVerificationClick = () => {
    openDialogAfterOverlayCloses(() => setRetryDialogOpen(true))
  }

  const handleDomainVerified = () => {
    queryClient.invalidateQueries({
      queryKey: ['domains', 'organization', orgId],
    })
    queryClient.invalidateQueries({
      queryKey: ['domain', domain.$id],
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
        <ContextMenuContent className="w-56">
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
            <ContextMenuItem onSelect={handleRetryVerificationClick}>
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
          <ContextMenuItem onSelect={() => openInNewTab(domainHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(domainHref)}>
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
        title="Delete domain"
        description={
          <>
            {t('Are you sure you want to delete this domain?')}{' '}
            {t('This action cannot be undone.')}
          </>
        }
        confirmValue={domain.domain}
        confirmPlaceholder="Enter domain name"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />

      <RetryVerification
        open={retryDialogOpen}
        onOpenChange={setRetryDialogOpen}
        domain={domain}
        orgId={orgId}
        onVerified={handleDomainVerified}
      />
    </>
  )
}
