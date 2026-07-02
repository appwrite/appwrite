import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useQueries } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import { ChevronLeft, ChevronRight, Search } from 'lucide-react'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { fetchProjectDowngradeResources } from '@/lib/billing/fetch-project-downgrade-resources'
import {
  buildResourcesToDelete,
  deleteDowngradeResources,
  type ResourcesToDelete,
} from '@/lib/billing/delete-downgrade-resources'
import {
  DOWNGRADE_RESOURCE_TYPES,
  countResourcesToDeleteForProject,
  getDefaultKeepIds,
  getDowngradePlanLimits,
  getResourceViolationCount,
  isResourceSelectionValid,
  mergeResourceImpacts,
  type DowngradeResourceImpact,
  type DowngradeResourceType,
  type ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'
import type { ProjectResourceImpact } from './DowngradeImpactSummary'

const COLUMN_LIST_PAGE_SIZE = 5
/** Matches list row (py-2.5 + single line) and space-y-1 gaps for stable pagination height. */
const PAGINATED_LIST_ROW_HEIGHT_CLASS = 'h-[42px]'
const PAGINATED_LIST_BODY_MIN_HEIGHT_CLASS = 'min-h-[226px]'

function filterPaginatedList<T>(
  items: T[],
  search: string,
  filterFn: (item: T, query: string) => boolean,
  page: number,
  pageSize: number,
) {
  const query = search.trim().toLowerCase()
  const filtered = query
    ? items.filter((item) => filterFn(item, query))
    : items
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize))
  const safePage = Math.min(Math.max(1, page), totalPages)
  const start = (safePage - 1) * pageSize

  return {
    filtered,
    paginated: filtered.slice(start, start + pageSize),
    totalPages,
    safePage,
    totalItems: filtered.length,
  }
}

function ColumnSearchBar({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (value: string) => void
  placeholder: string
}) {
  return (
    <div className="shrink-0 border-b border-border px-2 py-2">
      <div className="relative">
        <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          className="h-8 border-border bg-background ps-8 text-[13px]"
        />
      </div>
    </div>
  )
}

function ColumnPaginationFooter({
  currentPage,
  totalPages,
  totalItems,
  onPageChange,
  reserveSpace = false,
}: {
  currentPage: number
  totalPages: number
  totalItems: number
  onPageChange: (page: number) => void
  reserveSpace?: boolean
}) {
  const t = useT()
  const showPagination = totalItems > COLUMN_LIST_PAGE_SIZE
  if (!showPagination && !reserveSpace) return null

  return (
    <div
      className={cn(
        'flex min-h-[41px] shrink-0 items-center justify-between gap-2 border-t border-border px-2 py-2',
        !showPagination && 'invisible pointer-events-none',
      )}
      aria-hidden={!showPagination}
    >
      <span className="text-[11px] tabular-nums text-muted-foreground">
        {t('Page')} {currentPage} {t('of')} {totalPages}
      </span>
      <div className="flex items-center gap-1">
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-7 w-7"
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage <= 1}
          aria-label={t('Previous page')}
        >
          <ChevronLeft className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-7 w-7"
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage >= totalPages}
          aria-label={t('Next page')}
        >
          <ChevronRight className="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>
  )
}

function PaginatedListSlots({
  pageSize,
  itemCount,
  children,
}: {
  pageSize: number
  itemCount: number
  children: ReactNode
}) {
  const spacerCount = Math.max(0, pageSize - itemCount)

  return (
    <>
      {children}
      {Array.from({ length: spacerCount }).map((_, index) => (
        <div
          key={`paginated-slot-spacer-${index}`}
          className={cn(PAGINATED_LIST_ROW_HEIGHT_CLASS, 'shrink-0')}
          aria-hidden
        />
      ))}
    </>
  )
}

export type DowngradeResourceValidationHandle = {
  getSelectedProjects: () => string[]
  isValid: () => boolean
  deleteMarkedResources: () => Promise<void>
}

type ProjectResourceSelections = Partial<
  Record<DowngradeResourceType, Set<string>>
>

interface DowngradeResourceValidationProps {
  projects: Models.Project[]
  targetPlan: Record<string, unknown> | null | undefined
  onRef: (ref: DowngradeResourceValidationHandle | null) => void
  onValidityChange?: (valid: boolean) => void
  onImpactChange?: (
    impact: DowngradeResourceImpact,
    loading: boolean,
    projectImpacts: ProjectResourceImpact[],
  ) => void
}

