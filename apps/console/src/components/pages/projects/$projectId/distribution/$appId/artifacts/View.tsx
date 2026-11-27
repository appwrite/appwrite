import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { AlertCircle, Loader2, Package, Upload } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type {
  DistributionArtifact,
  DistributionArtifactList,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { canWriteDistributionApp } from '@/lib/console-access-checks'
import { pageAfterEmptyResult } from '@/lib/distribution/paging'
import { useT } from '@/lib/i18n/translate'
import {
  DEFAULT_PAGE_SIZE,
  useDistributionArtifacts,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../../_components/DisabledReasonTooltip'
import { ArtifactsTable } from './_components/ArtifactsTable'
import { DeleteArtifact } from './_components/DeleteArtifact'
import { UploadArtifact } from './_components/UploadArtifact'

export type ArtifactsInitialData = {
  artifacts: DistributionArtifactList
  targets: DistributionTarget[]
}

type ViewProps = {
  initialData?: ArtifactsInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canEdit = canWriteDistributionApp(access, features)

  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [uploading, setUploading] = useState(false)
  const [deleting, setDeleting] = useState<DistributionArtifact | null>(null)

  const requested = useDistributionArtifacts(
    projectId,
    appId,
    requestedPage - 1,
    pageSize,
  )
  const displayed = useDistributionArtifacts(
    projectId,
    appId,
    displayedPage - 1,
    pageSize,
  )
  const { targets: targetsFromHook, isLoading: targetsLoading } =
    useDistributionTargets(projectId, appId)

  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requested.isFetching &&
      !requested.isPlaceholderData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requested.isFetching,
    requested.isPlaceholderData,
  ])

  const fallback =
    displayed.isLoading && displayedPage === 1 ? initialData : undefined
  const artifacts = fallback?.artifacts.artifacts ?? displayed.artifacts
  const total = fallback?.artifacts.total ?? displayed.total
  const fallbackPage =
    artifacts.length === 0
      ? pageAfterEmptyResult(displayedPage, total, pageSize)
      : null
  const settled = !displayed.isFetching && !displayed.isPlaceholderData

  useEffect(() => {
    if (fallbackPage === null || !settled) return
    setRequestedPage(fallbackPage)
    setDisplayedPage(fallbackPage)
  }, [fallbackPage, settled])

  const targets =
    targetsLoading && initialData ? initialData.targets : targetsFromHook

  const upload = (
    <DisabledReasonTooltip
      reason={
        canEdit ? null : t("You don't have permission to perform this action.")
      }
    >
      <Button
        size="sm"
        className="h-9 shrink-0 gap-1.5 text-[13px]"
        disabled={!canEdit}
        onClick={() => setUploading(true)}
      >
        <Upload className="h-4 w-4" />
        {t('Upload artifact')}
      </Button>
    </DisabledReasonTooltip>
  )

  const loading =
    (displayed.isLoading && artifacts.length === 0) || fallbackPage !== null
  const failed = !!displayed.error && artifacts.length === 0

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-4 px-4 py-4 sm:px-6 sm:py-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="max-w-2xl text-[13px] text-muted-foreground">
          {t(
            'Binaries built by Appwrite or uploaded from your own pipeline. A ready artifact can be submitted to the stores from a release.',
          )}
        </p>
        {upload}
      </div>
      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : failed ? (
        <EmptyState
          icon={AlertCircle}
          title={t('Failed to load artifacts')}
          description={getErrorMessage(displayed.error)}
          variant="card"
          iconSize="md"
          action={
            <Button
              variant="outline"
              size="sm"
              onClick={() => void displayed.refetch()}
            >
              {t('Try again')}
            </Button>
          }
        />
      ) : artifacts.length === 0 ? (
        <EmptyState
          icon={Package}
          title={t('No artifacts yet')}
          description={t(
            'Builds produce artifacts here. You can also upload an IPA or AAB built elsewhere.',
          )}
          variant="card"
        />
      ) : (
        <>
          <ArtifactsTable
            projectId={projectId}
            appId={appId}
            artifacts={artifacts}
            canEdit={canEdit}
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
            itemLabel={t('artifacts')}
          />
        </>
      )}
      <UploadArtifact
        projectId={projectId}
        appId={appId}
        targets={targets}
        open={uploading}
        onOpenChange={setUploading}
      />
      <DeleteArtifact
        projectId={projectId}
        appId={appId}
        artifact={deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
      />
    </div>
  )
}
