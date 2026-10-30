import { createContext, useContext, useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
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
  Copy,
  ExternalLink,
  FileJson,
  FileText,
  KeyRound,
  Link2,
  Palette,
  Square,
  Trash2,
  Import,
  type LucideIcon,
} from 'lucide-react'
import {
  fetchOrganizationApp,
  useDeleteOrganizationApp,
} from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  ContextMenuIcon,
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { useT } from '@/lib/i18n/translate'
import type { MarketplaceApp } from '@/lib/marketplace/types'

type AppMenuAction = {
  icon: LucideIcon
  label: string
  onSelect: () => void
}

type AppMenuModel = {
  tabs: AppMenuAction[]
  copyItems: AppMenuAction[]
  links: AppMenuAction[]
  handoff: AppMenuAction | null
  handleDeleteClick: () => void
}

const AppMenuModelContext = createContext<AppMenuModel | null>(null)

type OrganizationAppContextMenuProps = {
  orgId: string
  app: Pick<MarketplaceApp, '$id' | 'name'>
  openResourceInNewTab?: boolean
  onHandoff?: (appId: string) => void
  handoffIcon?: LucideIcon
  children: React.ReactNode
}

export function OrganizationAppContextMenu({
  orgId,
  app,
  openResourceInNewTab = false,
  onHandoff,
  handoffIcon,
  children,
}: OrganizationAppContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteApp = useDeleteOrganizationApp(orgId)
  const appHref = buildConsoleUrl(`/organizations/${orgId}/apps/${app.$id}`)
  const hasName = !!app.name

  const navigateToTab = (
    path:
      | '/organizations/$orgId/apps/$appId'
      | '/organizations/$orgId/apps/$appId/oauth'
      | '/organizations/$orgId/apps/$appId/settings',
  ) => {
    if (openResourceInNewTab) {
      openInNewTab(
        buildConsoleUrl(
          path.replace('$orgId', orgId).replace('$appId', app.$id),
        ),
      )
      return
    }
    navigate({
      to: path,
      params: { orgId, appId: app.$id },
    })
  }

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteApp.mutate(app.$id, {
      onSuccess: () => {
        toast.success(t('App deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error, t('Failed to delete app')))
      },
    })
  }

  const model = useMemo<AppMenuModel>(() => {
    const copyItems: AppMenuAction[] = [
      {
        icon: Copy,
        label: t('Copy ID'),
        onSelect: () => copyToClipboard('ID', app.$id),
      },
    ]
    if (hasName) {
      copyItems.push({
        icon: Copy,
        label: t('Copy name'),
        onSelect: () => copyToClipboard('Name', app.name),
      })
    }
    copyItems.push(
      {
        icon: Link2,
        label: t('Copy link'),
        onSelect: () => copyToClipboard('Link', appHref),
      },
      {
        icon: FileJson,
        label: t('Copy as JSON'),
        onSelect: () =>
          void copyResourceAsJson(() => fetchOrganizationApp(app.$id)),
      },
    )

    return {
      tabs: [
        {
          icon: FileText,
          label: t('Overview'),
          onSelect: () => navigateToTab('/organizations/$orgId/apps/$appId'),
        },
        {
          icon: KeyRound,
          label: t('OAuth client'),
          onSelect: () =>
            navigateToTab('/organizations/$orgId/apps/$appId/oauth'),
        },
        {
          icon: Palette,
          label: t('Settings'),
          onSelect: () =>
            navigateToTab('/organizations/$orgId/apps/$appId/settings'),
        },
      ],
      copyItems,
      links: [
        {
          icon: ExternalLink,
          label: t('Open in new tab'),
          onSelect: () => openInNewTab(appHref),
        },
        {
          icon: Square,
          label: t('Open in new window'),
          onSelect: () => openInNewWindow(appHref),
        },
      ],
      handoff: onHandoff
        ? {
            icon: handoffIcon ?? Import,
            label: t('Handoff'),
            onSelect: () =>
              openDialogAfterOverlayCloses(() => onHandoff(app.$id)),
          }
        : null,
      handleDeleteClick,
    }
  }, [
    t,
    app.$id,
    app.name,
    appHref,
    hasName,
    onHandoff,
    handoffIcon,
    orgId,
    openResourceInNewTab,
    navigate,
  ])

  return (
    <AppMenuModelContext.Provider value={model}>
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
        title="Delete app"
        description={
          <>
            {t('Are you sure you want to delete')}{' '}
            {app.name || t('this app')}
            {'. '}
            {t('This action cannot be undone.')}
          </>
        }
        confirmValue={app.name?.trim() || app.$id}
        confirmPlaceholder="Enter app name"
        onConfirm={handleDelete}
        isConfirming={deleteApp.isPending}
      />
    </AppMenuModelContext.Provider>
  )
}

export function OrganizationAppRowActionsMenu() {
  const t = useT()
  const model = useContext(AppMenuModelContext)
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
