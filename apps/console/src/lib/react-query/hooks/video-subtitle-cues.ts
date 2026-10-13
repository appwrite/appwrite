/**
 * Caption editor data: reading a track's cues and saving edited cues back
 * as a WebVTT file in Storage.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import {
  dedupeSubtitleCues,
  parseSubtitleText,
  serializeWebVtt,
  subtitleFileName,
  type SubtitleCue,
} from '@/lib/videos/subtitle-cues'
import {
  downloadSubtitleCues,
  type SubtitleDownloadFormat,
} from '@/lib/videos/subtitle-download'
import {
  createVideoCaption,
  getCaptionManifestUrl,
  updateVideoCaption,
} from '@/lib/videos/captions'
import { DEFAULT_STALE_TIME } from './constants'
import { videoKeys } from './videos'

type CaptionSource = Pick<Models.VideoCaption, '$id' | 'bucketId' | 'fileId'>

async function fetchCaptionResource(url: string): Promise<string> {
  const response = await fetch(withAdminMode(url), { credentials: 'include' })
  if (!response.ok) {
    throw new Error(`Caption request failed (HTTP ${response.status})`)
  }
  return await response.text()
}

/** Relative segment URIs drop the playlist's `project` param, so carry it over. */
function resolvePlaylistUri(uri: string, playlistUrl: string): string {
  const base = new URL(playlistUrl)
  const resolved = new URL(uri, base)
  for (const [key, value] of base.searchParams.entries()) {
    if (key !== 'mode' && !resolved.searchParams.has(key)) {
      resolved.searchParams.set(key, value)
    }
  }
  return resolved.toString()
}

/** Embedded tracks have no Storage file: read the packaged WebVTT instead. */
async function fetchPackagedCaptionCues(
  projectId: string,
  videoId: string,
  captionId: string,
): Promise<SubtitleCue[]> {
  const dashUrl = getCaptionManifestUrl({
    projectId,
    videoId,
    captionId,
    output: VideoOutput.Dash,
  })
  try {
    const text = await fetchCaptionResource(dashUrl)
    if (text.trimStart().startsWith('WEBVTT')) return parseSubtitleText(text)
  } catch {
    // Fall back to the HLS playlist below.
  }
  const playlistUrl = getCaptionManifestUrl({
    projectId,
    videoId,
    captionId,
    output: VideoOutput.Hls,
  })
  const playlist = await fetchCaptionResource(playlistUrl)
  const segments = playlist
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'))
    .map((uri) => resolvePlaylistUri(uri, playlistUrl))
  const texts = await Promise.all(segments.map(fetchCaptionResource))
  return dedupeSubtitleCues(texts.flatMap((text) => parseSubtitleText(text)))
}

export async function fetchVideoCaptionCues(
  projectId: string,
  videoId: string,
  caption: CaptionSource,
): Promise<SubtitleCue[]> {
  if (caption.bucketId && caption.fileId) {
    const url = String(
      sdk.forProject(projectId).storage.getFileDownload({
        bucketId: caption.bucketId,
        fileId: caption.fileId,
      }),
    )
    return parseSubtitleText(await fetchCaptionResource(url))
  }
  return await fetchPackagedCaptionCues(projectId, videoId, caption.$id)
}

/** Keyed by file so a saved track (new file) never reuses the old cues. */
export function videoCaptionCuesQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  caption: CaptionSource | null,
) {
  return queryOptions({
    queryKey: [
      'video-caption-cues',
      'project',
      projectId,
      videoId,
      caption?.$id,
      caption?.fileId,
    ],
    queryFn: () => fetchVideoCaptionCues(projectId!, videoId!, caption!),
    enabled: !!projectId && !!videoId && !!caption,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

/** Reads a track's cues (from cache when fresh) and downloads them as a file. */
export function useDownloadVideoCaption(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return async (
    caption: CaptionSource & Pick<Models.VideoCaption, 'name' | 'code'>,
    format: SubtitleDownloadFormat,
  ) => {
    const cues = await queryClient.fetchQuery(
      videoCaptionCuesQueryOptions(projectId, videoId, caption),
    )
    downloadSubtitleCues(cues, caption.name, caption.code, format)
  }
}

export function useVideoCaptionCues(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  caption: CaptionSource | null,
) {
  return useQuery(videoCaptionCuesQueryOptions(projectId, videoId, caption))
}

/**
 * Saves editor cues as a new WebVTT file in Storage, then creates the caption
 * or points the existing one at the new file (which re-packages the track).
 * The previous file is kept.
 */
export function useSaveVideoCaptionCues(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      captionId?: string
      cues: SubtitleCue[]
      bucketId: string
      name: string
      code: string
      isDefault: boolean
    }): Promise<Models.VideoCaption> => {
      const projectSdk = sdk.forProject(projectId!)
      const file = new File(
        [serializeWebVtt(input.cues)],
        subtitleFileName(input.name, input.code, 'vtt'),
        { type: 'text/vtt' },
      )
      const uploaded = await projectSdk.storage.createFile({
        bucketId: input.bucketId,
        fileId: ID.unique(),
        file,
      })
      const params = {
        videoId: videoId!,
        bucketId: input.bucketId,
        fileId: uploaded.$id,
        name: input.name,
        code: input.code,
        xdefault: input.isDefault,
      }
      return input.captionId
        ? await updateVideoCaption(projectId!, {
            ...params,
            captionId: input.captionId,
          })
        : await createVideoCaption(projectId!, params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.captions(projectId, videoId),
      })
    },
  })
}
