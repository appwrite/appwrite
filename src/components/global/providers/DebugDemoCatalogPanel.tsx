import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ChevronRight, Filter, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  DEBUG_DEMO_CATEGORIES,
  getDebugDemoById,
  getDebugDemosGroupedByCategory,
  type DebugDemoCategory,
  type DebugDemoEntry,
} from '@/lib/debug-demos/catalog'
import {
  readHiddenDemoCategories,
  writeHiddenDemoCategories,
} from '@/lib/debug-demos/category-filter'
import {
  readCollapsedDemoCategories,
  writeCollapsedDemoCategories,
} from '@/lib/debug-demos/collapsed-sections'
import {
  DEBUG_MENU_BORDER_MIX_SUBTLE,
  DEBUG_MENU_MUTED_TEXT,
  DEBUG_MENU_ROW_HOVER,
} from '@/lib/debug-menu-chrome'
import { cn } from '@/lib/utils'

type DebugDemoCatalogPanelProps = {
  currentId: string
  onSelect: (demoId: string) => void
  className?: string
  /** Toolbar panel: taller tiles and section chrome. Menu: denser list. */
  density?: 'panel' | 'menu'
  showCategoryFilter?: boolean
}

export function DebugDemoCatalogPanel({
  currentId,
  onSelect,
  className,
  density = 'panel',
  showCategoryFilter = true,
}: DebugDemoCatalogPanelProps) {
  const grouped = useMemo(() => getDebugDemosGroupedByCategory(), [])
  const listRef = useRef<HTMLDivElement>(null)
  const [hiddenCategories, setHiddenCategories] = useState(() =>
    readHiddenDemoCategories(),
  )
  const [collapsedCategories, setCollapsedCategories] = useState(() =>
    readCollapsedDemoCategories(),
  )
  const [filterOpen, setFilterOpen] = useState(false)

  const currentDemo = getDebugDemoById(currentId)
  const forceCategory = currentDemo?.category

  const visibleGroups = useMemo(() => {
    return grouped.filter(
      (group) =>
        !hiddenCategories.has(group.category) ||
        group.category === forceCategory,
    )
  }, [grouped, hiddenCategories, forceCategory])

  const scrollActiveIntoView = useCallback(() => {
    const list = listRef.current
    if (!list) return
    const active = list.querySelector<HTMLElement>(`[data-demo-id="${currentId}"]`)
    active?.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
    })
  }, [currentId])

  useEffect(() => {
    scrollActiveIntoView()
  }, [scrollActiveIntoView, visibleGroups])

  useEffect(() => {
    if (!forceCategory) return
    setCollapsedCategories((prev) => {
      if (!prev.has(forceCategory)) return prev
      const next = new Set(prev)
      next.delete(forceCategory)
      writeCollapsedDemoCategories(next)
      return next
    })
  }, [forceCategory, currentId])

  const toggleSectionCollapsed = (category: DebugDemoCategory) => {
    setCollapsedCategories((prev) => {
      const next = new Set(prev)
      if (next.has(category)) {
        next.delete(category)
      } else {
        next.add(category)
      }
      writeCollapsedDemoCategories(next)
      return next
    })
  }

  const toggleCategory = (category: DebugDemoCategory, checked: boolean) => {
    setHiddenCategories((prev) => {
      const next = new Set(prev)
      if (checked) {
        next.delete(category)
      } else {
        next.add(category)
      }
      writeHiddenDemoCategories(next)
      return next
    })
  }

  const showAllCategories = () => {
    const next = new Set<DebugDemoCategory>()
    setHiddenCategories(next)
    writeHiddenDemoCategories(next)
  }

  const hiddenCount = hiddenCategories.size
  const isPanel = density === 'panel'

  return (
    <div className={cn('flex min-h-0 flex-col', className)} dir="ltr" lang="en">
      {showCategoryFilter ? (
        <div className="flex shrink-0 items-center justify-end border-b border-border px-2 py-1.5">
          <Popover open={filterOpen} onOpenChange={setFilterOpen}>
            <PopoverTrigger asChild>
              <Button
                type="button"
                variant={hiddenCount > 0 ? 'secondary' : 'ghost'}
                size="sm"
                className="h-7 gap-1.5 px-2 text-[11px]"
                aria-label="Filter demo sections"
              >
                <Filter className="h-3.5 w-3.5" />
                Sections
                {hiddenCount > 0 ? (
                  <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                    {DEBUG_DEMO_CATEGORIES.length - hiddenCount}/
                    {DEBUG_DEMO_CATEGORIES.length}
                  </span>
                ) : null}
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="end"
              className="z-[10070] w-52 p-2"
              dir="ltr"
              lang="en"
            >
              <div className="mb-2 flex items-center justify-between px-1">
                <p className="text-[11px] font-semibold text-foreground">
                  Sections
                </p>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1 px-1.5 text-[10px]"
                  disabled={hiddenCount === 0}
                  onClick={showAllCategories}
                >
                  <RotateCcw className="h-3 w-3" />
                  All
                </Button>
              </div>
              <ul className="space-y-0.5">
                {DEBUG_DEMO_CATEGORIES.map((category) => {
                  const checked = !hiddenCategories.has(category)
                  return (
                    <li key={category}>
                      <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-[12px] hover:bg-muted/60">
                        <Checkbox
                          checked={checked}
                          onCheckedChange={(value) =>
                            toggleCategory(category, value === true)
                          }
                        />
                        <span>{category}</span>
                      </label>
                    </li>
                  )
                })}
              </ul>
            </PopoverContent>
          </Popover>
        </div>
      ) : null}

      <div
        ref={listRef}
        className={cn(
          'min-h-0 flex-1 overflow-y-auto overscroll-contain pb-2 [scrollbar-width:thin]',
          isPanel ? 'px-1.5' : 'px-1',
        )}
        role="listbox"
        aria-label="Demo pages"
        aria-activedescendant={`demo-panel-${currentId}`}
      >
        {visibleGroups.length === 0 ? (
          <p className="px-3 py-4 text-center text-[12px] text-muted-foreground">
            All sections hidden. Open Sections filter to show demos.
          </p>
        ) : null}
        {visibleGroups.map((group, groupIndex) => {
          const isCollapsed = collapsedCategories.has(group.category)
          return (
            <section
              key={group.category}
              className={cn(groupIndex > 0 && (isPanel ? 'mt-2' : 'mt-1.5'))}
            >
              <div
                className={cn(
                  'sticky top-0 z-[1] border-b bg-popover backdrop-blur-sm',
                  DEBUG_MENU_BORDER_MIX_SUBTLE,
                  isPanel ? '-mx-1.5 px-1.5 py-0.5' : '-mx-1 px-1 py-0.5',
                )}
              >
                <button
                  type="button"
                  className={cn(
                    'flex w-full items-center gap-1.5 rounded-md text-left transition-colors',
                    DEBUG_MENU_ROW_HOVER,
                    isPanel ? 'px-2 py-1.5' : 'px-1.5 py-1',
                  )}
                  aria-expanded={!isCollapsed}
                  onClick={() => toggleSectionCollapsed(group.category)}
                >
                  <ChevronRight
                    className={cn(
                      'h-3.5 w-3.5 shrink-0 text-[var(--network-globe-edge)] transition-transform duration-150',
                      !isCollapsed && 'rotate-90',
                    )}
                    aria-hidden
                  />
                  <span className="min-w-0 flex-1 truncate text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {group.category}
                  </span>
                  <span
                    className={cn(
                      'shrink-0 tabular-nums text-[10px] font-medium',
                      DEBUG_MENU_MUTED_TEXT,
                    )}
                  >
                    {group.items.length}
                  </span>
                </button>
              </div>
              {!isCollapsed ? (
                <ul className={cn('space-y-0.5 pt-1', isPanel ? 'px-0.5' : '')}>
                  {group.items.map((item) => (
                    <li key={item.id}>
                      <DemoPanelRow
                        item={item}
                        isActive={item.id === currentId}
                        density={density}
                        onSelect={() => onSelect(item.id)}
                      />
                    </li>
                  ))}
                </ul>
              ) : null}
            </section>
          )
        })}
      </div>
    </div>
  )
}

function DemoPanelRow({
  item,
  isActive,
  density,
  onSelect,
}: {
  item: DebugDemoEntry
  isActive: boolean
  density: 'panel' | 'menu'
  onSelect: () => void
}) {
  const isPanel = density === 'panel'

  return (
    <button
      type="button"
      role="option"
      id={`demo-panel-${item.id}`}
      data-demo-id={item.id}
      aria-selected={isActive}
      onClick={onSelect}
      className={cn(
        'w-full rounded-lg border text-left transition-colors',
        isPanel ? 'px-3 py-2.5' : 'px-2.5 py-2',
        isActive
          ? 'border-primary/50 bg-primary/10 shadow-sm'
          : 'border-transparent bg-transparent hover:border-border hover:bg-muted/50',
      )}
    >
      <span
        className={cn(
          'block leading-snug',
          isPanel ? 'text-[13px]' : 'text-[12px]',
          isActive ? 'font-semibold text-foreground' : 'font-medium text-foreground/90',
        )}
      >
        {item.label}
      </span>
    </button>
  )
}
