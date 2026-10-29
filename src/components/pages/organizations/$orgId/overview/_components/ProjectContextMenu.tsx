import { createContext, useContext, useMemo, useState } from 'react'
import {
  Copy,
  Trash2,
  Link2,
  ExternalLink,
  Square,
  FileJson,
  LayoutDashboard,
  Users,
  Database,
  Folder,
  Zap,
  MessageSquare,
  Globe,
  Settings,
  Pin,
  PinOff,
  Import,
  type LucideIcon,
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
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { deleteProject, fetchProject } from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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
import {
  ContextMenuIcon,
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { useT } from '@/lib/i18n/translate'

type ProjectContextMenuProject = {
  $id: string
  name?: string | null
  teamId: string
  region?: string | null
}

interface ProjectContextMenuProps {
  project: ProjectContextMenuProject
  showSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted?: (projectId: string) => Promise<void> | void
  canPinProjects?: boolean
  isPinned?: boolean
  canPinMore?: boolean
  onPinProject?: (projectId: string) => void
  isPinPending?: boolean
  openResourceInNewTab?: boolean
  onHandoff?: (projectId: string) => void
  handoffIcon?: LucideIcon
  children: React.ReactNode
}

type ProjectMenuAction = {
  icon: LucideIcon
  label: string
  onSelect: () => void
  disabled?: boolean
}

type ProjectMenuModel = {
  tabs: ProjectMenuAction[]
  pin: ProjectMenuAction | null
  copyItems: ProjectMenuAction[]
  links: ProjectMenuAction[]
  handoff: ProjectMenuAction | null
  canDeleteProject: boolean
  handleDeleteClick: () => void
}

const ProjectMenuModelContext = createContext<ProjectMenuModel | null>(null)

export function ProjectContextMenu({
  project,
  showSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  canPinProjects = false,
  isPinned = false,
  canPinMore = false,
  onPinProject,
  isPinPending = false,
  openResourceInNewTab = false,
  onHandoff,
  handoffIcon,
  children,
}: ProjectContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const projectHref = buildConsoleUrl(`/projects/${project.$id}`)
  const projectEndpoint = getApiEndpoint(project.region ?? undefined)
  const hasName = !!project.name

  const navigateToTab = (
    path:
      | '/projects/$projectId'
      | '/projects/$projectId/auth'
      | '/projects/$projectId/databases'
      | '/projects/$projectId/storage/$bucketId'
      | '/projects/$projectId/functions'
      | '/projects/$projectId/messaging'
      | '/projects/$projectId/sites'
      | '/projects/$projectId/settings',
  ) => {
    if (openResourceInNewTab) {
      const href = path
        .replace('$projectId', project.$id)
        .replace('$bucketId', '-')
      openInNewTab(buildConsoleUrl(href))
      return
    }
    navigate({
      to: path,
      params: { projectId: project.$id },
    })
  }

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteProject(project.$id, project.region)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({
        queryKey: ['organization', project.teamId],
      })

      if (onProjectDeleted) {
        await onProjectDeleted(project.$id)
      }

      toast.success(`${project.name || t('Project')} ${t('has been deleted')}`)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to delete project')))
    },
  })

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate()
  }

  const model = useMemo<ProjectMenuModel>(() => {
    const tabs: ProjectMenuAction[] = [
      {
        icon: LayoutDashboard,
        label: t('Overview'),
        onSelect: () => navigateToTab('/projects/$projectId'),
      },
      {
        icon: Users,
        label: t('Auth'),
        onSelect: () => navigateToTab('/projects/$projectId/auth'),
      },
      {
        icon: Database,
        label: t('Databases'),
        onSelect: () => navigateToTab('/projects/$projectId/databases'),
      },
      {
        icon: Folder,
        label: t('Storage'),
        onSelect: () => {
          if (openResourceInNewTab) {
            openInNewTab(
              buildConsoleUrl(`/projects/${project.$id}/storage/-`),
            )
            return
          }
          navigate({
            to: '/projects/$projectId/storage/$bucketId',
            params: { projectId: project.$id, bucketId: '-' },
          })
        },
      },
      {
        icon: Zap,
        label: t('Functions'),
        onSelect: () => navigateToTab('/projects/$projectId/functions'),
      },
      {
        icon: MessageSquare,
        label: t('Messaging'),
        onSelect: () => navigateToTab('/projects/$projectId/messaging'),
      },
      {
        icon: Globe,
        label: t('Sites'),
        onSelect: () => navigateToTab('/projects/$projectId/sites'),
      },
    ]
    if (showSettingsTab) {
      tabs.push({
        icon: Settings,
        label: t('Settings'),
        onSelect: () => navigateToTab('/projects/$projectId/settings'),
      })
    }

    const showPin = canPinProjects && (isPinned || canPinMore)
    const pin: ProjectMenuAction | null = showPin
      ? {
          icon: isPinned ? PinOff : Pin,
          label: isPinned ? t('Unpin') : t('Pin'),
          disabled: isPinPending,
          onSelect: () => onPinProject?.(project.$id),
        }
      : null

    const copyItems: ProjectMenuAction[] = [
      {
        icon: Copy,
        label: t('Copy ID'),
        onSelect: () => copyToClipboard('ID', project.$id),
      },
      {
        icon: Link2,
        label: t('Copy endpoint'),
        onSelect: () => copyToClipboard('Endpoint', projectEndpoint),
      },
    ]
    if (hasName) {
      copyItems.push({
        icon: Copy,
        label: t('Copy name'),
        onSelect: () => copyToClipboard('Name', project.name),
      })
    }
    copyItems.push(
      {
        icon: Link2,
        label: t('Copy link'),
        onSelect: () => copyToClipboard('Link', projectHref),
      },
      {
        icon: FileJson,
        label: t('Copy as JSON'),
        onSelect: () => void copyResourceAsJson(() => fetchProject(project.$id)),
      },
    )

    const links: ProjectMenuAction[] = [
      {
        icon: ExternalLink,
        label: t('Open in new tab'),
        onSelect: () => openInNewTab(projectHref),
      },
      {
        icon: Square,
        label: t('Open in new window'),
        onSelect: () => openInNewWindow(projectHref),
      },
    ]

    const handoff: ProjectMenuAction | null = onHandoff
      ? {
          icon: handoffIcon ?? Import,
          label: t('Handoff'),
          onSelect: () =>
            openDialogAfterOverlayCloses(() => onHandoff(project.$id)),
        }
      : null

    return {
      tabs,
      pin,
      copyItems,
      links,
      handoff,
      canDeleteProject,
      handleDeleteClick,
    }
  }, [
    t,
    project.$id,
    project.name,
    projectHref,
    projectEndpoint,
    hasName,
    showSettingsTab,
    canPinProjects,
    isPinned,
    canPinMore,
    isPinPending,
    onPinProject,
    openResourceInNewTab,
    onHandoff,
    handoffIcon,
    canDeleteProject,
    navigate,
  ])

  if (!project.$id) {
    return <>{children}</>
  }

  return (
    <ProjectMenuModelContext.Provider value={model}>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          {model.tabs.map((item) => (
            <ContextMenuItem
              key={item.label}
              disabled={item.disabled}
              onSelect={item.onSelect}
            >
              <ContextMenuIcon icon={item.icon} />
              {item.label}
            </ContextMenuItem>
          ))}
          {model.pin ? (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem
                disabled={model.pin.disabled}
                onSelect={model.pin.onSelect}
              >
                <ContextMenuIcon icon={model.pin.icon} />
                {model.pin.label}
              </ContextMenuItem>
            </>
          ) : null}
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
          {(model.handoff || canDeleteProject) && (
            <>
              <ContextMenuSeparator />
              {model.handoff ? (
                <ContextMenuItem onSelect={model.handoff.onSelect}>
                  <ContextMenuIcon icon={model.handoff.icon} />
                  {model.handoff.label}
                </ContextMenuItem>
              ) : null}
              {canDeleteProject ? (
                <ContextMenuItem onSelect={handleDeleteClick}>
                  <ContextMenuIcon icon={Trash2} />
                  {t('Delete')}
                </ContextMenuItem>
              ) : null}
            </>
          )}
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete project"
        description={
          <>
            {t(
              'Are you sure you want to delete this project? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={project.name?.trim() || project.$id}
        confirmPlaceholder="Enter project name"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </ProjectMenuModelContext.Provider>
  )
}

export function ProjectRowActionsMenu() {
  const t = useT()
  const model = useContext(ProjectMenuModelContext)
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
          <DropdownMenuItem
            key={item.label}
            disabled={item.disabled}
            onSelect={item.onSelect}
          >
            <MenuItemContent icon={item.icon}>{item.label}</MenuItemContent>
          </DropdownMenuItem>
        ))}
        {model.pin ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              disabled={model.pin.disabled}
              onSelect={model.pin.onSelect}
            >
              <MenuItemContent icon={model.pin.icon}>
                {model.pin.label}
              </MenuItemContent>
            </DropdownMenuItem>
          </>
        ) : null}
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
        {(model.handoff || model.canDeleteProject) && (
          <>
            <DropdownMenuSeparator />
            {model.handoff ? (
              <DropdownMenuItem onSelect={model.handoff.onSelect}>
                <MenuItemContent icon={model.handoff.icon}>
                  {model.handoff.label}
                </MenuItemContent>
              </DropdownMenuItem>
            ) : null}
            {model.canDeleteProject ? (
              <DropdownMenuItem onSelect={model.handleDeleteClick}>
                <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
              </DropdownMenuItem>
            ) : null}
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
