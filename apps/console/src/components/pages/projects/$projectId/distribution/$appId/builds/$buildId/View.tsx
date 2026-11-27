import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  AlertCircle,
  ArrowLeft,
  CircleDashed,
  Hammer,
  Loader2,
  RefreshCw,
  Trash2,
  XCircle,
} from 'lucide-react'
import { BuildLogsCard } from '@/components/global/shared/BuildLogsCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type {
  DistributionBuild,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { canWriteDistributionApp } from '@/lib/console-access-checks'
import {
  buildDuration,
  buildVersion,
  cancelBlocker,
  deleteBlocker,
  formatDuration,
  hasRepository,
  retryBlocker,
  type BuildPermissions,
} from '@/lib/distribution/builds'
import { logsToText, mergeLogs } from '@/lib/distribution/logs'
import { isBuildActive } from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionApp,
  useDistributionBuild,
  useDistributionBuildLogs,
  useDistributionRelease,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { captureExceptionWithContext } from '@/lib/sentry/report-error'
import {
  getErrorMessage,
  isAppwriteHttpStatus,
} from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../../../_components/DisabledReasonTooltip'
import { BuildStatusBadge } from '../../../_components/status-badges'
import { useRetryBuild } from '../_components/BuildMenu'
import { BuildSummary } from '../_components/BuildSummary'
import { CancelBuild } from '../_components/CancelBuild'
import { DeleteBuild } from '../_components/DeleteBuild'

export type BuildInitialData = {
  build: DistributionBuild
  targets: DistributionTarget[]
}

type ViewProps = {
  initialData?: BuildInitialData
}

const TICK_MS = 1000