export function DowngradeResourceValidation({
  projects,
  targetPlan,
  onRef,
  onValidityChange,
  onImpactChange,
}: DowngradeResourceValidationProps) {
  const t = useT()
  const limits = useMemo(() => getDowngradePlanLimits(targetPlan), [targetPlan])

  const [activeProjectId, setActiveProjectId] = useState<string | null>(null)
  const [activeResourceType, setActiveResourceType] =
    useState<DowngradeResourceType | null>(null)
  const [resourceSelections, setResourceSelections] = useState<
    Record<string, ProjectResourceSelections>
  >({})
  const [resourceTypeSearch, setResourceTypeSearch] = useState('')
  const [resourceTypePage, setResourceTypePage] = useState(1)
  const [selectionSearch, setSelectionSearch] = useState('')
  const [selectionPage, setSelectionPage] = useState(1)

  const keptProjectIds = useMemo(
    () =>
      projects
        .map((project) => project.$id)
        .sort()
        .join(','),
    [projects],
  )

  const resourceQueries = useQueries({
    queries: projects.map((project) => ({
      queryKey: ['downgrade-resources', project.$id],
      queryFn: () => fetchProjectDowngradeResources(project.$id),
      enabled: !!project.$id,
      staleTime: 30_000,
    })),
  })

  const resourcesLoading = resourceQueries.some((query) => query.isLoading)

  const resourcesLoadedSignature = useMemo(
    () =>
      projects
        .map((project, index) => {
          const query = resourceQueries[index]
          return `${project.$id}:${query?.dataUpdatedAt ?? 0}:${query?.isLoading ? 'loading' : 'ready'}`
        })
        .join('|'),
    [projects, resourceQueries],
  )

  const resourcesByProjectId = useMemo(() => {
    const map = new Map<string, ProjectDowngradeResources>()
    projects.forEach((project, index) => {
      const data = resourceQueries[index]?.data
      if (data) {
        map.set(project.$id, data)
      }
    })
    return map
  }, [projects, resourcesLoadedSignature])

  useEffect(() => {
    setResourceSelections((prev) => {
      const keptIds = new Set(projects.map((project) => project.$id))
      const next = Object.fromEntries(
        Object.entries(prev).filter(([projectId]) => keptIds.has(projectId)),
      )
      return Object.keys(next).length === Object.keys(prev).length ? prev : next
    })
  }, [keptProjectIds, projects])

  useEffect(() => {
    if (projects.length === 0) {
      setActiveProjectId(null)
      return
    }
    if (
      !activeProjectId ||
      !projects.some((project) => project.$id === activeProjectId)
    ) {
      setActiveProjectId(projects[0].$id)
    }
  }, [activeProjectId, projects])

  useEffect(() => {
    setActiveResourceType(null)
  }, [activeProjectId])

  useEffect(() => {
    setResourceTypeSearch('')
    setResourceTypePage(1)
  }, [activeProjectId])

  useEffect(() => {
    setSelectionSearch('')
    setSelectionPage(1)
  }, [activeProjectId, activeResourceType])

  useEffect(() => {
    setResourceTypePage(1)
  }, [resourceTypeSearch])

  useEffect(() => {
    setSelectionPage(1)
  }, [selectionSearch])

  useEffect(() => {
    setResourceSelections((prev) => {
      let changed = false
      const next = { ...prev }

      for (const project of projects) {
        const resources = resourcesByProjectId.get(project.$id)
        if (!resources) continue

        const current = next[project.$id] ?? {}
        const updated: ProjectResourceSelections = { ...current }
        let projectChanged = false

        for (const { id } of DOWNGRADE_RESOURCE_TYPES) {
          const limit = limits[id]
          const items = resources[id].items
          if (
            !updated[id] &&
            getResourceViolationCount(items.length, limit) > 0
          ) {
            updated[id] = getDefaultKeepIds(items, limit)
            projectChanged = true
          }
        }

        if (projectChanged) {
          next[project.$id] = updated
          changed = true
        }
      }

      return changed ? next : prev
    })
  }, [projects, limits, resourcesLoadedSignature])

  const getProjectIssueCount = useCallback(
    (projectId: string) => {
      const resources = resourcesByProjectId.get(projectId)
      if (!resources) return 0

      return DOWNGRADE_RESOURCE_TYPES.reduce((count, { id }) => {
        const limit = limits[id]
        if (limit === null) return count
        return count + getResourceViolationCount(resources[id].total, limit)
      }, 0)
    },
    [limits, resourcesByProjectId],
  )

  const resourceSelectionValid = useMemo(() => {
    return projects.every((project) => {
      const resources = resourcesByProjectId.get(project.$id)
      if (!resources) return !resourcesLoading

      return DOWNGRADE_RESOURCE_TYPES.every(({ id }) => {
        const limit = limits[id]
        const selection = resourceSelections[project.$id]?.[id]
        return isResourceSelectionValid(
          resources[id].items,
          selection ?? new Set(),
          limit,
        )
      })
    })
  }, [
    projects,
    limits,
    resourceSelections,
    resourcesByProjectId,
    resourcesLoading,
  ])

  const isValid = resourceSelectionValid && !resourcesLoading

  const projectResourceImpacts = useMemo<ProjectResourceImpact[]>(() => {
    return projects.map((project) => {
      const resources = resourcesByProjectId.get(project.$id)
      const impact = resources
        ? countResourcesToDeleteForProject(
            resources,
            resourceSelections[project.$id] ?? {},
            limits,
          )
        : {}

      return {
        projectId: project.$id,
        projectName: project.name || project.$id,
        resourceImpact: impact,
      }
    })
  }, [projects, resourceSelections, resourcesByProjectId, limits])

  const resourceImpact = useMemo(() => {
    return mergeResourceImpacts(
      projectResourceImpacts.map(({ resourceImpact }) => resourceImpact),
    )
  }, [projectResourceImpacts])

  const onImpactChangeRef = useRef(onImpactChange)
  useEffect(() => {
    onImpactChangeRef.current = onImpactChange
  }, [onImpactChange])

  const lastImpactSignatureRef = useRef('')

  useEffect(() => {
    const signature = `${resourcesLoading}:${JSON.stringify(projectResourceImpacts)}`
    if (lastImpactSignatureRef.current === signature) return
    lastImpactSignatureRef.current = signature
    onImpactChangeRef.current?.(
      resourceImpact,
      resourcesLoading,
      projectResourceImpacts,
    )
  }, [projectResourceImpacts, resourceImpact, resourcesLoading])

  const getSelectedProjects = useCallback(
    () => projects.map((project) => project.$id),
    [projects],
  )

  const deleteMarkedResources = useCallback(async () => {
    const payload: ResourcesToDelete = {}

    for (const project of projects) {
      const resources = resourcesByProjectId.get(project.$id)
      if (!resources) continue

      const toDelete = buildResourcesToDelete(
        project.$id,
        resources,
        resourceSelections[project.$id] ?? {},
      )

      if (Object.keys(toDelete).length > 0) {
        payload[project.$id] = toDelete
      }
    }

    if (Object.keys(payload).length === 0) return
    await deleteDowngradeResources(payload)
  }, [projects, resourceSelections, resourcesByProjectId])

  const onRefRef = useRef(onRef)
  const onValidityChangeRef = useRef(onValidityChange)
  const getSelectedProjectsRef = useRef(getSelectedProjects)
  const deleteMarkedResourcesRef = useRef(deleteMarkedResources)
  const isValidRef = useRef(isValid)

  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  useEffect(() => {
    onValidityChangeRef.current = onValidityChange
  }, [onValidityChange])

  getSelectedProjectsRef.current = getSelectedProjects
  deleteMarkedResourcesRef.current = deleteMarkedResources
  isValidRef.current = isValid

  useEffect(() => {
    onRefRef.current({
      getSelectedProjects: () => getSelectedProjectsRef.current(),
      isValid: () => isValidRef.current,
      deleteMarkedResources: () => deleteMarkedResourcesRef.current(),
    })

    return () => {
      onRefRef.current(null)
    }
  }, [])

  const lastReportedValidRef = useRef<boolean | null>(null)

  useEffect(() => {
    if (lastReportedValidRef.current === isValid) return
    lastReportedValidRef.current = isValid
    onValidityChangeRef.current?.(isValid)
  }, [isValid])

  const toggleResource = (
    projectId: string,
    resourceType: DowngradeResourceType,
    resourceId: string,
    limit: number | null,
  ) => {
    if (limit === null) return

    setResourceSelections((prev) => {
      const projectSelection = prev[projectId] ?? {}
      const current = new Set(projectSelection[resourceType] ?? [])

      if (current.has(resourceId)) {
        current.delete(resourceId)
      } else if (current.size < limit) {
        current.add(resourceId)
      }

      return {
        ...prev,
        [projectId]: {
          ...projectSelection,
          [resourceType]: current,
        },
      }
    })
  }

  const activeProject = projects.find(
    (project) => project.$id === activeProjectId,
  )
  const activeResources = activeProjectId
    ? resourcesByProjectId.get(activeProjectId)
    : undefined
  const activeSelections = activeProjectId
    ? resourceSelections[activeProjectId] ?? {}
    : {}

  const planRelevantResourceTypes = useMemo(
    () => DOWNGRADE_RESOURCE_TYPES.filter(({ id }) => limits[id] !== null),
    [limits],
  )

  const activeTypeConfig = activeResourceType
    ? DOWNGRADE_RESOURCE_TYPES.find((type) => type.id === activeResourceType)
    : undefined
  const activeLimit = activeTypeConfig ? limits[activeTypeConfig.id] : null
  const activeItems =
    activeResourceType && activeResources
      ? activeResources[activeResourceType]?.items ?? []
      : []
  const activeSelected =
    activeResourceType && activeProjectId
      ? activeSelections[activeResourceType] ?? new Set<string>()
      : new Set<string>()
  const activeViolation = getResourceViolationCount(
    activeItems.length,
    activeLimit,
  )

  const filterResourceType = useCallback(
    (type: { label: string }, query: string) =>
      type.label.toLowerCase().includes(query),
    [],
  )

  const filterSelectionItem = useCallback(
    (item: { $id: string; name: string }, query: string) =>
      item.name.toLowerCase().includes(query) ||
      item.$id.toLowerCase().includes(query),
    [],
  )

  const paginatedResourceTypes = useMemo(
    () =>
      filterPaginatedList(
        planRelevantResourceTypes,
        resourceTypeSearch,
        filterResourceType,
        resourceTypePage,
        COLUMN_LIST_PAGE_SIZE,
      ),
    [
      planRelevantResourceTypes,
      resourceTypeSearch,
      filterResourceType,
      resourceTypePage,
    ],
  )

  const paginatedSelectionItems = useMemo(
    () =>
      filterPaginatedList(
        activeItems,
        selectionSearch,
        filterSelectionItem,
        selectionPage,
        COLUMN_LIST_PAGE_SIZE,
      ),
    [activeItems, selectionSearch, filterSelectionItem, selectionPage],
  )

  const activeProjectResourcesLoading =
    resourcesLoading &&
    !resourceQueries.some(
      (query, index) =>
        projects[index]?.$id === activeProjectId && query.data,
    )

  const showResourceTypeList =
    !!activeProject && !!activeResources && !activeProjectResourcesLoading

  const showSelectionList =
    !!activeResourceType && !!activeProject && !!activeResources

  const showSelectionItems =
    showSelectionList &&
    activeItems.length > 0 &&
    paginatedSelectionItems.filtered.length > 0

  const columnHeaderClassName =
    'border-b border-border bg-muted/20 px-4 py-3 min-h-[45px] flex items-center'
  const columnBodyClassName = 'p-2 space-y-1'
  const paginatedColumnBodyClassName = cn(
    columnBodyClassName,
    PAGINATED_LIST_BODY_MIN_HEIGHT_CLASS,
  )
  const columnPlaceholderClassName =
    'px-3 py-2.5 text-[13px] text-muted-foreground'
  const columnEmptyPlaceholderClassName =
    'text-center text-[13px] text-muted-foreground'
  const listRowClassName =
    'flex w-full items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors'
  const listRowActiveClassName = 'border-primary bg-primary/5'
  const listRowButtonClassName =
    'border-transparent bg-background/60 hover:border-border hover:bg-muted/30'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Adjust resources for the target plan')}
        </h3>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        <div className="rounded-lg border border-border overflow-hidden">
          <div className="grid grid-cols-1 lg:grid-cols-3 lg:divide-x divide-border">
            {/* Left: Projects to review */}
            <div className="flex flex-col border-b border-border lg:border-b-0">
              <div className={columnHeaderClassName}>
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Projects')}
                </p>
              </div>

              <TooltipProvider delayDuration={0}>
                <div className={columnBodyClassName}>
                  {projects.map((project) => {
                    const isActive = project.$id === activeProjectId
                    const issueCount = getProjectIssueCount(project.$id)
                    const isLoadingProject = resourceQueries.find(
                      (query, index) =>
                        projects[index]?.$id === project.$id && query.isLoading,
                    )

                    return (
                      <div
                        key={project.$id}
                        role="button"
                        tabIndex={0}
                        onClick={() => setActiveProjectId(project.$id)}
                        onKeyDown={(event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            setActiveProjectId(project.$id)
                          }
                        }}
                        className={cn(
                          listRowClassName,
                          'cursor-pointer justify-between gap-2 text-start',
                          isActive
                            ? listRowActiveClassName
                            : listRowButtonClassName,
                        )}
                      >
                        <span className="min-w-0">
                          <span
                            className="block truncate text-[13px] font-medium leading-normal text-foreground"
                            title={project.name}
                          >
                            {formatProjectNameForDisplay(project.name)}
                          </span>
                          {isLoadingProject ? (
                            <span className="block text-[12px] leading-normal text-muted-foreground mt-0.5">
                              {t('Loading resources...')}
                            </span>
                          ) : null}
                        </span>
                        {issueCount > 0 ? (
                          <TooltipPrimitive.Root>
                            <TooltipTrigger asChild>
                              <span
                                className="inline-flex shrink-0"
                                onClick={(event) => event.stopPropagation()}
                                onPointerDown={(event) =>
                                  event.stopPropagation()
                                }
                              >
                                <Badge
                                  variant="warning"
                                  className="text-[10px] shrink-0"
                                >
                                  {issueCount}
                                </Badge>
                              </span>
                            </TooltipTrigger>
                            <TooltipContent side="left">
                              {issueCount} {t('over limit')}
                            </TooltipContent>
                          </TooltipPrimitive.Root>
                        ) : null}
                      </div>
                    )
                  })}
                </div>
              </TooltipProvider>
            </div>

            {/* Middle: Resource counts */}
            <div className="flex flex-col border-b border-border lg:border-b-0">
              <div className={columnHeaderClassName}>
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Resources')}
                </p>
              </div>

              {showResourceTypeList ? (
                <ColumnSearchBar
                  value={resourceTypeSearch}
                  onChange={setResourceTypeSearch}
                  placeholder={t('Search resource types...')}
                />
              ) : null}

              <div
                className={
                  showResourceTypeList &&
                  paginatedResourceTypes.filtered.length > 0
                    ? paginatedColumnBodyClassName
                    : columnBodyClassName
                }
              >
                {!activeProject || !activeResources ? (
                  <p className={columnPlaceholderClassName}>
                    {t('Select a project to view resources.')}
                  </p>
                ) : activeProjectResourcesLoading ? (
                  <p className={columnPlaceholderClassName}>
                    {t('Loading resources...')}
                  </p>
                ) : paginatedResourceTypes.filtered.length === 0 ? (
                  <p className={columnPlaceholderClassName}>
                    {t('No resource types match your search.')}
                  </p>
                ) : (
                  <PaginatedListSlots
                    pageSize={COLUMN_LIST_PAGE_SIZE}
                    itemCount={paginatedResourceTypes.paginated.length}
                  >
                    {paginatedResourceTypes.paginated.map(({ id, label }) => {
                      const total = activeResources[id].total
                      const limit = limits[id]!
                      const overLimit = total > limit
                      const isActive = activeResourceType === id

                      return (
                        <button
                          key={id}
                          type="button"
                          onClick={() => setActiveResourceType(id)}
                          className={cn(
                            listRowClassName,
                            PAGINATED_LIST_ROW_HEIGHT_CLASS,
                            'items-center justify-between text-start shrink-0',
                            isActive
                              ? listRowActiveClassName
                              : listRowButtonClassName,
                          )}
                        >
                          <span className="text-[13px] font-medium leading-normal text-foreground">
                            {t(label)}
                          </span>
                          {overLimit ? (
                            <Badge
                              variant="error"
                              className="text-[10px] shrink-0"
                            >
                              {total}/{limit}
                            </Badge>
                          ) : (
                            <Badge
                              variant="success"
                              className="text-[10px] shrink-0"
                            >
                              {total}/{limit}
                            </Badge>
                          )}
                        </button>
                      )
                    })}
                  </PaginatedListSlots>
                )}
              </div>

              {showResourceTypeList ? (
                <ColumnPaginationFooter
                  currentPage={paginatedResourceTypes.safePage}
                  totalPages={paginatedResourceTypes.totalPages}
                  totalItems={paginatedResourceTypes.totalItems}
                  onPageChange={setResourceTypePage}
                  reserveSpace
                />
              ) : null}
            </div>

            {/* Right: Resources to keep */}
            <div className="flex flex-col">
              <div className={columnHeaderClassName}>
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {activeTypeConfig ? t(activeTypeConfig.label) : t('Selection')}
                </p>
              </div>

              {showSelectionList && activeItems.length > 0 ? (
                <ColumnSearchBar
                  value={selectionSearch}
                  onChange={setSelectionSearch}
                  placeholder={`${t('Search')} ${activeTypeConfig ? t(activeTypeConfig.label).toLowerCase() : t('items')}...`}
                />
              ) : null}

              <div
                className={cn(
                  showSelectionItems
                    ? paginatedColumnBodyClassName
                    : 'flex items-center justify-center p-8',
                )}
              >
                {!activeResourceType ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('Select a resource type to review items.')}
                  </p>
                ) : !activeProject || !activeResources ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('Select a project to view resources.')}
                  </p>
                ) : activeItems.length === 0 ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('No')}{' '}
                    {activeTypeConfig
                      ? t(activeTypeConfig.label).toLowerCase()
                      : ''}{' '}
                    {t('in this project.')}
                  </p>
                ) : paginatedSelectionItems.filtered.length === 0 ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('No items match your search.')}
                  </p>
                ) : (
                  <PaginatedListSlots
                    pageSize={COLUMN_LIST_PAGE_SIZE}
                    itemCount={paginatedSelectionItems.paginated.length}
                  >
                    {paginatedSelectionItems.paginated.map((item) => {
                      const selected = activeSelected.has(item.$id)
                      const disabled =
                        !selected &&
                        activeLimit !== null &&
                        activeSelected.size >= activeLimit &&
                        activeViolation > 0

                      return (
                        <div
                          key={item.$id}
                          className={cn(
                            listRowClassName,
                            PAGINATED_LIST_ROW_HEIGHT_CLASS,
                            'items-center justify-between gap-2 text-start shrink-0',
                            selected
                              ? listRowActiveClassName
                              : listRowButtonClassName,
                            disabled && 'opacity-50',
                          )}
                        >
                          <div className="flex min-w-0 flex-1 items-center gap-3">
                            <Checkbox
                              id={`${activeProject.$id}-${activeResourceType}-${item.$id}`}
                              checked={selected}
                              disabled={disabled || activeViolation === 0}
                              onCheckedChange={() =>
                                activeResourceType &&
                                toggleResource(
                                  activeProject.$id,
                                  activeResourceType,
                                  item.$id,
                                  activeLimit,
                                )
                              }
                              className="shrink-0"
                            />
                            <Label
                              htmlFor={`${activeProject.$id}-${activeResourceType}-${item.$id}`}
                              className={cn(
                                'min-w-0 truncate text-[13px] font-medium leading-normal text-foreground',
                                disabled || activeViolation === 0
                                  ? 'cursor-default'
                                  : 'cursor-pointer',
                              )}
                            >
                              {item.name}
                            </Label>
                          </div>
                          {!selected && activeViolation > 0 ? (
                            <Badge
                              variant="error"
                              className="text-[10px] shrink-0"
                            >
                              {t('Will delete')}
                            </Badge>
                          ) : null}
                        </div>
                      )
                    })}
                  </PaginatedListSlots>
                )}
              </div>

              {showSelectionList && activeItems.length > 0 ? (
                <ColumnPaginationFooter
                  currentPage={paginatedSelectionItems.safePage}
                  totalPages={paginatedSelectionItems.totalPages}
                  totalItems={paginatedSelectionItems.totalItems}
                  onPageChange={setSelectionPage}
                  reserveSpace
                />
              ) : null}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
