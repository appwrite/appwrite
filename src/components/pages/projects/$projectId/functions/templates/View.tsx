import {
  useMemo,
  useState,
  useEffect,
  useRef,
  useCallback,
} from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import {
  AlertCircle,
  Braces,
  ChevronDown,
  ExternalLink,
  LayoutTemplate,
  Loader2,
  Search,
} from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
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
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn, scrollConsoleMainToTop } from '@/lib/utils'
import {
  allFunctionTemplatesQueryOptions,
  functionTemplateFacetsQueryOptions,
  functionTemplatesPageQueryOptions,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreateFunction } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useQuery } from '@tanstack/react-query'
import {
  fetchProjectFunctions,
  FUNCTIONS_DEFAULT_SORT_BY,
  FUNCTIONS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/functions'
import {
  GRID_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
import type { Models } from '@appwrite.io/console'
import { Route } from '@/routes/_public/projects.$projectId.functions.templates'

/** Legacy 1-based page from URL (when `offset` is not used). */
function parseTemplatesPage(value: unknown): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 1) return 1
  return Math.min(Math.floor(n), 1_000_000)
}

function parseTemplatesOffset(value: unknown): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 0) return 0
  return Math.min(Math.floor(n), 1_000_000_000)
}

function parseTemplatesLimit(value: unknown, fallback: number): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 1) return fallback
  return Math.min(Math.max(1, Math.floor(n)), 100)
}

function parseCsvParam(s: string | undefined): string[] {
  if (!s?.trim()) return []
  return s
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean)
}

function joinCsvParam(arr: string[]): string | undefined {
  return arr.length > 0 ? arr.join(',') : undefined
}

type RuntimeRow = NonNullable<Models.TemplateFunction['runtimes']>[number]

function getBaseRuntimes(runtimes: Models.TemplateFunction['runtimes']) {
  const list = runtimes ?? []
  const base = new Map<string, RuntimeRow>()
  for (const runtime of list) {
    const key = runtime.name.split('-')[0] ?? runtime.name
    if (!base.has(key)) {
      base.set(key, { ...runtime, name: key })
    }
  }
  return [...base.values()]
}

function formatUseCaseLabel(useCase: string) {
  const u = useCase.trim()
  if (u.toLowerCase() === 'ai') return 'AI'
  return u.charAt(0).toUpperCase() + u.slice(1)
}

function formatRuntimeLabel(runtime: string) {
  return runtime.split('-').join(' ')
}

type TemplatesSearch = {
  search?: string
  offset?: number
  limit?: number
  uc?: string
  rt?: string
}

const filterSectionTitle =
  'text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground'

function UseCaseFilterTrigger({
  selectedUseCases,
}: {
  selectedUseCases: string[]
}) {
  if (selectedUseCases.length === 0) {
    return (
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">
        All use cases
      </span>
    )
  }
  if (selectedUseCases.length === 1) {
    const uc = selectedUseCases[0]!
    return (
      <span className="min-w-0 truncate text-[13px] text-foreground">
        {formatUseCaseLabel(uc)}
      </span>
    )
  }
  return (
    <span className="min-w-0 truncate text-[13px] text-foreground tabular-nums">
      {selectedUseCases.length} selected
    </span>
  )
}

function RuntimeFilterTrigger({
  selectedRuntimes,
}: {
  selectedRuntimes: string[]
}) {
  if (selectedRuntimes.length === 0) {
    return (
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">
        All runtimes
      </span>
    )
  }
  if (selectedRuntimes.length === 1) {
    const rt = selectedRuntimes[0]!
    return (
      <span className="flex min-w-0 flex-1 items-center gap-2">
        <RuntimeIcon
          runtime={rt}
          size="sm"
          className="h-4 w-4 shrink-0 text-muted-foreground"
        />
        <span className="min-w-0 truncate font-mono text-[13px] text-foreground">
          {formatRuntimeLabel(rt)}
        </span>
      </span>
    )
  }
  return (
    <span className="flex min-w-0 flex-1 items-center gap-2">
      <span className="flex shrink-0 -space-x-1.5">
        {selectedRuntimes.slice(0, 3).map((rt) => (
          <span
            key={rt}
            className="inline-flex rounded border border-border bg-muted/60 p-0.5 ring-2 ring-background"
          >
            <RuntimeIcon runtime={rt} size="sm" className="h-3.5 w-3.5" />
          </span>
        ))}
      </span>
      <span className="min-w-0 truncate text-[13px] text-foreground tabular-nums">
        {selectedRuntimes.length} selected
      </span>
    </span>
  )
}

