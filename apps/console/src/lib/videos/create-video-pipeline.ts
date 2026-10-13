/**
 * Create a video end to end: get the source into Storage, register the video,
 * then queue renditions. Timeline generation is queued by create.
 *
 * Runs outside React so it keeps going when the wizard closes; callers follow
 * along through `onStep`.
 */

import { ID } from '@appwrite.io/console'
import type { Models, VideoOutput } from '@appwrite.io/console'
import type { QueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from '@/lib/react-query/hooks/dependencies'
import { videoKeys } from '@/lib/react-query/hooks/videos'
import { translate } from '@/lib/i18n/translate'
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

export type CreateVideoStepId = 'import' | 'upload' | 'create' | 'renditions'

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
  options: Pick<CreateVideoOptions, 'profileIds'>,
): CreateVideoStepId[] {
  const steps: CreateVideoStepId[] = []
  if (source.kind === 'demo') steps.push('import')
  if (source.kind !== 'storage') steps.push('upload')
  steps.push('create')
  if (options.profileIds.length > 0) steps.push('renditions')
  return steps
}

/** Thrown when a step before video creation fails; nothing usable exists yet. */
export class CreateVideoError extends Error {
  constructor(
    readonly step: CreateVideoStepId,
    message: string,
  ) {
    super(message)
  }
}

const UPLOAD_INCOMPLETE = 'The upload ended before the whole file was stored.'

/**
 * Copy the file into a new Blob before any preview `<video>` is unmounted.
 * The preview and the upload otherwise share one read, and tearing the
 * player down cuts that read off after the first buffered seconds.
 */
export async function snapshotFile(file: File): Promise<File> {
  const buffer = await file.arrayBuffer()
  if (buffer.byteLength !== file.size) {
    throw new Error(translate(UPLOAD_INCOMPLETE))
  }
  return new File([buffer], file.name, {
    type: file.type,
    lastModified: file.lastModified,
  })
}

async function uploadSource(
  projectId: string,
  bucketId: string,
  file: File,
  onProgress: (progress: number) => void,
): Promise<Models.File> {
  const uploaded = await sdk.forProject(projectId).storage.createFile({
    bucketId,
    fileId: ID.unique(),
    file,
    onProgress: (event) => onProgress(event.progress),
  })
  const chunksTotal = Number(uploaded.chunksTotal) || 0
  const chunksUploaded = Number(uploaded.chunksUploaded) || 0
  const sizeOriginal = Number(uploaded.sizeOriginal) || 0
  if (
    (chunksTotal > 0 && chunksUploaded < chunksTotal) ||
    (file.size > 0 && sizeOriginal > 0 && sizeOriginal !== file.size)
  ) {
    throw new Error(translate(UPLOAD_INCOMPLETE))
  }
  return uploaded
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

  // 2. Register the video (timeline generation is queued automatically).
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
  markTimelinePending(projectId, video.$id)
  void queryClient.invalidateQueries({
    queryKey: videoKeys.timeline(projectId, video.$id),
  })

  const failedSteps: CreateVideoStepId[] = []
  if (options.profileIds.length === 0) {
    return { video, failedSteps, renditionsQueued: 0 }
  }

  let renditionsQueued = 0
  await runStep('renditions', async (onProgress) => {
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
  }).catch(() => {
    failedSteps.push('renditions')
  })
  void queryClient.refetchQueries({
    queryKey: videoKeys.renditions(projectId, video.$id),
  })
  return { video, failedSteps, renditionsQueued }
}
