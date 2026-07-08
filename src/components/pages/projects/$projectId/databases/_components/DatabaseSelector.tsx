/**
 * Database Selector
 *
 * Unified searchable dropdown for product databases (TablesDB, DocumentsDB,
 * VectorsDB) and native engines (PostgreSQL, MySQL). Uses debounced search
 * for product databases and client-side search for native engines.
 */

import { useState, useEffect, useMemo, useCallback } from 'react'
import { ChevronDown, Database, Loader2, Plus, Table2 } from 'lucide-react'
import { DatabaseTypeIcon } from './DatabaseTypeIcon'
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
  CommandList,
} from '@/components/ui/command'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  databaseQueryOptions,
  useDatabaseSpecifications,
} from '@/lib/react-query/hooks'
import {
  getNativeDatabaseEmptyLabel,
  matchesNativeEngine,
  type NativeDatabaseEngine,
} from '@/lib/databases/native-database-engines'
import { isNativeDedicatedDatabase } from '@/lib/database-routes'
import {
  getSpecOptionById,
  mapDedicatedDatabaseSpecifications,
  resolveDatabaseSpecSummary,
  SERVERLESS_DATABASE_SPEC_ID,
  type SpecOption,
} from '@/lib/database-specs'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const DEFAULT_LIMIT = 15

type DatabaseSelectorBaseProps = {
  projectId: string
  value: string
  selectedName?: string
  onSelect: (databaseId: string) => void
  placeholder?: string
  limit?: number
  triggerClassName?: string
  emptyLabel?: string
  /** Label for the table/container create action (e.g. Create table vs Create collection) */
  createTableMenuLabel?: string
  onCreateDatabaseClick?: () => void
  onCreateTableClick?: () => void
  /** When true, the create database menu item is disabled */
  createDatabaseDisabled?: boolean
  createDatabaseDisabledTooltip?: string
  /** When true, the create table menu item is disabled */
  createTableDisabled?: boolean
  createTableDisabledTooltip?: string
}

type ProductDatabaseSelectorProps = DatabaseSelectorBaseProps & {
  mode?: 'product'
  nativeEngine?: never
}

type NativeDatabaseSelectorProps = DatabaseSelectorBaseProps & {
  mode: 'native'
  nativeEngine: NativeDatabaseEngine
}

export type DatabaseSelectorProps =
  | ProductDatabaseSelectorProps
  | NativeDatabaseSelectorProps

type DatabaseSelectorItem = {
  id: string
  name: string
  apiType?: string | null
  engine?: string | null
  specSlug?: string | null
}

function matchesSpecSearch(
  query: string,
  specSlug: string | null | undefined,
  specs: SpecOption[],
  getSpecSummary: (slug: string | null | undefined) => string | null,
): boolean {
  const specSummary = getSpecSummary(specSlug)?.toLowerCase() ?? ''
  const spec =
    specs.find((item) => item.id === specSlug) ??
    getSpecOptionById(specSlug ?? '')
  const specSearch = [
    specSummary,
    spec?.cpu.toLowerCase(),
    spec?.memory.toLowerCase(),
    spec?.label.toLowerCase(),
  ]
    .filter(Boolean)
    .join(' ')

  return specSearch.includes(query)
}