const filterPopoverContentClass =
  'w-[var(--radix-popover-trigger-width)] min-w-[14rem] max-h-[min(320px,var(--radix-popover-content-available-height))] overflow-hidden p-0'

/** Minimal bulk actions under the search field in filter dropdowns */
function FilterDropdownToolbar({
  onSelectAll,
  onClear,
  selectAllDisabled,
  clearDisabled,
}: {
  onSelectAll: () => void
  onClear: () => void
  selectAllDisabled: boolean
  clearDisabled: boolean
}) {
  return (
    <div className="flex items-center justify-end gap-0.5 border-b border-border/50 px-2 py-0.5">
      <button
        type="button"
        disabled={selectAllDisabled}
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onSelectAll()
        }}
        className="rounded px-1 py-0.5 text-[11px] leading-none text-muted-foreground/80 transition-colors hover:bg-muted/50 hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
      >
        Select all
      </button>
      <span
        className="select-none px-0.5 text-[9px] text-muted-foreground/30"
        aria-hidden
      >
        ·
      </span>
      <button
        type="button"
        disabled={clearDisabled}
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onClear()
        }}
        className="rounded px-1 py-0.5 text-[11px] leading-none text-muted-foreground/80 transition-colors hover:bg-muted/50 hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
      >
        Clear
      </button>
    </div>
  )
}

