/**
 * Site Selector
 *
 * Form dropdown matching the site detail title switcher:
 * searchable list with FrameworkIcon per site.
 */

import { useState, useEffect, useMemo } from 'react'
import { ChevronDown, Globe, Loader2 } from 'lucide-react'
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
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { Skeleton } from '@/components/ui/skeleton'
import { sitesQueryOptions, siteQueryOptions } from '@/lib/react-query/hooks'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

const DEFAULT_LIMIT = 25

function getSiteFramework(site: {
  buildFramework?: string
  buildFrameworkId?: string
  framework?: string
}): string | undefined {
  return site.buildFramework || site.buildFrameworkId || site.framework
}

export interface SiteSelectorProps {
  projectId: string | null | undefined
  value: string
  onValueChange: (siteId: string) => void
  placeholder?: string
  limit?: number
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

export function SiteSelector({
  projectId,
  value,
  onValueChange,
  placeholder = 'Select site',
  limit = DEFAULT_LIMIT,
  disabled = false,
  triggerClassName,
  contentClassName,
}: SiteSelectorProps) {
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

  const { data: selectedSite } = useQuery({
    ...siteQueryOptions(projectId, value || undefined),
    enabled: !!projectId && !!value,
  })

  const { data, isFetching } = useQuery({
    ...sitesQueryOptions(
      projectId,
      0,
      limit,
      open ? debouncedSearch || undefined : undefined,
    ),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const items = useMemo(() => {
    const list = (data?.sites ?? []).map((site: Models.Site) => ({
      id: site.$id,
      label: site.name || 'Unnamed site',
      framework: getSiteFramework(
        site as {
          buildFramework?: string
          buildFrameworkId?: string
          framework?: string
        },
      ),
    }))
    if (value && selectedSite && !list.some((item) => item.id === value)) {
      return [
        {
          id: selectedSite.$id,
          label: selectedSite.name || 'Unnamed site',
          framework: getSiteFramework(
            selectedSite as {
              buildFramework?: string
              buildFrameworkId?: string
              framework?: string
            },
          ),
        },
        ...list,
      ]
    }
    return list
  }, [data?.sites, selectedSite, value])

  const selected =
    items.find((item) => item.id === value) ??
    (selectedSite
      ? {
          id: selectedSite.$id,
          label: selectedSite.name || 'Unnamed site',
          framework: getSiteFramework(
            selectedSite as {
              buildFramework?: string
              buildFrameworkId?: string
              framework?: string
            },
          ),
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
              <FrameworkIcon
                framework={selected.framework}
                size="sm"
                className="h-4 w-4 shrink-0"
              />
            ) : (
              <Globe className="h-4 w-4 shrink-0 text-muted-foreground" />
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
              placeholder={t('Search sites...')}
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
          <CommandList className="min-h-[180px] max-h-[240px]">
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
                      <FrameworkIcon
                        framework={item.framework}
                        size="sm"
                        className="h-4 w-4 shrink-0"
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
