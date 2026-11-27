import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { AlertCircle, Hammer, Plus } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type {
  DistributionBuild,
  DistributionBuildList,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { canWriteDistributionApp } from '@/lib/console-access-checks'
import {
  createBlocker,
  hasRepository,
  type BuildPermissions,
} from '@/lib/distribution/builds'
import { pageAfterEmptyResult } from '@/lib/distribution/paging'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionApp,
  useDistributionBuilds,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../../_components/DisabledReasonTooltip'
import { useRetryBuild } from './_components/BuildMenu'
import { BuildsTable } from './_components/BuildsTable'
import { CancelBuild } from './_components/CancelBuild'
import { CreateBuild } from './_components/CreateBuild'
import { DeleteBuild } from './_components/DeleteBuild'

export type BuildsInitialData = {
  builds: DistributionBuildList
  targets: DistributionTarget[]
}

type ViewProps = {
  initialData?: BuildsInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { data: app } = useDistributionApp(projectId, appId)
  const { targets: targetsFromHook, isLoading: targetsLoading } =
    useDistributionTargets(projectId, appId)
  const targets =
    targetsLoading && initialData ? initialData.targets : targetsFromHook

  const permissions: BuildPermissions = {
    canWrite: canWriteDistributionApp(access, features),
    hasRepository: !!app && hasRepository(app),
  }

  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const {
    builds: fetchedBuilds,
    total: fetchedTotal,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  } = useDistributionBuilds(
    projectId,
    appId,
    requestedPage - 1,
    DEFAULT_PAGE_SIZE,
  )

  useEffect(() => {
    if (!isFetching && !isPlaceholderData) setDisplayedPage(requestedPage)
  }, [isFetching, isPlaceholderData, requestedPage])

  const showsInitialData = !!initialData && isLoading && requestedPage === 1
  const builds = showsInitialData ? initialData.builds.builds : fetchedBuilds
  const total = showsInitialData ? initialData.builds.total : fetchedTotal
  const fallbackPage =
    isFetching || isPlaceholderData || builds.length > 0
      ? null
      : pageAfterEmptyResult(requestedPage, total, DEFAULT_PAGE_SIZE)

  useEffect(() => {
    if (fallbackPage !== null) setRequestedPage(fallbackPage)
  }, [fallbackPage])

  const showsLoading =
    (isLoading && !showsInitialData) ||
    fallbackPage !== null ||
    (isPlaceholderData && builds.length === 0)
  const showsError = !showsLoading && !!error && builds.length === 0
  const showsEmpty =
    !showsLoading && !showsError && builds.length === 0 && total === 0

  const [createOpen, setCreateOpen] = useState(false)
  const [canceling, setCanceling] = useState<DistributionBuild | null>(null)
  const [deleting, setDeleting] = useState<DistributionBuild | null>(null)
  const { retry } = useRetryBuild(projectId, targets)

  const blocker = createBlocker(app, targets, permissions)
  const createButton = (
    <CreateButton
      blocker={blocker}
      disabled={!app}
      onClick={() => setCreateOpen(true)}
    />
  )

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-4 px-4 py-4 sm:px-6 sm:py-6">
      {showsLoading ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading builds...')}
          </p>
        </div>
      ) : showsError ? (
        <EmptyState
          icon={AlertCircle}
          variant="card"
          iconSize="md"
          title={t('Failed to load builds')}
          description={getErrorMessage(error)}
          action={
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t('Try again')}
            </Button>
          }
        />
      ) : showsEmpty ? (
        <EmptyState
          icon={Hammer}
          variant="card"
          title={t('No builds yet')}
          description={t(
            'Start a build from the connected repository, or push to a branch that matches your triggers.',
          )}
          action={createButton}
        />
      ) : (
        <>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-[13px] text-muted-foreground">
              {t(
                'Each build compiles one target from the repository and signs it for its store.',
              )}
            </p>
            {createButton}
          </div>
          <BuildsTable
            projectId={projectId}
            appId={appId}
            builds={builds}
            permissions={permissions}
            onCancel={setCanceling}
            onRetry={retry}
            onDelete={setDeleting}
          />
          <Pagination
            currentPage={displayedPage}
            totalItems={total}
            pageSize={DEFAULT_PAGE_SIZE}
            onPageChange={setRequestedPage}
            onPageSizeChange={() => setRequestedPage(1)}
            showPageSizeSelector={false}
            itemLabel={t('builds')}
          />
        </>
      )}
      {app ? (
        <CreateBuild
          key={targets.map((target) => target.$id).join()}
          open={createOpen}
          onOpenChange={setCreateOpen}
          projectId={projectId}
          app={app}
          targets={targets}
        />
      ) : null}
      <CancelBuild
        projectId={projectId}
        build={canceling}
        onOpenChange={(open) => {
          if (!open) setCanceling(null)
        }}
      />
      <DeleteBuild
        projectId={projectId}
        build={deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
      />
    </div>
  )
}

function CreateButton({
  blocker,
  disabled,
  onClick,
}: {
  blocker: string | null
  disabled: boolean
  onClick: () => void
}) {
  const t = useT()
  return (
    <DisabledReasonTooltip reason={blocker ? t(blocker) : null}>
      <Button
        size="sm"
        className="h-9 shrink-0 gap-1.5 text-[13px]"
        disabled={disabled || !!blocker}
        onClick={onClick}
        {...analyticsAttrs('distribution-create-build')}
      >
        <Plus className="h-4 w-4" />
        {t('Create build')}
      </Button>
    </DisabledReasonTooltip>
  )
}
