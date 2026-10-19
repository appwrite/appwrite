import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { useT } from '@/lib/i18n/translate'

type PostgresSchemaSelectorProps = {
  value: string | null | undefined
  schemas: string[]
  total: number
  isLoading?: boolean
  isFetching?: boolean
  isFetchingNextPage?: boolean
  hasNextPage?: boolean
  onSelect: (schema: string) => void
  onSearchChange: (search: string) => void
  onLoadMore: () => void
  onOpenChange?: (open: boolean) => void
  action?: ReactNode
  /** Inline badge-style trigger without the schema label wrapper. */
  compact?: boolean
}

export function PostgresSchemaSelector({
  value,
  schemas,
  total,
  isLoading = false,
  isFetching = false,
  isFetchingNextPage = false,
  hasNextPage = false,
  onSelect,
  onSearchChange,
  onLoadMore,
  onOpenChange,
  action,
  compact = false,
}: PostgresSchemaSelectorProps) {
  const t = useT()
  const triggerId = useId()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const listScrollRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  useEffect(() => {
    onSearchChange(search)
  }, [onSearchChange, search])

  useEffect(() => {
    const sentinel = sentinelRef.current
    const root = listScrollRef.current
    if (!sentinel || !root || !open || !hasNextPage || isFetchingNextPage) return

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
  }, [hasNextPage, isFetchingNextPage, onLoadMore, open])

  const displayValue = coerceTrimmedString(value) || 'Select schema'
  const showInitialLoading = isLoading && schemas.length === 0

  const selector = (
    <div className={cn('flex items-center gap-2', compact && 'inline-flex')}>
      <Popover
        open={open}
        onOpenChange={(nextOpen) => {
          setOpen(nextOpen)
          onOpenChange?.(nextOpen)
        }}
      >
        <PopoverTrigger asChild>
          <Button
            id={compact ? undefined : triggerId}
            type="button"
            variant={compact ? 'secondary' : 'outline'}
            className={cn(
              'h-8 min-w-0 justify-between gap-1.5 font-normal',
              compact
                ? 'max-w-[160px] px-2 text-[12px] font-mono'
                : 'flex-1 text-[13px]',
              !value && 'text-muted-foreground',
            )}
          >
            <span className="truncate">{displayValue}</span>
            <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
          <PopoverContent
            className="min-w-[var(--radix-popover-trigger-width)] max-w-[320px] p-0"
            align="start"
          >
            <Command shouldFilter={false}>
              <div className="relative">
                <CommandInput
                  placeholder={t('Search schemas...')}
                  value={search}
                  onValueChange={setSearch}
                  className={cn('h-9', isFetching && 'pe-8')}
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
              <CommandList ref={listScrollRef} className="min-h-[180px] max-h-[240px]">
                {showInitialLoading ? (
                  <div className="px-3 py-6 text-center text-[12px] text-muted-foreground">
                    {t('Loading schemas…')}
                  </div>
                ) : schemas.length === 0 ? (
                  <CommandEmpty>{t('No schemas found')}</CommandEmpty>
                ) : (
                  <CommandGroup>
                    {schemas.map((schema) => (
                      <button
                        key={schema}
                        type="button"
                        onClick={() => {
                          onSelect(schema)
                          setOpen(false)
                        }}
                        className={cn(
                          'flex w-full cursor-pointer items-center rounded-sm px-2 py-1.5 text-start text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground',
                          schema === value && 'bg-accent/50',
                        )}
                      >
                        <span className="truncate">{schema}</span>
                      </button>
                    ))}
                    <div
                      ref={sentinelRef}
                      className="h-px w-full shrink-0"
                      aria-hidden
                    />
                    {isFetchingNextPage ? (
                      <div className="flex items-center justify-center py-2">
                        <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                      </div>
                    ) : null}
                  </CommandGroup>
                )}
              </CommandList>
              {total > schemas.length ? (
                <div className="border-t border-border px-3 py-2 text-[11px] tabular-nums text-muted-foreground">
                  Showing {schemas.length.toLocaleString()} of{' '}
                  {total.toLocaleString()} schemas
                </div>
              ) : null}
            </Command>
          </PopoverContent>
        </Popover>
        {action ? <div className="shrink-0">{action}</div> : null}
      </div>
  )

  if (compact) {
    return selector
  }

  return (
    <div className="space-y-2">
      <Label htmlFor={triggerId} className="text-[13px]">
        {t('Schema')}
      </Label>
      {selector}
    </div>
  )
}
