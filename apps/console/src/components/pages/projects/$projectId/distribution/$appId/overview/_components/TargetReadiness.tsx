import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import {
  AlertCircle,
  CheckCircle2,
  Loader2,
  RefreshCw,
  XCircle,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import type {
  DistributionBuild,
  DistributionCheck,
  DistributionConnection,
  DistributionSigningIdentity,
  DistributionSubmission,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { buildVersion } from '@/lib/distribution/builds'
import { targetTrack } from '@/lib/distribution/publish'
import { useT } from '@/lib/i18n/translate'
import {
  useAllDistributionConnections,
  useAllDistributionSigningIdentities,
  useDistributionCheck,
  useDistributionLatestBuild,
  useDistributionLatestSubmission,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CheckIssues } from '../../../_components/CheckIssues'
import { PLATFORM_PROVIDERS, PlatformIcon } from '../../../_components/platform'
import {
  BuildStatusBadge,
  ConnectionStatusBadge,
  SigningIdentityStatusBadge,
  SubmissionStatusBadge,
} from '../../../_components/status-badges'
import {
  TARGET_TITLES,
  TRACKS,
  trackLabel,
} from '../../../_components/target-options'

type TargetReadinessProps = {
  projectId: string
  target: DistributionTarget
  showSubmissions: boolean
  initialConnections?: DistributionConnection[]
  initialSigningIdentities?: DistributionSigningIdentity[]
}

export function TargetReadiness({
  projectId,
  target,
  showSubmissions,
  initialConnections,
  initialSigningIdentities,
}: TargetReadinessProps) {
  const t = useT()
  const prefix = `overview-${target.platform}`
  const track = targetTrack(
    target.track,
    TRACKS[PLATFORM_PROVIDERS[target.platform]],
  )
  const check = useDistributionCheck(projectId, target.appId, target.$id, track)
  const connection = useConnection(projectId, target, initialConnections)
  const signingIdentity = useSigningIdentity(
    projectId,
    target,
    initialSigningIdentities,
  )
  const checking = check.isPending || check.isFetching

  return (
    <section
      aria-labelledby={`${prefix}-title`}
      data-testid={`${prefix}-card`}
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h3
            id={`${prefix}-title`}
            className="flex items-center gap-2 text-[15px] font-semibold text-foreground"
          >
            <PlatformIcon platform={target.platform} />
            {t(TARGET_TITLES[target.platform])}
          </h3>
          <p className="mt-1 font-mono text-[13px] text-muted-foreground break-all">
            <span dir="ltr">{target.identifier}</span>
          </p>
        </div>
        <Button
          {...analyticsAttrs('distribution-run-checks')}
          variant="outline"
          size="sm"
          className="h-9 shrink-0 gap-1.5 text-[13px]"
          disabled={checking}
          onClick={() => check.refetch()}
        >
          <RefreshCw className="h-4 w-4" />
          {t('Run checks again')}
        </Button>
      </div>

      <div className="border-t border-border" />
      <dl className="grid gap-4 px-6 py-4 sm:grid-cols-3">
        <Detail label={t('Store connection')}>
          <Reference
            id={target.connectionId}
            name={connection?.name}
            badge={
              connection ? (
                <ConnectionStatusBadge status={connection.status} />
              ) : null
            }
          />
        </Detail>
        <Detail label={t('Signing identity')}>
          <Reference
            id={target.signingIdentityId}
            name={signingIdentity?.name}
            badge={
              signingIdentity ? (
                <SigningIdentityStatusBadge status={signingIdentity.status} />
              ) : null
            }
          />
        </Detail>
        <Detail label={t('Track')}>
          <span className="text-[13px] text-foreground">
            {t(trackLabel(track))}
          </span>
        </Detail>
      </dl>

      <div className="border-t border-border" />
      <div data-testid={`${prefix}-readiness`}>
        <Readiness check={check.data} checking={checking} error={check.error} />
      </div>

      <div className="border-t border-border" />
      <dl className="grid gap-4 px-6 py-4 sm:grid-cols-2">
        <LatestBuild projectId={projectId} target={target} />
        {showSubmissions ? (
          <LatestSubmission projectId={projectId} target={target} />
        ) : null}
      </dl>
    </section>
  )
}

function useConnection(
  projectId: string,
  target: DistributionTarget,
  initialConnections: DistributionConnection[] | undefined,
): DistributionConnection | undefined {
  const { connections, isLoading } = useAllDistributionConnections(
    projectId,
    PLATFORM_PROVIDERS[target.platform],
  )
  const available =
    isLoading && initialConnections ? initialConnections : connections
  return available.find((connection) => connection.$id === target.connectionId)
}

function useSigningIdentity(
  projectId: string,
  target: DistributionTarget,
  initialSigningIdentities: DistributionSigningIdentity[] | undefined,
): DistributionSigningIdentity | undefined {
  const { signingIdentities, isLoading } = useAllDistributionSigningIdentities(
    projectId,
    target.platform,
  )
  const available =
    isLoading && initialSigningIdentities
      ? initialSigningIdentities
      : signingIdentities
  return available.find((identity) => identity.$id === target.signingIdentityId)
}

function Readiness({
  check,
  checking,
  error,
}: {
  check: DistributionCheck | undefined
  checking: boolean
  error: Error | null
}) {
  const t = useT()
  if (checking) {
    return (
      <Summary icon={<Loader2 className="h-4 w-4 animate-spin" />}>
        {t('Checking…')}
      </Summary>
    )
  }
  if (error || !check) {
    return (
      <Summary
        icon={
          <AlertCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
        }
        description={getErrorMessage(
          error,
          t('Something went wrong. Please try again.'),
        )}
      >
        {t('Could not run the checks')}
      </Summary>
    )
  }
  return (
    <>
      {check.ready ? (
        <Summary
          icon={
            <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
          }
        >
          {t('Ready to submit')}
        </Summary>
      ) : (
        <Summary
          icon={<XCircle className="h-4 w-4 text-red-600 dark:text-red-400" />}
          description={t('Resolve the blockers below before submitting.')}
        >
          {t('Not ready to submit')}
        </Summary>
      )}
      {check.issues.length > 0 ? (
        <div className="border-t border-border px-2">
          <CheckIssues issues={check.issues} />
        </div>
      ) : null}
    </>
  )
}

function Summary({
  icon,
  description,
  children,
}: {
  icon: ReactNode
  description?: string
  children: ReactNode
}) {
  return (
    <div className="flex items-start gap-3 px-6 py-4">
      <span className="mt-0.5 shrink-0 text-muted-foreground">{icon}</span>
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">{children}</p>
        {description ? (
          <p className="mt-1 text-[13px] text-muted-foreground break-words">
            {description}
          </p>
        ) : null}
      </div>
    </div>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0 space-y-1.5">
      <dt className="text-[12px] text-muted-foreground">{label}</dt>
      <dd className="flex min-w-0 flex-wrap items-center gap-1.5">
        {children}
      </dd>
    </div>
  )
}

function Reference({
  id,
  name,
  badge,
}: {
  id: string
  name: string | undefined
  badge: ReactNode
}) {
  const t = useT()
  if (!id) {
    return (
      <span className="text-[13px] text-muted-foreground">{t('Not set')}</span>
    )
  }
  return (
    <>
      {name ? (
        <span className="truncate text-[13px] text-foreground">{name}</span>
      ) : (
        <span className="truncate font-mono text-[13px] text-foreground">
          <span dir="ltr">{id}</span>
        </span>
      )}
      {badge}
    </>
  )
}

function LatestBuild({
  projectId,
  target,
}: {
  projectId: string
  target: DistributionTarget
}) {
  const t = useT()
  const { data: build, isPending } = useDistributionLatestBuild(
    projectId,
    target.appId,
    target.$id,
  )
  return (
    <Detail label={t('Latest build')}>
      {isPending ? null : build ? (
        <BuildSummary projectId={projectId} build={build} />
      ) : (
        <span className="text-[13px] text-muted-foreground">
          {t('No builds yet')}
        </span>
      )}
    </Detail>
  )
}

function BuildSummary({
  projectId,
  build,
}: {
  projectId: string
  build: DistributionBuild
}) {
  const t = useT()
  const version = buildVersion(build)
  return (
    <>
      <BuildStatusBadge status={build.status} />
      {version ? (
        <span className="font-mono text-[13px] text-foreground">
          <span dir="ltr">{version}</span>
        </span>
      ) : null}
      <DateTooltip
        date={build.$createdAt}
        className="text-[13px] text-muted-foreground"
      />
      <Link
        to="/projects/$projectId/distribution/$appId/builds/$buildId"
        params={{ projectId, appId: build.appId, buildId: build.$id }}
        className="text-[13px] text-foreground underline-offset-4 hover:underline"
      >
        {t('View build')}
      </Link>
    </>
  )
}

function LatestSubmission({
  projectId,
  target,
}: {
  projectId: string
  target: DistributionTarget
}) {
  const t = useT()
  const { data: submission, isPending } = useDistributionLatestSubmission(
    projectId,
    target.appId,
    target.$id,
  )
  return (
    <Detail label={t('Latest submission')}>
      {isPending ? null : submission ? (
        <SubmissionSummary projectId={projectId} submission={submission} />
      ) : (
        <span className="text-[13px] text-muted-foreground">
          {t('No submissions yet')}
        </span>
      )}
    </Detail>
  )
}

function SubmissionSummary({
  projectId,
  submission,
}: {
  projectId: string
  submission: DistributionSubmission
}) {
  const t = useT()
  return (
    <>
      <SubmissionStatusBadge status={submission.status} />
      <span className="text-[13px] text-foreground">
        {t(trackLabel(submission.track))}
      </span>
      <DateTooltip
        date={submission.$createdAt}
        className="text-[13px] text-muted-foreground"
      />
      <Link
        to="/projects/$projectId/distribution/$appId/releases/$releaseId"
        params={{
          projectId,
          appId: submission.appId,
          releaseId: submission.releaseId,
        }}
        className="text-[13px] text-foreground underline-offset-4 hover:underline"
      >
        {t('View release')}
      </Link>
    </>
  )
}
