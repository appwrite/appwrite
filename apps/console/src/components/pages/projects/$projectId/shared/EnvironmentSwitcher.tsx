import { useEffect, useMemo, useState } from 'react'
import { Check, ChevronsUpDown, Pencil, Plus, Trash2 } from 'lucide-react'
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
import { CreateEnvironmentDialog } from '@/components/pages/projects/$projectId/shared/CreateEnvironmentDialog'
import { RenameEnvironmentDialog } from '@/components/pages/projects/$projectId/shared/RenameEnvironmentDialog'
import {
  projectEnvironmentDotClass,
  type ProjectEnvironmentOption,
  resolveProjectEnvironmentSelection,
  useProjectEnvironmentSelection,
} from '@/lib/project-environments'
import {
  useDeleteProjectEnvironment,
  useProject,
  useProjectEnvironments,
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
  const { features } = useConsoleProfile()
  const environmentsEnabled = features.projectEnvironments
  const { project } = useProject(projectId)
  const region = project?.region
  const { environments, isFetched, error } = useProjectEnvironments(
    projectId,
    region,
    environmentsEnabled,
  )
  const { selectedEnvironmentId, selectEnvironment } =
    useProjectEnvironmentSelection(projectId)

  const [createOpen, setCreateOpen] = useState(false)
  const [renameOpen, setRenameOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  const deleteEnvironmentMutation = useDeleteProjectEnvironment(
    projectId,
    region,
  )

  // The project itself is the default environment: it is the parent of every
  // environment and is never one of the rows the list endpoint returns.
  const options = useMemo<ProjectEnvironmentOption[]>(
    () => [
      {
        id: projectId,
        name: project?.name || projectId,
        isDefault: true,
      },
      ...environments.map((environment) => ({
        id: environment.$id,
        name: environment.name || environment.$id,
        isDefault: false,
      })),
    ],
    [projectId, project?.name, environments],
  )

  const { selected: resolvedSelection, isStale } =
    resolveProjectEnvironmentSelection(options, selectedEnvironmentId)
  // `options` always starts with the project, so a selection always resolves.
  const selected = resolvedSelection!

  // A stored id can outlive its environment (deleted here or on another
  // device), so drop it once a loaded list says it is gone. Only a successful
  // list counts: mid-flight, and on failure, the project is the only option.
  useEffect(() => {
    if (error || !isFetched || !isStale) return
    selectEnvironment(null)
  }, [error, isFetched, isStale, selectEnvironment])

  // Self-hosted has no `/project/environments` routes, and a member without
  // project read access cannot list them: show nothing rather than an error.
  if (!environmentsEnabled || error) return null

  const handleSelect = (next: ProjectEnvironmentOption) => {
    if (next.id === selected.id) return
    selectEnvironment(next.isDefault ? null : next.id)
    toast.success(t('Environment switched'))
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
    deleteEnvironmentMutation.mutate(selected.id, {
      onSuccess: () => {
        selectEnvironment(null)
        toast.success(t('Environment deleted'))
      },
      onError: (deleteError: Error) => {
        toast.error(
          getErrorMessage(deleteError, 'Failed to delete environment'),
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
      <DropdownMenu>
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
                onSelect={() => handleSelect(option)}
                className={cn('gap-2', isActive && 'bg-accent/50')}
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
              </DropdownMenuItem>
            )
          })}
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => setCreateOpen(true))
            }
            className="text-[13px]"
          >
            <MenuItemContent icon={Plus}>
              {t('Create environment')}
            </MenuItemContent>
          </DropdownMenuItem>
          {/* The project is managed from its own settings, so rename and delete
              only apply to the environments listed under it. */}
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

      <CreateEnvironmentDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
        region={region}
        onCreated={(environmentId) => selectEnvironment(environmentId)}
      />

      <RenameEnvironmentDialog
        open={renameOpen}
        onOpenChange={setRenameOpen}
        projectId={projectId}
        region={region}
        environment={selected.isDefault ? null : selected}
      />

      <ConfirmNameDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title="Delete environment"
        description={
          <>
            {t(
              'Are you sure you want to delete this environment? Its resources and users are removed with it. This action cannot be undone.',
            )}
          </>
        }
        confirmValue={selected.name.trim() || selected.id}
        confirmPlaceholder="Enter environment name"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteEnvironmentMutation.isPending}
      />
    </>
  )
}
