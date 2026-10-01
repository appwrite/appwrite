import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Link,
  useLocation,
  useNavigate,
  useParams,
  useSearch,
} from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Film } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
} from '../shared/ResourceCard'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { Pagination } from '@/components/global/shared/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { sdk } from '@/lib/appwrite/sdk'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  Dependencies,
  useOrganizationScopes,
  useProject,
  useProjectVideos,
  VIDEOS_DEFAULT_SORT_BY,
  VIDEOS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  buildListSearchParams,
  encodeSort,
  getLimit,
  getPage,
  getQueryParam,
  getSearch,
  getSort,
  mapToQueryParam,
  MIN_SEARCH_LENGTH,
  parseSort,
  queryParamToMap,
  urlFromRouterLocation,
  videosFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { CreateVideo } from './_components/CreateVideo'
import { VideoContextMenu } from './_components/VideoContextMenu'
import { VideosListTable } from './_components/VideosListTable'
import { VideoThumb } from './_components/VideoThumb'

export function getVideosServiceTabs(projectId: string): Tab[] {
  return [
    {
      id: 'videos',
      label: 'Videos',
      to: '/projects/$projectId/videos/',
      params: { projectId },
    },
    {
      id: 'profiles',
      label: 'Profiles',
      to: '/projects/$projectId/videos/profiles',
      params: { projectId },
    },
  ]
}

type VideosListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

