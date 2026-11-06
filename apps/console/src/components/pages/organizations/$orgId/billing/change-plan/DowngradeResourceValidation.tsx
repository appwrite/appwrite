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
  type ResourcesToDelete,
} from '@/lib/billing/delete-downgrade-resources'
import {
  DOWNGRADE_RESOURCE_TYPES,
  countResourcesToDeleteForProject,
  countStagedResourceDeletions,
  countStagedResourcesForProject,
  getDowngradePlanLimits,
  getResourceViolationCount,
  projectHasResourceViolations,
  mergeResourceImpacts,
  type DowngradeResourceImpact,
  type DowngradeResourceLimits,
  type DowngradeResourceType,
  type ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'
import { ConfirmDowngradeDeletes } from './ConfirmDowngradeDeletes'
import { DowngradeConfirmedSelection } from './DowngradeConfirmedSelection'
import {
  getNonCompliantProjectIds,
  getServerResourceLimits,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'
import type { ProjectResourceImpact } from './DowngradeImpactSummary'
import type { DowngradeProjectResourceDeletions } from './DowngradeValidation'

const EMPTY_PROJECTS: Models.Project[] = []

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
  const filtered = query ? items.filter((item) => filterFn(item, query)) : items
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

export type PendingResourceDeletions = {
  resources: ResourcesToDelete
  /** The same selection, named, for the confirmation manifest. */
  projectResources: DowngradeProjectResourceDeletions[]
}

export type DowngradeResourceValidationHandle = {
  isValid: () => boolean
  getPendingResourceDeletions: () => PendingResourceDeletions
}

type ProjectResourceSelections = Partial<
  Record<DowngradeResourceType, Set<string>>
>

interface DowngradeResourceValidationProps {
  projects: Models.Project[]
  targetPlan: Record<string, unknown> | null | undefined
  /**
   * Server-side compliance for the target plan. When present it is the
   * authority on which projects and resource types are over limit; the
   * `targetPlan` derivation is only a fallback for when the estimation failed.
   */
  planChangeLimits?: PlanChangeLimits | null
  planChangeLimitsLoading?: boolean
  onRef: (ref: DowngradeResourceValidationHandle | null) => void
  onValidityChange?: (valid: boolean, reason?: string | null) => void
  onImpactChange?: (payload: DowngradeResourceImpactPayload) => void
}

export type DowngradeResourceImpactPayload = {
  /** Still over the plan limit once the staged deletions are applied. */
  impact: DowngradeResourceImpact
  stagedImpact: DowngradeResourceImpact
  loading: boolean
  projectImpacts: ProjectResourceImpact[]
  stagedProjectImpacts: ProjectResourceImpact[]
}

export function DowngradeResourceValidation({
  projects,
  targetPlan,
  planChangeLimits = null,
  planChangeLimitsLoading = false,
  onRef,
  onValidityChange,
  onImpactChange,
}: DowngradeResourceValidationProps) {
  const t = useT()
  const clientLimits = useMemo(
    () => getDowngradePlanLimits(targetPlan),
    [targetPlan],
  )

  // The server reports only the resource types a project *exceeds*, so it is
  // authoritative where it speaks but silent about everything within limits.
  // Fall back to the plan-derived limit for the rest, otherwise compliant
  // resource types would drop out of the list instead of showing their headroom.
  const limitsForProject = useCallback(
    (projectId: string): DowngradeResourceLimits => {
      if (!planChangeLimits) return clientLimits

      const serverLimits = getServerResourceLimits(planChangeLimits, projectId)
      return DOWNGRADE_RESOURCE_TYPES.reduce((acc, { id }) => {
        acc[id] = serverLimits[id] ?? clientLimits[id]
        return acc
      }, {} as DowngradeResourceLimits)
    },
    [clientLimits, planChangeLimits],
  )

  // Projects the server flagged as over limit. Used only to open the step on a
  // project that needs attention - every kept project still gets its resources
  // listed, since the UI shows usage against the limit for compliant ones too.
  const flaggedProjects = useMemo(() => {
    if (!planChangeLimits) return EMPTY_PROJECTS
    const nonCompliant = new Set(getNonCompliantProjectIds(planChangeLimits))
    return projects.filter((project) => nonCompliant.has(project.$id))
  }, [projects, planChangeLimits])

  const [activeProjectId, setActiveProjectId] = useState<string | null>(null)
  const [activeResourceType, setActiveResourceType] =
    useState<DowngradeResourceType | null>(null)
  const [resourceSelections, setResourceSelections] = useState<
    Record<string, ProjectResourceSelections>
  >({})
  const [confirmedSelections, setConfirmedSelections] = useState<
    Record<string, ProjectResourceSelections>
  >({})
  const [confirmOpen, setConfirmOpen] = useState(false)
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

  const resourcesLoading =
    planChangeLimitsLoading || resourceQueries.some((query) => query.isLoading)

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

  // resourceQueries is a fresh array every render; the signature above is its
  // stable content proxy, so depending on it directly would defeat the memo.
  const resourcesByProjectId = useMemo(() => {
    const map = new Map<string, ProjectDowngradeResources>()
    projects.forEach((project, index) => {
      const data = resourceQueries[index]?.data
      if (data) {
        map.set(project.$id, data)
      }
    })
    return map
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projects, resourcesLoadedSignature])

  useEffect(() => {
    const keptIds = new Set(projects.map((project) => project.$id))
    const prune = (prev: Record<string, ProjectResourceSelections>) => {
      const next = Object.fromEntries(
        Object.entries(prev).filter(([projectId]) => keptIds.has(projectId)),
      )
      return Object.keys(next).length === Object.keys(prev).length ? prev : next
    }
    setResourceSelections(prune)
    setConfirmedSelections(prune)
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
      // Prefer a project that actually has something to resolve.
      setActiveProjectId((flaggedProjects[0] ?? projects[0]).$id)
    }
  }, [activeProjectId, projects, flaggedProjects])

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

  const getProjectIssueCount = useCallback(
    (projectId: string) => {
      const resources = resourcesByProjectId.get(projectId)
      if (!resources) return 0

      const projectLimits = limitsForProject(projectId)
      const staged = confirmedSelections[projectId]
      return DOWNGRADE_RESOURCE_TYPES.reduce((count, { id }) => {
        const limit = projectLimits[id]
        if (limit === null) return count
        return (
          count +
          getResourceViolationCount(
            resources[id].total -
              countStagedResourceDeletions(resources, id, staged),
            limit,
          )
        )
      }, 0)
    },
    [confirmedSelections, limitsForProject, resourcesByProjectId],
  )

  const remainingWithinLimits = useMemo(() => {
    return projects.every((project) => {
      const resources = resourcesByProjectId.get(project.$id)
      if (!resources) return !resourcesLoading
      return !projectHasResourceViolations(
        resources,
        limitsForProject(project.$id),
        confirmedSelections[project.$id],
      )
    })
  }, [
    projects,
    confirmedSelections,
    limitsForProject,
    resourcesByProjectId,
    resourcesLoading,
  ])

  // A failed list call reports 0, which would otherwise pass as compliant and
  // let the user submit against usage nobody has actually seen.
  const hasLoadFailures = useMemo(
    () =>
      projects.some((project) => {
        const resources = resourcesByProjectId.get(project.$id)
        if (!resources) return false
        const projectLimits = limitsForProject(project.$id)
        return DOWNGRADE_RESOURCE_TYPES.some(
          ({ id }) => resources[id].failed && projectLimits[id] !== null,
        )
      }),
    [projects, resourcesByProjectId, limitsForProject],
  )

  const isValid = remainingWithinLimits && !resourcesLoading && !hasLoadFailures

  const blockReason = useMemo(() => {
    if (resourcesLoading) return 'Loading project resources...'
    if (hasLoadFailures) {
      return 'Some project resources could not be loaded. Reload and try again.'
    }
    if (!remainingWithinLimits) {
      return 'Finish deleting project resources that exceed the selected plan.'
    }
    return null
  }, [resourcesLoading, remainingWithinLimits, hasLoadFailures])

  const projectResourceImpacts = useMemo<ProjectResourceImpact[]>(() => {
    return projects.map((project) => {
      const resources = resourcesByProjectId.get(project.$id)
      const impact = resources
        ? countResourcesToDeleteForProject(
            resources,
            limitsForProject(project.$id),
            confirmedSelections[project.$id],
          )
        : {}

      return {
        projectId: project.$id,
        projectName: project.name || project.$id,
        resourceImpact: impact,
      }
    })
  }, [projects, confirmedSelections, resourcesByProjectId, limitsForProject])

  const stagedProjectResourceImpacts = useMemo<ProjectResourceImpact[]>(() => {
    return projects.map((project) => {
      const resources = resourcesByProjectId.get(project.$id)

      return {
        projectId: project.$id,
        projectName: project.name || project.$id,
        resourceImpact: resources
          ? countStagedResourcesForProject(
              resources,
              confirmedSelections[project.$id],
            )
          : {},
      }
    })
  }, [projects, confirmedSelections, resourcesByProjectId])

  const resourceImpact = useMemo(() => {
    return mergeResourceImpacts(
      projectResourceImpacts.map(({ resourceImpact }) => resourceImpact),
    )
  }, [projectResourceImpacts])

  const stagedResourceImpact = useMemo(() => {
    return mergeResourceImpacts(
      stagedProjectResourceImpacts.map(({ resourceImpact }) => resourceImpact),
    )
  }, [stagedProjectResourceImpacts])

  const onImpactChangeRef = useRef(onImpactChange)
  useEffect(() => {
    onImpactChangeRef.current = onImpactChange
  }, [onImpactChange])

  const lastImpactSignatureRef = useRef('')

  useEffect(() => {
    const signature = `${resourcesLoading}:${JSON.stringify(projectResourceImpacts)}:${JSON.stringify(stagedProjectResourceImpacts)}`
    if (lastImpactSignatureRef.current === signature) return
    lastImpactSignatureRef.current = signature
    onImpactChangeRef.current?.({
      impact: resourceImpact,
      stagedImpact: stagedResourceImpact,
      loading: resourcesLoading,
      projectImpacts: projectResourceImpacts,
      stagedProjectImpacts: stagedProjectResourceImpacts,
    })
  }, [
    projectResourceImpacts,
    stagedProjectResourceImpacts,
    resourceImpact,
    stagedResourceImpact,
    resourcesLoading,
  ])

  const confirmSelectedDeletes = useCallback(() => {
    if (!activeProjectId || !activeResourceType) return
    const selected = resourceSelections[activeProjectId]?.[activeResourceType]
    if (!selected || selected.size === 0) return

    setConfirmedSelections((prev) => ({
      ...prev,
      [activeProjectId]: {
        ...(prev[activeProjectId] ?? {}),
        [activeResourceType]: new Set(selected),
      },
    }))
    setConfirmOpen(false)
  }, [activeProjectId, activeResourceType, resourceSelections])

  const editSelectedDeletes = useCallback(() => {
    if (!activeProjectId || !activeResourceType) return
    setConfirmedSelections((prev) => {
      const projectSelection = { ...(prev[activeProjectId] ?? {}) }
      delete projectSelection[activeResourceType]
      return { ...prev, [activeProjectId]: projectSelection }
    })
  }, [activeProjectId, activeResourceType])

  const onRefRef = useRef(onRef)
  const onValidityChangeRef = useRef(onValidityChange)
  const isValidRef = useRef(isValid)
  const confirmedSelectionsRef = useRef(confirmedSelections)
  const resourcesByProjectIdRef = useRef(resourcesByProjectId)
  const projectsRef = useRef(projects)

  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  useEffect(() => {
    onValidityChangeRef.current = onValidityChange
  }, [onValidityChange])

  isValidRef.current = isValid
  confirmedSelectionsRef.current = confirmedSelections
  resourcesByProjectIdRef.current = resourcesByProjectId
  projectsRef.current = projects

  useEffect(() => {
    onRefRef.current({
      isValid: () => isValidRef.current,
      getPendingResourceDeletions: () => {
        const payload: ResourcesToDelete = {}
        const projectResources: DowngradeProjectResourceDeletions[] = []

        for (const [projectId, selections] of Object.entries(
          confirmedSelectionsRef.current,
        )) {
          const resources = resourcesByProjectIdRef.current.get(projectId)
          if (!resources) continue
          const entry = buildResourcesToDelete(resources, selections)
          if (Object.keys(entry).length === 0) continue
          payload[projectId] = entry

          const project = projectsRef.current.find(
            (item) => item.$id === projectId,
          )
          projectResources.push({
            projectId,
            projectName: project?.name
              ? formatProjectNameForDisplay(project.name)
              : projectId,
            types: DOWNGRADE_RESOURCE_TYPES.map(({ id, label }) => ({
              type: id,
              label,
              items: resources[id].items
                .filter((item) => selections[id]?.has(item.$id))
                .map((item) => ({ id: item.$id, name: item.name })),
            })).filter(({ items }) => items.length > 0),
          })
        }

        return { resources: payload, projectResources }
      },
    })

    return () => {
      onRefRef.current(null)
    }
  }, [])

  const lastReportedValidRef = useRef<boolean | null>(null)
  const lastReportedReasonRef = useRef<string | null | undefined>(undefined)

  useEffect(() => {
    if (
      lastReportedValidRef.current === isValid &&
      lastReportedReasonRef.current === blockReason
    ) {
      return
    }
    lastReportedValidRef.current = isValid
    lastReportedReasonRef.current = blockReason
    onValidityChangeRef.current?.(isValid, blockReason)
  }, [isValid, blockReason])

  const toggleResource = (
    projectId: string,
    resourceType: DowngradeResourceType,
    resourceId: string,
  ) => {
    setResourceSelections((prev) => {
      const projectSelection = prev[projectId] ?? {}
      const current = new Set(projectSelection[resourceType] ?? [])

      if (current.has(resourceId)) {
        current.delete(resourceId)
      } else {
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
    ? (resourceSelections[activeProjectId] ?? {})
    : {}

  const activeProjectLimits = useMemo(
    () => (activeProjectId ? limitsForProject(activeProjectId) : null),
    [activeProjectId, limitsForProject],
  )

  const planRelevantResourceTypes = useMemo(
    () =>
      DOWNGRADE_RESOURCE_TYPES.filter(
        ({ id }) => (activeProjectLimits?.[id] ?? null) !== null,
      ),
    [activeProjectLimits],
  )

  const activeTypeConfig = activeResourceType
    ? DOWNGRADE_RESOURCE_TYPES.find((type) => type.id === activeResourceType)
    : undefined
  const activeLimit =
    activeTypeConfig && activeProjectLimits
      ? activeProjectLimits[activeTypeConfig.id]
      : null
  const activeItems = useMemo(
    () =>
      activeResourceType && activeResources
        ? (activeResources[activeResourceType]?.items ?? [])
        : [],
    [activeResourceType, activeResources],
  )
  const activeSelected =
    activeResourceType && activeProjectId
      ? (activeSelections[activeResourceType] ?? new Set<string>())
      : new Set<string>()
  const activeConfirmedSelections = activeProjectId
    ? (confirmedSelections[activeProjectId] ?? {})
    : {}
  const activeConfirmed =
    activeResourceType && activeProjectId
      ? (activeConfirmedSelections[activeResourceType] ?? new Set<string>())
      : new Set<string>()
  const activeConfirmedItems = activeItems.filter((item) =>
    activeConfirmed.has(item.$id),
  )
  const selectionLocked = activeConfirmedItems.length > 0
  const activeSelectedItems = activeItems
    .filter((item) => activeSelected.has(item.$id))
    .map((item) => ({ id: item.$id, label: item.name }))
  const activeViolation = getResourceViolationCount(
    activeItems.length - activeConfirmedItems.length,
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
      (query, index) => projects[index]?.$id === activeProjectId && query.data,
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
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Mark extras to delete in each project. Only selected items are removed after you confirm.',
          )}
        </p>
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
                                  {t('Over limit')}
                                </Badge>
                              </span>
                            </TooltipTrigger>
                            <TooltipContent side="left">
                              {`${issueCount} ${t('over limit')}`}
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
                      const remaining =
                        activeResources[id].total -
                        countStagedResourceDeletions(
                          activeResources,
                          id,
                          activeConfirmedSelections,
                        )
                      const limit = activeProjectLimits?.[id] ?? null
                      const overLimit = limit !== null && remaining > limit
                      const isActive = activeResourceType === id
                      // A failed list call also reads 0; showing it as a count
                      // would claim the project is empty.
                      const failed = activeResources[id].failed

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
                          {failed ? (
                            <Badge
                              variant="warning"
                              className="text-[10px] shrink-0"
                            >
                              {t('Failed')}
                            </Badge>
                          ) : overLimit ? (
                            <Badge
                              variant="error"
                              className="text-[10px] shrink-0"
                            >
                              {remaining}/{limit}
                            </Badge>
                          ) : (
                            <Badge
                              variant="success"
                              className="text-[10px] shrink-0"
                            >
                              {remaining}/{limit}
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
                  {activeTypeConfig
                    ? t(activeTypeConfig.label)
                    : t('Selection')}
                </p>
              </div>

              {showSelectionList &&
              activeItems.length > 0 &&
              !selectionLocked ? (
                <ColumnSearchBar
                  value={selectionSearch}
                  onChange={setSelectionSearch}
                  placeholder={`${t('Search')} ${activeTypeConfig ? t(activeTypeConfig.label).toLowerCase() : t('items')}...`}
                />
              ) : null}

              <div
                className={cn(
                  showSelectionItems || selectionLocked
                    ? paginatedColumnBodyClassName
                    : 'flex items-center justify-center p-8',
                )}
              >
                {selectionLocked && activeTypeConfig ? (
                  <DowngradeConfirmedSelection
                    title={`${activeConfirmedItems.length} ${t(
                      activeTypeConfig.label,
                    ).toLowerCase()} ${t('marked for deletion')}`}
                    labels={activeConfirmedItems.map((item) => item.name)}
                    onEditSelection={editSelectedDeletes}
                  />
                ) : !activeResourceType ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('Select a resource type to review items.')}
                  </p>
                ) : !activeProject || !activeResources ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('Select a project to view resources.')}
                  </p>
                ) : activeResourceType &&
                  activeResources[activeResourceType]?.failed ? (
                  <p className={columnEmptyPlaceholderClassName}>
                    {t('Could not load')}{' '}
                    {activeTypeConfig
                      ? t(activeTypeConfig.label).toLowerCase()
                      : ''}{' '}
                    {t('for this project.')}
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
                          )}
                        >
                          <div className="flex min-w-0 flex-1 items-center gap-3">
                            <Checkbox
                              id={`${activeProject.$id}-${activeResourceType}-${item.$id}`}
                              checked={selected}
                              onCheckedChange={() =>
                                activeResourceType &&
                                toggleResource(
                                  activeProject.$id,
                                  activeResourceType,
                                  item.$id,
                                )
                              }
                              className="shrink-0"
                            />
                            <Label
                              htmlFor={`${activeProject.$id}-${activeResourceType}-${item.$id}`}
                              className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground cursor-pointer"
                            >
                              {item.name}
                            </Label>
                          </div>
                          {selected ? (
                            <Badge
                              variant="error"
                              className="text-[10px] shrink-0"
                            >
                              {t('Marked')}
                            </Badge>
                          ) : null}
                        </div>
                      )
                    })}
                  </PaginatedListSlots>
                )}
              </div>

              {showSelectionList &&
              activeItems.length > 0 &&
              !selectionLocked ? (
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

        {activeProject && activeResourceType ? (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-[13px] text-muted-foreground">
              {activeViolation > 0
                ? `${t('Delete at least')} ${activeViolation} ${t('to fit the selected plan.')}`
                : t('This resource type fits the selected plan.')}
            </p>
            {selectionLocked ? null : (
              <Button
                type="button"
                size="sm"
                className="h-8 text-[13px]"
                disabled={activeSelected.size === 0}
                onClick={() => setConfirmOpen(true)}
              >
                {t('Confirm selection')}
              </Button>
            )}
          </div>
        ) : null}
      </div>

      <ConfirmDowngradeDeletes
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('Delete selected')}
        items={activeSelectedItems}
        confirming={false}
        onConfirm={confirmSelectedDeletes}
      />
    </div>
  )
}
