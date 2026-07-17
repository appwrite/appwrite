/**
 * Function Selector
 *
 * Form dropdown matching the function detail title switcher:
 * searchable list with RuntimeIcon per function.
 */

import { useState, useEffect, useMemo } from 'react'
import { ChevronDown, Loader2, Terminal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { Skeleton } from '@/components/ui/skeleton'
import {
  functionsQueryOptions,
  projectFunctionQueryOptions,
} from '@/lib/react-query/hooks'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

const DEFAULT_LIMIT = 25

export interface FunctionSelectorProps {
  projectId: string | null | undefined
  value: string
  onValueChange: (functionId: string) => void
  placeholder?: string
  limit?: number
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

export function FunctionSelector({
  projectId,
  value,
  onValueChange,
  placeholder = 'Select function',
  limit = DEFAULT_LIMIT,
  disabled = false,
  triggerClassName,
  contentClassName,
}: FunctionSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: selectedFunction } = useQuery({
    ...projectFunctionQueryOptions(projectId, value || undefined),
    enabled: !!projectId && !!value,
  })

  const { data, isFetching } = useQuery({
    ...functionsQueryOptions(
      projectId,
      0,
      limit,
      open ? debouncedSearch || undefined : undefined,
    ),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const items = useMemo(() => {
    const list = (data?.functions ?? []).map((fn: Models.Function) => ({
      id: fn.$id,
      label: fn.name || 'Unnamed function',
      runtime: fn.runtime,
    }))
    if (
      value &&
      selectedFunction &&
      !list.some((item) => item.id === value)
    ) {
      return [
        {
          id: selectedFunction.$id,
          label: selectedFunction.name || 'Unnamed function',
          runtime: selectedFunction.runtime,
        },
        ...list,
      ]
    }
    return list
  }, [data?.functions, selectedFunction, value])

  const selected =
    items.find((item) => item.id === value) ??
    (selectedFunction
      ? {
          id: selectedFunction.$id,
          label: selectedFunction.name || 'Unnamed function',
          runtime: selectedFunction.runtime,
        }
      : null)

  const showListSkeleton = isFetching && items.length === 0

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled || !projectId}
          className={cn(
            'h-9 w-full justify-between gap-2 text-[13px] font-normal',
            !value && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-2 truncate">
            {selected ? (
              <RuntimeIcon
                runtime={selected.runtime ?? ''}
                size="sm"
                className="h-4 w-4 shrink-0 text-muted-foreground"
              />
            ) : (
              <Terminal className="h-4 w-4 shrink-0 text-muted-foreground" />
            )}
            <span className="truncate">
              {selected?.label ?? t(placeholder)}
            </span>
          </span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'w-[var(--radix-popover-trigger-width)] min-w-[240px] p-0',
          contentClassName,
        )}
        align="start"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder={t('Search functions...')}
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
          <CommandList className="max-h-[240px]">
            {showListSkeleton ? (
              <div className="space-y-0.5 p-1" aria-hidden>
                {Array.from({ length: 5 }, (_, index) => (
                  <div
                    key={index}
                    className="flex items-center gap-2 rounded-sm px-2 py-1.5"
                  >
                    <Skeleton className="h-4 w-4 shrink-0 rounded-sm" />
                    <Skeleton
                      className="h-4 rounded-sm"
                      style={{ width: `${55 + (index % 3) * 12}%` }}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <>
                <CommandEmpty>{t('No results found')}</CommandEmpty>
                <CommandGroup>
                  {items.map((item) => (
                    <CommandItem
                      key={item.id}
                      value={`${item.id} ${item.label}`}
                      onSelect={() => {
                        onValueChange(item.id)
                        setOpen(false)
                      }}
                      className={cn(
                        'gap-2',
                        item.id === value && 'bg-accent/50',
                      )}
                    >
                      <RuntimeIcon
                        runtime={item.runtime ?? ''}
                        size="sm"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                      />
                      <span className="truncate">{item.label}</span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
