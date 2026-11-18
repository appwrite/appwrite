import type { Models } from '@appwrite.io/console'
import { AlertCircle, Check, Circle, Loader2 } from 'lucide-react'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { cn } from '@/lib/utils'
import {
  isVideoRenditionActive,
  parseVideoProgress,
} from '@/lib/react-query/hooks/videos'
import type {
  CreateVideoStepId,
  CreateVideoStepState,
  CreateVideoStepStatus,
} from '@/lib/videos/create-video-pipeline'
import { useT } from '@/lib/i18n/translate'
import { VideoStatusBadge } from './VideoStatusBadge'

export const CREATE_VIDEO_STEP_COPY: Record<
  CreateVideoStepId,
  { title: string; description: string }
> = {
  import: {
    title: 'Download demo video',
    description: 'Fetch the open movie in your browser.',
  },
  upload: {
    title: 'Upload to Storage',
    description: 'Store the source file in your bucket.',
  },
  create: {
    title: 'Register video',
    description: 'Create the video from the source file.',
  },
  renditions: {
    title: 'Queue renditions',
    description: 'Start encoding one rendition per selected profile.',
  },
}

const SETUP_STEPS = new Set<CreateVideoStepId>(['import', 'upload', 'create'])

export type CreateVideoProgressProfile = {
  id: string
  name: string
}

function stepStatus(
  step: CreateVideoStepId,
  states: Partial<Record<CreateVideoStepId, CreateVideoStepState>>,
): CreateVideoStepStatus {
  return states[step]?.status ?? 'pending'
}

function StepMark({ status }: { status: CreateVideoStepStatus }) {
  if (status === 'done' || status === 'skipped') {
    return (
      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-foreground text-background">
        <Check className="h-3.5 w-3.5" />
      </span>
    )
  }
  if (status === 'error') {
    return (
      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <AlertCircle className="h-3.5 w-3.5" />
      </span>
    )
  }
  if (status === 'active') {
    return (
      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
      </span>
    )
  }
  return (
    <span className="flex h-6 w-6 items-center justify-center text-muted-foreground">
      <Circle className="h-3.5 w-3.5" />
    </span>
  )
}

function statusHeading(
  phase: 'running' | 'encoding' | 'done' | 'failed',
  hasFailedSteps: boolean,
  encoding: boolean,
  willEncode: boolean,
): { title: string; detail: string } {
  if (phase === 'failed') {
    return {
      title: 'Video could not be created',
      detail:
        'The video was not created. Go back to adjust the source or try again.',
    }
  }
  if (phase === 'done' && hasFailedSteps) {
    return {
      title: 'Video created with issues',
      detail: 'Open the video to retry the steps that failed.',
    }
  }
  if (phase === 'done') {
    return {
      title: 'Video created',
      detail: 'Open the video when you are ready.',
    }
  }
  if (encoding || phase === 'encoding') {
    return {
      title: 'Encoding renditions',
      detail: 'You can leave this screen. Encoding continues on the video.',
    }
  }
  return {
    title: 'Creating your video',
    detail: willEncode
      ? 'The source is saved first. Renditions start after that.'
      : 'Saving the source and registering the video.',
  }
}

