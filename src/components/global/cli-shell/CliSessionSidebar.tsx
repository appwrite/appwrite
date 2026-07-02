import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
  type PointerEvent,
  type ReactNode,
  type RefObject,
} from 'react'
import {
  Columns2,
  GripVertical,
  Loader2,
  Terminal as TerminalIcon,
  Trash2,
} from 'lucide-react'
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
  horizontalListSortingStrategy,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { getAxisRestrictedDragModifiers, sortableAxisTransform } from '@/lib/dnd-modifiers'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { getCliShellSplitChildren } from '@/lib/cli-shell/cli-shell-split'
import type { CliShellSession } from '@/lib/cli-shell/cli-shell-sessions'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { MAX_CLI_SHELL_SESSION_NAME_LENGTH } from '@/lib/user-prefs-keys'
import { useCliShell } from './CliShellProvider'
import { useCliTerminalSessionsLayoutMode } from './CliTerminalLayoutContext'

type SessionRowProps = {
  session: CliShellSession
  isRoot: boolean
  isNested?: boolean
  isActive: boolean
  isFocused: boolean
  isInSplitPane: boolean
  isRunning: boolean
  isEditing: boolean
  editingName: string
  editInputRef: RefObject<HTMLInputElement | null>
  canRemoveRoot: boolean
  layout: 'sidebar' | 'strip'
  onSelect: () => void
  onSplit: () => void
  onCloseSplit: () => void
  onRemoveRoot: () => void
  onStartEditing: () => void
  onEditingNameChange: (value: string) => void
  onCommitRename: () => void
  onCancelRename: () => void
  dragHandleProps?: {
    ref: (element: HTMLButtonElement | null) => void
    onPointerDown?: (event: PointerEvent<HTMLButtonElement>) => void
    onKeyDown?: (event: KeyboardEvent<HTMLButtonElement>) => void
    'aria-describedby'?: string
    'aria-pressed'?: boolean | 'true' | 'false' | 'mixed'
    'aria-roledescription'?: string
    tabIndex?: number
  }
}

