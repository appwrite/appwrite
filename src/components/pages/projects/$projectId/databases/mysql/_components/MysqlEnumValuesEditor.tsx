import { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react'
import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  type DragEndEvent,
  type DragStartEvent,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { GripVertical, Plus, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { getAxisRestrictedDragModifiers, sortableAxisTransform } from '@/lib/dnd-modifiers'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const enumValueDragModifiers = getAxisRestrictedDragModifiers('vertical')

const rowIconButtonClass =
  'flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:bg-muted focus-visible:text-foreground'

type EnumValueEntry = {
  id: string
  value: string
}

type MysqlEnumValuesEditorProps = {
  values: string[]
  onChange: (values: string[]) => void
  /** When true, warn that dropping a label fails if rows still use it. */
  existing?: boolean
}

type SortableEnumValueRowProps = {
  entry: EnumValueEntry
  canRemove: boolean
  autoFocus?: boolean
  onAutoFocused?: () => void
  onValueChange: (value: string) => void
  onRemove: () => void
}

function createEntry(value = ''): EnumValueEntry {
  return { id: crypto.randomUUID(), value }
}

function SortableEnumValueRow({
  entry,
  canRemove,
  autoFocus = false,
  onAutoFocused,
  onValueChange,
  onRemove,
}: SortableEnumValueRowProps) {
  const t = useT()
  const inputRef = useRef<HTMLInputElement>(null)
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: entry.id,
    animateLayoutChanges: () => false,
    transition: null,
  })

  useLayoutEffect(() => {
    if (!autoFocus) return
    inputRef.current?.focus()
    onAutoFocused?.()
  }, [autoFocus, onAutoFocused])

  const style = isDragging
    ? undefined
    : {
        transform: sortableAxisTransform(transform, 'vertical'),
        transition,
      }

  const removeControl = (
    <button
      type="button"
      disabled={!canRemove}
      onClick={onRemove}
      className={cn(
        rowIconButtonClass,
        !canRemove &&
          'cursor-not-allowed opacity-40 hover:bg-transparent hover:text-muted-foreground',
      )}
      aria-label={t('Remove value')}
    >
      <X className="h-4 w-4" />
    </button>
  )

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        'group flex h-10 items-center gap-1 px-1.5',
        isDragging && 'opacity-0',
      )}
    >
      <button
        ref={setActivatorNodeRef}
        type="button"
        className={cn(rowIconButtonClass, 'cursor-grab touch-none active:cursor-grabbing')}
        aria-label={t('Drag to reorder value')}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4" />
      </button>

      <input
        ref={inputRef}
        value={entry.value}
        onChange={(event) => onValueChange(event.target.value)}
        placeholder={t('Value')}
        className="h-8 min-w-0 flex-1 rounded-md border-0 bg-transparent px-2 font-mono text-[13px] shadow-none outline-none focus-visible:border-transparent focus-visible:ring-0 placeholder:text-muted-foreground"
        autoComplete="off"
        spellCheck={false}
      />

      {canRemove ? (
        removeControl
      ) : (
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex shrink-0">{removeControl}</span>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-xs text-[12px]">
            {t('Enum columns need at least one value.')}
          </TooltipContent>
        </Tooltip>
      )}
    </div>
  )
}

function EnumValueRowPreview({ entry }: { entry: EnumValueEntry }) {
  return (
    <div className="flex h-10 items-center gap-1 overflow-hidden rounded-lg border border-border bg-background px-1.5 shadow-md">
      <div className={cn(rowIconButtonClass, 'pointer-events-none')}>
        <GripVertical className="h-4 w-4" />
      </div>
      <div className="flex h-8 min-w-[12rem] flex-1 items-center px-2">
        <span className="truncate font-mono text-[13px] text-foreground">
          {entry.value.trim() || '…'}
        </span>
      </div>
    </div>
  )
}

export function MysqlEnumValuesEditor({
  values,
  onChange,
  existing = false,
}: MysqlEnumValuesEditorProps) {
  const t = useT()
  const [entries, setEntries] = useState<EnumValueEntry[]>(() =>
    (values.length > 0 ? values : ['']).map((value) => createEntry(value)),
  )
  const [activeId, setActiveId] = useState<string | null>(null)
  const [focusEntryId, setFocusEntryId] = useState<string | null>(null)

  const commit = (next: EnumValueEntry[]) => {
    setEntries(next)
    onChange(next.map((entry) => entry.value))
  }

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 6 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const sortableIds = useMemo(() => entries.map((entry) => entry.id), [entries])
  const activeEntry = useMemo(
    () => entries.find((entry) => entry.id === activeId) ?? null,
    [activeId, entries],
  )

  const handleDragStart = (event: DragStartEvent) => {
    setActiveId(String(event.active.id))
  }

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveId(null)
    const { active, over } = event
    if (!over || active.id === over.id) return

    const oldIndex = entries.findIndex((entry) => entry.id === active.id)
    const newIndex = entries.findIndex((entry) => entry.id === over.id)
    if (oldIndex === -1 || newIndex === -1) return

    commit(arrayMove(entries, oldIndex, newIndex))
  }

  const handleDragCancel = () => {
    setActiveId(null)
  }

  const handleAddValue = () => {
    const newEntry = createEntry()
    setFocusEntryId(newEntry.id)
    commit([...entries, newEntry])
  }

  const handleAutoFocused = useCallback(() => {
    setFocusEntryId(null)
  }, [])

  return (
    <div className="space-y-3">
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        modifiers={enumValueDragModifiers}
        onDragStart={handleDragStart}
        onDragEnd={handleDragEnd}
        onDragCancel={handleDragCancel}
      >
        <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
          <div className="overflow-hidden rounded-lg border border-border bg-card divide-y divide-border">
            {entries.map((entry) => (
              <SortableEnumValueRow
                key={entry.id}
                entry={entry}
                autoFocus={focusEntryId === entry.id}
                onAutoFocused={handleAutoFocused}
                canRemove={entries.length > 1}
                onValueChange={(value) =>
                  commit(
                    entries.map((current) =>
                      current.id === entry.id ? { ...current, value } : current,
                    ),
                  )
                }
                onRemove={() =>
                  commit(entries.filter((current) => current.id !== entry.id))
                }
              />
            ))}
          </div>
        </SortableContext>

        <DragOverlay dropAnimation={null}>
          {activeEntry ? <EnumValueRowPreview entry={activeEntry} /> : null}
        </DragOverlay>
      </DndContext>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 text-[12px]"
        onClick={handleAddValue}
      >
        <Plus className="me-1.5 h-3.5 w-3.5" />
        {t('Add value')}
      </Button>

      <p className="text-[11px] text-muted-foreground">
        {existing
          ? t('Removing a value fails if existing rows still use it.')
          : t('Drag to set the order of allowed values.')}
      </p>
    </div>
  )
}
