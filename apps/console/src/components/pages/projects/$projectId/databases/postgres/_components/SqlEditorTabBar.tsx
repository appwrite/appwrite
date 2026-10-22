import { useEffect, useRef, useState } from 'react'
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
} from '@dnd-kit/sortable'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ChevronUp, Plus, X } from 'lucide-react'
import { MAX_POSTGRES_SQL_EDITOR_TAB_TITLE_LENGTH } from '@/lib/user-prefs-keys'
import type { SqlEditorTab } from './PostgresSidebarContext'
import { SqlEditorTabContextMenu } from './SqlEditorTabContextMenu'
import { getAxisRestrictedDragModifiers, sortableAxisTransform } from '@/lib/dnd-modifiers'
import { useT } from '@/lib/i18n/translate'

const tabDragModifiers = getAxisRestrictedDragModifiers('horizontal')

type SqlEditorTabBarProps = {
  projectId: string
  databaseId: string
  tabs: SqlEditorTab[]
  activeTabId: string
  onSelectTab: (tabId: string) => void
  onCreateTab: () => void
  onCloseTab: (tabId: string) => void
  onReorderTabs: (activeId: string, overId: string) => void
  onRenameTab: (tabId: string, title: string) => void
  headerCollapsed?: boolean
  onToggleHeaderCollapsed?: () => void
}

type SortableTabProps = {
  projectId: string
  databaseId: string
  tab: SqlEditorTab
  isActive: boolean
  canClose: boolean
  hasOtherTabs: boolean
  onSelectTab: (tabId: string) => void
  onCloseTab: (tabId: string) => void
  onRenameTab: (tabId: string, title: string) => void
}

function SortableTab({
  projectId,
  databaseId,
  tab,
  isActive,
  canClose,
  hasOtherTabs,
  onSelectTab,
  onCloseTab,
  onRenameTab,
}: SortableTabProps) {
  const t = useT()
  const [isEditing, setIsEditing] = useState(false)
  const [draftTitle, setDraftTitle] = useState(tab.title)
  const editInputRef = useRef<HTMLInputElement>(null)
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: tab.id,
    animateLayoutChanges: () => false,
    transition: null,
  })

  const style = isDragging
    ? undefined
    : {
        transform: sortableAxisTransform(transform, 'horizontal'),
        transition,
      }

  useEffect(() => {
    if (!isEditing) {
      setDraftTitle(tab.title)
    }
  }, [isEditing, tab.title])

  useEffect(() => {
    if (!isEditing) return
    editInputRef.current?.focus()
    editInputRef.current?.select()
  }, [isEditing])

  const startEditing = () => {
    onSelectTab(tab.id)
    setDraftTitle(tab.title)
    setIsEditing(true)
  }

  const cancelEditing = () => {
    setDraftTitle(tab.title)
    setIsEditing(false)
  }

  const commitEditing = () => {
    const trimmed = draftTitle.trim()
    if (!trimmed || trimmed === tab.title) {
      cancelEditing()
      return
    }

    onRenameTab(tab.id, trimmed)
    setIsEditing(false)
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      data-tab-id={tab.id}
      className={cn('shrink-0', isDragging && 'opacity-0')}
    >
      <SqlEditorTabContextMenu
        projectId={projectId}
        databaseId={databaseId}
        tab={tab}
        canClose={canClose}
        hasOtherTabs={hasOtherTabs}
        onSelectTab={onSelectTab}
        onCloseTab={onCloseTab}
      >
        <div
          className={cn(
            'group relative flex max-w-[220px] items-center rounded-t-md border-x border-t border-b-0 transition-opacity',
            isActive
              ? 'z-[1] h-10 border-border border-b-background bg-background text-foreground shadow-[inset_0_1px_0_0_var(--border)]'
              : 'h-10 border-border/45 text-muted-foreground/70 opacity-85 shadow-[inset_0_1px_0_0_color-mix(in_oklch,var(--border)_40%,transparent)] hover:opacity-100 hover:border-border/65 hover:bg-background/50 hover:text-muted-foreground',
          )}
        >
          <button
            ref={setActivatorNodeRef}
            type="button"
            onClick={() => {
              if (!isEditing) onSelectTab(tab.id)
            }}
            className={cn(
              'flex h-full min-w-0 flex-1 items-center px-2.5 text-start',
              !isEditing && 'cursor-grab active:cursor-grabbing',
            )}
            title={isEditing ? undefined : tab.title}
            {...(isEditing ? {} : attributes)}
            {...(isEditing ? {} : listeners)}
          >
            {isEditing ? (
              <Input
                ref={editInputRef}
                value={draftTitle}
                maxLength={MAX_POSTGRES_SQL_EDITOR_TAB_TITLE_LENGTH}
                onChange={(event) => setDraftTitle(event.target.value)}
                onBlur={commitEditing}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault()
                    commitEditing()
                  }
                  if (event.key === 'Escape') {
                    event.preventDefault()
                    cancelEditing()
                  }
                }}
                onClick={(event) => event.stopPropagation()}
                onDoubleClick={(event) => event.stopPropagation()}
                onPointerDown={(event) => event.stopPropagation()}
                className="h-6 min-h-6 max-h-6 w-full min-w-0 rounded-sm border-0 bg-transparent px-0 py-0 text-[12px] font-medium leading-6 shadow-none outline-none focus-visible:border-0 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-ring/50 focus-visible:ring-offset-0"
                aria-label={t('Rename query tab')}
              />
            ) : (
              <span
                className="truncate text-[12px] font-medium"
                onDoubleClick={(event) => {
                  event.stopPropagation()
                  event.preventDefault()
                  startEditing()
                }}
              >
                {tab.title}
              </span>
            )}
          </button>
          {canClose ? (
            <button
              type="button"
              onClick={() => onCloseTab(tab.id)}
              onPointerDown={(event) => event.stopPropagation()}
              className={cn(
                'me-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-sm text-muted-foreground transition-opacity hover:bg-muted hover:text-foreground',
                isActive
                  ? 'opacity-70 hover:opacity-100'
                  : 'opacity-0 group-hover:opacity-70 group-hover:hover:opacity-100',
              )}
              aria-label={`Close ${tab.title}`}
            >
              <X className="h-3 w-3" />
            </button>
          ) : null}
        </div>
      </SqlEditorTabContextMenu>
    </div>
  )
}