const isDefaultSort = (sortBy: string, sortOrder: 'asc' | 'desc') =>
  sortBy === VIDEOS_DEFAULT_SORT_BY && sortOrder === VIDEOS_DEFAULT_SORT_ORDER

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const search = useSearch({ strict: false })

  const listParams = useMemo(() => {
    if (typeof search !== 'object') return null
    const url = urlFromRouterLocation(location, window.location.origin)
    const parsed = parseSort(search.sort as string | undefined) ??
      getSort(url) ?? {
        sortBy: VIDEOS_DEFAULT_SORT_BY,
        sortOrder: VIDEOS_DEFAULT_SORT_ORDER,
      }
    const pageFromSearch = search.page != null ? Number(search.page) : undefined
    const limitFromSearch =
      search.limit != null ? Number(search.limit) : undefined
    return {
      search: getSearch(url) ?? (search.search as string | undefined),
      page:
        Number.isInteger(pageFromSearch) && (pageFromSearch ?? 0) >= 1
          ? pageFromSearch!
          : getPage(url, 1),
      limit:
        Number.isInteger(limitFromSearch) && (limitFromSearch ?? 0) >= 1
          ? limitFromSearch!
          : getLimit(url, GRID_DEFAULT_PAGE_SIZE),
      filterMap: queryParamToMap(
        getQueryParam(url) ?? (search.query as string | undefined) ?? null,
      ),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [search, location.pathname, location.search, projectId])

  const urlPage = listParams?.page ?? 1
  const urlLimit = listParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = listParams?.search
  const urlSortBy = listParams?.sortBy ?? VIDEOS_DEFAULT_SORT_BY
  const urlSortOrder = listParams?.sortOrder ?? VIDEOS_DEFAULT_SORT_ORDER
  const filterMap = listParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''
  const encodedSort = isDefaultSort(urlSortBy, urlSortOrder)
    ? undefined
    : encodeSort(urlSortBy, urlSortOrder)

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayed, setDisplayed] = useState<{
    page: number
    search?: string
    sortBy: string
    sortOrder: 'asc' | 'desc'
    filterQueryString: string
  } | null>(null)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [selectedVideos, setSelectedVideos] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const { viewMode, setViewMode } = useServiceListViewMode('videos')

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    setRequestedPage(urlPage)
  }, [urlPage])

  useEffect(() => {
    if (displayed || !listParams) return
    setDisplayed({
      page: urlPage,
      search: urlSearch ?? undefined,
      sortBy: urlSortBy,
      sortOrder: urlSortOrder,
      filterQueryString,
    })
  }, [
    displayed,
    listParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  const displayedFilterQueries = useMemo(() => {
    const map = queryParamToMap(displayed?.filterQueryString || null)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayed?.filterQueryString])

  const navigateToList = (params: VideosListSearch) => {
    navigate({
      to: '/projects/$projectId/videos',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: 'search' in params ? params.search : (urlSearch ?? undefined),
          query:
            'query' in params ? params.query : filterQueryString || undefined,
          page: params.page ?? 1,
          limit: params.limit ?? urlLimit,
          sort: 'sort' in params ? params.sort : encodedSort,
        })
        const next = { ...prev, ...built }
        if (params.page === 1) delete next.page
        if ('query' in params && params.query === undefined) delete next.query
        if ('search' in params && !params.search) delete next.search
        if ('sort' in params && params.sort === undefined) delete next.sort
        return next
      },
      replace: true,
    })
  }

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigateToList({ search: trimmed || undefined, page: 1 })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput, urlSearch])

  // Requested page: drives the fetch for what the user asked for.
  const requested = useProjectVideos(
    projectId,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )
  // Displayed page: what is on screen until the requested page has loaded.
  const shown = useProjectVideos(
    projectId,
    (displayed?.page ?? urlPage) - 1,
    urlLimit,
    displayed ? displayed.search : (urlSearch ?? undefined),
    displayed ? displayedFilterQueries : filterQueries,
    displayed?.sortBy ?? urlSortBy,
    displayed?.sortOrder ?? urlSortOrder,
  )

  useEffect(() => {
    if (requested.isFetching || requested.isLoading || !requested.isFetched)
      return
    const next = {
      page: urlPage,
      search: urlSearch ?? undefined,
      sortBy: urlSortBy,
      sortOrder: urlSortOrder,
      filterQueryString,
    }
    if (
      displayed &&
      displayed.page === next.page &&
      (displayed.search ?? '') === (next.search ?? '') &&
      displayed.sortBy === next.sortBy &&
      displayed.sortOrder === next.sortOrder &&
      displayed.filterQueryString === next.filterQueryString
    ) {
      return
    }
    setDisplayed(next)
  }, [
    requested.isFetching,
    requested.isLoading,
    requested.isFetched,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
    displayed,
  ])

  const videos = shown.videos
  const displayedTotal = shown.total

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateVideo(access, features)

  useEffect(() => {
    setSelectedVideos(new Set())
    setDeleteDialogOpen(false)
  }, [projectId, urlSearch, filterQueryString])

  const bulkDeleteMutation = useMutation({
    mutationFn: async (videoIds: string[]) => {
      const projectVideos = sdk.forProject(projectId!).videos
      await Promise.all(
        videoIds.map((videoId) => projectVideos.delete({ videoId })),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
      toast.success(
        selectedVideos.size === 1
          ? t('Video deleted')
          : `${t('Successfully deleted')} ${selectedVideos.size} ${t('videos')}`,
      )
      setSelectedVideos(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete videos'))
    },
  })

  const toggleVideo = (videoId: string) => {
    const next = new Set(selectedVideos)
    if (next.has(videoId)) next.delete(videoId)
    else next.add(videoId)
    setSelectedVideos(next)
  }

  const toggleAll = () => {
    setSelectedVideos(
      selectedVideos.size === videos.length
        ? new Set()
        : new Set(videos.map((video) => video.$id)),
    )
  }

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(filterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(compactKey, queryStr)
    navigateToList({ query: mapToQueryParam(next) || undefined, page: 1 })
  }

  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(compactKey)
    navigateToList({
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
    })
  }

  const tabs: Tab[] = useMemo(
    () =>
      getVideosServiceTabs(projectId as string).map((tab) => ({
        ...tab,
        label: t(tab.label),
      })),
    [projectId, t],
  )

  const hasFilters = (urlSearch?.length ?? 0) > 0 || filterMap.size > 0
  const filtersMatch =
    (displayed?.search ?? '') === (urlSearch ?? '') &&
    (displayed?.filterQueryString ?? '') === filterQueryString
  const showLoading = shown.isLoading && videos.length === 0
  const noSearchResults =
    hasFilters && filtersMatch && displayedTotal === 0 && !shown.isLoading

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Videos')}
        tabs={tabs}
        activeTab="videos"
        searchPlaceholder={t('Search videos...')}
        searchValue={searchInput}
        onSearchChange={(value) => {
          setSearchInput(value)
          setSelectedVideos(new Set())
        }}
        createLabel={t('Create video')}
        createAnalyticsAction="create-video"
        onCreate={() => setCreateOpen(true)}
        createDisabled={noCreatePermission}
        createDisabledTooltip={
          noCreatePermission
            ? t("You don't have permission to create videos.")
            : undefined
        }
        fullWidthBorder
        showFilters
        rightContent={
          <ServiceListViewToggle
            viewMode={viewMode}
            onViewModeChange={setViewMode}
          />
        }
        filterTrigger={
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={videosFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={() => {
              navigateToList({ query: undefined, page: 1 })
              setFiltersOpen(false)
            }}
            onApplyFilter={applyFilter}
            resourceLabel="videos"
            filterScope="videos"
            onApplyQuery={(queryParam, sortParam) =>
              navigateToList({
                query: queryParam ?? undefined,
                page: 1,
                sort: sortParam ?? undefined,
              })
            }
            sortBy={urlSortBy}
            sortOrder={urlSortOrder}
            onSortChange={(sortBy, sortOrder) =>
              navigateToList({
                page: 1,
                sort: isDefaultSort(sortBy, sortOrder)
                  ? undefined
                  : encodeSort(sortBy, sortOrder),
              })
            }
            defaultSortParam={encodeSort(
              VIDEOS_DEFAULT_SORT_BY,
              VIDEOS_DEFAULT_SORT_ORDER,
            )}
            onReset={() =>
              navigate({
                to: '/projects/$projectId/videos',
                params: { projectId: projectId! },
                search: { page: 1, limit: urlLimit },
                replace: true,
              })
            }
            teamId={project?.teamId}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {shown.error && videos.length === 0 ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {getErrorMessage(shown.error) ||
                t('Failed to load videos. Please try again.')}
            </p>
          </div>
        ) : showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading videos...')}
            </p>
          </div>
        ) : noSearchResults ? (
          <EmptyState
            icon={Film}
            isEmpty={false}
            hasFilters={hasFilters}
            variant="card"
          />
        ) : displayedTotal === 0 ? (
          <EmptyState
            icon={Film}
            title={t('No videos yet')}
            description={t(
              'Create a video from a Storage file to encode adaptive HLS, DASH, and CMAF streams.',
            )}
            isEmpty
            hasFilters={false}
            variant="card"
          />
        ) : (
          <>
            {viewMode === 'list' ? (
              <VideosListTable
                projectId={projectId!}
                videos={videos}
                selectedVideoIds={selectedVideos}
                onToggleVideo={toggleVideo}
                onToggleAll={toggleAll}
              />
            ) : (
              <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                {videos.map((video) => (
                  <VideoCard
                    key={video.$id}
                    projectId={projectId!}
                    video={video}
                  />
                ))}
              </div>
            )}
            <Pagination
              currentPage={displayed?.page ?? urlPage}
              totalItems={displayedTotal ?? requested.total}
              pageSize={urlLimit}
              pageSizeOptions={[12, 18, 36, 72]}
              onPageChange={(page) => {
                setRequestedPage(page)
                setSelectedVideos(new Set())
                navigateToList({ page })
              }}
              onPageSizeChange={(size) => {
                setRequestedPage(1)
                setSelectedVideos(new Set())
                navigateToList({ page: 1, limit: size })
              }}
              itemLabel={t('videos')}
            />
          </>
        )}

        {selectedVideos.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedVideos.size}{' '}
                {selectedVideos.size > 1 ? t('videos') : t('video')}{' '}
                {t('selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedVideos(new Set())}
                  className="h-8 text-xs"
                >
                  {t('Cancel')}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={() => setDeleteDialogOpen(true)}
                  disabled={bulkDeleteMutation.isPending || noCreatePermission}
                  className="h-8"
                >
                  {t('Delete')}
                </Button>
              </div>
            </div>
          </div>
        )}

        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Delete videos')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {selectedVideos.size}{' '}
                {selectedVideos.size > 1 ? t('videos') : t('video')}?{' '}
                {t('This action cannot be undone.')}
              </DialogDescription>
            </DialogHeader>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={() =>
                  bulkDeleteMutation.mutate(Array.from(selectedVideos))
                }
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {projectId ? (
        <CreateVideo
          open={createOpen}
          onOpenChange={setCreateOpen}
          projectId={projectId}
        />
      ) : null}
    </div>
  )
}

