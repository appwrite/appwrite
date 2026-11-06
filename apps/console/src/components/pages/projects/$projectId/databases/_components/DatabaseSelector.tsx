/**
 * Database Selector
 *
 * Unified searchable dropdown for every project database: Appwrite products
 * (TablesDB, DocumentsDB, VectorsDB via console.listDatabases) and native
 * engines (PostgreSQL, MySQL, MongoDB). Same list is used in every workspace.
 */

import { useState, useEffect, useMemo, Fragment } from 'react'
import { ChevronDown, ChevronRight, Database, Loader2, Plus, Table2 } from 'lucide-react'
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
  consoleDatabasesQueryOptions,
  dedicatedDatabasesQueryOptions,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'
import type { DatabaseSwitcherSelection } from '@/lib/databases/navigate-to-database-switcher'
import {
  engineFromDatabaseTypeValue,
  productFromDatabaseTypeValue,
} from '@/lib/databases/database-type'
import { resolveDatabaseComputeSpecId } from '@/lib/databases/database-compute'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { isDatabaseTypeFeatureEnabled } from '@/lib/database-routes'
import { DEDICATED_FEATURE_UNAVAILABLE } from '@/lib/databases/dedicated-engine'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'
import {
  DatabaseTypeIcon,
  getDatabaseTypeDisplayLabel,
} from './DatabaseTypeIcon'
import { DedicatedDatabaseStatusBadge } from './DedicatedDatabaseStatusBadge'

const DEFAULT_LIMIT = 15

