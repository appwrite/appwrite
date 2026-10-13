/**
 * Console UI calls text tracks captions. The Console SDK on main still
 * names them subtitles (`listSubtitles`, `VideoSubtitle`, …). This maps
 * the two until the SDK rename lands.
 */

import type { Models, VideoOutput } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

declare module '@appwrite.io/console' {
  namespace Models {
    export type VideoCaption = VideoSubtitle
    export type VideoCaptionList = {
      total: number
      captions: VideoSubtitle[]
    }
  }
}

export type VideoCaption = Models.VideoSubtitle

export type VideoCaptionList = {
  total: number
  captions: VideoCaption[]
}

function fromSubtitleList(list: Models.VideoSubtitleList): VideoCaptionList {
  return { total: list.total, captions: list.subtitles }
}

export async function listVideoCaptions(
  projectId: string,
  videoId: string,
): Promise<VideoCaptionList> {
  return fromSubtitleList(
    await sdk.forProject(projectId).videos.listSubtitles({ videoId }),
  )
}

export function createVideoCaption(
  projectId: string,
  params: {
    videoId: string
    bucketId: string
    fileId: string
    name: string
    code: string
    xdefault?: boolean
  },
): Promise<VideoCaption> {
  return sdk.forProject(projectId).videos.createSubtitle(params)
}

export function updateVideoCaption(
  projectId: string,
  params: {
    videoId: string
    captionId: string
    bucketId?: string
    fileId?: string
    name?: string
    code?: string
    xdefault?: boolean
  },
): Promise<VideoCaption> {
  const { captionId, ...rest } = params
  return sdk.forProject(projectId).videos.updateSubtitle({
    ...rest,
    subtitleId: captionId,
  })
}

export function deleteVideoCaption(
  projectId: string,
  videoId: string,
  captionId: string,
) {
  return sdk.forProject(projectId).videos.deleteSubtitle({
    videoId,
    subtitleId: captionId,
  })
}

export function getCaptionManifestUrl(params: {
  projectId: string
  videoId: string
  captionId: string
  output: VideoOutput
}): string {
  return String(
    sdk.forProject(params.projectId).videos.getSubtitleManifest({
      videoId: params.videoId,
      output: params.output,
      subtitleId: params.captionId,
    }),
  )
}

export function getCaptionSegmentUrl(params: {
  projectId: string
  videoId: string
  captionId: string
  output: VideoOutput
  segmentId: string
}): string {
  return String(
    sdk.forProject(params.projectId).videos.getSubtitleSegment({
      videoId: params.videoId,
      output: params.output,
      subtitleId: params.captionId,
      segmentId: params.segmentId,
    }),
  )
}
