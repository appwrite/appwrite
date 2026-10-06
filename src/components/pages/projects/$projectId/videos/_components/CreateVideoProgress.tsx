import {
  AlertCircle,
  CheckCircle2,
  Circle,
  Loader2,
  MinusCircle,
} from 'lucide-react'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import type {
  CreateVideoStepId,
  CreateVideoStepState,
} from '@/lib/videos/create-video-pipeline'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

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
  timeline: {
    title: 'Generate timeline',
    description: 'Queue sprite sheet thumbnails for scrubbing previews.',
  },
  renditions: {
    title: 'Queue renditions',
    description: 'Start encoding one rendition per selected profile.',
  },
}

/** Steps that report a meaningful percentage. */
const PROGRESS_STEPS = new Set<CreateVideoStepId>(['import', 'upload'])

function StepIcon({ status }: { status: CreateVideoStepState['status'] }) {
  switch (status) {
    case 'active':
      return <Loader2 className="h-4 w-4 animate-spin text-foreground" />
    case 'done':
      return <CheckCircle2 className="h-4 w-4 text-green-600" />
    case 'error':
      return <AlertCircle className="h-4 w-4 text-destructive" />
    case 'skipped':
      return <MinusCircle className="h-4 w-4 text-muted-foreground" />
    default:
      return <Circle className="h-4 w-4 text-muted-foreground/50" />
  }
}

export function CreateVideoProgress({
  steps,
  states,
  phase,
  hasFailedSteps,
}: {
  steps: CreateVideoStepId[]
  states: Partial<Record<CreateVideoStepId, CreateVideoStepState>>
  phase: 'running' | 'done' | 'failed'
  hasFailedSteps: boolean
}) {
  const t = useT()
  const heading =
    phase === 'running'
      ? t('Creating your video')
      : phase === 'failed'
        ? t('Video could not be created')
        : hasFailedSteps
          ? t('Video created with issues')
          : t('Video created')
  const description =
    phase === 'running'
      ? t(
          'Keep this window open while the file uploads. Once the video exists, you can open it and the remaining steps continue in the background.',
        )
      : phase === 'failed'
        ? t(
            'The video was not created. Go back to adjust the source or try again.',
          )
        : t('Open the video to retry the steps that failed.')

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-[18px] font-semibold tracking-tight text-foreground">
          {heading}
        </h2>
        <p className="mt-1.5 max-w-xl text-[13px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
      <ol className="overflow-hidden rounded-xl border border-border bg-card/50">
        {steps.map((step, index) => {
          const state = states[step] ?? { status: 'pending' as const }
          const copy = CREATE_VIDEO_STEP_COPY[step]
          return (
            <li
              key={step}
              className={cn(
                'flex gap-3 px-4 py-3.5',
                index > 0 && 'border-t border-border',
                state.status === 'active' && 'bg-muted/30',
              )}
            >
              <span className="mt-0.5 shrink-0">
                <StepIcon status={state.status} />
              </span>
              <div className="min-w-0 flex-1">
                <p
                  className={cn(
                    'text-[13px] font-medium',
                    state.status === 'pending'
                      ? 'text-muted-foreground'
                      : 'text-foreground',
                  )}
                >
                  {t(copy.title)}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {state.status === 'error' && state.error
                    ? state.error
                    : state.status === 'skipped'
                      ? t('Skipped')
                      : t(copy.description)}
                </p>
                {state.status === 'active' && PROGRESS_STEPS.has(step) ? (
                  <ProgressBarRow
                    value={state.progress ?? 0}
                    className="mt-2.5 mb-0"
                  />
                ) : null}
              </div>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
