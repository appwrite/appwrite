import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { AlertCircle, Layers } from 'lucide-react'
import { toast } from 'sonner'
import { ProfilesEmptyState } from './_components/ProfilesEmptyState'
import { CreateProfile } from '../_components/CreateProfile'
import { ProfilesListTable } from '../_components/ProfilesListTable'
import { SpreadsheetBulkActionsBar } from '../_components/SpreadsheetBulkActionsBar'
import { getProjectProfilesApi } from '@/lib/videos/project-profiles-api'
import { getVideosServiceTabs } from '../_components/videos-service-tabs'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { Button } from '@/components/ui/button'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  useDeleteVideoProfile,
  useOrganizationScopes,
  useProject,
  useVideoProfiles,
  videoKeys,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  encodeSort,
  filterRecordsByCompactMap,
  mapToQueryParam,
  MIN_SEARCH_LENGTH,
  parseSort,
  queryParamToMap,
  videoProfilesFilterColumns,
  type CompactFilterKey,
} from '@/lib/table-filters'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import {
  compareVideoProfiles,
  profileToFilterRecord,
  PROFILES_DEFAULT_SORT_BY,
  PROFILES_DEFAULT_SORT_ORDER,
} from '@/lib/videos/profile-list-filters'

const isDefaultSort = (sortBy: string, sortOrder: 'asc' | 'desc') =>
  sortBy === PROFILES_DEFAULT_SORT_BY &&
  sortOrder === PROFILES_DEFAULT_SORT_ORDER

