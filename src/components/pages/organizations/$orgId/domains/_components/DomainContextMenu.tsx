import { createContext, useContext, useMemo, useState } from 'react'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
  Import,
  type LucideIcon,
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
import {
  ContextMenuIcon,
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { RetryVerification } from '../RetryVerification'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

type DomainMenuAction = {
  icon: LucideIcon
  label: string
  onSelect: () => void
  disabled?: boolean
}

type DomainMenuModel = {
  tabs: DomainMenuAction[]
  copyItems: DomainMenuAction[]
  links: DomainMenuAction[]
  handoff: DomainMenuAction | null
  handleDeleteClick: () => void
}

const DomainMenuModelContext = createContext<DomainMenuModel | null>(null)

interface DomainContextMenuProps {
  orgId: string
  domain: Models.Domain
  onHandoff?: (domainId: string) => void
  handoffIcon?: LucideIcon
  openResourceInNewTab?: boolean
  children: React.ReactNode
}

export function DomainContextMenu({
  orgId,
  domain,
  onHandoff,
  handoffIcon,
  openResourceInNewTab = false,
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
    if (openResourceInNewTab) {
      openInNewTab(
        buildConsoleUrl(
          path.replace('$orgId', orgId).replace('$domainId', domain.$id),
        ),
      )
      return
    }
    navigate({
      to: path,
      params: { orgId, domainId: domain.$id },
    })
  }

  const model = useMemo<DomainMenuModel>(() => {
    const tabs: DomainMenuAction[] = [
      {
        icon: Globe,
        label: t('DNS Records'),
        onSelect: () =>
          navigateToTab('/organizations/$orgId/domains/$domainId'),
      },
      {
        icon: Settings,
        label: t('Settings'),
        onSelect: () =>
          navigateToTab('/organizations/$orgId/domains/$domainId/settings'),
      },
    ]
    if (!isVerified) {
      tabs.push({
        icon: RefreshCw,
        label: t('Retry verification'),
        onSelect: handleRetryVerificationClick,
      })
    }

    return {
      tabs,
      copyItems: [
        {
          icon: Copy,
          label: t('Copy ID'),
          onSelect: () => copyToClipboard('ID', domain.$id),
        },
        {
          icon: Copy,
          label: t('Copy domain'),
          onSelect: () => copyToClipboard('Domain', domain.domain),
        },
        {
          icon: Link2,
          label: t('Copy link'),
          onSelect: () => copyToClipboard('Link', domainHref),
        },
        {
          icon: FileJson,
          label: t('Copy as JSON'),
          onSelect: () =>
            void copyResourceAsJson(() => fetchDomain(domain.$id)),
        },
      ],
      links: [
        {
          icon: ExternalLink,
          label: t('Open in new tab'),
          onSelect: () => openInNewTab(domainHref),
        },
        {
          icon: Square,
          label: t('Open in new window'),
          onSelect: () => openInNewWindow(domainHref),
        },
      ],
      handoff: onHandoff
        ? {
            icon: handoffIcon ?? Import,
            label: t('Handoff'),
            onSelect: () =>
              openDialogAfterOverlayCloses(() => onHandoff(domain.$id)),
          }
        : null,
      handleDeleteClick,
    }
  }, [
    t,
    domain.$id,
    domain.domain,
    domainHref,
    isVerified,
    onHandoff,
    handoffIcon,
    orgId,
    openResourceInNewTab,
    navigate,
  ])

  return (
    <DomainMenuModelContext.Provider value={model}>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          {model.tabs.map((item) => (
            <ContextMenuItem key={item.label} onSelect={item.onSelect}>
              <ContextMenuIcon icon={item.icon} />
              {item.label}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              {model.copyItems.map((item) => (
                <ContextMenuItem key={item.label} onSelect={item.onSelect}>
                  <ContextMenuIcon icon={item.icon} />
                  {item.label}
                </ContextMenuItem>
              ))}
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          {model.links.map((item) => (
            <ContextMenuItem key={item.label} onSelect={item.onSelect}>
              <ContextMenuIcon icon={item.icon} />
              {item.label}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          {model.handoff ? (
            <ContextMenuItem onSelect={model.handoff.onSelect}>
              <ContextMenuIcon icon={model.handoff.icon} />
              {model.handoff.label}
            </ContextMenuItem>
          ) : null}
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
    </DomainMenuModelContext.Provider>
  )
}

export function DomainRowActionsMenu() {
  const t = useT()
  const model = useContext(DomainMenuModelContext)
  if (!model) return null

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger
          compact
          onClick={(event) => event.stopPropagation()}
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        className="w-56"
        onClick={(event) => event.stopPropagation()}
      >
        {model.tabs.map((item) => (
          <DropdownMenuItem key={item.label} onSelect={item.onSelect}>
            <MenuItemContent icon={item.icon}>{item.label}</MenuItemContent>
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <MenuItemIcon icon={Copy} />
            {t('Copy')}
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            {model.copyItems.map((item) => (
              <DropdownMenuItem key={item.label} onSelect={item.onSelect}>
                <MenuItemContent icon={item.icon}>{item.label}</MenuItemContent>
              </DropdownMenuItem>
            ))}
          </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuSeparator />
        {model.links.map((item) => (
          <DropdownMenuItem key={item.label} onSelect={item.onSelect}>
            <MenuItemContent icon={item.icon}>{item.label}</MenuItemContent>
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        {model.handoff ? (
          <DropdownMenuItem onSelect={model.handoff.onSelect}>
            <MenuItemContent icon={model.handoff.icon}>
              {model.handoff.label}
            </MenuItemContent>
          </DropdownMenuItem>
        ) : null}
        <DropdownMenuItem onSelect={model.handleDeleteClick}>
          <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
