import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import type { Models, VideoOutput } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Layers } from 'lucide-react'
import { toast } from 'sonner'
import {
  RenditionsSpreadsheet,
  RETRYABLE_RENDITION_STATUSES,
} from '../../_components/RenditionsSpreadsheet'
import { RenditionsEmptyState } from '../../_components/RenditionsEmptyState'
import { SpreadsheetBulkActionsBar } from '../../_components/SpreadsheetBulkActionsBar'
import { VideoPage } from '../../_components/VideoPage'
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
  const [selectedRenditionIds, setSelectedRenditionIds] = useState<Set<string>>(
    new Set(),
  )
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)

  const filterMap = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )

  useEffect(() => {
    setSelectedRenditionIds(new Set())
    setBulkDeleteDialogOpen(false)
  }, [search?.query, search?.sort, projectId, videoId])
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
      columnKey === 'videoBitRate' ||
      columnKey === 'audioBitRate' ||
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

  const bulkDeleteMutation = useMutation({
    mutationFn: async (renditionIds: string[]) => {
      const videos = sdk.forProject(projectId).videos
      await Promise.all(
        renditionIds.map((renditionId) =>
          videos.deleteRendition({ videoId, renditionId }),
        ),
      )
    },
    onSuccess: async (_data, renditionIds) => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
      const count = renditionIds.length
      toast.success(
        count === 1
          ? t('Rendition deleted')
          : `${t('Successfully deleted')} ${count} ${t('renditions')}`,
      )
      setSelectedRenditionIds(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete renditions'))
    },
  })

  const bulkRetryMutation = useMutation({
    mutationFn: async (items: Models.VideoRendition[]) => {
      const videos = sdk.forProject(projectId).videos
      await Promise.all(
        items.map(async (rendition) => {
          await videos.deleteRendition({
            videoId,
            renditionId: rendition.$id,
          })
          await videos.createRendition({
            videoId,
            profileId: rendition.profileId,
            output: rendition.output as VideoOutput,
          })
        }),
      )
    },
    onSuccess: async (_data, items) => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
      toast.success(
        items.length === 1
          ? t('Rendition queued')
          : `${items.length} ${t('renditions queued')}`,
      )
      setSelectedRenditionIds(new Set())
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to retry renditions'))
    },
  })

  const toggleRenditionSelected = (renditionId: string) => {
    setSelectedRenditionIds((prev) => {
      const next = new Set(prev)
      if (next.has(renditionId)) next.delete(renditionId)
      else next.add(renditionId)
      return next
    })
  }

  const toggleAllRenditionsSelected = (selectAll: boolean) => {
    if (!selectAll) {
      setSelectedRenditionIds(new Set())
      return
    }
    setSelectedRenditionIds(new Set(renditions.map((r) => r.$id)))
  }

  const confirmBulkDeleteRenditions = () => {
    if (selectedRenditionIds.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedRenditionIds))
  }

  const selectedRetryableRenditions = useMemo(() => {
    return renditions.filter(
      (rendition) =>
        selectedRenditionIds.has(rendition.$id) &&
        RETRYABLE_RENDITION_STATUSES.has(rendition.status),
    )
  }, [renditions, selectedRenditionIds])

  const handleBulkRetry = () => {
    if (selectedRetryableRenditions.length === 0) return
    bulkRetryMutation.mutate(selectedRetryableRenditions)
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
      hideToolbar={allRenditions.length === 0}
    >
      {allRenditions.length === 0 ? (
        <div className="min-h-0 flex-1 overflow-y-auto px-4 sm:px-6">
          <RenditionsEmptyState
            projectId={projectId}
            onCreate={openCreateRenditions}
            createDisabled={Boolean(writeDisabledReason)}
            createDisabledTooltip={
              writeDisabledReason ? t(writeDisabledReason) : undefined
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
          retryPending={retryMutation.isPending || bulkRetryMutation.isPending}
          onRetry={(rendition) => retryMutation.mutate(rendition)}
          onDelete={setDeleting}
          selectedIds={canWrite ? selectedRenditionIds : undefined}
          onToggleSelected={canWrite ? toggleRenditionSelected : undefined}
          onToggleAllSelected={
            canWrite ? toggleAllRenditionsSelected : undefined
          }
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
      {canWrite ? (
        <SpreadsheetBulkActionsBar
          selectedCount={selectedRenditionIds.size}
          selectionBadge={
            selectedRenditionIds.size === 1
              ? t('1 rendition selected')
              : `${selectedRenditionIds.size} ${t('renditions selected')}`
          }
          onClearSelection={() => setSelectedRenditionIds(new Set())}
          deleteDialogOpen={bulkDeleteDialogOpen}
          onDeleteDialogOpenChange={setBulkDeleteDialogOpen}
          deleteDialogTitle={
            selectedRenditionIds.size === 1
              ? t('Delete rendition')
              : t('Delete renditions')
          }
          deleteDialogDescription={`${t('Are you sure you want to delete')} ${selectedRenditionIds.size} ${
            selectedRenditionIds.size === 1 ? t('rendition') : t('renditions')
          }? ${t('Their segments are removed and players stop receiving these qualities. This action cannot be undone.')}`}
          onConfirmDelete={confirmBulkDeleteRenditions}
          isDeleting={bulkDeleteMutation.isPending}
          extraActions={
            selectedRetryableRenditions.length > 0 ? (
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-xs"
                onClick={handleBulkRetry}
                disabled={
                  bulkRetryMutation.isPending || bulkDeleteMutation.isPending
                }
              >
                {t('Retry')}
              </Button>
            ) : null
          }
        />
      ) : null}
    </VideoPage>
  )
}