function TabPreview({ tab, isActive }: { tab: SqlEditorTab; isActive: boolean }) {
  return (
    <div
      className={cn(
        'flex max-w-[220px] items-center rounded-t-md border-x border-b-0 px-2.5 shadow-md',
        'h-10 border-border/75 bg-muted/30 text-muted-foreground shadow-[inset_0_1px_0_0_var(--border)]',
        isActive && 'border-border border-b-background bg-background text-foreground',
      )}
    >
      <span className="truncate text-[12px] font-medium">{tab.title}</span>
    </div>
  )
}

export function SqlEditorTabBar({
  projectId,
  databaseId,
  tabs,
  activeTabId,
  onSelectTab,
  onCreateTab,
  onCloseTab,
  onReorderTabs,
  onRenameTab,
  headerCollapsed = false,
  onToggleHeaderCollapsed,
}: SqlEditorTabBarProps) {
  const t = useT()
  const [activeDragTabId, setActiveDragTabId] = useState<string | null>(null)
  const tabListRef = useRef<HTMLDivElement>(null)
  const canReorder = tabs.length > 1
  const canClose = tabs.length > 1

  useEffect(() => {
    const container = tabListRef.current
    if (!container) return

    const scrollActiveTabIntoView = () => {
      const activeTabEl = container.querySelector<HTMLElement>(
        `[data-tab-id="${activeTabId}"]`,
      )
      activeTabEl?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
    }

    const frame = requestAnimationFrame(() => {
      scrollActiveTabIntoView()
      requestAnimationFrame(scrollActiveTabIntoView)
    })

    return () => cancelAnimationFrame(frame)
  }, [activeTabId, tabs.length])

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 6 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const handleDragStart = (event: DragStartEvent) => {
    setActiveDragTabId(String(event.active.id))
  }

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveDragTabId(null)
    const { active, over } = event
    if (!over || active.id === over.id) return
    onReorderTabs(String(active.id), String(over.id))
  }

  const activeDragTab = activeDragTabId
    ? tabs.find((tab) => tab.id === activeDragTabId)
    : undefined

  const newTabButton = (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className="mb-1.5 h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
      onClick={onCreateTab}
      aria-label={t('New query tab')}
    >
      <Plus className="h-3 w-3" />
    </Button>
  )

  const sortableTabs = (
    <div className="flex min-w-max items-end gap-1.5">
      {tabs.map((tab) => (
        <SortableTab
          key={tab.id}
          projectId={projectId}
          databaseId={databaseId}
          tab={tab}
          isActive={tab.id === activeTabId}
          canClose={canClose}
          hasOtherTabs={canClose}
          onSelectTab={onSelectTab}
          onCloseTab={onCloseTab}
          onRenameTab={onRenameTab}
        />
      ))}
    </div>
  )

  const sortableTabsRegion = canReorder ? (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      modifiers={tabDragModifiers}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
      onDragCancel={handleDragEnd}
    >
      <SortableContext
        items={tabs.map((tab) => tab.id)}
        strategy={horizontalListSortingStrategy}
      >
        {sortableTabs}
      </SortableContext>
      <DragOverlay dropAnimation={null}>
        {activeDragTab ? (
          <TabPreview tab={activeDragTab} isActive={activeDragTab.id === activeTabId} />
        ) : null}
      </DragOverlay>
    </DndContext>
  ) : (
    sortableTabs
  )

  return (
    <div
      className={cn(
        'relative z-20 flex h-10 min-w-0 w-full shrink-0 items-stretch border-b border-border bg-background px-4 sm:px-6',
        headerCollapsed ? 'mt-3 sm:mt-4' : 'mt-2',
      )}
    >
      <div
        ref={tabListRef}
        className="flex min-w-0 flex-1 items-center overflow-x-auto overflow-y-hidden overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <div className="flex min-w-max items-end gap-1.5 pe-1.5">
          {sortableTabsRegion}
          {newTabButton}
        </div>
      </div>
      {onToggleHeaderCollapsed ? (
        <div className="relative z-[1] flex shrink-0 items-center bg-background">
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={onToggleHeaderCollapsed}
                  className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
                  aria-label={headerCollapsed ? t('Expand header') : t('Collapse header')}
                >
                  <ChevronUp
                    className={cn(
                      'h-3 w-3 transition-transform duration-200',
                      headerCollapsed && 'rotate-180',
                    )}
                  />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                <p>
                  {headerCollapsed ? t('Expand header') : t('Collapse header')}
                </p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
      ) : null}
    </div>
  )
}