function SessionRow({
  session,
  isRoot,
  isNested = false,
  isActive,
  isFocused,
  isInSplitPane,
  isRunning,
  isEditing,
  editingName,
  editInputRef,
  canRemoveRoot,
  layout,
  onSelect,
  onSplit,
  onCloseSplit,
  onRemoveRoot,
  onStartEditing,
  onEditingNameChange,
  onCommitRename,
  onCancelRename,
  dragHandleProps,
}: SessionRowProps) {
  const t = useT()
  const isStrip = layout === 'strip'
  const isHighlighted = isNested
    ? isRunning
    : isActive || isFocused || isInSplitPane

  return (
    <div
      className={cn(
        'group flex items-center gap-0.5',
        isStrip
          ? cn(
              'h-7 max-w-[180px] shrink-0 rounded-md border px-0.5',
              isHighlighted
                ? 'border-border bg-background text-foreground shadow-sm'
                : 'border-transparent text-muted-foreground hover:bg-muted/60',
            )
          : cn(
              'h-11',
              isRoot && 'ps-1 pe-1',
              !isNested && 'hover:bg-muted/60',
              !isNested && isFocused && 'bg-muted/60',
              isRoot && isActive && !isFocused && 'bg-muted/40',
            ),
      )}
    >
      {isRoot && dragHandleProps && !isStrip ? (
        <button
          type="button"
          ref={dragHandleProps.ref}
          className="flex h-7 w-5 shrink-0 cursor-grab touch-none items-center justify-center rounded-sm text-muted-foreground opacity-0 transition-opacity hover:bg-muted/60 hover:opacity-100 group-hover:opacity-100 focus-visible:opacity-100 active:cursor-grabbing"
          aria-label={`${t('Reorder')} ${session.name}`}
          onPointerDown={dragHandleProps.onPointerDown}
          onKeyDown={dragHandleProps.onKeyDown}
          aria-describedby={dragHandleProps['aria-describedby']}
          aria-pressed={dragHandleProps['aria-pressed']}
          aria-roledescription={dragHandleProps['aria-roledescription']}
          tabIndex={dragHandleProps.tabIndex}
        >
          <GripVertical className="h-3.5 w-3.5" />
        </button>
      ) : null}
      <div
        role={isEditing ? undefined : 'button'}
        tabIndex={isEditing ? -1 : 0}
        onClick={() => {
          if (!isEditing) onSelect()
        }}
        onKeyDown={(event) => {
          if (isEditing) return
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            onSelect()
          }
        }}
        className={cn(
          'flex min-w-0 flex-1 items-center gap-2 rounded-md text-start text-foreground transition-colors',
          isStrip ? 'h-7 px-1.5' : cn('h-11', isNested ? 'px-0' : 'px-2'),
          !isEditing && 'cursor-pointer',
        )}
        aria-current={!isNested && isFocused ? 'true' : undefined}
      >
        {isRunning ? (
          <Loader2
            className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
            strokeWidth={isHighlighted ? 3 : 2}
          />
        ) : (
          <TerminalIcon
            className="h-3.5 w-3.5 shrink-0 text-muted-foreground rtl:-scale-x-100"
            strokeWidth={isHighlighted ? 3 : 2}
          />
        )}
        <div
          className="min-w-0 flex-1 h-7"
          onDoubleClick={(event) => {
            event.stopPropagation()
            if (!isEditing) onStartEditing()
          }}
        >
          {isEditing ? (
            <Input
              ref={editInputRef}
              value={editingName}
              maxLength={MAX_CLI_SHELL_SESSION_NAME_LENGTH}
              onChange={(event) => onEditingNameChange(event.target.value)}
              onBlur={onCommitRename}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  onCommitRename()
                }
                if (event.key === 'Escape') {
                  event.preventDefault()
                  onCancelRename()
                }
              }}
              className={cn(
                'h-7 min-h-7 max-h-7 w-full min-w-0 rounded-sm border-0 bg-transparent px-0 py-0 text-[12px] font-medium leading-7 shadow-none outline-none',
                'focus-visible:border-0 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-ring/50 focus-visible:ring-offset-0',
                isNested && 'text-muted-foreground',
              )}
              aria-label={t('Rename terminal session')}
            />
          ) : (
            <span
              className={cn(
                'block truncate text-[12px] font-medium leading-7 h-7',
                isNested && 'text-muted-foreground',
              )}
            >
              {session.name}
            </span>
          )}
        </div>
      </div>

      <Button
        type="button"
        variant="ghost"
        size="icon"
        className={cn(
          'h-7 w-7 shrink-0 text-muted-foreground',
          isStrip
            ? cn(isHighlighted && 'opacity-100', !isHighlighted && 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100')
            : 'opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100',
        )}
        onClick={(event) => {
          event.stopPropagation()
          onSplit()
        }}
        title={t('Split terminal')}
        aria-label={`${t('Split')} ${session.name}`}
      >
        <Columns2 className="h-3.5 w-3.5" />
      </Button>

      {isNested ? (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className={cn(
            'h-7 w-7 shrink-0 text-muted-foreground',
            isStrip
              ? cn(isHighlighted && 'opacity-100', !isHighlighted && 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100')
              : 'opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100',
          )}
          onClick={(event) => {
            event.stopPropagation()
            onCloseSplit()
          }}
          title={t('Delete terminal')}
          aria-label={`${t('Delete')} ${session.name}`}
        >
          <Trash2 className="h-3.5 w-3.5" />
        </Button>
      ) : canRemoveRoot ? (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className={cn(
            'h-7 w-7 shrink-0 text-muted-foreground',
            isStrip
              ? cn(isHighlighted && 'opacity-100', !isHighlighted && 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100')
              : 'opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100',
          )}
          onClick={(event) => {
            event.stopPropagation()
            onRemoveRoot()
          }}
          title={t('Delete terminal')}
          aria-label={`${t('Delete')} ${session.name}`}
        >
          <Trash2 className="h-3.5 w-3.5" />
        </Button>
      ) : null}
    </div>
  )
}

type RootSessionGroupBodyProps = {
  session: CliShellSession
  children: ReactNode
  layout: 'sidebar' | 'strip'
  rowProps: Omit<SessionRowProps, 'session' | 'isRoot' | 'dragHandleProps' | 'layout'>
  canReorder: boolean
  dragHandleProps?: SessionRowProps['dragHandleProps']
}

function RootSessionGroupBody({
  session,
  children,
  layout,
  rowProps,
  canReorder,
  dragHandleProps,
}: RootSessionGroupBodyProps) {
  return (
    <>
      <SessionRow
        session={session}
        isRoot={true}
        layout={layout}
        dragHandleProps={canReorder ? dragHandleProps : undefined}
        {...rowProps}
      />
      {children}
    </>
  )
}

type SortableRootSessionGroupProps = {
  session: CliShellSession
  children: ReactNode
  layout: 'sidebar' | 'strip'
  canReorder: boolean
  rowProps: Omit<SessionRowProps, 'session' | 'isRoot' | 'dragHandleProps' | 'layout'>
}

function SortableRootSessionGroup({
  session,
  children,
  layout,
  canReorder,
  rowProps,
}: SortableRootSessionGroupProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: session.id,
    animateLayoutChanges: () => false,
    transition: null,
  })

  const dragAxis = layout === 'strip' ? 'horizontal' : 'vertical'

  const style = isDragging
    ? undefined
    : {
        transform: sortableAxisTransform(transform, dragAxis),
        transition,
      }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        isDragging && 'opacity-0',
        layout === 'strip' && 'flex shrink-0 items-center gap-1',
      )}
    >
      {layout === 'strip' && canReorder ? (
        <div
          ref={setActivatorNodeRef}
          className="flex shrink-0 cursor-grab touch-none items-center gap-1 active:cursor-grabbing"
          {...attributes}
          {...listeners}
        >
          <RootSessionGroupBody
            session={session}
            layout={layout}
            rowProps={rowProps}
            canReorder={canReorder}
          >
            {children}
          </RootSessionGroupBody>
        </div>
      ) : (
        <RootSessionGroupBody
          session={session}
          layout={layout}
          rowProps={rowProps}
          canReorder={canReorder}
          dragHandleProps={{
            ref: setActivatorNodeRef,
            ...attributes,
            ...listeners,
          }}
        >
          {children}
        </RootSessionGroupBody>
      )}
    </div>
  )
}