function useNow(ticking: boolean): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!ticking) return
    const interval = setInterval(() => setNow(Date.now()), TICK_MS)
    return () => clearInterval(interval)
  }, [ticking])
  return now
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const {
    projectId = '',
    appId = '',
    buildId = '',
  } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { data: app } = useDistributionApp(projectId, appId)
  const {
    data: buildFromHook,
    isLoading,
    error,
  } = useDistributionBuild(projectId, appId, buildId)
  const build = buildFromHook ?? initialData?.build
  const { targets: targetsFromHook, isLoading: targetsLoading } =
    useDistributionTargets(projectId, appId)
  const targets =
    targetsLoading && initialData ? initialData.targets : targetsFromHook
  const target = targets.find((item) => item.$id === build?.targetId)
  const { data: release } = useDistributionRelease(
    projectId,
    appId,
    build?.releaseId || null,
  )

  const {
    pages,
    sync: syncLogs,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading: logsLoading,
    error: logsError,
    refetch: refetchLogs,
  } = useDistributionBuildLogs(projectId, appId, buildId)
  const logs = useMemo(
    () => logsToText(mergeLogs(pages.flatMap((page) => page.logs))),
    [pages],
  )
  const [refreshing, setRefreshing] = useState(false)
  const refreshLogs = async () => {
    setRefreshing(true)
    try {
      await syncLogs()
    } catch (error) {
      toast.error(
        getErrorMessage(error, t('Something went wrong. Please try again.')),
      )
    } finally {
      setRefreshing(false)
    }
  }

  const status = build?.status ?? ''
  const active = isBuildActive(status)
  const previousStatus = useRef(status)
  useEffect(() => {
    const previous = previousStatus.current
    previousStatus.current = status
    if (isBuildActive(previous) && status && !isBuildActive(status)) {
      syncLogs().catch((error: unknown) =>
        captureExceptionWithContext(error, {
          projectId,
          source: 'distribution-build-logs-sync',
        }),
      )
    }
  }, [status, syncLogs, projectId])

  const now = useNow(active)
  const permissions: BuildPermissions = {
    canWrite: canWriteDistributionApp(access, features),
    hasRepository: !!app && hasRepository(app),
  }
  const { retry, isRetrying } = useRetryBuild(projectId, targets)
  const [canceling, setCanceling] = useState<DistributionBuild | null>(null)
  const [deleting, setDeleting] = useState<DistributionBuild | null>(null)

  const backToBuilds = (
    <Button
      variant="ghost"
      size="sm"
      asChild
      className="h-7 w-7 shrink-0 p-0"
      aria-label={t('Back to builds')}
    >
      <Link
        to="/projects/$projectId/distribution/$appId/builds"
        params={{ projectId, appId }}
      >
        <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
      </Link>
    </Button>
  )

  if (!build) {
    if (isLoading) return null
    const missing = !error || isAppwriteHttpStatus(error, 404)
    return (
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
        <EmptyState
          icon={missing ? Hammer : AlertCircle}
          variant="card"
          title={
            missing ? t('Build not found') : t('Could not load this build')
          }
          description={
            missing
              ? t('This build does not exist or has been deleted.')
              : t('Something went wrong. Please try again.')
          }
          action={
            <Button variant="outline" size="sm" asChild>
              <Link
                to="/projects/$projectId/distribution/$appId/builds"
                params={{ projectId, appId }}
              >
                {t('Back to builds')}
              </Link>
            </Button>
          }
        />
      </div>
    )
  }

  const version = buildVersion(build)
  const duration = buildDuration(build, now)
  const cancelReason = cancelBlocker(permissions)
  const retryReason = retryBlocker(build, permissions)
  const deleteReason = deleteBlocker(build, permissions)

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-6 px-4 py-4 sm:px-6 sm:py-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-center gap-2">
          {backToBuilds}
          <h2 className="truncate text-[15px] font-semibold text-foreground">
            {version ? <span dir="ltr">{version}</span> : t('Build')}
          </h2>
          <BuildStatusBadge status={build.status} />
          <CopyableId id={build.$id} size="xs" className="shrink-0" />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {active ? (
            <ActionButton
              reason={cancelReason}
              onClick={() => setCanceling(build)}
              analytics="distribution-cancel-build"
            >
              <XCircle className="h-4 w-4" />
              {t('Cancel build')}
            </ActionButton>
          ) : null}
          <ActionButton
            reason={retryReason}
            busy={isRetrying}
            onClick={() => retry(build)}
            analytics="distribution-retry-build"
          >
            <RefreshCw className="h-4 w-4" />
            {t('Retry')}
          </ActionButton>
          <ActionButton
            reason={deleteReason}
            onClick={() => setDeleting(build)}
          >
            <Trash2 className="h-4 w-4" />
            {t('Delete')}
          </ActionButton>
        </div>
      </div>

      {build.status === 'failed' ? (
        <Alert variant="destructive" data-testid="build-error">
          <AlertCircle />
          <AlertTitle>{t('Build failed')}</AlertTitle>
          <AlertDescription>
            {build.errorMessage ? <p>{build.errorMessage}</p> : null}
            {build.errorCode ? (
              <p dir="ltr" className="font-mono text-[12px]">
                {build.errorCode}
              </p>
            ) : null}
          </AlertDescription>
        </Alert>
      ) : null}

      <BuildSummary
        projectId={projectId}
        build={build}
        target={target}
        release={release}
        now={now}
      />

      <div className="space-y-3">
        {hasNextPage ? (
          <div className="flex flex-col gap-2 rounded-lg border border-dashed border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-[13px] text-muted-foreground">
              {t('Showing the most recent logs.')}
            </p>
            <Button
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[13px]"
              disabled={isFetchingNextPage}
              onClick={() => void fetchNextPage()}
            >
              {t('Load earlier logs')}
            </Button>
          </div>
        ) : null}
        <div data-testid="build-logs">
          <BuildLogsCard
            buildLogs={logs}
            durationDisplay={duration > 0 ? formatDuration(duration) : null}
            downloadFilename={`${build.$id}-logs.txt`}
            onRefresh={() => void refreshLogs()}
            isRefreshing={refreshing}
            emptyMessage={
              logsLoading ? (
                <div className="flex items-center gap-2">
                  <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
                  {t('Loading logs')}
                </div>
              ) : logsError && pages.length === 0 ? (
                <div
                  className="flex flex-wrap items-center gap-2"
                  data-testid="build-logs-error"
                >
                  <AlertCircle className="h-3.5 w-3.5 shrink-0 text-destructive" />
                  {t('Failed to load logs')}
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 text-[12px]"
                    onClick={() => void refetchLogs()}
                  >
                    {t('Try again')}
                  </Button>
                </div>
              ) : active ? undefined : (
                <div className="flex items-center gap-2">
                  <CircleDashed className="h-3.5 w-3.5 shrink-0" />
                  {t('This build has no logs.')}
                </div>
              )
            }
          />
        </div>
      </div>

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
        onDeleted={() =>
          void navigate({
            to: '/projects/$projectId/distribution/$appId/builds',
            params: { projectId, appId },
            replace: true,
          })
        }
      />
    </div>
  )
}

function ActionButton({
  reason,
  busy = false,
  onClick,
  analytics,
  children,
}: {
  reason: string | null
  busy?: boolean
  onClick: () => void
  analytics?: 'distribution-cancel-build' | 'distribution-retry-build'
  children: ReactNode
}) {
  const t = useT()
  return (
    <DisabledReasonTooltip reason={reason ? t(reason) : null}>
      <Button
        variant="outline"
        size="sm"
        className="h-9 gap-1.5 text-[13px]"
        disabled={!!reason || busy}
        onClick={onClick}
        {...(analytics ? analyticsAttrs(analytics) : {})}
      >
        {children}
      </Button>
    </DisabledReasonTooltip>
  )
}
