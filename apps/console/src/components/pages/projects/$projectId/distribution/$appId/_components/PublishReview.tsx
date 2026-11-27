import { AlertCircle, CheckCircle2, Loader2, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type {
  DistributionArtifact,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  artifactVersion,
  type TargetProgress,
  type TargetSource,
} from '@/lib/distribution/publish'
import { useT } from '@/lib/i18n/translate'
import {
  PLATFORM_PROVIDERS,
  PlatformIcon,
  platformLabel,
} from '../../_components/platform'
import { ReleaseStatusBadge } from '../../_components/status-badges'
import { trackLabel } from '../../_components/target-options'

export type ReviewRelease = {
  version: string
  status: string | null
}

type PublishReviewProps = {
  release: ReviewRelease
  releaseError: string | null
  branch: string
  targets: DistributionTarget[]
  tracks: Record<string, string>
  sources: Record<string, TargetSource>
  artifacts: Record<string, DistributionArtifact>
  progress: Record<string, TargetProgress>
  running: boolean
  onRetry: (targetId: string) => void
}

export function PublishReview({
  release,
  releaseError,
  branch,
  targets,
  tracks,
  sources,
  artifacts,
  progress,
  running,
  onRetry,
}: PublishReviewProps) {
  const t = useT()

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border px-4 py-3">
        <span className="text-[13px] text-muted-foreground">
          {t('Release')}
        </span>
        <span dir="ltr" className="font-mono text-[13px] font-medium">
          {release.version}
        </span>
        {release.status ? (
          <ReleaseStatusBadge status={release.status} />
        ) : (
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('New')}
          </Badge>
        )}
      </div>
      {releaseError ? (
        <p
          role="alert"
          className="flex items-start gap-1.5 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-[13px] text-destructive"
        >
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span className="break-words">{releaseError}</span>
        </p>
      ) : null}
      <ul className="divide-y divide-border rounded-lg border border-border">
        {targets.map((target) => {
          const source = sources[target.$id]
          const artifact = artifacts[source.artifactId]
          const state = progress[target.$id] ?? { status: 'pending' }
          const apple = PLATFORM_PROVIDERS[target.platform] === 'apple'
          return (
            <li
              key={target.$id}
              data-testid={`publish-review-${target.platform}`}
              className="space-y-2 px-4 py-3"
            >
              <div className="flex items-center gap-2">
                <PlatformIcon platform={target.platform} />
                <span className="text-[13px] font-medium">
                  {platformLabel(target.platform)}
                </span>
                <span
                  dir="ltr"
                  className="min-w-0 truncate font-mono text-[12px] text-muted-foreground"
                >
                  {target.identifier}
                </span>
                <div className="ms-auto flex shrink-0 items-center gap-2">
                  <ProgressStatus
                    progress={state}
                    build={source.kind === 'build'}
                  />
                  {state.status === 'failed' ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-7 gap-1.5 text-[12px]"
                      onClick={() => onRetry(target.$id)}
                      disabled={running}
                    >
                      <RefreshCw className="h-3 w-3" />
                      {t('Retry')}
                    </Button>
                  ) : null}
                </div>
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 ps-6 text-[13px]">
                {source.kind === 'build' ? (
                  <Detail label={t('Source')}>
                    {t('Build from {branch}').replace('{branch}', branch)}
                  </Detail>
                ) : (
                  <>
                    <Detail label={t('Source')}>
                      {artifact ? (
                        <>
                          {artifact.name}{' '}
                          <span dir="ltr" className="text-muted-foreground">
                            {artifactVersion(artifact)}
                          </span>
                        </>
                      ) : (
                        source.artifactId
                      )}
                    </Detail>
                    <Detail label={t('Track')}>
                      {t(trackLabel(tracks[target.$id]))}
                    </Detail>
                    {apple ? (
                      <Detail label={t('After approval')}>
                        {source.automatic
                          ? t('Release automatically')
                          : t('Wait for a manual release')}
                      </Detail>
                    ) : null}
                  </>
                )}
              </dl>
              {source.kind === 'build' ? (
                <p className="ps-6 text-[12px] text-muted-foreground">
                  {t(
                    'Submit this build from the release page when it is ready.',
                  )}
                </p>
              ) : null}
              {state.status === 'failed' ? (
                <p
                  role="alert"
                  className="flex items-start gap-1.5 ps-6 text-[13px] text-destructive"
                >
                  <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                  <span className="break-words">{state.error}</span>
                </p>
              ) : null}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words text-foreground">{children}</dd>
    </>
  )
}

function ProgressStatus({
  progress,
  build,
}: {
  progress: TargetProgress
  build: boolean
}) {
  const t = useT()
  if (progress.status === 'running') {
    return (
      <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
        {build ? t('Starting build') : t('Submitting')}
      </span>
    )
  }
  if (progress.status === 'done') {
    return (
      <span className="flex items-center gap-1.5 text-[12px] text-foreground">
        <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
        {build ? t('Build started') : t('Submitted')}
      </span>
    )
  }
  if (progress.status === 'failed') {
    return (
      <span className="flex items-center gap-1.5 text-[12px] text-destructive">
        <AlertCircle className="h-3.5 w-3.5" />
        {t('Failed')}
      </span>
    )
  }
  return null
}
