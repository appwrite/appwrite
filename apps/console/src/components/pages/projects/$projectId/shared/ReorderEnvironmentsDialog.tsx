/**
 * Reorders the environments of a project for the switcher. Production keeps the
 * first row; the rest are dragged, or moved with the keyboard, into place and
 * the order is saved in the production project's prefs, so every member of the
 * project sees the same list.
 */

import { useEffect, useMemo, useState } from 'react'
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { GripVertical } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  projectEnvironmentDotClass,
  type ProjectEnvironmentOption,
} from '@/lib/project-environments'
import {
  mergeEnvironmentsOrderPrefs,
  type ProjectPrefs,
} from '@/lib/project-prefs-keys'
import { useUpdateProjectPrefs } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

interface ReorderEnvironmentsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The production project, whose prefs hold the order */
  projectId: string
  region?: string
  /** The project's prefs as loaded; null while loading or after a failed fetch */
  prefs: ProjectPrefs | null
  production: ProjectEnvironmentOption
  /** The environments in the order the switcher shows them now */
  environments: ProjectEnvironmentOption[]
}

function SortableEnvironmentRow({
  environment,
  disabled,
}: {
  environment: ProjectEnvironmentOption
  disabled: boolean
}) {
  const t = useT()
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: environment.id, disabled })

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'flex items-center gap-3 rounded-lg border border-border bg-card/50 px-3 py-2',
        isDragging && 'relative z-10 bg-background shadow-md',
      )}
    >
      <button
        type="button"
        className="flex h-6 w-6 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing disabled:cursor-default"
        aria-label={t('Drag to reorder')}
        disabled={disabled}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4" />
      </button>
      <span
        aria-hidden
        className={cn(
          'h-2 w-2 shrink-0 rounded-full',
          projectEnvironmentDotClass(environment),
        )}
      />
      <span className="min-w-0 flex-1 truncate text-[13px] text-foreground">
        {environment.name}
      </span>
    </li>
  )
}

export function ReorderEnvironmentsDialog({
  open,
  onOpenChange,
  projectId,
  region,
  prefs,
  production,
  environments,
}: ReorderEnvironmentsDialogProps) {
  const t = useT()
  const [items, setItems] = useState<ProjectEnvironmentOption[]>(environments)
  const updatePrefsMutation = useUpdateProjectPrefs(projectId, region)

  // Start from the order on screen each time the dialog opens
  useEffect(() => {
    if (open) setItems(environments)
  }, [open, environments])

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const ids = useMemo(() => items.map((item) => item.id), [items])
  const isDirty = ids.some((id, index) => id !== environments[index]?.id)
  const isPending = updatePrefsMutation.isPending
  // The update replaces the whole prefs object, so saving over prefs that
  // never loaded would drop every other key the project holds
  const prefsLoaded = prefs !== null

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return
    setItems((prev) => {
      const from = prev.findIndex((item) => item.id === active.id)
      const to = prev.findIndex((item) => item.id === over.id)
      if (from === -1 || to === -1) return prev
      return arrayMove(prev, from, to)
    })
  }

  const handleSave = () => {
    updatePrefsMutation.mutate(mergeEnvironmentsOrderPrefs(prefs, ids), {
      onSuccess: () => {
        toast.success(t('Environment order updated'))
        onOpenChange(false)
      },
      onError: (error: Error) => {
        toast.error(
          getErrorMessage(error, t('Failed to update environment order')),
        )
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Reorder environments')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Drag the environments into the order the switcher should list them. Production always comes first.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <ul className="space-y-2">
            <li className="flex items-center gap-3 rounded-lg border border-dashed border-border px-3 py-2 ps-12">
              <span
                aria-hidden
                className={cn(
                  'h-2 w-2 shrink-0 rounded-full',
                  projectEnvironmentDotClass(production),
                )}
              />
              <span className="min-w-0 flex-1 truncate text-[13px] text-muted-foreground">
                {production.name}
              </span>
              <span className="shrink-0 text-[11px] text-muted-foreground">
                {t('Production')}
              </span>
            </li>
            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={handleDragEnd}
            >
              <SortableContext
                items={ids}
                strategy={verticalListSortingStrategy}
              >
                {items.map((environment) => (
                  <SortableEnvironmentRow
                    key={environment.id}
                    environment={environment}
                    disabled={isPending}
                  />
                ))}
              </SortableContext>
            </DndContext>
          </ul>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          {prefsLoaded ? (
            <Button
              type="button"
              variant="brandCta"
              onClick={handleSave}
              disabled={!isDirty || isPending}
            >
              {t('Save')}
            </Button>
          ) : (
            <Tooltip>
              <TooltipTrigger asChild>
                <span tabIndex={0} className="inline-flex">
                  <Button type="button" variant="brandCta" disabled>
                    {t('Save')}
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent>
                {t('The project preferences have not loaded yet.')}
              </TooltipContent>
            </Tooltip>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