export function CreateVideoProgress({
  steps,
  states,
  phase,
  hasFailedSteps,
  profiles,
  renditions,
  errorMessage,
}: {
  steps: CreateVideoStepId[]
  states: Partial<Record<CreateVideoStepId, CreateVideoStepState>>
  phase: 'running' | 'encoding' | 'done' | 'failed'
  hasFailedSteps: boolean
  profiles: CreateVideoProgressProfile[]
  renditions: Models.VideoRendition[]
  errorMessage?: string
}) {
  const t = useT()
  const setupSteps = steps.filter((step) => SETUP_STEPS.has(step))
  const showRenditions = steps.includes('renditions') && profiles.length > 0
  const byProfile = new Map(renditions.map((rendition) => [rendition.profileId, rendition]))
  const readyCount = renditions.filter((rendition) => rendition.status === 'ready').length
  const encoding =
    phase === 'encoding' ||
    renditions.some((rendition) => isVideoRenditionActive(rendition.status))
  const heading = statusHeading(phase, hasFailedSteps, encoding, showRenditions)
  const erroredStep = [...setupSteps]
    .reverse()
    .find((step) => states[step]?.status === 'error')
  const rawError = errorMessage || (erroredStep ? states[erroredStep]?.error : undefined)

  return (
    <div className="mx-auto w-full min-w-0 max-w-xl space-y-6">
      <div>
        <h2 className="text-[20px] font-semibold leading-7 tracking-tight text-foreground">
          {t(heading.title)}
        </h2>
        <p
          className={cn(
            'mt-1.5 min-h-10 text-[13px] leading-5',
            rawError ? 'text-destructive' : 'text-muted-foreground',
          )}
        >
          {rawError ?? t(heading.detail)}
        </p>
      </div>

      <ol className="overflow-hidden rounded-xl border border-border bg-card/50">
        {setupSteps.map((step, index) => {
          const status = stepStatus(step, states)
          const copy = CREATE_VIDEO_STEP_COPY[step]
          const showBar = step === 'import' || step === 'upload'
          const progress =
            status === 'done' || status === 'skipped'
              ? 100
              : (states[step]?.progress ?? 0)
          return (
            <li
              key={step}
              className={cn(
                'flex items-start gap-3 px-4 py-3',
                index > 0 && 'border-t border-border',
              )}
            >
              <StepMark status={status} />
              <div className="min-w-0 flex-1 pt-0.5">
                <p
                  className={cn(
                    'text-[13px] font-medium',
                    status === 'pending'
                      ? 'text-muted-foreground'
                      : 'text-foreground',
                  )}
                >
                  {t(copy.title)}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {t(copy.description)}
                </p>
                {showBar ? (
                  <ProgressBarRow value={progress} className="mb-0 mt-2" />
                ) : null}
              </div>
            </li>
          )
        })}
      </ol>

      {showRenditions ? (
        <section className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-border px-4 py-3">
            <h3 className="text-[13px] font-semibold text-foreground">
              {t('Renditions')}
            </h3>
            <p className="text-[12px] text-muted-foreground">
              {t('{ready} of {total} ready')
                .replace('{ready}', String(readyCount))
                .replace('{total}', String(profiles.length))}
            </p>
          </div>
          <ul>
            {profiles.map((profile, index) => {
              const rendition = byProfile.get(profile.id)
              const value = !rendition
                ? 0
                : rendition.status === 'ready'
                  ? 100
                  : parseVideoProgress(rendition.progress)
              const resolution =
                rendition && rendition.width > 0 && rendition.height > 0
                  ? `${rendition.width}×${rendition.height}`
                  : null
              return (
                <li
                  key={profile.id}
                  className={cn('px-4 py-3', index > 0 && 'border-t border-border')}
                >
                  <div className="flex min-w-0 items-center justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex min-w-0 items-center gap-2">
                        <p className="min-w-0 flex-1 truncate text-[13px] font-medium leading-5 text-foreground">
                          {rendition?.name || profile.name}
                        </p>
                        <p className="hidden w-[5.5rem] shrink-0 text-end font-mono text-[11px] leading-5 whitespace-nowrap text-muted-foreground sm:block">
                          {resolution ?? ''}
                        </p>
                      </div>
                      {resolution ? (
                        <p className="mt-0.5 font-mono text-[11px] leading-4 text-muted-foreground sm:hidden">
                          {resolution}
                        </p>
                      ) : null}
                    </div>
                    <VideoStatusBadge
                      kind="rendition"
                      status={rendition?.status ?? 'pending'}
                    />
                  </div>
                  <ProgressBarRow value={value} className="mb-0 mt-2" />
                </li>
              )
            })}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
