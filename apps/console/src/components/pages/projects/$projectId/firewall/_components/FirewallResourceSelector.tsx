/**
 * Firewall resource scope picker.
 *
 * One searchable list of the project API plus sites and functions, with
 * FrameworkIcon / RuntimeIcon per item and capped page size so long projects
 * stay responsive.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { ChevronDown, Loader2, Server } from 'lucide-react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
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
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  firewallResourcePickerFunctionsQueryOptions,
  firewallResourcePickerSitesQueryOptions,
  FIREWALL_RESOURCE_PICKER_LIMIT,
  projectFunctionQueryOptions,
  siteQueryOptions,
} from '@/lib/react-query/hooks'
import type { FirewallResourceSelection } from '@/lib/firewall/conditions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const API_VALUE = 'api'

export type { FirewallResourceSelection }

export interface FirewallResourceSelectorProps {
  projectId: string
  value: FirewallResourceSelection
  onValueChange: (next: FirewallResourceSelection) => void
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

function selectionValue(selection: FirewallResourceSelection): string {
  if (selection.resourceType === 'api') return API_VALUE
  const id = selection.resourceId?.trim()
  if (!id) return API_VALUE
  return `${selection.resourceType}:${id}`
}

function parseSelectionValue(raw: string): FirewallResourceSelection | null {
  if (raw === API_VALUE) return { resourceType: 'api' }
  const separator = raw.indexOf(':')
  if (separator <= 0) return null
  const resourceType = raw.slice(0, separator)
  const resourceId = raw.slice(separator + 1).trim()
  if (
    (resourceType !== 'functions' && resourceType !== 'sites') ||
    !resourceId
  ) {
    return null
  }
  return { resourceType, resourceId }
}

function getSiteFramework(site: {
  buildFramework?: string
  buildFrameworkId?: string
  framework?: string
}): string | undefined {
  return site.buildFramework || site.buildFrameworkId || site.framework
}

function matchesApiSearch(search: string): boolean {
  const q = search.trim().toLowerCase()
  if (!q) return true
  return 'api'.includes(q) || 'project api'.includes(q) || q.includes('api')
}

export function FirewallResourceSelector({
  projectId,
  value,
  onValueChange,
  disabled = false,
  triggerClassName,
  contentClassName,
}: FirewallResourceSelectorProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [isPreparingOpen, setIsPreparingOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    if (!projectId) return
    void queryClient.prefetchQuery(
      firewallResourcePickerFunctionsQueryOptions(projectId),
    )
    void queryClient.prefetchQuery(
      firewallResourcePickerSitesQueryOptions(projectId),
    )
  }, [projectId, queryClient])

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) {
      setSearch('')
      setDebouncedSearch('')
    }
  }, [open])

  const selectedFunctionId =
    value.resourceType === 'functions' ? value.resourceId?.trim() || '' : ''
  const selectedSiteId =
    value.resourceType === 'sites' ? value.resourceId?.trim() || '' : ''

  const { data: selectedFunction } = useQuery({
    ...projectFunctionQueryOptions(projectId, selectedFunctionId || undefined),
    enabled: !!projectId && !!selectedFunctionId,
  })

  const { data: selectedSite } = useQuery({
    ...siteQueryOptions(projectId, selectedSiteId || undefined),
    enabled: !!projectId && !!selectedSiteId,
  })

  const listSearch = debouncedSearch || undefined

  const { data: functionsData, isFetching: functionsFetching } = useQuery({
    ...firewallResourcePickerFunctionsQueryOptions(projectId, listSearch),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const { data: sitesData, isFetching: sitesFetching } = useQuery({
    ...firewallResourcePickerSitesQueryOptions(projectId, listSearch),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const isSearching = !!debouncedSearch && (functionsFetching || sitesFetching)
  const showSearchSpinner = isSearching

  const functionItems = useMemo(() => {
    const list = (functionsData?.functions ?? []).map((fn: Models.Function) => ({
      id: fn.$id,
      label: fn.name || 'Unnamed function',
      runtime: fn.runtime,
    }))
    if (
      selectedFunctionId &&
      selectedFunction &&
      !list.some((item) => item.id === selectedFunctionId)
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
  }, [functionsData?.functions, selectedFunction, selectedFunctionId])

  const siteItems = useMemo(() => {
    const list = (sitesData?.sites ?? []).map((site: Models.Site) => ({
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
    if (
      selectedSiteId &&
      selectedSite &&
      !list.some((item) => item.id === selectedSiteId)
    ) {
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
  }, [sitesData?.sites, selectedSite, selectedSiteId])

  const showApi = matchesApiSearch(debouncedSearch)
  const currentValue = selectionValue(value)

  const functionsTotal = functionsData?.total ?? 0
  const sitesTotal = sitesData?.total ?? 0
  const functionsTruncated = functionsTotal > FIREWALL_RESOURCE_PICKER_LIMIT
  const sitesTruncated = sitesTotal > FIREWALL_RESOURCE_PICKER_LIMIT
  const showTruncateHint =
    !showSearchSpinner &&
    (functionsTruncated || sitesTruncated) &&
    (functionItems.length > 0 || siteItems.length > 0)

  const triggerLabel = (() => {
    if (value.resourceType === 'api') return t('API')
    if (value.resourceType === 'functions') {
      return (
        selectedFunction?.name ||
        functionItems.find((item) => item.id === selectedFunctionId)?.label ||
        selectedFunctionId ||
        t('Select function')
      )
    }
    return (
      selectedSite?.name ||
      siteItems.find((item) => item.id === selectedSiteId)?.label ||
      selectedSiteId ||
      t('Select site')
    )
  })()

  const triggerIcon = (() => {
    if (value.resourceType === 'api') {
      return <Server className="h-4 w-4 shrink-0 text-muted-foreground" />
    }
    if (value.resourceType === 'functions') {
      const runtime =
        selectedFunction?.runtime ??
        functionItems.find((item) => item.id === selectedFunctionId)?.runtime
      return (
        <RuntimeIcon
          runtime={runtime ?? ''}
          size="sm"
          className="h-4 w-4 shrink-0 text-muted-foreground"
        />
      )
    }
    const framework =
      (selectedSite
        ? getSiteFramework(
            selectedSite as {
              buildFramework?: string
              buildFrameworkId?: string
              framework?: string
            },
          )
        : undefined) ??
      siteItems.find((item) => item.id === selectedSiteId)?.framework
    return (
      <FrameworkIcon
        framework={framework}
        size="sm"
        className="h-4 w-4 shrink-0"
      />
    )
  })()

  const handleSelect = (raw: string) => {
    const next = parseSelectionValue(raw)
    if (!next) return
    onValueChange(next)
    setOpen(false)
  }

  const handleOpenChange = useCallback(
    (nextOpen: boolean) => {
      if (!nextOpen) {
        setOpen(false)
        return
      }
      if (!projectId || disabled || isPreparingOpen) return

      void (async () => {
        setIsPreparingOpen(true)
        try {
          await Promise.all([
            queryClient.ensureQueryData(
              firewallResourcePickerFunctionsQueryOptions(projectId),
            ),
            queryClient.ensureQueryData(
              firewallResourcePickerSitesQueryOptions(projectId),
            ),
          ])
        } catch {
          // Open anyway; lists stay empty if the prefetch failed.
        } finally {
          setIsPreparingOpen(false)
        }
        setOpen(true)
      })()
    },
    [disabled, isPreparingOpen, projectId, queryClient],
  )

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-label={t('Firewall resource')}
          disabled={disabled || !projectId || isPreparingOpen}
          className={cn(
            'h-9 w-full max-w-xs justify-between gap-2 text-[13px] font-normal sm:w-56 sm:max-w-none',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-2 truncate">
            {triggerIcon}
            <span className="truncate">{triggerLabel}</span>
          </span>
          {isPreparingOpen ? (
            <Loader2 className="h-4 w-4 shrink-0 animate-spin opacity-50" />
          ) : (
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'w-[var(--radix-popover-trigger-width)] min-w-[280px] p-0',
          contentClassName,
        )}
        align="start"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder={t('Search resources...')}
              value={search}
              onValueChange={setSearch}
              className={cn('h-9', showSearchSpinner && 'pe-8')}
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                showSearchSpinner ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList className="min-h-[180px] max-h-[280px] overflow-y-auto overscroll-contain">
            <CommandEmpty>{t('No results found')}</CommandEmpty>
            {showApi ? (
              <CommandGroup heading={t('Project')}>
                <CommandItem
                  value={`${API_VALUE} API`}
                  onSelect={() => handleSelect(API_VALUE)}
                  className={cn(
                    'gap-2',
                    currentValue === API_VALUE && 'bg-accent/50',
                  )}
                >
                  <Server className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="truncate">{t('API')}</span>
                </CommandItem>
              </CommandGroup>
            ) : null}
            {siteItems.length > 0 ? (
              <CommandGroup heading={t('Sites')}>
                {siteItems.map((item) => {
                  const itemValue = `sites:${item.id}`
                  return (
                    <CommandItem
                      key={itemValue}
                      value={`${itemValue} ${item.label}`}
                      onSelect={() => handleSelect(itemValue)}
                      className={cn(
                        'gap-2',
                        currentValue === itemValue && 'bg-accent/50',
                      )}
                    >
                      <FrameworkIcon
                        framework={item.framework}
                        size="sm"
                        className="h-4 w-4 shrink-0"
                      />
                      <span className="truncate">{item.label}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            ) : null}
            {functionItems.length > 0 ? (
              <CommandGroup heading={t('Functions')}>
                {functionItems.map((item) => {
                  const itemValue = `functions:${item.id}`
                  return (
                    <CommandItem
                      key={itemValue}
                      value={`${itemValue} ${item.label}`}
                      onSelect={() => handleSelect(itemValue)}
                      className={cn(
                        'gap-2',
                        currentValue === itemValue && 'bg-accent/50',
                      )}
                    >
                      <RuntimeIcon
                        runtime={item.runtime ?? ''}
                        size="sm"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                      />
                      <span className="truncate">{item.label}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            ) : null}
          </CommandList>
          {showTruncateHint ? (
            <div className="border-t border-border px-3 py-2 text-[11px] text-muted-foreground">
              {t('Showing first results. Refine your search to find more.')}
            </div>
          ) : null}
        </Command>
      </PopoverContent>
    </Popover>
  )
}