export function View() {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const routeSearch = useSearch({ strict: false }) as {
    search?: string
    query?: string
    sort?: string
  }
  const urlSearch = routeSearch.search?.trim() || undefined
  const filterMap = useMemo(
    () => queryParamToMap(routeSearch.query ?? null),
    [routeSearch.query],
  )
  const parsedSort = parseSort(routeSearch.sort)
  const sortBy = parsedSort?.sortBy ?? PROFILES_DEFAULT_SORT_BY
  const sortOrder = parsedSort?.sortOrder ?? PROFILES_DEFAULT_SORT_ORDER

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingProfile, setEditingProfile] =
    useState<Models.VideoProfile | null>(null)
  const [deletingProfile, setDeletingProfile] =
    useState<Models.VideoProfile | null>(null)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [selectedProfileIds, setSelectedProfileIds] = useState<Set<string>>(
    new Set(),
  )
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)

  const { data, isLoading, isFetching, error, refetch } =
    useVideoProfiles(projectId)
  const apiError = error ? getAppwriteErrorInfo(error) : null
  const deleteMutation = useDeleteVideoProfile(projectId)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateVideo(access, features)
  const createPermissionTooltip = canWrite
    ? undefined
    : t("You don't have permission to manage video profiles.")

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    setSelectedProfileIds(new Set())
    setBulkDeleteDialogOpen(false)
  }, [urlSearch, routeSearch.query, routeSearch.sort, projectId])

  const bulkDeleteMutation = useMutation({
    mutationFn: async (profileIds: string[]) => {
      const projectApi = getProjectProfilesApi(projectId)
      await Promise.all(
        profileIds.map((profileId) => projectApi.deleteProfile({ profileId })),
      )
    },
    onSuccess: async (_data, profileIds) => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.profiles(projectId),
      })
      const count = profileIds.length
      toast.success(
        count === 1
          ? t('Profile deleted')
          : `${t('Successfully deleted')} ${count} ${t('profiles')}`,
      )
      setSelectedProfileIds(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (err) => {
      toast.error(getErrorMessage(err) || t('Failed to delete profiles'))
    },
  })

  const buildProfilesRouteSearch = (
    overrides: {
      search?: string | null
      query?: string | null
      sort?: string | null
    } = {},
  ) => {
    const searchParams: {
      search?: string
      query?: string
      sort?: string
    } = {}
    const search =
      overrides.search === null ? undefined : (overrides.search ?? urlSearch)
    const query =
      overrides.query === null
        ? undefined
        : (overrides.query ?? routeSearch.query)
    const sort =
      overrides.sort === null ? undefined : (overrides.sort ?? routeSearch.sort)
    if (search) searchParams.search = search
    if (query) searchParams.query = query
    if (sort) searchParams.sort = sort
    return searchParams
  }

  const navigateProfilesList = (
    overrides: {
      search?: string | null
      query?: string | null
      sort?: string | null
    } = {},
  ) => {
    navigate({
      to: '/projects/$projectId/videos/profiles',
      params: { projectId },
      search: buildProfilesRouteSearch(overrides),
      replace: true,
    })
  }

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigateProfilesList({
        search: trimmed || null,
      })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [
    searchInput,
    urlSearch,
    routeSearch.query,
    routeSearch.sort,
    projectId,
    navigate,
  ])

  const allProfiles = useMemo(
    () => [...(data?.profiles ?? [])],
    [data?.profiles],
  )

  const profiles = useMemo(() => {
    const records = allProfiles.map((profile) => profileToFilterRecord(profile))
    const filtered = filterRecordsByCompactMap(records, filterMap)
    const ids = new Set(filtered.map((row) => row.$id))
    let list = allProfiles.filter((profile) => ids.has(profile.$id))

    const query = searchInput.trim().toLowerCase()
    if (query) {
      list = list.filter(
        (profile) =>
          profile.name.toLowerCase().includes(query) ||
          profile.$id.toLowerCase().includes(query),
      )
    }

    return [...list].sort((a, b) =>
      compareVideoProfiles(a, b, sortBy, sortOrder),
    )
  }, [allProfiles, filterMap, searchInput, urlSearch, sortBy, sortOrder])

  const toggleProfileSelected = (profileId: string) => {
    setSelectedProfileIds((prev) => {
      const next = new Set(prev)
      if (next.has(profileId)) next.delete(profileId)
      else next.add(profileId)
      return next
    })
  }

  const toggleAllProfilesSelected = (selectAll: boolean) => {
    if (!selectAll) {
      setSelectedProfileIds(new Set())
      return
    }
    setSelectedProfileIds(new Set(profiles.map((profile) => profile.$id)))
  }

  const confirmBulkDeleteProfiles = () => {
    if (selectedProfileIds.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedProfileIds))
  }

  const navigateWithQuery = (query?: string) => {
    navigateProfilesList({ query: query ?? null })
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

  const openCreate = () => {
    setEditingProfile(null)
    setDialogOpen(true)
  }

  const openUpdate = (profile: Models.VideoProfile) => {
    setEditingProfile(profile)
    setDialogOpen(true)
  }

  const confirmDelete = () => {
    if (!deletingProfile) return
    deleteMutation.mutate(deletingProfile.$id, {
      onSuccess: () => {
        toast.success(t('Profile deleted'))
        setDeletingProfile(null)
      },
      onError: (err) => {
        toast.error(getErrorMessage(err) || t('Failed to delete profile'))
      },
    })
  }

  const clearSearch = () => {
    setSearchInput('')
    navigateProfilesList({ search: null })
  }

  const totalProfiles = allProfiles.length

  const tabs = useMemo(
    () =>
      getVideosServiceTabs(projectId).map((tab) => ({
        ...tab,
        label: t(tab.label),
      })),
    [projectId, t],
  )

  let body: ReactNode
  if (error && totalProfiles === 0) {
    body = (
      <div className="flex items-center justify-center rounded-lg border border-border bg-card px-6 py-12">
        <div className="max-w-lg text-center">
          <AlertCircle className="mx-auto h-5 w-5 text-muted-foreground" />
          <p className="mt-3 text-[14px] font-medium text-foreground">
            {t('Failed to load profiles')}
          </p>
          <p className="mx-auto mt-1 max-w-md text-[13px] text-muted-foreground">
            {getErrorMessage(error)}
          </p>
          <p className="mx-auto mt-3 text-[12px] leading-relaxed text-muted-foreground">
            {t(
              'The console calls GET /project/profiles on your project API. A server error here usually means the Videos service failed to load or seed profiles for this project, not a problem with your browser.',
            )}
          </p>
          {apiError?.type || apiError?.code ? (
            <p className="mx-auto mt-2 font-mono text-[11px] text-muted-foreground">
              {apiError.code ? `HTTP ${apiError.code}` : null}
              {apiError.code && apiError.type ? ' · ' : null}
              {apiError.type ? (
                <>
                  {t('Error type:')} {apiError.type}
                </>
              ) : null}
            </p>
          ) : null}
          <Button
            variant="outline"
            size="sm"
            className="mt-4 h-8 text-[13px]"
            onClick={() => void refetch()}
            disabled={isFetching}
          >
            {t('Try again')}
          </Button>
        </div>
      </div>
    )
  } else if (isLoading && totalProfiles === 0) {
    body = (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading profiles...')}
        </p>
      </div>
    )
  } else if (totalProfiles === 0) {
    body = (
      <ProfilesEmptyState
        onCreate={openCreate}
        createDisabled={!canWrite}
        createDisabledTooltip={createPermissionTooltip}
      />
    )
  } else if (profiles.length === 0) {
    body = (
      <EmptyState
          icon={Layers}
          title={
            filterMap.size > 0
              ? t('No profiles match your filters')
              : t('No profiles match your search')
          }
          description={
            filterMap.size > 0
              ? t('Try adjusting or clearing filters.')
              : t('Try a different search term or clear the search.')
          }
          isEmpty={false}
          hasFilters
          variant="card"
          iconSize="md"
          action={
            filterMap.size > 0 ? (
              <Button variant="outline" size="sm" onClick={clearAllFilters}>
                {t('Clear filters')}
              </Button>
            ) : (
              <Button variant="outline" size="sm" onClick={clearSearch}>
                {t('Clear search')}
              </Button>
            )
          }
        />
    )
  } else {
    body = (
      <ProfilesListTable
        profiles={profiles}
        canWrite={canWrite}
        onUpdate={openUpdate}
        onDelete={setDeletingProfile}
        selectedIds={selectedProfileIds}
        onToggleSelected={toggleProfileSelected}
        onToggleAllSelected={toggleAllProfilesSelected}
      />
    )
  }

  const filterToolbar =
    totalProfiles > 0 ? (
      <FiltersPopover
        open={filtersOpen}
        onOpenChange={setFiltersOpen}
        columns={videoProfilesFilterColumns}
        filterMap={filterMap}
        onRemoveFilter={removeFilter}
        onClearAll={clearAllFilters}
        onApplyFilter={applyFilter}
        resourceLabel={t('profiles')}
        filterScope="videos.profiles"
        onApplyQuery={(queryParam, sortParam) =>
          navigateProfilesList({
            query: queryParam ?? null,
            sort: sortParam ?? null,
          })
        }
        sortBy={sortBy}
        sortOrder={sortOrder}
        onSortChange={(nextSortBy, nextSortOrder) =>
          navigateProfilesList({
            sort: isDefaultSort(nextSortBy, nextSortOrder)
              ? null
              : encodeSort(nextSortBy, nextSortOrder),
          })
        }
        defaultSortParam={encodeSort(
          PROFILES_DEFAULT_SORT_BY,
          PROFILES_DEFAULT_SORT_ORDER,
        )}
        onReset={() =>
          navigateProfilesList({ search: null, query: null, sort: null })
        }
        teamId={project?.teamId}
      />
    ) : undefined

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Videos')}
        tabs={tabs}
        activeTab="profiles"
        searchPlaceholder={t('Search profiles...')}
        searchValue={searchInput}
        onSearchChange={setSearchInput}
        showRefresh
        onRefresh={() => void refetch()}
        isRefreshing={isFetching}
        showFilters={Boolean(filterToolbar)}
        filterTrigger={filterToolbar}
        hideToolbar={!error && !isLoading && totalProfiles === 0}
        createLabel={t('Create profile')}
        onCreate={openCreate}
        createDisabled={!canWrite}
        createDisabledTooltip={createPermissionTooltip}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {body}
      </div>

      <CreateProfile
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        projectId={projectId}
        profile={editingProfile}
      />
      <ConfirmActionDialog
        open={!!deletingProfile}
        onOpenChange={(open) => {
          if (!open) setDeletingProfile(null)
        }}
        title={t('Delete profile')}
        description={`${t('Delete')} "${deletingProfile?.name ?? ''}"? ${t('Renditions already encoded with this profile stay playable. This action cannot be undone.')}`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
      {canWrite ? (
        <SpreadsheetBulkActionsBar
          selectedCount={selectedProfileIds.size}
          selectionBadge={
            selectedProfileIds.size === 1
              ? t('1 profile selected')
              : `${selectedProfileIds.size} ${t('profiles selected')}`
          }
          onClearSelection={() => setSelectedProfileIds(new Set())}
          deleteDialogOpen={bulkDeleteDialogOpen}
          onDeleteDialogOpenChange={setBulkDeleteDialogOpen}
          deleteDialogTitle={
            selectedProfileIds.size === 1
              ? t('Delete profile')
              : t('Delete profiles')
          }
          deleteDialogDescription={`${t('Are you sure you want to delete')} ${selectedProfileIds.size} ${
            selectedProfileIds.size === 1 ? t('profile') : t('profiles')
          }? ${t('Renditions already encoded with these profiles stay playable. This action cannot be undone.')}`}
          onConfirmDelete={confirmBulkDeleteProfiles}
          isDeleting={bulkDeleteMutation.isPending}
        />
      ) : null}
    </div>
  )
}
