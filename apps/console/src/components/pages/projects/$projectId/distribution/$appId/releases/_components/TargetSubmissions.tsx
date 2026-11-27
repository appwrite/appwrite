import { useEffect, useState, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Pause, Percent, Play, Rocket, Send, XCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type {
  DistributionSubmission,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import type { AnalyticsActionId } from '@/lib/analytics-actions'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { rolloutPercent, submissionActions } from '@/lib/distribution/status'
import { useT, type Translator } from '@/lib/i18n/translate'
import { PlatformIcon } from '../../../_components/platform'
import { DisabledReasonTooltip } from '../../../_components/DisabledReasonTooltip'
import { SubmissionStatusBadge } from '../../../_components/status-badges'
import { TARGET_TITLES, trackLabel } from '../../../_components/target-options'
import { UpdateRollout } from './UpdateRollout'
import {
  CONTROL_LABELS,
  UpdateSubmissionStatus,
  type SubmissionControl,
  type SubmissionControlRequest,
} from './UpdateSubmissionStatus'

const STEP_LABELS: Record<string, string> = {
  validated: 'Validated',
  located: 'Located',
  uploaded: 'Uploaded',
  processed: 'Processed',
  released: 'Released',
  settled: 'Settled',
}

const CONTROL_ICONS: Record<SubmissionControl, LucideIcon> = {
  cancel: XCircle,
  halt: Pause,
  resume: Play,
  publish: Rocket,
}

const CONTROL_ANALYTICS: Record<SubmissionControl, AnalyticsActionId> = {
  cancel: 'distribution-cancel-submission',
  halt: 'distribution-halt-submission',
  resume: 'distribution-resume-submission',
  publish: 'distribution-publish-submission',
}

type Fact = { label: string; value: ReactNode }

function trackName(track: string, t: Translator): string {
  const label = trackLabel(track)
  return label === track ? track : t(label)
}

function stepLabel(step: string, t: Translator): ReactNode {
  if (!step) return '-'
  if (Object.hasOwn(STEP_LABELS, step)) return t(STEP_LABELS[step])
  return (
    <span className="font-mono" dir="ltr">
      {step}
    </span>
  )
}

function availableControls(submission: DistributionSubmission): {
  controls: SubmissionControl[]
  rollout: boolean
} {
  const actions = submissionActions(submission)
  const controls: SubmissionControl[] = [
    ...(actions.halt ? (['halt'] as const) : []),
    ...(actions.resume ? (['resume'] as const) : []),
    ...(actions.publish ? (['publish'] as const) : []),
    ...(actions.cancel ? (['cancel'] as const) : []),
  ]
  return { controls, rollout: actions.rollout }
}

type TargetSubmissionsProps = {
  projectId: string
  target: DistributionTarget
  submissions: DistributionSubmission[]
  canManage: boolean
  onSubmit: (target: DistributionTarget) => void
}

export function TargetSubmissions({
  projectId,
  target,
  submissions,
  canManage,
  onSubmit,
}: TargetSubmissionsProps) {
  const t = useT()
  const permissionReason = canManage
    ? null
    : t("You don't have permission to manage submissions.")
  const [request, setRequest] = useState<SubmissionControlRequest | null>(null)
  const [rolloutFor, setRolloutFor] = useState<DistributionSubmission | null>(
    null,
  )
  const [requested, setRequested] = useState<Record<string, string>>({})

  useEffect(() => {
    setRequested((current) => {
      const pending = Object.entries(current).filter(([id, status]) =>
        submissions.some(
          (submission) => submission.$id === id && submission.status === status,
        ),
      )
      return pending.length === Object.keys(current).length
        ? current
        : Object.fromEntries(pending)
    })
  }, [submissions])

  return (
    <section
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
      data-testid={`target-submissions-${target.$id}`}
    >
      <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <PlatformIcon platform={target.platform} />
          </div>
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t(TARGET_TITLES[target.platform])}
            </h3>
            <p
              className="truncate font-mono text-[12px] text-muted-foreground"
              dir="ltr"
            >
              {target.identifier}
            </p>
          </div>
        </div>
        <DisabledReasonTooltip reason={permissionReason}>
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-1.5 text-[13px]"
            disabled={!canManage}
            onClick={() => onSubmit(target)}
            {...analyticsAttrs('distribution-create-submission')}
          >
            <Send className="h-4 w-4 rtl:-scale-x-100" />
            {t('Submit release')}
          </Button>
        </DisabledReasonTooltip>
      </div>
      <div className="border-t border-border" />
      {submissions.length === 0 ? (
        <p className="px-6 py-4 text-[13px] text-muted-foreground">
          {t('No submissions for this target yet.')}
        </p>
      ) : (
        <ul className="divide-y divide-border">
          {submissions.map((submission) => {
            const { controls, rollout } = availableControls(submission)
            const changeRequested =
              requested[submission.$id] === submission.status
            const facts: Fact[] = [
              ...(submission.provider === 'google'
                ? [
                    {
                      label: t('Rollout'),
                      value: (
                        <span dir="ltr">
                          {rolloutPercent(submission.rollout)}%
                        </span>
                      ),
                    },
                  ]
                : [
                    {
                      label: t('Release mode'),
                      value: submission.automatic
                        ? t('Automatic')
                        : t('Manual'),
                    },
                  ]),
              {
                label: t('Step'),
                value: stepLabel(submission.step, t),
              },
              {
                label: t('Store status'),
                value: submission.storeStatus ? (
                  <span className="font-mono" dir="ltr">
                    {submission.storeStatus}
                  </span>
                ) : (
                  '-'
                ),
              },
              {
                label: t('Submitted on'),
                value: submission.submittedAt ? (
                  <DateTooltip date={submission.submittedAt} />
                ) : (
                  '-'
                ),
              },
              {
                label: t('Reviewed on'),
                value: submission.reviewedAt ? (
                  <DateTooltip date={submission.reviewedAt} />
                ) : (
                  '-'
                ),
              },
              {
                label: t('Published on'),
                value: submission.publishedAt ? (
                  <DateTooltip date={submission.publishedAt} />
                ) : (
                  '-'
                ),
              },
            ]
            return (
              <li
                key={submission.$id}
                className="space-y-3 px-6 py-4"
                data-testid={`submission-${submission.$id}`}
              >
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-[14px] font-medium text-foreground">
                        {trackName(submission.track, t)}
                      </span>
                      <span data-testid="submission-status">
                        <SubmissionStatusBadge status={submission.status} />
                      </span>
                      {changeRequested ? (
                        <Badge
                          variant="info"
                          className="text-[10px] shrink-0"
                          data-testid="submission-change-requested"
                        >
                          {t('Change requested')}
                        </Badge>
                      ) : null}
                    </div>
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-[12px] sm:grid-cols-[repeat(3,minmax(0,13rem))]">
                      {facts.map((fact) => (
                        <div key={fact.label} className="min-w-0">
                          <dt className="text-muted-foreground">
                            {fact.label}
                          </dt>
                          <dd className="truncate text-foreground">
                            {fact.value}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  </div>
                  {controls.length > 0 || rollout ? (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                      {rollout ? (
                        <DisabledReasonTooltip reason={permissionReason}>
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 gap-1.5 text-[13px]"
                            disabled={!canManage || changeRequested}
                            onClick={() => setRolloutFor(submission)}
                            {...analyticsAttrs('distribution-update-rollout')}
                          >
                            <Percent className="h-3.5 w-3.5" />
                            {t('Update rollout')}
                          </Button>
                        </DisabledReasonTooltip>
                      ) : null}
                      {controls.map((control) => {
                        const Icon = CONTROL_ICONS[control]
                        return (
                          <DisabledReasonTooltip
                            key={control}
                            reason={permissionReason}
                          >
                            <Button
                              variant="outline"
                              size="sm"
                              className="h-8 gap-1.5 text-[13px]"
                              disabled={!canManage || changeRequested}
                              onClick={() =>
                                setRequest({ submission, control })
                              }
                              {...analyticsAttrs(CONTROL_ANALYTICS[control])}
                            >
                              <Icon className="h-3.5 w-3.5" />
                              {t(CONTROL_LABELS[control])}
                            </Button>
                          </DisabledReasonTooltip>
                        )
                      })}
                    </div>
                  ) : null}
                </div>
                {submission.errorMessage ? (
                  <div
                    role="alert"
                    className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
                  >
                    <p>{submission.errorMessage}</p>
                    {submission.errorCode ? (
                      <p className="mt-1 font-mono opacity-80" dir="ltr">
                        {submission.errorCode}
                      </p>
                    ) : null}
                  </div>
                ) : null}
              </li>
            )
          })}
        </ul>
      )}
      <UpdateSubmissionStatus
        projectId={projectId}
        request={request}
        onOpenChange={(open) => {
          if (!open) setRequest(null)
        }}
        onRequested={(submission) =>
          setRequested((current) => ({
            ...current,
            [submission.$id]: submission.status,
          }))
        }
      />
      <UpdateRollout
        projectId={projectId}
        submission={rolloutFor}
        onOpenChange={(open) => {
          if (!open) setRolloutFor(null)
        }}
      />
    </section>
  )
}
