/**
 * Searchable dropdown (combobox) using Popover + Command.
 * Use for column, operator, and value droplists in filters and elsewhere.
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, Loader2, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export interface SearchableSelectItem {
  value: string
  label: string
  /** Extra text included in filter matching (defaults to label). */
  searchText?: string
  /** Secondary line shown in the dropdown list. */
  description?: string
  /** When true, description is shown on the same line as the label. */
  inlineDescription?: boolean
  /** Optional leading icon in the trigger and list. */
  icon?: LucideIcon
}

export interface SearchableSelectProps {
  value: string
  onValueChange: (value: string) => void
  items: SearchableSelectItem[]
  /** Pinned below the scrollable list with a separator; not affected by search filtering. */
  footerItems?: SearchableSelectItem[]
  placeholder?: string
  searchPlaceholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
  /**
   * Overrides the scroll area's sizing. The default reserves `min-h-[180px]` so
   * droplists with many options do not resize while searching; pass `min-h-0`
   * where the list is short and that reservation reads as empty space.
   */
  listClassName?: string
  emptyMessage?: string
  /** When true, trigger shows placeholder-style text when no value selected */
  showPlaceholderWhenEmpty?: boolean
  /** When set, search is handled externally and cmdk filtering is disabled. */
  onSearchChange?: (query: string) => void
  /** Show a spinner in the search field while results are loading. */
  isFetching?: boolean
  hasNextPage?: boolean
  isFetchingNextPage?: boolean
  onLoadMore?: () => void
  /** Optional footer below the list (e.g. "Showing X of Y"). */
  listFooter?: ReactNode
  /** Called when the popover opens or closes. */
  onOpenChange?: (open: boolean) => void
}

function SearchableSelectItemContent({ item }: { item: SearchableSelectItem }) {
  const ItemIcon = item.icon

  if (!item.description && !ItemIcon) {
    return <>{item.label}</>
  }

  if (item.inlineDescription) {
    return (
      <div className="flex min-w-0 items-center gap-2">
        {ItemIcon ? (
          <ItemIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        ) : null}
        <span className="flex min-w-0 flex-1 items-center gap-1.5">
          <span className="truncate">{item.label}</span>
          {item.description ? (
            <span className="shrink-0 text-[11px] text-muted-foreground">
              {item.description}
            </span>
          ) : null}
        </span>
      </div>
    )
  }

  return (
    <div className="flex min-w-0 items-start gap-2">
      {ItemIcon ? (
        <ItemIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      ) : null}
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="truncate">{item.label}</span>
        {item.description ? (
          <span className="truncate text-[11px] text-muted-foreground">
            {item.description}
          </span>
        ) : null}
      </div>
    </div>
  )
}

export function SearchableSelect({
  value,
  onValueChange,
  items,
  footerItems,
  placeholder = 'Select…',
  searchPlaceholder = 'Search...',
  disabled = false,
  triggerClassName,
  contentClassName,
  listClassName,
  emptyMessage = 'No results',
  showPlaceholderWhenEmpty = true,
  onSearchChange,
  isFetching = false,
  hasNextPage = false,
  isFetchingNextPage = false,
  onLoadMore,
  listFooter,
  onOpenChange,
}: SearchableSelectProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const listScrollRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const allItems = useMemo(
    () => [...items, ...(footerItems ?? [])],
    [items, footerItems],
  )
  const selectedItem = allItems.find((i) => i.value === value)
  const selectedLabel = selectedItem?.label ?? ''
  const displayText =
    value && selectedLabel
      ? selectedLabel
      : showPlaceholderWhenEmpty
        ? placeholder
        : ''
  const SelectedIcon = selectedItem?.icon

  useEffect(() => {
    const sentinel = sentinelRef.current
    const root = listScrollRef.current
    if (!sentinel || !root || !open || !hasNextPage || isFetchingNextPage || !onLoadMore) {
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
          onLoadMore()
        }
      },
      { root, rootMargin: '120px', threshold: 0.1 },
    )

    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [hasNextPage, isFetchingNextPage, onLoadMore, open, items.length])

  return (
    <Popover
      open={open}
      onOpenChange={(nextOpen) => {
        setOpen(nextOpen)
        onOpenChange?.(nextOpen)
        if (!nextOpen && onSearchChange) {
          onSearchChange('')
        }
      }}
    >
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            'h-9 w-full justify-between gap-2 text-[13px] font-normal',
            !value && showPlaceholderWhenEmpty && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-2 truncate">
            {SelectedIcon ? (
              <SelectedIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            ) : null}
            <span className="flex min-w-0 items-center gap-1.5 truncate">
              <span className="truncate">{t(displayText || placeholder)}</span>
              {selectedItem?.inlineDescription && selectedItem.description ? (
                <span className="shrink-0 text-[11px] text-muted-foreground">
                  {selectedItem.description}
                </span>
              ) : null}
            </span>
          </span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0',
          contentClassName,
        )}
        align="start"
        onWheelCapture={(event) => {
          event.stopPropagation()
        }}
      >
        <Command shouldFilter={!onSearchChange} className="overflow-hidden">
          <div className="relative">
            <CommandInput
              placeholder={t(searchPlaceholder)}
              className={cn('h-9 text-[13px]', isFetching && 'pe-8')}
              onValueChange={onSearchChange}
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList
            ref={listScrollRef}
            className={cn(
              'min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain',
              listClassName,
            )}
          >
            {items.length === 0 && isFetching ? (
              <div className="px-3 py-6 text-center text-[12px] text-muted-foreground">
                {t('Loading…')}
              </div>
            ) : items.length === 0 && !(footerItems?.length) ? (
              <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                {t(emptyMessage)}
              </CommandEmpty>
            ) : (
              <CommandGroup>
                {items.map((item) => (
                  <CommandItem
                    key={item.value}
                    value={item.searchText ?? item.label}
                    className="text-[13px]"
                    onSelect={() => {
                      onValueChange(item.value)
                      setOpen(false)
                    }}
                  >
                    <SearchableSelectItemContent item={item} />
                  </CommandItem>
                ))}
                {hasNextPage ? (
                  <div ref={sentinelRef} className="h-px w-full shrink-0" aria-hidden />
                ) : null}
                {isFetchingNextPage ? (
                  <div className="flex items-center justify-center py-2">
                    <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                  </div>
                ) : null}
              </CommandGroup>
            )}
          </CommandList>
          {footerItems && footerItems.length > 0 ? (
            <div className="shrink-0 border-t border-border bg-popover p-1">
              {footerItems.map((item) => (
                <button
                  key={item.value}
                  type="button"
                  className="relative flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-start text-[13px] outline-hidden hover:bg-accent hover:text-accent-foreground"
                  onClick={() => {
                    onValueChange(item.value)
                    setOpen(false)
                  }}
                >
                  <SearchableSelectItemContent item={item} />
                </button>
              ))}
            </div>
          ) : null}
          {listFooter ? (
            <div className="border-t border-border px-3 py-2 text-[11px] tabular-nums text-muted-foreground">
              {listFooter}
            </div>
          ) : null}
        </Command>
      </PopoverContent>
    </Popover>
  )
}
