import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { AlertCircle, Loader2, Plus, Rocket, Search } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { DistributionRelease } from '@/lib/appwrite/distribution'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canWriteDistributionApp } from '@/lib/console-access-checks'
import { pageAfterEmptyResult } from '@/lib/distribution/paging'
import { useT } from '@/lib/i18n/translate'
import {
  DEFAULT_PAGE_SIZE,
  useDistributionReleases,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../../_components/DisabledReasonTooltip'
import { CreateRelease } from './_components/CreateRelease'
import { DeleteRelease } from './_components/DeleteRelease'
import { ReleasesTable } from './_components/ReleasesTable'
import { UpdateRelease } from './_components/UpdateRelease'

const SEARCH_DEBOUNCE_MS = 300

export function View() {
  const t = useT()
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteDistributionApp(access, features)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [creating, setCreating] = useState(false)
  const [updating, setUpdating] = useState<DistributionRelease | null>(null)
  const [deleting, setDeleting] = useState<DistributionRelease | null>(null)

  useEffect(() => {
    const term = searchInput.trim()
    if (term === search) return
    const timeout = setTimeout(() => {
      setSearch(term)
      setRequestedPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput, search])

  const {
    releases,
    total,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  } = useDistributionReleases(
    projectId,
    appId,
    requestedPage - 1,
    pageSize,
    search,
  )

  const settled = !isLoading && !isFetching && !isPlaceholderData && !error
  const clampedPage =
    settled && releases.length === 0
      ? pageAfterEmptyResult(requestedPage, total, pageSize)
      : null

  useEffect(() => {
    if (clampedPage !== null) setRequestedPage(clampedPage)
  }, [clampedPage])

  useEffect(() => {
    if (!isFetching && !isPlaceholderData) setDisplayedPage(requestedPage)
  }, [isFetching, isPlaceholderData, requestedPage])

  const loading =
    releases.length === 0 &&
    (isLoading || isPlaceholderData || clampedPage !== null)
  const empty = !loading && !error && releases.length === 0
  const firstRun = empty && !search

  const createButton = (
    <DisabledReasonTooltip
      reason={
        canWrite ? null : t("You don't have permission to create releases.")
      }
    >
      <Button
        size="sm"
        className="h-9 gap-1.5 text-[13px]"
        disabled={!canWrite}
        onClick={() => setCreating(true)}
        {...analyticsAttrs('distribution-create-release')}
      >
        <Plus className="h-4 w-4" />
        {t('Create release')}
      </Button>
    </DisabledReasonTooltip>
  )

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-4 px-4 py-4 sm:px-6 sm:py-6">
      {firstRun ? null : (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative w-full sm:w-64">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              aria-label={t('Search releases')}
              placeholder={t('Search releases...')}
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              className="h-9 w-full border-border bg-accent/50 ps-10 text-[13px]"
            />
          </div>
          {createButton}
        </div>
      )}
      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : error && releases.length === 0 ? (
        <EmptyState
          icon={AlertCircle}
          title={t('Failed to load releases')}
          description={getErrorMessage(error)}
          variant="card"
          iconSize="md"
          action={
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t('Try again')}
            </Button>
          }
        />
      ) : empty && search ? (
        <EmptyState icon={Rocket} hasFilters variant="card" />
      ) : empty ? (
        <EmptyState
          icon={Rocket}
          title={t('No releases yet')}
          description={t(
            'Create a release for the next version of this app, then submit its builds to the App Store and Google Play.',
          )}
          action={createButton}
          variant="card"
        />
      ) : (
        <>
          <ReleasesTable
            projectId={projectId}
            appId={appId}
            releases={releases}
            canWrite={canWrite}
            onUpdate={setUpdating}
            onDelete={setDeleting}
          />
          <Pagination
            currentPage={displayedPage}
            totalItems={total}
            pageSize={pageSize}
            onPageChange={setRequestedPage}
            onPageSizeChange={(size) => {
              setPageSize(size)
              setRequestedPage(1)
              setDisplayedPage(1)
            }}
            itemLabel={t('releases')}
          />
        </>
      )}
      <CreateRelease
        open={creating}
        onOpenChange={setCreating}
        projectId={projectId}
        appId={appId}
      />
      <UpdateRelease
        projectId={projectId}
        release={updating}
        onOpenChange={(open) => {
          if (!open) setUpdating(null)
        }}
      />
      <DeleteRelease
        projectId={projectId}
        release={deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
      />
    </div>
  )
}