export function CliSessionSidebar() {
  const t = useT()
  const layout = useCliTerminalSessionsLayoutMode()
  const isStrip = layout === 'strip'
  const {
    sessions,
    activeSessionId,
    focusedSessionId,
    splitPaneSessionIds,
    selectSession,
    splitSession,
    removeSession,
    renameSession,
    reorderRootSessions,
    isSessionRunning,
  } = useCliShell()

  const [editingSessionId, setEditingSessionId] = useState<string | null>(null)
  const [editingName, setEditingName] = useState('')
  const [activeDragSessionId, setActiveDragSessionId] = useState<string | null>(
    null,
  )
  const editInputRef = useRef<HTMLInputElement>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 6 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  useEffect(() => {
    if (!editingSessionId) return
    const raf = requestAnimationFrame(() => {
      editInputRef.current?.focus()
      editInputRef.current?.select()
    })
    return () => cancelAnimationFrame(raf)
  }, [editingSessionId])

  const startEditing = (sessionId: string, currentName: string) => {
    setEditingSessionId(sessionId)
    setEditingName(currentName)
  }

  const commitRename = () => {
    if (!editingSessionId) return
    renameSession(editingSessionId, editingName)
    setEditingSessionId(null)
    setEditingName('')
  }

  const cancelRename = () => {
    setEditingSessionId(null)
    setEditingName('')
  }

  const rootSessions = sessions.filter((session) => !session.parentSessionId)
  const rootCount = rootSessions.length
  const canReorder = rootCount > 1

  const dragModifiers = useMemo(
    () =>
      getAxisRestrictedDragModifiers(isStrip ? 'horizontal' : 'vertical'),
    [isStrip],
  )

  const handleDragStart = (event: DragStartEvent) => {
    setActiveDragSessionId(String(event.active.id))
  }

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveDragSessionId(null)

    const { active, over } = event
    if (!over || active.id === over.id) return

    const fromIndex = rootSessions.findIndex((session) => session.id === active.id)
    const toIndex = rootSessions.findIndex((session) => session.id === over.id)
    if (fromIndex === -1 || toIndex === -1) return

    reorderRootSessions(fromIndex, toIndex)
  }

  const handleDragCancel = () => {
    setActiveDragSessionId(null)
  }

  const activeDragSession = activeDragSessionId
    ? rootSessions.find((session) => session.id === activeDragSessionId)
    : null

  const renderSplitChildren = (parentSessionId: string) => {
    const children = getCliShellSplitChildren(parentSessionId, sessions).sort(
      (a, b) => {
        const aIndex = splitPaneSessionIds.indexOf(a.id)
        const bIndex = splitPaneSessionIds.indexOf(b.id)
        if (aIndex === -1 && bIndex === -1) return 0
        if (aIndex === -1) return 1
        if (bIndex === -1) return -1
        return aIndex - bIndex
      },
    )

    if (children.length === 0) return null

    return (
      <div
        className={cn(
          isStrip ? undefined : 'border-s border-border/80 ps-3',
          !isStrip && (canReorder ? 'ms-8' : 'ms-5'),
          isStrip && 'flex shrink-0 items-center gap-1',
        )}
      >
        {children.map((child) => {
          const isChildEditing = editingSessionId === child.id
          return (
            <SessionRow
              key={child.id}
              session={child}
              isRoot={false}
              isNested={true}
              layout={layout}
              isActive={activeSessionId === parentSessionId}
              isFocused={focusedSessionId === child.id}
              isInSplitPane={splitPaneSessionIds.includes(child.id)}
              isRunning={isSessionRunning(child.id)}
              isEditing={isChildEditing}
              editingName={editingName}
              editInputRef={editInputRef}
              canRemoveRoot={false}
              onSelect={() => selectSession(child.id)}
              onSplit={() => splitSession(child.id)}
              onCloseSplit={() => removeSession(child.id)}
              onRemoveRoot={() => removeSession(child.id)}
              onStartEditing={() => startEditing(child.id, child.name)}
              onEditingNameChange={setEditingName}
              onCommitRename={commitRename}
              onCancelRename={cancelRename}
            />
          )
        })}
      </div>
    )
  }

  const buildRootRowProps = (
    session: CliShellSession,
    childCount: number,
  ): Omit<SessionRowProps, 'session' | 'isRoot' | 'dragHandleProps' | 'layout'> => {
    const isEditing = editingSessionId === session.id
    return {
      isActive: activeSessionId === session.id,
      isFocused: focusedSessionId === session.id,
      isInSplitPane: splitPaneSessionIds.includes(session.id),
      isRunning: isSessionRunning(session.id),
      isEditing,
      editingName,
      editInputRef,
      canRemoveRoot: rootCount > 1 || childCount > 0,
      onSelect: () => selectSession(session.id),
      onSplit: () => splitSession(session.id),
      onCloseSplit: () => removeSession(session.id),
      onRemoveRoot: () => removeSession(session.id),
      onStartEditing: () => startEditing(session.id, session.name),
      onEditingNameChange: setEditingName,
      onCommitRename: commitRename,
      onCancelRename: cancelRename,
    }
  }

  return (
    <div
      className={cn(
        'flex min-w-0 bg-muted/20',
        isStrip
          ? 'h-11 w-full shrink-0 flex-row border-b border-border'
          : 'h-full w-full flex-col pb-1',
      )}
      aria-label={t('Terminal sessions')}
    >
      <div
        className={cn(
          isStrip
            ? 'flex min-w-0 flex-1 items-center overflow-x-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'
            : 'min-h-0 flex-1 overflow-y-auto',
        )}
      >
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          modifiers={dragModifiers}
          onDragStart={handleDragStart}
          onDragEnd={handleDragEnd}
          onDragCancel={handleDragCancel}
        >
          <SortableContext
            items={rootSessions.map((session) => session.id)}
            strategy={
              isStrip
                ? horizontalListSortingStrategy
                : verticalListSortingStrategy
            }
            disabled={!canReorder}
          >
            <div
              className={cn(
                isStrip && 'flex min-w-max items-center gap-1 py-1.5',
              )}
            >
              {rootSessions.map((session) => {
                const splitChildren = getCliShellSplitChildren(
                  session.id,
                  sessions,
                )

                return (
                  <SortableRootSessionGroup
                    key={session.id}
                    session={session}
                    layout={layout}
                    canReorder={canReorder}
                    rowProps={buildRootRowProps(session, splitChildren.length)}
                  >
                    {renderSplitChildren(session.id)}
                  </SortableRootSessionGroup>
                )
              })}
            </div>
          </SortableContext>
          <DragOverlay dropAnimation={null}>
            {activeDragSession ? (
              <div
                className={cn(
                  'rounded-md bg-muted/60 shadow-md ring-1 ring-border',
                  isStrip ? 'w-auto' : 'w-full',
                )}
              >
                <RootSessionGroupBody
                  session={activeDragSession}
                  layout={layout}
                  canReorder={canReorder}
                  rowProps={buildRootRowProps(
                    activeDragSession,
                    getCliShellSplitChildren(activeDragSession.id, sessions)
                      .length,
                  )}
                >
                  {renderSplitChildren(activeDragSession.id)}
                </RootSessionGroupBody>
              </div>
            ) : null}
          </DragOverlay>
        </DndContext>
      </div>
    </div>
  )
}