function VideoCard({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  return (
    <VideoContextMenu
      projectId={projectId}
      video={{ $id: video.$id, name: video.name }}
    >
      <Link
        to="/projects/$projectId/videos/$videoId"
        params={{ projectId, videoId: video.$id }}
        className="block min-w-0"
      >
        <div className={cn(RESOURCE_CARD_MEDIA_SHELL_CLASSNAME, 'h-auto')}>
          <div className="p-1.5 pb-0">
            <VideoThumb
              projectId={projectId}
              video={video}
              className="aspect-video w-full rounded-md border border-border/60"
              showDuration
            />
          </div>
          <div className="px-4 pt-4 pb-0">
            <h3 className="truncate text-[14px] font-medium text-foreground">
              {video.name || t('Untitled video')}
            </h3>
            <div className="mt-1.5">
              <CopyableId id={video.$id} size="xs" maxWidth={120} />
            </div>
            <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
              <div className="flex min-w-0 items-center gap-x-1.5">
                {video.width > 0 ? (
                  <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
                    {formatResolution(video.width, video.height)}
                  </span>
                ) : null}
                <span className="ms-auto shrink-0 text-[12px] text-muted-foreground">
                  <DateTooltip
                    date={video.$createdAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </span>
              </div>
            </div>
          </div>
        </div>
      </Link>
    </VideoContextMenu>
  )
}
