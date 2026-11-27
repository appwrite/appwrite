import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type {
  DistributionBuild,
  DistributionRelease,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  buildDuration,
  buildVersion,
  formatDuration,
  shortCommit,
  stageLabel,
  triggerLabel,
} from '@/lib/distribution/builds'
import {
  BUILD_STATUS,
  isBuildActive,
  statusAppearance,
} from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'
import { PlatformIcon, platformLabel } from '../../../_components/platform'
import { BuildStatusBadge } from '../../../_components/status-badges'
import { BuildSource } from './BuildSource'

type BuildSummaryProps = {
  projectId: string
  build: DistributionBuild
  target: DistributionTarget | undefined
  release: DistributionRelease | undefined
  now: number
}

const LINK_CLASSNAME =
  'truncate font-mono text-[13px] text-foreground underline-offset-4 hover:underline'

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0 space-y-1">
      <dt className="text-[12px] text-muted-foreground">{label}</dt>
      <dd className="flex min-w-0 items-center gap-1.5 text-[13px] text-foreground">
        {children}
      </dd>
    </div>
  )
}

function Empty() {
  return <span className="text-muted-foreground">-</span>
}

function Timestamp({ date }: { date: string | null }) {
  return date ? (
    <DateTooltip date={date} className="text-[13px] text-foreground" />
  ) : (
    <Empty />
  )
}

export function BuildSummary({
  projectId,
  build,
  target,
  release,
  now,
}: BuildSummaryProps) {
  const t = useT()
  const version = buildVersion(build)
  const duration = buildDuration(build, now)
  const params = { projectId, appId: build.appId }
  const stage = stageLabel(build.stage)
  const trigger = triggerLabel(build.type)
  const showsStage =
    build.stage !== '' &&
    isBuildActive(build.status) &&
    (stage ?? build.stage) !==
      statusAppearance(build.status, BUILD_STATUS).label

  return (
    <section
      data-testid="build-summary"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Summary')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <dl className="grid gap-x-6 gap-y-4 px-6 py-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label={t('Status')}>
          <BuildStatusBadge status={build.status} />
          {showsStage ? (
            <span className="truncate text-[12px] text-muted-foreground">
              {stage ? t(stage) : build.stage}
            </span>
          ) : null}
        </Field>
        <Field label={t('Platform')}>
          <PlatformIcon
            platform={build.platform}
            className="text-muted-foreground"
          />
          {platformLabel(build.platform)}
        </Field>
        <Field label={t('Identifier')}>
          {target ? (
            <span dir="ltr" className="truncate font-mono">
              {target.identifier}
            </span>
          ) : (
            <Empty />
          )}
        </Field>
        <Field label={t('Version')}>
          {version ? (
            <span dir="ltr" className="truncate font-mono">
              {version}
            </span>
          ) : (
            <Empty />
          )}
        </Field>
        <Field label={t('Trigger')}>{trigger ? t(trigger) : build.type}</Field>
        <Field label={t('Source')}>
          <BuildSource build={build} linked />
        </Field>
        <Field label={t('Commit')}>
          {build.providerCommitHash ? (
            <span className="flex min-w-0 items-center gap-1.5">
              {build.providerCommitUrl ? (
                <a
                  href={build.providerCommitUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  dir="ltr"
                  className={`${LINK_CLASSNAME} shrink-0`}
                >
                  {shortCommit(build.providerCommitHash)}
                </a>
              ) : (
                <span dir="ltr" className="shrink-0 font-mono">
                  {shortCommit(build.providerCommitHash)}
                </span>
              )}
              {build.providerCommitMessage ? (
                <span className="truncate text-muted-foreground">
                  {build.providerCommitMessage}
                </span>
              ) : null}
            </span>
          ) : (
            <Empty />
          )}
        </Field>
        <Field label={t('Duration')}>
          {duration > 0 ? (
            <span dir="ltr" className="font-mono">
              {formatDuration(duration)}
            </span>
          ) : (
            <Empty />
          )}
        </Field>
        <Field label={t('Created')}>
          <Timestamp date={build.$createdAt} />
        </Field>
        <Field label={t('Start time')}>
          <Timestamp date={build.startedAt} />
        </Field>
        <Field label={t('Artifact')}>
          {build.artifactId ? (
            <Link
              to="/projects/$projectId/distribution/$appId/artifacts"
              params={params}
              dir="ltr"
              className={LINK_CLASSNAME}
            >
              {build.artifactId}
            </Link>
          ) : (
            <Empty />
          )}
        </Field>
        <Field label={t('Release')}>
          {build.releaseId ? (
            <Link
              to="/projects/$projectId/distribution/$appId/releases/$releaseId"
              params={{ ...params, releaseId: build.releaseId }}
              dir="ltr"
              className={LINK_CLASSNAME}
            >
              {release?.version ?? build.releaseId}
            </Link>
          ) : (
            <Empty />
          )}
        </Field>
      </dl>
    </section>
  )
}
