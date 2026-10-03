/**
 * Create a video end to end: get the source into Storage, register the video,
 * prepare its working copy, then queue the timeline and renditions.
 *
 * Runs outside React so it keeps going when the wizard closes; callers follow
 * along through `onStep`.
 */

import { AppwriteException, ID } from '@appwrite.io/console'
import type { Models, VideoOutput } from '@appwrite.io/console'
import type { QueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from '@/lib/react-query/hooks/dependencies'
import { videoKeys } from '@/lib/react-query/hooks/videos'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  downloadDemoVideo,
  type DemoVideo,
  type DemoVideoVariant,
} from './demo-videos'
import { markTimelinePending } from './pending-timelines'

export type CreateVideoSource =
  | { kind: 'storage'; bucketId: string; fileId: string }
  | { kind: 'upload'; bucketId: string; file: File }
  | {
      kind: 'demo'
      bucketId: string
      demo: DemoVideo
      variant: DemoVideoVariant
    }

export type CreateVideoStepId =
  | 'import'
  | 'upload'
  | 'create'
  | 'prepare'
  | 'timeline'
  | 'renditions'

export type CreateVideoStepStatus =
  | 'pending'
  | 'active'
  | 'done'
  | 'skipped'
  | 'error'

export type CreateVideoStepState = {
  status: CreateVideoStepStatus
  /** 0-100 when the step reports progress. */
  progress?: number
  error?: string
}

export type CreateVideoOptions = {
  timeline: boolean
  output: VideoOutput
  profileIds: string[]
}

export type CreateVideoResult = {
  video: Models.Video
  /** Steps that failed after the video was created. */
  failedSteps: CreateVideoStepId[]
  renditionsQueued: number
}

/** Steps shown for a source and option set, in run order. */
export function getCreateVideoSteps(
  source: Pick<CreateVideoSource, 'kind'>,
  options: Pick<CreateVideoOptions, 'timeline' | 'profileIds'>,
): CreateVideoStepId[] {
  const steps: CreateVideoStepId[] = []
  if (source.kind === 'demo') steps.push('import')
  if (source.kind !== 'storage') steps.push('upload')
  steps.push('create')
  if (options.timeline || options.profileIds.length > 0) steps.push('prepare')
  if (options.timeline) steps.push('timeline')
  if (options.profileIds.length > 0) steps.push('renditions')
  return steps
}

const POLL_INTERVAL_MS = 2000
const PREPARE_TIMEOUT_MS = 30 * 60 * 1000

const sleep = (ms: number) =>
  new Promise<void>((resolve) => window.setTimeout(resolve, ms))

/** Thrown when a step before video creation fails; nothing usable exists yet. */
export class CreateVideoError extends Error {
  constructor(
    readonly step: CreateVideoStepId,
    message: string,
  ) {
    super(message)
  }
}

async function uploadSource(
  projectId: string,
  bucketId: string,
  file: File,
  onProgress: (progress: number) => void,
): Promise<Models.File> {
  return await sdk.forProject(projectId).storage.createFile({
    bucketId,
    fileId: ID.unique(),
    file,
    onProgress: (event) => onProgress(event.progress),
  })
}

/**
 * Ask the worker to materialise the working copy and wait for `ready`.
 * Returns `null` when the server predates create-source (renditions and
 * timelines probe the source themselves there).
 */
async function prepareSource(
  projectId: string,
  video: Models.Video,
  onProgress: (progress: number) => void,
): Promise<Models.Video | null> {
  const videos = sdk.forProject(projectId).videos
  let current = video
  try {
    current = await videos.createSource({ videoId: video.$id })
  } catch (error) {
    if (!(error instanceof AppwriteException)) throw error
    // Older servers have no route; their edge may answer with a non-JSON 404
    // that leaves `type` empty.
    if (
      error.type === 'general_route_not_found' ||
      (error.code === 404 && !error.type)
    ) {
      return null
    }
    if (
      error.type !== 'video_source_in_progress' &&
      error.type !== 'video_source_already_exists'
    ) {
      throw error
    }
    current = await videos.get({ videoId: video.$id })
  }

  const deadline = Date.now() + PREPARE_TIMEOUT_MS
  for (;;) {
    if (current.status === 'ready') {
      onProgress(100)
      return current
    }
    if (current.status === 'error' || current.status === 'aborted') {
      throw new Error('The source file could not be prepared.')
    }
    if (current.chunksTotal > 0) {
      onProgress((current.chunksUploaded / current.chunksTotal) * 100)
    }
    if (Date.now() > deadline) {
      throw new Error('Preparing the source is taking longer than expected.')
    }
    await sleep(POLL_INTERVAL_MS)
    current = await videos.get({ videoId: video.$id })
  }
}

