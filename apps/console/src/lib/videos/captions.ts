/**
 * Videos text tracks are **captions** on `__stream_v6` (`/captions`, `VideoCaption`).
 * Published Console SDK builds still expose subtitle names and `/subtitles` paths.
 * This module talks to the captions API until a matching SDK ships.
 */

import type { Client, Models, VideoOutput } from '@appwrite.io/console'
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

type CaptionListResponse = {
  total: number
  captions: VideoCaption[]
}

function projectClient(projectId: string): Client {
  return sdk.forProject(projectId).client
}

function projectHeaders(client: Client): Record<string, string> {
  return {
    'X-Appwrite-Project': client.config.project,
    accept: 'application/json',
  }
}

function jsonHeaders(client: Client): Record<string, string> {
  return {
    ...projectHeaders(client),
    'content-type': 'application/json',
  }
}

function captionsCollectionUri(client: Client, videoId: string): URL {
  return new URL(
    `${client.config.endpoint}/videos/${encodeURIComponent(videoId)}/captions`,
  )
}

function captionUri(client: Client, videoId: string, captionId: string): URL {
  return new URL(
    `${client.config.endpoint}/videos/${encodeURIComponent(videoId)}/captions/${encodeURIComponent(captionId)}`,
  )
}

export async function listVideoCaptions(
  projectId: string,
  videoId: string,
): Promise<VideoCaptionList> {
  const client = projectClient(projectId)
  const response = (await client.call(
    'get',
    captionsCollectionUri(client, videoId),
    projectHeaders(client),
    {},
  )) as CaptionListResponse
  return {
    total: response.total ?? 0,
    captions: response.captions ?? [],
  }
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
  const client = projectClient(projectId)
  const body: Record<string, string | boolean> = {
    bucketId: params.bucketId,
    fileId: params.fileId,
    name: params.name,
    code: params.code,
  }
  if (params.xdefault != null) {
    body.default = params.xdefault
  }
  return client.call(
    'post',
    captionsCollectionUri(client, params.videoId),
    jsonHeaders(client),
    body,
  )
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
  const client = projectClient(projectId)
  const { captionId, videoId, xdefault, ...rest } = params
  const body: Record<string, string | boolean> = { ...rest }
  if (xdefault != null) {
    body.default = xdefault
  }
  return client.call(
    'patch',
    captionUri(client, videoId, captionId),
    jsonHeaders(client),
    body,
  )
}

export function deleteVideoCaption(
  projectId: string,
  videoId: string,
  captionId: string,
) {
  const client = projectClient(projectId)
  return client.call(
    'delete',
    captionUri(client, videoId, captionId),
    projectHeaders(client),
    {},
  )
}

export function getCaptionManifestUrl(params: {
  projectId: string
  videoId: string
  captionId: string
  output: VideoOutput
}): string {
  const client = projectClient(params.projectId)
  const url = new URL(
    `${client.config.endpoint}/videos/${encodeURIComponent(params.videoId)}/outputs/${encodeURIComponent(params.output)}/captions/${encodeURIComponent(params.captionId)}/manifest`,
  )
  url.searchParams.set('project', params.projectId)
  return url.toString()
}

export function getCaptionSegmentUrl(params: {
  projectId: string
  videoId: string
  captionId: string
  output: VideoOutput
  segmentId: string
}): string {
  const client = projectClient(params.projectId)
  const url = new URL(
    `${client.config.endpoint}/videos/${encodeURIComponent(params.videoId)}/outputs/${encodeURIComponent(params.output)}/captions/${encodeURIComponent(params.captionId)}/segments/${encodeURIComponent(params.segmentId)}`,
  )
  url.searchParams.set('project', params.projectId)
  return url.toString()
}