export function DatabaseSelector({
  projectId,
  value,
  mode = 'product',
  nativeEngine,
  selectedName,
  onSelect,
  placeholder = 'Select database',
  limit = DEFAULT_LIMIT,
  triggerClassName,
  emptyLabel,
  createTableMenuLabel = 'Create table',
  onCreateDatabaseClick,
  onCreateTableClick,
  createDatabaseDisabled = false,
  createDatabaseDisabledTooltip = "You don't have permission to create databases.",
  createTableDisabled = false,
  createTableDisabledTooltip = "You don't have permission to create tables.",
}: DatabaseSelectorProps) {
  const t = useT()
  const isNative = mode === 'native'
  const resolvedNativeEngine = isNative ? nativeEngine : undefined
  const showCreateActions =
    onCreateDatabaseClick != null || onCreateTableClick != null

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    if (isNative) return
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [isNative, search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: specificationsData } = useDatabaseSpecifications(projectId)

  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const getSpecSummary = useCallback(
    (specSlug: string | null | undefined) =>
      resolveDatabaseSpecSummary(specs, specSlug),
    [specs],
  )

  const { data: productData, isFetching: isProductFetching } = useQuery({
    ...databasesQueryOptions(projectId, 0, limit, debouncedSearch || undefined),
    enabled: !!projectId && open && !isNative,
    placeholderData: keepPreviousData,
  })

  const { data: dedicatedData, isFetching: isDedicatedFetching } = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: !!projectId && (open || !!value),
    placeholderData: keepPreviousData,
  })

  const { data: selectedProductDatabase } = useQuery({
    ...databaseQueryOptions(projectId, value),
    enabled: !!projectId && !!value && !isNative,
  })

  const specSlugByDedicatedId = useMemo(() => {
    const map = new Map<string, string>()
    for (const dedicated of dedicatedData?.databases ?? []) {
      const slug = dedicated.specification?.trim()
      if (dedicated.$id && slug) {
        map.set(dedicated.$id, slug)
      }
    }
    return map
  }, [dedicatedData?.databases])

  const productItems = useMemo((): DatabaseSelectorItem[] => {
    return (productData?.databases ?? []).map((db) => ({
      id: db.$id,
      name: db.name,
      apiType: db.type,
      specSlug:
        specSlugByDedicatedId.get(db.$id) ?? SERVERLESS_DATABASE_SPEC_ID,
    }))
  }, [productData?.databases, specSlugByDedicatedId])

  const nativeItems = useMemo((): DatabaseSelectorItem[] => {
    if (!resolvedNativeEngine) return []
    return (dedicatedData?.databases ?? [])
      .filter(
        (db) =>
          isNativeDedicatedDatabase(db) &&
          matchesNativeEngine(db.engine, resolvedNativeEngine),
      )
      .map((db) => ({
        id: db.$id,
        name: db.name,
        engine: db.engine,
        specSlug: db.specification ?? null,
      }))
  }, [dedicatedData?.databases, resolvedNativeEngine])

  const items = isNative ? nativeItems : productItems

  const filteredItems = useMemo(() => {
    if (!isNative) return items
    const query = search.trim().toLowerCase()
    if (!query) return items
    return items.filter((item) => {
      return (
        item.name.toLowerCase().includes(query) ||
        item.id.toLowerCase().includes(query) ||
        matchesSpecSearch(query, item.specSlug, specs, getSpecSummary)
      )
    })
  }, [getSpecSummary, isNative, items, search, specs])

  const selectedItem = value ? items.find((item) => item.id === value) : undefined

  const displayName = selectedName || selectedItem?.name || t(placeholder)

  const selectedApiType =
    selectedItem?.apiType ?? selectedProductDatabase?.databaseType ?? null
  const selectedEngine =
    selectedItem?.engine ??
    (isNative ? (resolvedNativeEngine ?? null) : null)

  const isFetching = isNative ? isDedicatedFetching : isProductFetching

  const resolvedEmptyLabel =
    emptyLabel ??
    (isNative && resolvedNativeEngine
      ? t(getNativeDatabaseEmptyLabel(resolvedNativeEngine))
      : t('No databases found'))

  const bothCreateDisabled = createDatabaseDisabled && createTableDisabled
  const triggerDisabledTooltip = t(
    "You don't have permission to create databases or tables.",
  )

  const popover = (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          className={cn(
            'h-9 min-w-0 justify-between gap-1.5 text-[13px] font-normal',
            showCreateActions ? 'flex-1' : 'w-full',
            !value && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 flex-1 items-center gap-1.5">
            <DatabaseTypeIcon
              apiType={selectedApiType}
              engine={selectedEngine}
            />
            <span
              className={cn(
                'min-w-0 truncate',
                value && 'text-[13px] font-medium text-foreground',
              )}
            >
              {displayName}
            </span>
          </span>
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
              placeholder={t('Search databases...')}
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
            {filteredItems.length === 0 && (
              <CommandEmpty>
                {isFetching ? '' : t(resolvedEmptyLabel)}
              </CommandEmpty>
            )}
            <CommandGroup>
              {filteredItems.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => {
                    onSelect(item.id)
                    setOpen(false)
                  }}
                  className={cn(
                    'flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-start outline-none transition-colors hover:bg-accent hover:text-accent-foreground',
                    item.id === value && 'bg-accent/50',
                  )}
                >
                  <DatabaseTypeIcon
                    apiType={item.apiType}
                    engine={item.engine}
                  />
                  <span className="min-w-0 truncate text-[13px] font-medium text-foreground">
                    {item.name}
                  </span>
                </button>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )

  if (!showCreateActions) {
    return popover
  }

  return (
    <div className="flex min-w-0 flex-1 items-center gap-2">
      {popover}
      {bothCreateDisabled ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">
              <Button
                variant="outline"
                size="icon"
                className="h-8 w-8 shrink-0"
                disabled
                aria-label={triggerDisabledTooltip}
              >
                <Plus className="h-4 w-4" />
              </Button>
            </span>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            {triggerDisabledTooltip}
          </TooltipContent>
        </Tooltip>
      ) : (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8 shrink-0"
              aria-label={t('Create database or table')}
              aria-haspopup="menu"
            >
              <Plus className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            <DropdownMenuItem
              disabled={createDatabaseDisabled}
              title={
                createDatabaseDisabled
                  ? t(createDatabaseDisabledTooltip)
                  : undefined
              }
              className="gap-2 text-[13px]"
              onSelect={() => onCreateDatabaseClick?.()}
            >
              <Database className="h-4 w-4 shrink-0 text-muted-foreground" />
              {t('Create database')}
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={createTableDisabled}
              title={
                createTableDisabled ? t(createTableDisabledTooltip) : undefined
              }
              className="gap-2 text-[13px]"
              onSelect={() => onCreateTableClick?.()}
            >
              <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
              {t(createTableMenuLabel)}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  )
}