export async function runCreateVideoPipeline(input: {
  projectId: string
  queryClient: QueryClient
  source: CreateVideoSource
  name: string
  options: CreateVideoOptions
  onStep: (step: CreateVideoStepId, state: CreateVideoStepState) => void
  onVideo?: (video: Models.Video) => void
}): Promise<CreateVideoResult> {
  const { projectId, queryClient, source, options, onStep } = input
  const videos = sdk.forProject(projectId).videos

  const runStep = async <T>(
    step: CreateVideoStepId,
    fn: (onProgress: (progress: number) => void) => Promise<T>,
  ): Promise<T> => {
    onStep(step, { status: 'active', progress: 0 })
    try {
      const result = await fn((progress) =>
        onStep(step, { status: 'active', progress }),
      )
      onStep(step, { status: 'done', progress: 100 })
      return result
    } catch (error) {
      onStep(step, { status: 'error', error: getErrorMessage(error) })
      throw error
    }
  }

  // 1. Get the source file into Storage.
  let bucketId = source.bucketId
  let fileId: string
  try {
    if (source.kind === 'storage') {
      fileId = source.fileId
    } else {
      const file =
        source.kind === 'demo'
          ? await runStep('import', (onProgress) =>
              downloadDemoVideo(source.demo, source.variant, onProgress),
            )
          : source.file
      const uploaded = await runStep('upload', (onProgress) =>
        uploadSource(projectId, source.bucketId, file, onProgress),
      )
      bucketId = uploaded.bucketId
      fileId = uploaded.$id
      void queryClient.invalidateQueries({
        queryKey: ['files', 'project', projectId, 'bucket', bucketId],
      })
    }
  } catch (error) {
    throw new CreateVideoError(
      source.kind === 'demo' ? 'import' : 'upload',
      getErrorMessage(error),
    )
  }

  // 2. Register the video.
  let video: Models.Video
  try {
    video = await runStep('create', () =>
      videos.create({
        bucketId,
        fileId,
        name: input.name.trim() || undefined,
      }),
    )
  } catch (error) {
    throw new CreateVideoError('create', getErrorMessage(error))
  }
  queryClient.setQueryData(videoKeys.detail(projectId, video.$id), video)
  void queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
  input.onVideo?.(video)

  const failedSteps: CreateVideoStepId[] = []
  const skipRemaining = () => {
    if (options.timeline) onStep('timeline', { status: 'skipped' })
    if (options.profileIds.length > 0) {
      onStep('renditions', { status: 'skipped' })
    }
  }
  if (!options.timeline && options.profileIds.length === 0) {
    return { video, failedSteps, renditionsQueued: 0 }
  }

  // 3. Prepare the working copy.
  let probed = false
  try {
    const prepared = await runStep('prepare', (onProgress) =>
      prepareSource(projectId, video, onProgress),
    )
    if (prepared) {
      video = prepared
      probed = true
      queryClient.setQueryData(videoKeys.detail(projectId, video.$id), video)
    } else {
      onStep('prepare', { status: 'skipped' })
    }
  } catch {
    failedSteps.push('prepare')
    skipRemaining()
    return { video, failedSteps, renditionsQueued: 0 }
  }

  // 4. Timeline and renditions are independent; run both.
  const audioOnly = probed && !video.width && !video.height
  const timelineTask = options.timeline
    ? audioOnly
      ? Promise.resolve(onStep('timeline', { status: 'skipped' }))
      : runStep('timeline', () =>
          videos.createTimeline({ videoId: video.$id }),
        ).then(
          () => {
            markTimelinePending(projectId, video.$id)
            return queryClient.invalidateQueries({
              queryKey: videoKeys.timeline(projectId, video.$id),
            })
          },
          () => {
            failedSteps.push('timeline')
          },
        )
    : Promise.resolve()

  let renditionsQueued = 0
  const renditionsTask =
    options.profileIds.length > 0
      ? runStep('renditions', async (onProgress) => {
          let settled = 0
          const results = await Promise.allSettled(
            options.profileIds.map((profileId) =>
              videos
                .createRendition({
                  videoId: video.$id,
                  profileId,
                  output: options.output,
                })
                .finally(() => {
                  settled += 1
                  onProgress((settled / options.profileIds.length) * 100)
                }),
            ),
          )
          const rejected = results.filter(
            (r): r is PromiseRejectedResult => r.status === 'rejected',
          )
          renditionsQueued = results.length - rejected.length
          if (rejected.length > 0) throw rejected[0].reason
        }).then(
          () => undefined,
          () => {
            failedSteps.push('renditions')
          },
        )
      : Promise.resolve()

  await Promise.all([timelineTask, renditionsTask])
  if (options.profileIds.length > 0) {
    void queryClient.refetchQueries({
      queryKey: videoKeys.renditions(projectId, video.$id),
    })
  }
  return { video, failedSteps, renditionsQueued }
}
