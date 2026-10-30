import { useMemo, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  ArrowUpDown,
  Check,
  ChevronsUpDown,
  Pencil,
  Plus,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RenameEnvironmentDialog } from '@/components/pages/projects/$projectId/shared/RenameEnvironmentDialog'
import { ReorderEnvironmentsDialog } from '@/components/pages/projects/$projectId/shared/ReorderEnvironmentsDialog'
import {
  projectEnvironmentDotClass,
  sortProjectEnvironments,
  type ProjectEnvironmentOption,
} from '@/lib/project-environments'
import { parseEnvironmentsOrder } from '@/lib/project-prefs-keys'
import {
  useDeleteProjectEnvironment,
  useProject,
  useProjectEnvironments,
  useProjectPrefs,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

interface EnvironmentSwitcherProps {
  projectId: string
  collapsed?: boolean
  isMobile?: boolean
}

function EnvironmentDot({
  environment,
  className,
}: {
  environment: ProjectEnvironmentOption
  className?: string
}) {
  return (
    <span
      aria-hidden
      className={cn(
        'h-2 w-2 shrink-0 rounded-full',
        projectEnvironmentDotClass(environment),
        className,
      )}
    />
  )
}

export function EnvironmentSwitcher({
  projectId,
  collapsed = false,
  isMobile = false,
}: EnvironmentSwitcherProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const environmentsEnabled = features.projectEnvironments
  // `projectId` is whichever project the Console is showing, which may be an
  // environment. The switcher always lists from the parent, which is also the
  // only project the environment routes accept.
  const { project, projectData } = useProject(projectId)
  const parentProjectId = projectData
    ? projectData.parentId || projectData.$id
    : undefined
  const { project: parentProject } = useProject(parentProjectId)
  const region = project?.region
  const { environments, error, refetch } = useProjectEnvironments(
    parentProjectId,
    region,
    environmentsEnabled,
  )
  // The order members gave the list, kept in the parent's prefs
  const { prefs } = useProjectPrefs(
    parentProjectId,
    region,
    environmentsEnabled,
  )

  const [reorderOpen, setReorderOpen] = useState(false)
  const [renameOpen, setRenameOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  const deleteEnvironmentMutation = useDeleteProjectEnvironment(
    parentProjectId,
    region,
  )

  // The parent is the default environment and is never one of the rows the
  // list endpoint returns.
  const production = useMemo<ProjectEnvironmentOption | null>(
    () =>
      parentProjectId
        ? {
            id: parentProjectId,
            name: parentProject?.name || parentProjectId,
            isDefault: true,
          }
        : null,
    [parentProjectId, parentProject?.name],
  )
  const environmentOptions = useMemo<ProjectEnvironmentOption[]>(
    () =>
      sortProjectEnvironments(environments, parseEnvironmentsOrder(prefs)).map(
        (environment) => ({
          id: environment.$id,
          name: environment.name || environment.$id,
          isDefault: false,
        }),
      ),
    [environments, prefs],
  )

  if (!environmentsEnabled || !project || !parentProjectId || !production) {
    return null
  }

  const options = [production, ...environmentOptions]

  const selected: ProjectEnvironmentOption = {
    id: project.$id,
    name: project.name || project.$id,
    isDefault: project.$id === parentProjectId,
  }

  const openProject = (nextProjectId: string) =>
    navigate({
      to: '/projects/$projectId',
      params: { projectId: nextProjectId },
    })

  // Environments are created from the parent, on its own wizard route
  const openCreate = () =>
    navigate({
      to: '/projects/$projectId/environments/create',
      params: { projectId: parentProjectId },
    })

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
    deleteEnvironmentMutation.mutate(selected.id, {
      onSuccess: async () => {
        toast.success(t('Environment deleted'))
        await openProject(parentProjectId)
        // Only once its page is gone, or that page refetches a deleted project.
        queryClient.removeQueries({ queryKey: ['project', selected.id] })
      },
      onError: (deleteError: Error) => {
        toast.error(
          getErrorMessage(deleteError, t('Failed to delete environment')),
        )
      },
    })
  }

  const trigger = collapsed ? (
    <button
      type="button"
      aria-label={t('Switch environment')}
      className="flex h-8 w-full cursor-pointer items-center justify-center rounded-md border border-border bg-card/50 transition-colors hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <EnvironmentDot environment={selected} className="h-2.5 w-2.5" />
    </button>
  ) : (
    <button
      type="button"
      aria-label={t('Switch environment')}
      className={cn(
        'flex w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-card/50 px-2.5 text-start transition-colors hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isMobile ? 'h-10' : 'h-8',
      )}
    >
      <EnvironmentDot environment={selected} />
      <span
        className={cn(
          'min-w-0 flex-1 truncate font-medium text-foreground',
          isMobile ? 'text-[14px]' : 'text-[13px]',
        )}
      >
        {selected.name}
      </span>
      <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    </button>
  )

  return (
    <>
      <DropdownMenu
        // A failed list leaves only the project row; opening the menu retries it.
        onOpenChange={(open) => {
          if (open && error) void refetch()
        }}
      >
        {collapsed ? (
          <Tooltip delayDuration={0}>
            <TooltipTrigger asChild>
              <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            </TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
              <p>{selected.name}</p>
            </TooltipContent>
          </Tooltip>
        ) : (
          <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
        )}
        <DropdownMenuContent
          side={collapsed ? 'right' : 'bottom'}
          align="start"
          sideOffset={collapsed ? 12 : 4}
          className={cn(
            collapsed
              ? 'w-[240px]'
              : 'w-[var(--radix-dropdown-menu-trigger-width)] min-w-[220px]',
          )}
        >
          <DropdownMenuLabel className="text-[11px] font-medium text-muted-foreground">
            {t('Environments')}
          </DropdownMenuLabel>
          {options.map((option) => {
            const isActive = option.id === selected.id
            return (
              <DropdownMenuItem
                key={option.id}
                asChild
                className={cn('gap-2', isActive && 'bg-accent/50')}
              >
                <Link
                  to="/projects/$projectId"
                  params={{ projectId: option.id }}
                  // Opening the current project again would only leave the
                  // page for its root.
                  onClick={
                    isActive ? (event) => event.preventDefault() : undefined
                  }
                >
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                    <EnvironmentDot environment={option} />
                  </span>
                  <span className="min-w-0 flex-1 truncate text-[13px]">
                    {option.name}
                  </span>
                  <Check
                    className={cn(
                      'h-3.5 w-3.5 shrink-0',
                      isActive ? 'text-foreground' : 'invisible',
                    )}
                  />
                </Link>
              </DropdownMenuItem>
            )
          })}
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onSelect={() => void openCreate()}
            className="text-[13px]"
          >
            <MenuItemContent icon={Plus}>
              {t('Create environment')}
            </MenuItemContent>
          </DropdownMenuItem>
          {/* One environment has nothing to be ordered against */}
          {environmentOptions.length > 1 && (
            <DropdownMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() => setReorderOpen(true))
              }
              className="text-[13px]"
            >
              <MenuItemContent icon={ArrowUpDown}>
                {t('Reorder environments')}
              </MenuItemContent>
            </DropdownMenuItem>
          )}
          {/* The parent is managed from its own settings, so rename and delete
              only apply to the environment being shown. */}
          {!selected.isDefault && (
            <>
              <DropdownMenuItem
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => setRenameOpen(true))
                }
                className="text-[13px]"
              >
                <MenuItemContent icon={Pencil}>
                  {t('Rename environment')}
                </MenuItemContent>
              </DropdownMenuItem>
              <DropdownMenuItem
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => setDeleteOpen(true))
                }
                className="text-[13px]"
              >
                <MenuItemContent icon={Trash2}>
                  {t('Delete environment')}
                </MenuItemContent>
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      <ReorderEnvironmentsDialog
        open={reorderOpen}
        onOpenChange={setReorderOpen}
        projectId={parentProjectId}
        region={region}
        prefs={prefs}
        production={production}
        environments={environmentOptions}
      />

      <RenameEnvironmentDialog
        open={renameOpen}
        onOpenChange={setRenameOpen}
        projectId={parentProjectId}
        region={region}
        environment={selected.isDefault ? null : selected}
      />

      <ConfirmNameDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title="Delete environment"
        description={t(
          'Are you sure you want to delete this environment? Its resources and users are removed with it. This action cannot be undone.',
        )}
        confirmValue={selected.name.trim() || selected.id}
        confirmPlaceholder="Enter environment name"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteEnvironmentMutation.isPending}
      />
    </>
  )
}
