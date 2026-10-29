import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Boxes, Globe, Package, Plus, Search, X } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import type { HandoffResourceKind } from '@/components/pages/partners/$partnerId/_components/HandoffResource'
import { useT } from '@/lib/i18n/translate'
import {
  HANDOFF_RESOURCES_PAGE_SIZE,
  activeProjectsQueryOptions,
  formatProjectNameForDisplay,
  organizationAppsQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

export type HandoffSelection = {
  kind: HandoffResourceKind
  id: string
  label: string
}

const KINDS: HandoffResourceKind[] = ['project', 'domain', 'app']

const KIND_LABEL: Record<HandoffResourceKind, string> = {
  project: 'Projects',
  domain: 'Domains',
  app: 'Apps',
}

const KIND_SEARCH_PLACEHOLDER: Record<HandoffResourceKind, string> = {
  project: 'Search projects...',
  domain: 'Search domains...',
  app: 'Search apps...',
}

const KIND_EMPTY_TITLE: Record<HandoffResourceKind, string> = {
  project: 'No projects yet',
  domain: 'No domains yet',
  app: 'No apps yet',
}

const KIND_EMPTY_DESCRIPTION: Record<HandoffResourceKind, string> = {
  project: 'No development projects to hand off yet.',
  domain: 'No development domains to hand off yet.',
  app: 'No development apps to hand off yet.',
}

const KIND_ITEM_LABEL: Record<HandoffResourceKind, string> = {
  project: 'projects',
  domain: 'domains',
  app: 'apps',
}

const KIND_SINGULAR: Record<HandoffResourceKind, string> = {
  project: 'Project',
  domain: 'Domain',
  app: 'App',
}

const KIND_ICON = {
  project: Boxes,
  domain: Globe,
  app: Package,
} as const

export const HANDOFF_PAGE_SIZE_OPTIONS = [5, 10, 25]

/** Viewport for 5 compact rows (`h-9` + `gap-1`) so empty types keep the same height. */
const HANDOFF_LIST_VIEWPORT_CLASSNAME = 'h-[12.25rem]'

export function handoffSelectionKey(item: Pick<HandoffSelection, 'kind' | 'id'>) {
  return `${item.kind}:${item.id}`
}

export function HandoffResourcePicker({
  partnerId,
  selected,
  onSelectedChange,
  disabled = false,
}: {
  partnerId: string
  selected: HandoffSelection[]
  onSelectedChange: (next: HandoffSelection[]) => void
  disabled?: boolean
}) {
  const t = useT()
  const [kind, setKind] = useState<HandoffResourceKind>('project')
  const [searchValue, setSearchValue] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [pageSize, setPageSize] = useState(HANDOFF_RESOURCES_PAGE_SIZE)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(searchValue.trim()), 300)
    return () => clearTimeout(timer)
  }, [searchValue])

  const resetListToFirstPage = () => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  const handleKindChange = (nextKind: string) => {
    if (!KINDS.includes(nextKind as HandoffResourceKind)) return
    setKind(nextKind as HandoffResourceKind)
    setSearchValue('')
    setDebouncedSearch('')
    resetListToFirstPage()
  }

  const search = debouncedSearch || undefined
  const searchActive = Boolean(search)

  const projectsQuery = useQuery({
    ...activeProjectsQueryOptions(
      partnerId,
      requestedPage - 1,
      pageSize,
      search ?? '',
    ),
    enabled: !!partnerId && kind === 'project',
  })
  const displayedProjectsQuery = useQuery({
    ...activeProjectsQueryOptions(
      partnerId,
      displayedPage - 1,
      pageSize,
      search ?? '',
    ),
    enabled: !!partnerId && kind === 'project',
  })
  const domainsQuery = useQuery({
    ...organizationDomainsQueryOptions(
      partnerId,
      requestedPage - 1,
      pageSize,
      search,
    ),
    enabled: !!partnerId && kind === 'domain',
  })
  const displayedDomainsQuery = useQuery({
    ...organizationDomainsQueryOptions(
      partnerId,
      displayedPage - 1,
      pageSize,
      search,
    ),
    enabled: !!partnerId && kind === 'domain',
  })
  const appsQuery = useQuery({
    ...organizationAppsQueryOptions(
      partnerId,
      search,
      undefined,
      requestedPage - 1,
      pageSize,
    ),
    enabled: !!partnerId && kind === 'app',
  })
  const displayedAppsQuery = useQuery({
    ...organizationAppsQueryOptions(
      partnerId,
      search,
      undefined,
      displayedPage - 1,
      pageSize,
    ),
    enabled: !!partnerId && kind === 'app',
  })

  const requestedQuery =
    kind === 'project'
      ? projectsQuery
      : kind === 'domain'
        ? domainsQuery
        : appsQuery

  useEffect(() => {
    if (!requestedQuery.isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedQuery.isFetching, requestedPage, displayedPage])

  const items = useMemo(() => {
    if (kind === 'project') {
      return (displayedProjectsQuery.data?.projects ?? []).map((project) => ({
        id: project.$id,
        label: formatProjectNameForDisplay(project.name) || project.$id,
        meta: project.region || undefined,
      }))
    }
    if (kind === 'domain') {
      return (displayedDomainsQuery.data?.domains ?? []).map((domain) => ({
        id: domain.$id,
        label: domain.domain || domain.$id,
        meta: undefined as string | undefined,
      }))
    }
    return (displayedAppsQuery.data?.apps ?? []).map((app) => ({
      id: app.$id,
      label: app.name || app.$id,
      meta: undefined as string | undefined,
    }))
  }, [
    kind,
    displayedProjectsQuery.data,
    displayedDomainsQuery.data,
    displayedAppsQuery.data,
  ])

  const total =
    kind === 'project'
      ? (displayedProjectsQuery.data?.total ?? 0)
      : kind === 'domain'
        ? (displayedDomainsQuery.data?.total ?? 0)
        : (displayedAppsQuery.data?.total ?? 0)

  const isLoading =
    (kind === 'project'
      ? displayedProjectsQuery.isLoading
      : kind === 'domain'
        ? displayedDomainsQuery.isLoading
        : displayedAppsQuery.isLoading) && items.length === 0

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(total / pageSize))
    if (total > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [total, pageSize, requestedPage])

  const selectedKeys = useMemo(
    () => new Set(selected.map(handoffSelectionKey)),
    [selected],
  )
  const KindIcon = KIND_ICON[kind]

  const addItem = (item: HandoffSelection) => {
    if (selectedKeys.has(handoffSelectionKey(item))) return
    onSelectedChange([...selected, item])
  }

  const removeItem = (item: Pick<HandoffSelection, 'kind' | 'id'>) => {
    const key = handoffSelectionKey(item)
    onSelectedChange(selected.filter((current) => handoffSelectionKey(current) !== key))
  }

  const toggleItem = (item: HandoffSelection) => {
    if (selectedKeys.has(handoffSelectionKey(item))) {
      removeItem(item)
      return
    }
    addItem(item)
  }

  return (
    <div className="space-y-4">
      {selected.length > 0 ? (
        <div className="flex flex-wrap gap-1.5">
          {selected.map((item) => {
            const Icon = KIND_ICON[item.kind]
            return (
              <span
                key={handoffSelectionKey(item)}
                className="flex max-w-full items-center gap-1.5 rounded-md bg-muted py-1 pe-1 ps-2 text-[12px]"
              >
                <Icon className="h-3 w-3 shrink-0 text-muted-foreground" />
                <span className="text-muted-foreground">{t(KIND_SINGULAR[item.kind])}</span>
                <span className="min-w-0 truncate font-medium text-foreground">
                  {item.label}
                </span>
                <button
                  type="button"
                  aria-label={`${t('Remove')} ${item.label}`}
                  disabled={disabled}
                  onClick={() => removeItem(item)}
                  className="cursor-pointer text-muted-foreground hover:text-foreground disabled:opacity-50"
                >
                  <X className="size-3" />
                </button>
              </span>
            )
          })}
        </div>
      ) : null}

      <Tabs value={kind} onValueChange={handleKindChange}>
        <TabsList className="grid h-9 w-full grid-cols-3">
          {KINDS.map((item) => (
            <TabsTrigger
              key={item}
              value={item}
              disabled={disabled}
              className="text-[13px]"
            >
              {t(KIND_LABEL[item])}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="relative">
        <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          value={searchValue}
          onChange={(event) => {
            setSearchValue(event.target.value)
            resetListToFirstPage()
          }}
          placeholder={t(KIND_SEARCH_PLACEHOLDER[kind])}
          disabled={disabled}
          className="h-9 ps-9 text-[13px]"
        />
      </div>

      <div className={cn(HANDOFF_LIST_VIEWPORT_CLASSNAME, 'min-h-0')}>
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <p className="text-[13px] text-muted-foreground">{t('Loading...')}</p>
          </div>
        ) : items.length === 0 ? (
          <div className="flex h-full items-center justify-center">
            <EmptyState
              icon={KindIcon}
              title={searchActive ? undefined : KIND_EMPTY_TITLE[kind]}
              description={
                searchActive ? undefined : KIND_EMPTY_DESCRIPTION[kind]
              }
              isEmpty={!searchActive}
              hasFilters={searchActive}
              iconSize="sm"
            />
          </div>
        ) : (
          <div className="flex h-full flex-col gap-1 overflow-y-auto">
            {items.map((item) => {
              const selection: HandoffSelection = {
                kind,
                id: item.id,
                label: item.label,
              }
              const isSelected = selectedKeys.has(handoffSelectionKey(selection))
              return (
                <button
                  key={item.id}
                  type="button"
                  disabled={disabled}
                  onClick={() => toggleItem(selection)}
                  className={cn(
                    'flex h-9 w-full shrink-0 items-center gap-2 rounded-md border px-2.5 text-start transition-colors',
                    isSelected
                      ? 'border-primary bg-primary/5'
                      : 'border-border bg-background/60 hover:border-primary/30',
                    disabled && 'cursor-not-allowed opacity-50',
                  )}
                >
                  <KindIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span
                    className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground"
                    title={item.label}
                  >
                    {item.label}
                  </span>
                  {item.meta ? (
                    <span className="max-w-[7rem] shrink-0 truncate text-[12px] text-muted-foreground">
                      {item.meta}
                    </span>
                  ) : null}
                  {isSelected ? (
                    <X className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  ) : (
                    <Plus className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  )}
                </button>
              )
            })}
          </div>
        )}
      </div>

      <Pagination
        currentPage={displayedPage}
        totalItems={total}
        pageSize={pageSize}
        pageSizeOptions={HANDOFF_PAGE_SIZE_OPTIONS}
        onPageChange={setRequestedPage}
        onPageSizeChange={(nextSize) => {
          setPageSize(nextSize)
          resetListToFirstPage()
        }}
        itemLabel={KIND_ITEM_LABEL[kind]}
        scrollToTopOnPageChange={false}
        className="mt-0 min-h-8 py-0"
      />
    </div>
  )
}