export type DatabaseSelectorProps = {
  projectId: string
  value: string
  selectedName?: string
  onSelect: (
    databaseId: string,
    meta?: DatabaseSwitcherSelection,
  ) => void
  placeholder?: string
  limit?: number
  triggerClassName?: string
  emptyLabel?: string
  /**
   * When the current selection is a native dedicated DB (PostgreSQL / MySQL /
   * MongoDB), skip the product `tablesdb` / `documentsdb` / `vectorsdb` lookup.
   * Those APIs 404 for native IDs and are not needed for icon/label metadata.
   */
  selectedIsNative?: boolean
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

type DatabaseSelectorItem = DatabaseSwitcherSelection & {
  name: string
  specSlug?: string | null
  status?: string | null
}

function DatabaseSelectorBreadcrumb({
  typeLabel,
  name,
  status,
  translate,
}: {
  typeLabel: string
  name: string
  status?: string | null
  translate: (text: string) => string
}) {
  return (
    <span className="flex min-w-0 flex-1 items-center gap-1.5 text-[13px]">
      <span className="flex min-w-0 items-center gap-1">
        <span className="shrink-0 text-muted-foreground">
          {translate(typeLabel)}
        </span>
        <ChevronRight
          className="h-3 w-3 shrink-0 text-muted-foreground/60"
          aria-hidden
        />
        <span className="min-w-0 truncate font-medium text-foreground">
          {name}
        </span>
      </span>
      <DedicatedDatabaseStatusBadge status={status} onlyWhenNotReady />
    </span>
  )
}

export function DatabaseSelector({
  projectId,
  value,
  selectedName,
  onSelect,
  placeholder = 'Select database',
  limit = DEFAULT_LIMIT,
  triggerClassName,
  emptyLabel,
  selectedIsNative = false,
  createTableMenuLabel = 'Create table',
  onCreateDatabaseClick,
  onCreateTableClick,
  createDatabaseDisabled = false,
  createDatabaseDisabledTooltip = "You don't have permission to create databases.",
  createTableDisabled = false,
  createTableDisabledTooltip = "You don't have permission to create tables.",
}: DatabaseSelectorProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showCreateActions =
    onCreateDatabaseClick != null || onCreateTableClick != null

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: consoleData, isFetching: isConsoleFetching } = useQuery({
    ...consoleDatabasesQueryOptions(
      projectId,
      0,
      limit,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  // Metadata only (engine/spec). List + search always come from console.listDatabases.
  const { data: dedicatedData } = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: !!projectId && (open || !!value),
    placeholderData: keepPreviousData,
  })

  const dedicatedById = useMemo(() => {
    const map = new Map<
      string,
      {
        specSlug: string | null
        engine: string | null
        api: string | null
        status: string | null
      }
    >()
    for (const dedicated of dedicatedData?.databases ?? []) {
      if (!dedicated.$id) continue
      map.set(dedicated.$id, {
        specSlug: coerceTrimmedString(dedicated.specification) || null,
        engine: dedicated.engine ?? null,
        api: dedicated.api ?? null,
        status: dedicated.status ?? null,
      })
    }
    return map
  }, [dedicatedData?.databases])

  const selectedDedicated = value ? dedicatedById.get(value) : undefined
  // `dedicatedDatabasesQueryOptions` only lists native engines. If the selected
  // id is in that list (or the caller already said so), never probe product APIs.
  const skipProductDatabaseLookup =
    selectedIsNative || Boolean(selectedDedicated)

  // Product-agnostic lookup: fetch the selected database's metadata via the
  // console list filtered by ID (never probe per-product APIs to guess type).
  const { data: selectedProductDatabase } = useQuery({
    ...consoleDatabasesQueryOptions(projectId, 0, 1, undefined, [
      Query.equal('$id', [value || '']),
    ]),
    enabled: !!projectId && !!value && !skipProductDatabaseLookup,
  })

  const items = useMemo((): DatabaseSelectorItem[] => {
    return (consoleData?.databases ?? []).map((db) => {
      const dedicated = dedicatedById.get(db.$id)
      const productHints = {
        databaseType: db.type,
        status: db.status,
        replicas: typeof db.replicas === 'number' ? db.replicas : null,
        specification: coerceTrimmedString(db.specification) || null,
      }
      return {
        id: db.$id,
        // Prefer the live selected name so a rename shows up before the console
        // list refetch completes.
        name:
          db.$id === value && selectedName ? selectedName : db.name,
        apiType: db.type,
        engine:
          db.engine ??
          engineFromDatabaseTypeValue(db.type) ??
          dedicated?.engine ??
          null,
        product:
          productFromDatabaseTypeValue(db.type) ??
          dedicated?.api ??
          null,
        specSlug: resolveDatabaseComputeSpecId(productHints, dedicated),
        status: dedicated?.status ?? (typeof db.status === 'string' ? db.status : null),
      }
    })
  }, [consoleData?.databases, dedicatedById, selectedName, value])

  const selectedItem = value ? items.find((item) => item.id === value) : undefined

  const displayName = selectedName || selectedItem?.name || t(placeholder)

  const selectedApiType =
    selectedItem?.apiType ??
    selectedProductDatabase?.databases?.[0]?.type ??
    null
  const selectedEngine =
    selectedItem?.engine ?? selectedDedicated?.engine ?? null
  const selectedProduct =
    selectedItem?.product ?? selectedDedicated?.api ?? null

  const isFetching = isConsoleFetching

  const resolvedEmptyLabel = emptyLabel ?? t('No databases found')

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
              product={selectedProduct}
            />
            <span
              className={cn(
                'min-w-0 truncate',
                value && 'text-[13px] font-medium text-foreground',
              )}
            >
              {displayName}
            </span>
            <DedicatedDatabaseStatusBadge
              status={
                selectedItem?.status ?? selectedDedicated?.status ?? null
              }
              onlyWhenNotReady
            />
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
          <CommandList className="min-h-[180px] max-h-[240px]">
            {items.length === 0 && (
              <CommandEmpty>
                {isFetching ? '' : t(resolvedEmptyLabel)}
              </CommandEmpty>
            )}
            <CommandGroup>
              {items.map((item) => {
                const typeLabel = getDatabaseTypeDisplayLabel(
                  item.apiType,
                  item.engine,
                  item.product,
                )
                const typeUnavailable = !isDatabaseTypeFeatureEnabled(
                  item.apiType,
                  features,
                )
                const option = (
                  <button
                    type="button"
                    disabled={typeUnavailable}
                    onClick={() => {
                      if (typeUnavailable) return
                      onSelect(item.id, {
                        id: item.id,
                        apiType: item.apiType,
                        engine: item.engine,
                        product: item.product,
                      })
                      setOpen(false)
                    }}
                    className={cn(
                      'flex w-full items-center gap-1.5 rounded-sm px-2 py-1.5 text-start outline-none transition-colors',
                      typeUnavailable
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer hover:bg-accent hover:text-accent-foreground',
                      item.id === value && 'bg-accent/50',
                    )}
                  >
                    <DatabaseTypeIcon
                      apiType={item.apiType}
                      engine={item.engine}
                      product={item.product}
                    />
                    <DatabaseSelectorBreadcrumb
                      typeLabel={typeLabel}
                      name={item.name}
                      status={item.status}
                      translate={t}
                    />
                  </button>
                )
                if (!typeUnavailable) {
                  return (
                    <Fragment key={item.id}>{option}</Fragment>
                  )
                }
                return (
                  <Tooltip key={item.id}>
                    <TooltipTrigger asChild>
                      <span className="block w-full">{option}</span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-xs text-[12px]">
                      {t(DEDICATED_FEATURE_UNAVAILABLE)}
                    </TooltipContent>
                  </Tooltip>
                )
              })}
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
              {...analyticsAttrs('create-database')}
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
              {...analyticsAttrs('create-table')}
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
