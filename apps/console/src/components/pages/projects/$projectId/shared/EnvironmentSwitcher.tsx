import { useMemo, useState, type KeyboardEvent } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  DndContext,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import {
  Check,
  ChevronsUpDown,
  GripVertical,
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
import {
  projectEnvironmentDotClass,
  sortProjectEnvironments,
  type ProjectEnvironmentOption,
} from '@/lib/project-environments'
import {
  mergeEnvironmentsOrderPrefs,
  parseEnvironmentsOrder,
} from '@/lib/project-prefs-keys'
import {
  useDeleteProjectEnvironment,
  useProject,
  useProjectEnvironments,
  useProjectPrefs,
  useUpdateProjectPrefs,
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

/** The row contents every option shares; the handle column stays so dots line up. */
function EnvironmentRow({
  option,
  isActive,
  handle,
}: {
  option: ProjectEnvironmentOption
  isActive: boolean
  handle?: React.ReactNode
}) {
  return (
    <>
      <span className="flex h-4 w-4 shrink-0 items-center justify-center">
        {handle}
      </span>
      <span className="flex h-4 w-4 shrink-0 items-center justify-center">
        <EnvironmentDot environment={option} />
      </span>
      <span className="min-w-0 flex-1 truncate text-[13px]">{option.name}</span>
      <Check
        className={cn(
          'h-3.5 w-3.5 shrink-0',
          isActive ? 'text-foreground' : 'invisible',
        )}
      />
    </>
  )
}

/**
 * An environment row that can be dragged into place by its handle, or moved
 * with Alt+Arrow while focused. The handle swallows its pointer events so the
 * menu item does not take the press for a selection, and every row but the
 * dragged one ignores the pointer during a drag so the drop does not select
 * the row it lands on.
 */
function SortableEnvironmentItem({
  option,
  isActive,
  canReorder,
  someoneDragging,
  onSelect,
  onMove,
}: {
  option: ProjectEnvironmentOption
  isActive: boolean
  canReorder: boolean
  someoneDragging: boolean
  onSelect: () => void
  onMove: (direction: -1 | 1) => void
}) {
  const t = useT()
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: option.id, disabled: !canReorder })

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (!canReorder || !event.altKey) return
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return
    event.preventDefault()
    event.stopPropagation()
    onMove(event.key === 'ArrowUp' ? -1 : 1)
  }

  return (
    <DropdownMenuItem
      asChild
      className={cn(
        'group gap-2',
        isActive && 'bg-accent/50',
        isDragging && 'relative z-10 bg-background shadow-md',
        someoneDragging && !isDragging && 'pointer-events-none',
      )}
      onSelect={onSelect}
      onKeyDown={handleKeyDown}
    >
      <div
        ref={setNodeRef}
        style={{ transform: CSS.Transform.toString(transform), transition }}
      >
        <EnvironmentRow
          option={option}
          isActive={isActive}
          handle={
            canReorder ? (
              <button
                ref={setActivatorNodeRef}
                type="button"
                aria-label={t('Drag to reorder')}
                className={cn(
                  'flex h-4 w-4 cursor-grab touch-none items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing',
                  isDragging && 'opacity-100',
                )}
                {...attributes}
                {...listeners}
                onPointerDown={(event) => {
                  event.stopPropagation()
                  listeners?.onPointerDown?.(event)
                }}
                onClick={(event) => {
                  event.preventDefault()
                  event.stopPropagation()
                }}
              >
                <GripVertical className="h-3.5 w-3.5" />
              </button>
            ) : null
          }
        />
      </div>
    </DropdownMenuItem>
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
  const updatePrefsMutation = useUpdateProjectPrefs(parentProjectId, region)

  const [renameOpen, setRenameOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [dragging, setDragging] = useState(false)
  // The order as just dragged, shown until the saved prefs carry it
  const [pendingOrder, setPendingOrder] = useState<string[] | null>(null)

  const deleteEnvironmentMutation = useDeleteProjectEnvironment(
    parentProjectId,
    region,
  )

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
  )

  // The parent is production. It has no environment document of its own, so it
  // is never in the list endpoint; the switcher always labels it Production.
  const productionName = t('Production')
  const production = useMemo<ProjectEnvironmentOption | null>(
    () =>
      parentProjectId
        ? {
            id: parentProjectId,
            name: productionName,
            isDefault: true,
          }
        : null,
    [parentProjectId, productionName],
  )
  const environmentOptions = useMemo<ProjectEnvironmentOption[]>(
    () =>
      sortProjectEnvironments(
        environments,
        pendingOrder ?? parseEnvironmentsOrder(prefs),
      ).map((environment) => ({
        id: environment.$id,
        name: environment.name || environment.$id,
        isDefault: false,
      })),
    [environments, prefs, pendingOrder],
  )
  const environmentIds = useMemo(
    () => environmentOptions.map((option) => option.id),
    [environmentOptions],
  )

  if (!environmentsEnabled || !project || !parentProjectId || !production) {
    return null
  }

  const selected: ProjectEnvironmentOption =
    project.$id === parentProjectId
      ? production
      : {
          id: project.$id,
          name: project.name || project.$id,
          isDefault: false,
        }

  // The update replaces the whole prefs object, so nothing is saved over
  // prefs that never loaded; one environment has nothing to be ordered against
  const canReorder =
    prefs !== null &&
    environmentOptions.length > 1 &&
    !updatePrefsMutation.isPending

  const openProject = (nextProjectId: string) => {
    // Opening the current project again would only leave the page for its root.
    if (nextProjectId === selected.id) return
    void navigate({
      to: '/projects/$projectId',
      params: { projectId: nextProjectId },
    })
  }

  const saveOrder = (ids: string[]) => {
    setPendingOrder(ids)
    updatePrefsMutation.mutate(mergeEnvironmentsOrderPrefs(prefs, ids), {
      // The hook has written the prefs to the cache by now, so the saved
      // order takes over from the pending one without a flicker
      onSuccess: () => setPendingOrder(null),
      onError: (orderError: Error) => {
        setPendingOrder(null)
        toast.error(
          getErrorMessage(orderError, t('Failed to update environment order')),
        )
      },
    })
  }

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    setDragging(false)
    if (!over || active.id === over.id) return
    const from = environmentIds.indexOf(String(active.id))
    const to = environmentIds.indexOf(String(over.id))
    if (from === -1 || to === -1) return
    saveOrder(arrayMove(environmentIds, from, to))
  }

  const moveEnvironment = (id: string, direction: -1 | 1) => {
    const from = environmentIds.indexOf(id)
    const to = from + direction
    if (from === -1 || to < 0 || to >= environmentIds.length) return
    saveOrder(arrayMove(environmentIds, from, to))
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
    deleteEnvironmentMutation.mutate(selected.id, {
      onSuccess: async () => {
        toast.success(t('Environment deleted'))
        await navigate({
          to: '/projects/$projectId',
          params: { projectId: parentProjectId },
        })
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
          if (!open) setDragging(false)
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
          <DropdownMenuItem
            className={cn(
              'gap-2',
              production.id === selected.id && 'bg-accent/50',
              dragging && 'pointer-events-none',
            )}
            onSelect={() => openProject(production.id)}
          >
            <EnvironmentRow
              option={production}
              isActive={production.id === selected.id}
            />
          </DropdownMenuItem>
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragStart={() => setDragging(true)}
            onDragCancel={() => setDragging(false)}
            onDragEnd={handleDragEnd}
          >
            <SortableContext
              items={environmentIds}
              strategy={verticalListSortingStrategy}
            >
              {environmentOptions.map((option) => (
                <SortableEnvironmentItem
                  key={option.id}
                  option={option}
                  isActive={option.id === selected.id}
                  canReorder={canReorder}
                  someoneDragging={dragging}
                  onSelect={() => openProject(option.id)}
                  onMove={(direction) => moveEnvironment(option.id, direction)}
                />
              ))}
            </SortableContext>
          </DndContext>
          <DropdownMenuSeparator />
          <DropdownMenuItem asChild className="text-[13px]">
            <Link
              to="/projects/$projectId/environments/create"
              params={{ projectId: parentProjectId }}
            >
              <MenuItemContent icon={Plus}>
                {t('Create environment')}
              </MenuItemContent>
            </Link>
          </DropdownMenuItem>
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