function TemplateCatalogFilters({
  searchInput,
  onSearchInputChange,
  catalogUseCases,
  catalogRuntimes,
  selectedUseCases,
  selectedRuntimes,
  toggleUseCase,
  toggleRuntime,
  onSelectAllUseCases,
  onClearUseCases,
  onSelectAllRuntimes,
  onClearRuntimes,
  className,
}: {
  searchInput: string
  onSearchInputChange: (value: string) => void
  catalogUseCases: string[]
  catalogRuntimes: string[]
  selectedUseCases: string[]
  selectedRuntimes: string[]
  toggleUseCase: (value: string) => void
  toggleRuntime: (value: string) => void
  onSelectAllUseCases: () => void
  onClearUseCases: () => void
  onSelectAllRuntimes: () => void
  onClearRuntimes: () => void
  className?: string
}) {
  const [useCaseOpen, setUseCaseOpen] = useState(false)
  const [runtimeOpen, setRuntimeOpen] = useState(false)

  const useCaseAllSelected =
    catalogUseCases.length > 0 &&
    catalogUseCases.every((uc) =>
      selectedUseCases.some((s) => s.toLowerCase() === uc.toLowerCase()),
    )
  const useCaseHasSelection = selectedUseCases.length > 0

  const runtimeAllSelected =
    catalogRuntimes.length > 0 &&
    catalogRuntimes.every((rt) => selectedRuntimes.includes(rt))
  const runtimeHasSelection = selectedRuntimes.length > 0

  return (
    <div
      className={cn(
        'flex max-h-[min(70vh,calc(100vh-10rem))] flex-col gap-5',
        className,
      )}
    >
      <section className="space-y-2">
        <label htmlFor="template-catalog-search" className="sr-only">
          Search templates by name
        </label>
        <div className="relative w-full">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            id="template-catalog-search"
            type="search"
            placeholder="Search by name…"
            value={searchInput}
            onChange={(e) => onSearchInputChange(e.target.value)}
            className="h-9 w-full border-border bg-background pl-9 pr-3 text-[13px] placeholder:text-muted-foreground"
            autoComplete="off"
          />
        </div>
      </section>

      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain">
        <section className="space-y-2">
          <h3 className={filterSectionTitle}>Use case</h3>
          <Popover open={useCaseOpen} onOpenChange={setUseCaseOpen}>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                type="button"
                role="combobox"
                aria-expanded={useCaseOpen}
                className="h-9 w-full justify-between gap-2 px-3 text-[13px] font-normal"
              >
                <UseCaseFilterTrigger selectedUseCases={selectedUseCases} />
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="start"
              className={filterPopoverContentClass}
              onWheelCapture={(e) => e.stopPropagation()}
            >
              <Command>
                <CommandInput
                  placeholder="Search use cases…"
                  className="h-9 text-[13px]"
                />
                {catalogUseCases.length > 0 ? (
                  <FilterDropdownToolbar
                    onSelectAll={onSelectAllUseCases}
                    onClear={onClearUseCases}
                    selectAllDisabled={useCaseAllSelected}
                    clearDisabled={!useCaseHasSelection}
                  />
                ) : null}
                <CommandList className="max-h-[240px] overflow-y-auto overscroll-contain">
                  <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                    No use cases match
                  </CommandEmpty>
                  <CommandGroup className="p-1">
                    {catalogUseCases.map((uc) => {
                      const checked = selectedUseCases.some(
                        (s) => s.toLowerCase() === uc.toLowerCase(),
                      )
                      const label = formatUseCaseLabel(uc)
                      return (
                        <CommandItem
                          key={uc}
                          value={`${uc} ${label}`}
                          onSelect={() => toggleUseCase(uc)}
                          className={cn(
                            'group cursor-pointer gap-2 rounded-sm px-2 py-2 text-[13px]',
                            '[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/55 [&_[data-slot=checkbox][data-state=unchecked]]:bg-background',
                            'data-[selected=true]:[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/80',
                            '[&_[data-slot=checkbox][data-state=checked]]:!border-primary [&_[data-slot=checkbox][data-state=checked]]:!bg-primary',
                            '[&_[data-slot=checkbox]_svg]:!text-primary-foreground',
                          )}
                        >
                          <Checkbox
                            checked={checked}
                            className="pointer-events-none shrink-0 border-border bg-background shadow-sm data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
                            tabIndex={-1}
                          />
                          <span className="min-w-0 flex-1 text-[13px] leading-snug">
                            {label}
                          </span>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </section>

        <section className="space-y-2">
          <h3 className={filterSectionTitle}>Runtime</h3>
          <Popover open={runtimeOpen} onOpenChange={setRuntimeOpen}>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                type="button"
                role="combobox"
                aria-expanded={runtimeOpen}
                className="h-9 w-full justify-between gap-2 px-3 text-[13px] font-normal"
              >
                <RuntimeFilterTrigger selectedRuntimes={selectedRuntimes} />
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="start"
              className={filterPopoverContentClass}
              onWheelCapture={(e) => e.stopPropagation()}
            >
              <Command>
                <CommandInput
                  placeholder="Search runtimes…"
                  className="h-9 text-[13px]"
                />
                {catalogRuntimes.length > 0 ? (
                  <FilterDropdownToolbar
                    onSelectAll={onSelectAllRuntimes}
                    onClear={onClearRuntimes}
                    selectAllDisabled={runtimeAllSelected}
                    clearDisabled={!runtimeHasSelection}
                  />
                ) : null}
                <CommandList className="max-h-[240px] overflow-y-auto overscroll-contain">
                  <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                    No runtimes match
                  </CommandEmpty>
                  <CommandGroup className="p-1">
                    {catalogRuntimes.map((rt) => {
                      const checked = selectedRuntimes.includes(rt)
                      const label = formatRuntimeLabel(rt)
                      return (
                        <CommandItem
                          key={rt}
                          value={`${rt} ${label}`}
                          onSelect={() => toggleRuntime(rt)}
                          className={cn(
                            'group cursor-pointer gap-2 rounded-sm px-2 py-2 text-[13px]',
                            // cmdk selected row uses bg-accent; keep checkbox readable
                            '[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/55 [&_[data-slot=checkbox][data-state=unchecked]]:bg-background',
                            'data-[selected=true]:[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/80',
                            '[&_[data-slot=checkbox][data-state=checked]]:!border-primary [&_[data-slot=checkbox][data-state=checked]]:!bg-primary',
                            '[&_[data-slot=checkbox]_svg]:!text-primary-foreground',
                          )}
                        >
                          <Checkbox
                            checked={checked}
                            className="pointer-events-none shrink-0 border-border bg-background shadow-sm data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
                            tabIndex={-1}
                          />
                          <RuntimeIcon
                            runtime={rt}
                            size="sm"
                            className="h-4 w-4 shrink-0 text-muted-foreground group-data-[selected=true]:text-foreground"
                          />
                          <span className="min-w-0 flex-1 font-mono text-[13px] leading-snug">
                            {label}
                          </span>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </section>
      </div>

      <div className="shrink-0 border-t border-border pt-4">
        <a
          href="https://github.com/appwrite/templates/blob/main/CONTRIBUTING.md"
          target="_blank"
          rel="noreferrer noopener"
          className="inline-flex max-w-full items-start gap-1.5 text-[12px] leading-snug text-muted-foreground transition-colors hover:text-foreground"
        >
          <span className="min-w-0 break-words">
            Contribute a template — guidelines
          </span>
          <ExternalLink className="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-70" />
        </a>
      </div>
    </div>
  )
}

export function View() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const search = Route.useSearch()

  const urlLimit = parseTemplatesLimit(search.limit, GRID_DEFAULT_PAGE_SIZE)

  const urlOffset = useMemo(() => {
    if (search.offset != null) {
      return parseTemplatesOffset(search.offset)
    }
    if (search.page != null) {
      return (parseTemplatesPage(search.page) - 1) * urlLimit
    }
    return 0
  }, [search.offset, search.page, urlLimit])

  const urlSearch = search.search?.trim() ?? ''
  const selectedUseCases = useMemo(
    () => parseCsvParam(search.uc),
    [search.uc],
  )
  const selectedRuntimes = useMemo(
    () => parseCsvParam(search.rt),
    [search.rt],
  )

  const [searchInput, setSearchInput] = useState(urlSearch)
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    setSearchInput(urlSearch)
  }, [urlSearch])

  const navigateCatalog = useCallback(
    (patch: Partial<TemplatesSearch>) => {
      navigate({
        to: '/projects/$projectId/functions/templates',
        params: { projectId: projectId! },
        search: (prev) => {
          const base = { ...(prev as Record<string, unknown>) }
          const next: Record<string, string | number | undefined> = {
            ...base,
            ...patch,
          }
          if (next.search === '' || next.search === undefined) delete next.search
          delete next.page
          if (next.offset === 0 || next.offset === undefined) delete next.offset
          if (
            next.limit === GRID_DEFAULT_PAGE_SIZE ||
            next.limit === undefined
          )
            delete next.limit
          if (!next.uc) delete next.uc
          if (!next.rt) delete next.rt
          return next
        },
        replace: true,
      })
    },
    [navigate, projectId],
  )

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const t = searchInput.trim()
      if (t === urlSearch) return
      navigateCatalog({ search: t || undefined, offset: 0 })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [searchInput, urlSearch, navigateCatalog])

  const skipFilterScrollRef = useRef(true)
  useEffect(() => {
    if (skipFilterScrollRef.current) {
      skipFilterScrollRef.current = false
      return
    }
    scrollConsoleMainToTop()
  }, [urlSearch, search.uc, search.rt])

  const isNameSearch = urlSearch.trim().length > 0
  const pageSize = Math.max(1, urlLimit)

  const facetsQuery = useQuery(functionTemplateFacetsQueryOptions(projectId))

  const pageQuery = useQuery({
    ...functionTemplatesPageQueryOptions(
      projectId,
      urlOffset,
      pageSize,
      selectedRuntimes,
      selectedUseCases,
    ),
    enabled: !!projectId && !isNameSearch,
  })

  const searchCatalogQuery = useQuery({
    ...allFunctionTemplatesQueryOptions(projectId, {
      runtimes: selectedRuntimes.length ? selectedRuntimes : undefined,
      useCases: selectedUseCases.length ? selectedUseCases : undefined,
    }),
    enabled: !!projectId && isNameSearch,
  })

  const searchFilteredTemplates = useMemo(() => {
    if (!isNameSearch) return []
    const q = urlSearch.toLowerCase()
    const list = searchCatalogQuery.data?.templates ?? []
    return [...list]
      .filter((template) => {
        const name = (template.name ?? '').toLowerCase()
        return name.includes(q)
      })
      .sort((a, b) => {
        const an = (a.name ?? '').toLowerCase()
        const bn = (b.name ?? '').toLowerCase()
        if (an !== bn) return an.localeCompare(bn)
        return String(a.id ?? '').localeCompare(String(b.id ?? ''))
      })
  }, [isNameSearch, urlSearch, searchCatalogQuery.data?.templates])

  const catalogUseCases = useMemo(
    () => facetsQuery.data?.useCases ?? [],
    [facetsQuery.data],
  )
  const catalogRuntimes = useMemo(
    () => facetsQuery.data?.runtimes ?? [],
    [facetsQuery.data],
  )

  /** Distinguishes filter sets so we can reuse last known `total` while paging (offset is not part of key). */
  const catalogFacetKey = useMemo(
    () =>
      `${String(projectId ?? '')}\u0000${[...selectedRuntimes].sort().join(',')}\u0000${[...selectedUseCases].sort().join(',')}`,
    [projectId, selectedRuntimes, selectedUseCases],
  )

  const lastServerTotalByFacetRef = useRef<Map<string, number>>(new Map())
  if (!isNameSearch && pageQuery.data && typeof pageQuery.data.total === 'number') {
    lastServerTotalByFacetRef.current.set(catalogFacetKey, pageQuery.data.total)
  }

  /** Without this, `pageQuery.data` is briefly undefined between pages and total looks like 0, which triggers a bogus clamp to offset 0. */
  const hasKnownCatalogTotal =
    isNameSearch ||
    pageQuery.data != null ||
    lastServerTotalByFacetRef.current.has(catalogFacetKey)

  const totalFiltered = isNameSearch
    ? searchFilteredTemplates.length
    : (pageQuery.data?.total ??
      lastServerTotalByFacetRef.current.get(catalogFacetKey) ??
      0)

  const pageCount = Math.max(1, Math.ceil(totalFiltered / pageSize))
  const maxOffset =
    isNameSearch || hasKnownCatalogTotal
      ? totalFiltered === 0
        ? 0
        : Math.max(0, (pageCount - 1) * pageSize)
      : Number.MAX_SAFE_INTEGER
  const safeOffset =
    maxOffset === Number.MAX_SAFE_INTEGER
      ? urlOffset
      : Math.min(urlOffset, maxOffset)
  const safePage = Math.floor(safeOffset / pageSize) + 1
  const paginatedTemplates = isNameSearch
    ? searchFilteredTemplates.slice(safeOffset, safeOffset + pageSize)
    : (pageQuery.data?.templates ?? [])

  /** Accurate "X–Y of Z" when this page shows fewer rows than `pageSize` (e.g. last page). */
  const paginationDisplayItemRange = useMemo(() => {
    if (totalFiltered <= 0 || paginatedTemplates.length === 0) return undefined
    return {
      start: safeOffset + 1,
      end: Math.min(safeOffset + paginatedTemplates.length, totalFiltered),
    }
  }, [totalFiltered, paginatedTemplates.length, safeOffset])

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  const { data: totalFunctionsData } = useQuery({
    queryKey: [
      'functions',
      'project',
      projectId,
      0,
      GRID_DEFAULT_PAGE_SIZE,
      undefined,
      undefined,
      FUNCTIONS_DEFAULT_SORT_BY,
      FUNCTIONS_DEFAULT_SORT_ORDER,
    ],
    queryFn: () =>
      fetchProjectFunctions(
        projectId!,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        undefined,
        undefined,
        FUNCTIONS_DEFAULT_SORT_BY,
        FUNCTIONS_DEFAULT_SORT_ORDER,
      ),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    refetchOnMount: false,
  })

  const totalFunctionsCount = totalFunctionsData?.total ?? 0
  const functionsLimit = organizationPlan?.functions ?? 0
  const noCreatePermission = !canCreateFunction(access, features)
  const createBlockedTooltip = noCreatePermission
    ? "You don't have permission to create functions."
    : functionsLimit > 0 && totalFunctionsCount >= functionsLimit
      ? 'Function limit reached for your plan.'
      : undefined

  useEffect(() => {
    if (urlOffset > maxOffset) {
      navigateCatalog({ offset: maxOffset })
    }
  }, [urlOffset, maxOffset, navigateCatalog])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'functions',
        label: 'Functions',
        to: '/projects/$projectId/functions/',
        params: { projectId: projectId as string },
      },
      {
        id: 'templates',
        label: 'Templates',
        to: '/projects/$projectId/functions/templates',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const toggleUseCase = (value: string) => {
    const lower = value.toLowerCase()
    const has = selectedUseCases.some((u) => u.toLowerCase() === lower)
    const next = has
      ? selectedUseCases.filter((u) => u.toLowerCase() !== lower)
      : [...selectedUseCases, value]
    navigateCatalog({ uc: joinCsvParam(next), offset: 0 })
  }

  const toggleRuntime = (value: string) => {
    const has = selectedRuntimes.includes(value)
    const next = has
      ? selectedRuntimes.filter((r) => r !== value)
      : [...selectedRuntimes, value]
    navigateCatalog({ rt: joinCsvParam(next), offset: 0 })
  }

  const selectAllUseCases = useCallback(() => {
    navigateCatalog({ uc: joinCsvParam([...catalogUseCases]), offset: 0 })
  }, [navigateCatalog, catalogUseCases])

  const clearUseCases = useCallback(() => {
    navigateCatalog({ uc: undefined, offset: 0 })
  }, [navigateCatalog])

  const selectAllRuntimes = useCallback(() => {
    navigateCatalog({ rt: joinCsvParam([...catalogRuntimes]), offset: 0 })
  }, [navigateCatalog, catalogRuntimes])

  const clearRuntimes = useCallback(() => {
    navigateCatalog({ rt: undefined, offset: 0 })
  }, [navigateCatalog])

  const clearFiltersAndSearch = () => {
    setSearchInput('')
    navigate({
      to: '/projects/$projectId/functions/templates',
      params: { projectId: projectId! },
      search: {},
      replace: true,
    })
  }

  const [detailTemplate, setDetailTemplate] =
    useState<Models.TemplateFunction | null>(null)

  const listError =
    facetsQuery.error ?? pageQuery.error ?? searchCatalogQuery.error

  const facetsLoading = facetsQuery.isPending && !facetsQuery.data
  const pageLoading =
    !isNameSearch &&
    pageQuery.isPending &&
    !pageQuery.data
  const searchLoading =
    isNameSearch &&
    searchCatalogQuery.isPending &&
    !searchCatalogQuery.data

  const showLoading = facetsLoading || pageLoading || searchLoading

  const hasFiltersOrSearch =
    urlSearch.length > 0 ||
    selectedUseCases.length > 0 ||
    selectedRuntimes.length > 0

  const noResults =
    !showLoading &&
    totalFiltered === 0 &&
    hasFiltersOrSearch

  const emptyCatalog =
    !showLoading &&
    totalFiltered === 0 &&
    !hasFiltersOrSearch &&
    !listError

  return (
    <div className="flex flex-col">
      <ServiceHeader title="Functions" tabs={tabs} activeTab="templates" fullWidthBorder />

      <div className="mx-auto flex w-full max-w-7xl flex-1 gap-4 px-4 pb-6 pt-6 sm:px-6 lg:gap-5">
        <aside className="hidden w-[15.5rem] shrink-0 lg:block">
          <div className="sticky top-4">
            <TemplateCatalogFilters
              searchInput={searchInput}
              onSearchInputChange={setSearchInput}
              catalogUseCases={catalogUseCases}
              catalogRuntimes={catalogRuntimes}
              selectedUseCases={selectedUseCases}
              selectedRuntimes={selectedRuntimes}
              toggleUseCase={toggleUseCase}
              toggleRuntime={toggleRuntime}
              onSelectAllUseCases={selectAllUseCases}
              onClearUseCases={clearUseCases}
              onSelectAllRuntimes={selectAllRuntimes}
              onClearRuntimes={clearRuntimes}
            />
          </div>
        </aside>

        <div className="min-w-0 flex-1">
          <div className="mb-4 lg:hidden">
            <TemplateCatalogFilters
              searchInput={searchInput}
              onSearchInputChange={setSearchInput}
              catalogUseCases={catalogUseCases}
              catalogRuntimes={catalogRuntimes}
              selectedUseCases={selectedUseCases}
              selectedRuntimes={selectedRuntimes}
              toggleUseCase={toggleUseCase}
              toggleRuntime={toggleRuntime}
              onSelectAllUseCases={selectAllUseCases}
              onClearUseCases={clearUseCases}
              onSelectAllRuntimes={selectAllRuntimes}
              onClearRuntimes={clearRuntimes}
              className="max-h-[min(55vh,26rem)] lg:max-h-none"
            />
          </div>
          {listError ? (
            <EmptyState
              icon={AlertCircle}
              title="Couldn't load templates"
              description="Something went wrong. Please try again."
              isEmpty={false}
              hasFilters={false}
              variant="card"
            />
          ) : showLoading ? (
            <EmptyState variant="card">
              <div className="flex flex-col items-center gap-3">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                <p className="text-[13px] text-muted-foreground">
                  Loading templates...
                </p>
              </div>
            </EmptyState>
          ) : emptyCatalog ? (
            <EmptyState
              icon={LayoutTemplate}
              title="No templates yet"
              description="Function templates will appear here when they are available in the catalog."
              isEmpty
              hasFilters={false}
              variant="card"
            />
          ) : noResults ? (
            <EmptyState
              icon={Braces}
              isEmpty={false}
              hasFilters
              variant="card"
              title="No templates match"
              description="Try adjusting filters or search, or clear everything to see the full catalog."
              action={
                <Button
                  variant="outline"
                  size="sm"
                  onClick={clearFiltersAndSearch}
                >
                  Clear filters and search
                </Button>
              }
            />
          ) : (
            <>
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {paginatedTemplates.map((template, i) => (
                  <TemplateCard
                    key={`${safeOffset + i}-${String(template.id)}`}
                    template={template}
                    projectId={projectId!}
                    createBlockedTooltip={createBlockedTooltip}
                    onDetails={() => setDetailTemplate(template)}
                  />
                ))}
              </div>

              {totalFiltered > 0 && (
                <div className="mt-6">
                  <Pagination
                    currentPage={safePage}
                    totalItems={totalFiltered}
                    pageSize={pageSize}
                    displayItemRange={paginationDisplayItemRange}
                    pageSizeOptions={[12, 18, 36, 72]}
                    onPageChange={(page) => {
                      navigateCatalog({ offset: (page - 1) * pageSize })
                    }}
                    onPageSizeChange={(limit) => {
                      navigateCatalog({ limit, offset: 0 })
                    }}
                    itemLabel="templates"
                  />
                </div>
              )}
            </>
          )}
        </div>
      </div>

      <Sheet
        open={!!detailTemplate}
        onOpenChange={(open) => !open && setDetailTemplate(null)}
      >
        <SheetContent className="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-md">
          {detailTemplate && (
            <>
              <SheetHeader className="space-y-1 border-b border-border px-6 pb-4 pt-6 text-left">
                <SheetTitle className="text-left text-[17px] font-semibold leading-snug">
                  {detailTemplate.name}
                </SheetTitle>
                <SheetDescription className="text-left text-[13px] leading-relaxed">
                  {detailTemplate.tagline}
                </SheetDescription>
              </SheetHeader>
              <div className="space-y-6 px-6 py-6">
                <div>
                  <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                    Use cases
                  </p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {(detailTemplate.useCases ?? []).map((u) => (
                      <Badge
                        key={u}
                        variant="info"
                        className="text-[10px] font-normal"
                      >
                        {formatUseCaseLabel(u)}
                      </Badge>
                    ))}
                  </div>
                </div>
                <div>
                  <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                    Runtimes
                  </p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {(detailTemplate.runtimes ?? []).map((r) => (
                      <Badge
                        key={r.name}
                        variant="info"
                        className="font-mono text-[10px] font-normal"
                      >
                        {r.name}
                      </Badge>
                    ))}
                  </div>
                </div>
                <div className="flex flex-col gap-2 border-t border-border pt-6">
                  {createBlockedTooltip ? (
                    <TooltipProvider delayDuration={0}>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <span className="inline-flex w-full">
                            <Button
                              className="w-full"
                              disabled
                              type="button"
                            >
                              Create from template
                            </Button>
                          </span>
                        </TooltipTrigger>
                        <TooltipContent>{createBlockedTooltip}</TooltipContent>
                      </Tooltip>
                    </TooltipProvider>
                  ) : (
                    <Button className="w-full" asChild>
                      <Link
                        to="/projects/$projectId/functions/create/template/$templateId"
                        params={{
                          projectId: projectId!,
                          templateId: detailTemplate.id,
                        }}
                      >
                        Create from template
                      </Link>
                    </Button>
                  )}
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  )
}

function TemplateCard({
  template,
  projectId,
  createBlockedTooltip,
  onDetails,
}: {
  template: Models.TemplateFunction
  projectId: string
  createBlockedTooltip?: string
  onDetails: () => void
}) {
  const baseRuntimes = getBaseRuntimes(template.runtimes ?? [])
  const displayed = baseRuntimes.slice(0, 2)
  const hidden = baseRuntimes.slice(2)

  return (
    <div
      className={cn(
        'group flex min-h-[160px] flex-col rounded-lg border border-border bg-card p-4 transition-all',
        'hover:border-border hover:bg-accent/50',
      )}
    >
      <div className="flex flex-1 flex-col gap-3">
        <div className="space-y-1">
          <div className="flex items-start justify-between gap-2">
            <h3 className="text-[15px] font-semibold leading-snug text-foreground">
              {template.name}
            </h3>
            <LayoutTemplate className="h-4 w-4 shrink-0 text-muted-foreground opacity-60" />
          </div>
          <p className="line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
            {template.tagline}
          </p>
        </div>

        <div className="mt-auto flex items-end justify-between gap-2 border-t border-border pt-3">
          <div className="flex items-center gap-1">
            <TooltipProvider delayDuration={0}>
              {displayed.map((r) => (
                <div
                  key={r.name}
                  className="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-muted/50"
                >
                  <RuntimeIcon runtime={r.name} size="sm" className="h-4 w-4" />
                </div>
              ))}
              {hidden.length > 0 && (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <div className="flex h-8 min-w-8 cursor-default items-center justify-center rounded-md border border-dashed border-border px-1.5 font-mono text-[10px] text-muted-foreground">
                      +{hidden.length}
                    </div>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="max-w-xs">
                    <p className="font-mono text-[11px]">
                      {hidden.map((h) => h.name).join(', ')}
                    </p>
                  </TooltipContent>
                </Tooltip>
              )}
            </TooltipProvider>
          </div>
          <div className="flex shrink-0 items-center gap-1">
            <Button
              variant="ghost"
              size="sm"
              className="h-8 text-[13px] text-muted-foreground"
              type="button"
              onClick={onDetails}
            >
              Details
            </Button>
            {createBlockedTooltip ? (
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button
                        variant="secondary"
                        size="sm"
                        className="h-8 text-[13px]"
                        type="button"
                        disabled
                      >
                        Create
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>{createBlockedTooltip}</TooltipContent>
                </Tooltip>
              </TooltipProvider>
            ) : (
              <Button
                variant="secondary"
                size="sm"
                className="h-8 text-[13px]"
                asChild
              >
                <Link
                  to="/projects/$projectId/functions/create/template/$templateId"
                  params={{ projectId, templateId: template.id }}
                >
                  Create
                </Link>
              </Button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
