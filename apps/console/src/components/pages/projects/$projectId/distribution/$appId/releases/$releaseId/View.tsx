import { useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import {
  AlertCircle,
  ArrowLeft,
  Info,
  Lock,
  Pencil,
  Rocket,
  Send,
  Trash2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type {
  DistributionBuild,
  DistributionRelease,
  DistributionSubmission,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  canManageDistributionSubmissions,
  canShowDistributionSubmissions,
  canWriteDistributionApp,
} from '@/lib/console-access-checks'
import { buildVersion } from '@/lib/distribution/builds'
import { buildSubmissionBlocker } from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionArtifact,
  useDistributionRelease,
  useDistributionReleaseBuilds,
  useDistributionSubmissions,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import {
  getErrorMessage,
  isAppwriteHttpStatus,
} from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../../../_components/DisabledReasonTooltip'
import { PlatformIcon, platformLabel } from '../../../_components/platform'
import {
  BuildStatusBadge,
  ReleaseStatusBadge,
} from '../../../_components/status-badges'
import { CreateSubmission } from '../_components/CreateSubmission'
import { DeleteRelease } from '../_components/DeleteRelease'
import { TargetSubmissions } from '../_components/TargetSubmissions'
import { UpdateRelease } from '../_components/UpdateRelease'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export type ReleaseInitialData = {
  release: DistributionRelease
  builds: DistributionBuild[]
  targets: DistributionTarget[]
  submissions: DistributionSubmission[]
}

type ViewProps = {
  initialData?: ReleaseInitialData
}

type SubmissionDraft = {
  target: DistributionTarget
  artifactId?: string
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const {
    projectId = '',
    appId = '',
    releaseId = '',
  } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteDistributionApp(access, features)
  const showSubmissions = canShowDistributionSubmissions(access, features)
  const canManage = canManageDistributionSubmissions(access, features)

  const {
    data: releaseFromHook,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useDistributionRelease(projectId, appId, releaseId)
  const release = releaseFromHook ?? initialData?.release
  const buildsQuery = useDistributionReleaseBuilds(projectId, appId, releaseId)
  const targetsQuery = useDistributionTargets(projectId, appId)
  const submissionsQuery = useDistributionSubmissions(
    projectId,
    appId,
    showSubmissions ? releaseId : null,
  )
  const builds =
    buildsQuery.isLoading && initialData
      ? initialData.builds
      : buildsQuery.builds
  const targets =
    targetsQuery.isLoading && initialData
      ? initialData.targets
      : targetsQuery.targets
  const submissions =
    submissionsQuery.isLoading && initialData
      ? initialData.submissions
      : submissionsQuery.submissions

  const [updating, setUpdating] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [draft, setDraft] = useState<SubmissionDraft | null>(null)

  const backToReleases = (
    <Button
      variant="ghost"
      size="sm"
      asChild
      className="h-8 gap-1.5 px-2 text-[13px] text-muted-foreground"
    >
      <Link
        to="/projects/$projectId/distribution/$appId/releases"
        params={{ projectId, appId }}
      >
        <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
        {t('Back to releases')}
      </Link>
    </Button>
  )

  if (!release) {
    if (isLoading) return null
    const missing = !error || isAppwriteHttpStatus(error, 404)
    return (
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
        {missing ? (
          <EmptyState
            icon={Rocket}
            title={t('Release not found')}
            description={t('This release does not exist or has been deleted.')}
            variant="card"
            action={backToReleases}
          />
        ) : (
          <EmptyState
            icon={AlertCircle}
            title={t('Could not load this release')}
            description={getErrorMessage(
              error,
              t('Something went wrong. Please try again.'),
            )}
            variant="card"
            action={
              <div className="flex items-center gap-2">
                {backToReleases}
                <Button
                  size="sm"
                  disabled={isFetching}
                  onClick={() => void refetch()}
                >
                  {t('Try again')}
                </Button>
              </div>
            }
          />
        )}
      </div>
    )
  }

  const writeReason = canWrite
    ? null
    : t("You don't have permission to update releases.")
  const notes = Object.entries(release.notes)
  const targetFor = (targetId: string) =>
    targets.find((target) => target.$id === targetId)

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-6 px-4 py-4 sm:px-6 sm:py-6">
      <div className="-ms-2">{backToReleases}</div>

      <section
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
        data-testid="release-summary"
      >
        <div className="flex flex-col gap-4 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <h2
                className="font-mono text-[18px] font-semibold text-foreground"
                dir="ltr"
              >
                {release.version}
              </h2>
              <ReleaseStatusBadge status={release.status} />
              <CopyableId id={release.$id} size="xs" className="shrink-0" />
            </div>
            <dl className="flex flex-wrap gap-x-6 gap-y-1 text-[12px]">
              <div className="flex gap-1.5">
                <dt className="text-muted-foreground">{t('Created')}</dt>
                <dd>
                  <DateTooltip date={release.$createdAt} />
                </dd>
              </div>
              <div className="flex gap-1.5">
                <dt className="text-muted-foreground">{t('Updated')}</dt>
                <dd>
                  <DateTooltip date={release.$updatedAt} />
                </dd>
              </div>
            </dl>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <DisabledReasonTooltip reason={writeReason}>
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-1.5 text-[13px]"
                disabled={!canWrite}
                onClick={() => setUpdating(true)}
              >
                <Pencil className="h-3.5 w-3.5" />
                {t('Update')}
              </Button>
            </DisabledReasonTooltip>
            <DisabledReasonTooltip reason={writeReason}>
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-1.5 text-[13px]"
                disabled={!canWrite}
                onClick={() => setDeleting(true)}
              >
                <Trash2 className="h-3.5 w-3.5" />
                {t('Delete')}
              </Button>
            </DisabledReasonTooltip>
          </div>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <h3 className="text-[13px] font-semibold text-foreground">
            {t('Release notes')}
          </h3>
          {notes.length === 0 ? (
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t('No release notes.')}
            </p>
          ) : (
            <dl className="mt-2 space-y-2">
              {notes.map(([locale, text]) => (
                <div
                  key={locale}
                  className="flex flex-col gap-1 sm:flex-row sm:gap-4"
                >
                  <dt
                    className="w-20 shrink-0 font-mono text-[12px] text-muted-foreground"
                    dir="ltr"
                  >
                    {locale}
                  </dt>
                  <dd className="min-w-0 whitespace-pre-wrap text-[13px] text-foreground">
                    {text}
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      </section>

      <section
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
        data-testid="release-builds"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Builds for this release')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Builds started for this release. Submit a ready build to a store from here.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {builds.length === 0 ? (
          <p className="px-6 py-4 text-[13px] text-muted-foreground">
            {t('No builds for this release yet.')}
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={`${HEAD_CLASS} ps-6`}>
                  {t('Build')}
                </TableHead>
                <TableHead className={HEAD_CLASS}>{t('Platform')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Version')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Created')}</TableHead>
                <TableHead
                  className={`${HEAD_CLASS} pe-6 text-end w-[140px]`}
                />
              </TableRow>
            </TableHeader>
            <TableBody>
              {builds.map((build) => {
                const target = targetFor(build.targetId)
                const ready = build.status === 'ready' && build.artifactId
                return (
                  <TableRow
                    key={build.$id}
                    data-testid={`release-build-${build.$id}`}
                  >
                    <TableCell className="px-4 py-3 ps-6">
                      <Link
                        to="/projects/$projectId/distribution/$appId/builds/$buildId"
                        params={{ projectId, appId, buildId: build.$id }}
                        className="font-mono text-[13px] text-foreground hover:underline"
                        dir="ltr"
                      >
                        {build.$id}
                      </Link>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="flex items-center gap-1.5 text-[13px]">
                        <PlatformIcon
                          platform={build.platform}
                          className="text-muted-foreground"
                        />
                        {platformLabel(build.platform)}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <BuildStatusBadge status={build.status} />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="font-mono text-[13px]" dir="ltr">
                        {buildVersion(build) || '-'}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip date={build.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 pe-6 text-end">
                      {ready ? (
                        <SubmitBuildButton
                          projectId={projectId}
                          appId={appId}
                          build={build}
                          allowed={canManage && showSubmissions}
                          targetExists={!!target}
                          onSubmit={() =>
                            target &&
                            setDraft({ target, artifactId: build.artifactId })
                          }
                        />
                      ) : null}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </section>

      <section className="space-y-4" data-testid="release-submissions">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Submissions')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-1">
            {t(
              'Each store target gets its own submissions. The status follows the store as it processes, reviews and releases the version.',
            )}
          </p>
        </div>
        {!showSubmissions ? (
          <div
            className="flex items-start gap-3 rounded-xl border border-dashed border-border px-6 py-5"
            data-testid="release-submissions-hidden"
          >
            <Lock className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p className="text-[13px] text-muted-foreground">
              {t(
                "You don't have permission to view submissions. Ask an organization owner for access.",
              )}
            </p>
          </div>
        ) : targets.length === 0 ? (
          <p className="text-[13px] text-muted-foreground">
            {t('Add a target to this app before submitting a release.')}
          </p>
        ) : (
          targets.map((target) => (
            <TargetSubmissions
              key={target.$id}
              projectId={projectId}
              target={target}
              submissions={submissions.filter(
                (submission) => submission.targetId === target.$id,
              )}
              canManage={canManage}
              onSubmit={(selected) => setDraft({ target: selected })}
            />
          ))
        )}
        <div
          className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3"
          data-testid="release-rollback-note"
        >
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Store releases cannot be rolled back. To stop a release, pause a Google Play staged rollout or an App Store phased release, or submit a previous artifact with a higher version code as a new submission.',
            )}
          </p>
        </div>
      </section>

      <UpdateRelease
        projectId={projectId}
        release={updating ? release : null}
        onOpenChange={setUpdating}
      />
      <DeleteRelease
        projectId={projectId}
        release={deleting ? release : null}
        onOpenChange={setDeleting}
        onDeleted={() =>
          void navigate({
            to: '/projects/$projectId/distribution/$appId/releases',
            params: { projectId, appId },
          })
        }
      />
      <CreateSubmission
        open={draft !== null}
        onOpenChange={(open) => {
          if (!open) setDraft(null)
        }}
        projectId={projectId}
        appId={appId}
        releaseId={release.$id}
        target={draft?.target ?? null}
        initialArtifactId={draft?.artifactId}
      />
    </div>
  )
}

function SubmitBuildButton({
  projectId,
  appId,
  build,
  allowed,
  targetExists,
  onSubmit,
}: {
  projectId: string
  appId: string
  build: DistributionBuild
  allowed: boolean
  targetExists: boolean
  onSubmit: () => void
}) {
  const t = useT()
  const { data: artifact, error } = useDistributionArtifact(
    projectId,
    appId,
    build.artifactId,
  )
  const blocker = buildSubmissionBlocker({
    allowed,
    targetExists,
    artifactStatus: error ? 'unavailable' : (artifact?.status ?? null),
  })
  return (
    <DisabledReasonTooltip reason={blocker ? t(blocker) : null}>
      <Button
        variant="outline"
        size="sm"
        className="h-8 gap-1.5 text-[13px]"
        disabled={blocker !== null}
        onClick={onSubmit}
        {...analyticsAttrs('distribution-create-submission')}
      >
        <Send className="h-3.5 w-3.5 rtl:-scale-x-100" />
        {t('Submit build')}
      </Button>
    </DisabledReasonTooltip>
  )
}
