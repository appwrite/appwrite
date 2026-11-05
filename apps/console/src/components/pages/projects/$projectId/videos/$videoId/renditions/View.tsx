import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import type { Models, VideoOutput } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Layers } from 'lucide-react'
import { toast } from 'sonner'
import { RenditionsSpreadsheet } from '../../_components/RenditionsSpreadsheet'
import { VideoActionButton, VideoPage } from '../../_components/VideoPage'
import { useVideoDetailActions } from '../../_components/video-detail-actions'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useDeleteVideoRendition,
  useProject,
  useVideoProfiles,
  useVideoRenditions,
  videoKeys,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  encodeSort,
  filterRecordsByCompactMap,
  mapToQueryParam,
  parseSort,
  queryParamToMap,
  videoRenditionsFilterColumns,
  type CompactFilterKey,
} from '@/lib/table-filters'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  compareVideoRenditions,
  renditionToFilterRecord,
  RENDITIONS_DEFAULT_SORT_BY,
  RENDITIONS_DEFAULT_SORT_ORDER,
  type RenditionSortColumn,
} from '@/lib/videos/rendition-list-filters'

type ViewProps = {
  initialData?: { renditions: Models.VideoRenditionList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const search = useSearch({ strict: false }) as {
    query?: string
    sort?: string
  }
  const parsedSort = parseSort(search?.sort)
  const sortBy = parsedSort?.sortBy ?? RENDITIONS_DEFAULT_SORT_BY
  const sortOrder = parsedSort?.sortOrder ?? RENDITIONS_DEFAULT_SORT_ORDER
  const { project } = useProject(projectId)
  const { openCreateRenditions, canWrite, writeDisabledReason } =
    useVideoDetailActions()
  const { data } = useVideoRenditions(projectId, videoId)
  const { data: profilesData } = useVideoProfiles(projectId)
  const deleteMutation = useDeleteVideoRendition(projectId, videoId)
  const [deleting, setDeleting] = useState<Models.VideoRendition | null>(null)
  const [filtersOpen, setFiltersOpen] = useState(false)

  const filterMap = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const profileNames = useMemo(() => {
    const map = new Map<string, string>()
    for (const profile of profilesData?.profiles ?? []) {
      map.set(profile.$id, profile.name)
    }
    return map
  }, [profilesData?.profiles])

  const allRenditions = useMemo(
    () => [...(data?.renditions ?? initialData?.renditions.renditions ?? [])],
    [data?.renditions, initialData?.renditions.renditions],
  )

  const renditions = useMemo(() => {
    const records = allRenditions.map((rendition) =>
      renditionToFilterRecord(rendition, profileNames.get(rendition.profileId)),
    )
    const filtered = filterRecordsByCompactMap(records, filterMap)
    const ids = new Set(filtered.map((row) => row.$id))
    const list = allRenditions.filter((rendition) => ids.has(rendition.$id))
    return [...list].sort((a, b) =>
      compareVideoRenditions(a, b, sortBy, sortOrder, profileNames),
    )
  }, [allRenditions, filterMap, profileNames, sortBy, sortOrder])

  const buildRenditionsRouteSearch = (
    overrides: { query?: string | null; sort?: string | null } = {},
  ) => {
    const searchParams: { query?: string; sort?: string } = {}
    const query =
      overrides.query === null ? undefined : (overrides.query ?? search?.query)
    const sort =
      overrides.sort === null ? undefined : (overrides.sort ?? search?.sort)
    if (query) searchParams.query = query
    if (sort) searchParams.sort = sort
    return searchParams
  }

  const navigateRenditionsList = (
    overrides: { query?: string | null; sort?: string | null } = {},
  ) => {
    navigate({
      to: '/projects/$projectId/videos/$videoId/renditions',
      params: { projectId, videoId },
      search: buildRenditionsRouteSearch(overrides),
      replace: true,
    })
  }

  const navigateWithQuery = (query?: string) => {
    navigateRenditionsList({ query: query ?? null })
  }

  const handleSortColumn = (columnKey: RenditionSortColumn) => {
    let nextOrder: 'asc' | 'desc' = 'asc'
    if (sortBy === columnKey) {
      nextOrder = sortOrder === 'asc' ? 'desc' : 'asc'
    } else if (
      columnKey === 'resolution' ||
      columnKey === 'bitrate' ||
      columnKey === 'encodingTime' ||
      columnKey === 'created'
    ) {
      nextOrder = 'desc'
    }
    const isDefaultSort =
      columnKey === RENDITIONS_DEFAULT_SORT_BY &&
      nextOrder === RENDITIONS_DEFAULT_SORT_ORDER
    navigateRenditionsList({
      sort: isDefaultSort ? null : encodeSort(columnKey, nextOrder),
    })
  }

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(filterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(compactKey, queryStr)
    navigateWithQuery(mapToQueryParam(next) || undefined)
  }

  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(compactKey)
    navigateWithQuery(next.size > 0 ? mapToQueryParam(next) : undefined)
  }

  const clearAllFilters = () => navigateWithQuery(undefined)

  const retryMutation = useMutation({
    mutationFn: async (rendition: Models.VideoRendition) => {
      const videos = sdk.forProject(projectId).videos
      await videos.deleteRendition({ videoId, renditionId: rendition.$id })
      return await videos.createRendition({
        videoId,
        profileId: rendition.profileId,
        output: rendition.output as VideoOutput,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
      toast.success(t('Rendition queued'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to retry rendition'))
    },
  })

  const confirmDelete = () => {
    if (!deleting) return
    deleteMutation.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Rendition deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete rendition'))
      },
    })
  }

  const filterToolbar =
    allRenditions.length > 0 ? (
      <FiltersPopover
        open={filtersOpen}
        onOpenChange={setFiltersOpen}
        columns={videoRenditionsFilterColumns}
        filterMap={filterMap}
        onRemoveFilter={removeFilter}
        onClearAll={clearAllFilters}
        onApplyFilter={applyFilter}
        resourceLabel={t('renditions')}
        filterScope={`videos.renditions.${videoId}`}
        onApplyQuery={(queryParam) =>
          navigateWithQuery(queryParam ?? undefined)
        }
        teamId={project?.teamId}
      />
    ) : undefined

  return (
    <VideoPage
      title={t('Renditions')}
      term="rendition"
      spreadsheetContent
      toolbar={filterToolbar}
      createLabel={t('Create renditions')}
      onCreate={openCreateRenditions}
      createDisabled={Boolean(writeDisabledReason)}
      createDisabledTooltip={writeDisabledReason ?? undefined}
    >
      {allRenditions.length === 0 ? (
        <div className="flex flex-1 items-center justify-center px-6 py-12">
          <EmptyState
            icon={Layers}
            title={t('No renditions')}
            description={t(
              'Encode this video into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.',
            )}
            isEmpty
            variant="centered"
            iconSize="md"
            action={
              <VideoActionButton
                size="sm"
                className="h-9 text-[13px]"
                onClick={openCreateRenditions}
                disabledReason={writeDisabledReason}
              >
                {t('Create renditions')}
              </VideoActionButton>
            }
          />
        </div>
      ) : renditions.length === 0 ? (
        <div className="flex flex-1 items-center justify-center px-6 py-12">
          <EmptyState
            icon={Layers}
            title={t('No renditions match your filters')}
            description={t(
              'Try adjusting or clearing filters to see more results',
            )}
            hasFilters
            variant="centered"
            iconSize="md"
            action={
              <Button variant="outline" size="sm" onClick={clearAllFilters}>
                {t('Clear filters')}
              </Button>
            }
          />
        </div>
      ) : (
        <RenditionsSpreadsheet
          className="min-h-0 flex-1"
          projectId={projectId}
          videoId={videoId}
          renditions={renditions}
          profileNames={profileNames}
          canWrite={canWrite}
          retryPending={retryMutation.isPending}
          onRetry={(rendition) => retryMutation.mutate(rendition)}
          onDelete={setDeleting}
          sortBy={sortBy}
          sortOrder={sortOrder}
          onSortColumn={handleSortColumn}
        />
      )}

      <ConfirmActionDialog
        open={!!deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        title={t('Delete rendition')}
        description={`${t('Delete')} "${deleting?.name ?? ''}"? ${t('Its segments are removed and players stop receiving this quality. This action cannot be undone.')}`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </VideoPage>
  )
}
