import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { AlertCircle, Layers } from 'lucide-react'
import { toast } from 'sonner'
import { ProfilesEmptyState } from './_components/ProfilesEmptyState'
import { CreateProfile } from '../_components/CreateProfile'
import { ProfilesSpreadsheet } from '../_components/ProfilesSpreadsheet'
import { VideoPage } from '../_components/VideoPage'
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
  type ProfileSortColumn,
} from '@/lib/videos/profile-list-filters'

export function View() {
  const t = useT()
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

  const { data, isLoading, isFetching, error, refetch } =
    useVideoProfiles(projectId)
  const apiError = error ? getAppwriteErrorInfo(error) : null
  const deleteMutation = useDeleteVideoProfile(projectId)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateVideo(access, features)

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

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

  const handleSortColumn = (columnKey: ProfileSortColumn) => {
    let nextOrder: 'asc' | 'desc' = 'asc'
    if (sortBy === columnKey) {
      nextOrder = sortOrder === 'asc' ? 'desc' : 'asc'
    } else if (
      columnKey === 'resolution' ||
      columnKey === 'videoBitRate' ||
      columnKey === 'audioBitRate' ||
      columnKey === '$createdAt'
    ) {
      nextOrder = 'desc'
    }
    const isDefaultSort =
      columnKey === PROFILES_DEFAULT_SORT_BY &&
      nextOrder === PROFILES_DEFAULT_SORT_ORDER
    navigateProfilesList({
      sort: isDefaultSort ? null : encodeSort(columnKey, nextOrder),
    })
  }

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

  let body: ReactNode
  if (error && totalProfiles === 0) {
    body = (
      <div className="flex flex-1 items-center justify-center px-6 py-12">
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
              'The console calls GET /videos/profiles on your project API. A server error here usually means the Videos service failed to load or seed profiles for this project, not a problem with your browser.',
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
      <div className="flex flex-1 items-center justify-center px-6 py-12">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading profiles...')}
        </p>
      </div>
    )
  } else if (totalProfiles === 0) {
    body = (
      <div className="min-h-0 flex-1 overflow-y-auto px-4 sm:px-6">
        <ProfilesEmptyState
          onCreate={openCreate}
          createDisabled={!canWrite}
          createDisabledTooltip={
            canWrite
              ? undefined
              : t("You don't have permission to manage video profiles.")
          }
        />
      </div>
    )
  } else if (profiles.length === 0) {
    body = (
      <div className="flex flex-1 items-center justify-center px-6 py-12">
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
          variant="centered"
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
      </div>
    )
  } else {
    body = (
      <ProfilesSpreadsheet
        className="min-h-0 flex-1"
        profiles={profiles}
        canWrite={canWrite}
        onUpdate={openUpdate}
        onDelete={setDeletingProfile}
        sortBy={sortBy}
        sortOrder={sortOrder}
        onSortColumn={handleSortColumn}
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
        onApplyQuery={(queryParam) =>
          navigateWithQuery(queryParam ?? undefined)
        }
        teamId={project?.teamId}
      />
    ) : undefined

  return (
    <VideoPage
      title={t('Encoding profiles')}
      term="profile"
      spreadsheetContent
      searchPlaceholder={t('Search profiles...')}
      searchValue={searchInput}
      onSearchChange={setSearchInput}
      showRefresh
      onRefresh={() => void refetch()}
      isRefreshing={isFetching}
      filterTrigger={filterToolbar}
      hideToolbar={!error && !isLoading && totalProfiles === 0}
      createLabel={t('Create profile')}
      onCreate={openCreate}
      createDisabled={!canWrite}
      createDisabledTooltip={
        canWrite
          ? undefined
          : "You don't have permission to manage video profiles."
      }
    >
      {body}

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
    </VideoPage>
  )
}
