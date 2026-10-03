/**
 * Subtitle editor data: reading a track's cues and saving edited cues back
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
import { DEFAULT_STALE_TIME } from './constants'
import { videoKeys } from './videos'

type SubtitleSource = Pick<Models.VideoSubtitle, '$id' | 'bucketId' | 'fileId'>

async function fetchSubtitleResource(url: string): Promise<string> {
  const response = await fetch(withAdminMode(url), { credentials: 'include' })
  if (!response.ok) {
    throw new Error(`Subtitle request failed (HTTP ${response.status})`)
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
async function fetchPackagedSubtitleCues(
  projectId: string,
  videoId: string,
  subtitleId: string,
): Promise<SubtitleCue[]> {
  const videos = sdk.forProject(projectId).videos
  const dashUrl = String(
    videos.getSubtitleManifest({
      videoId,
      subtitleId,
      output: VideoOutput.Dash,
    }),
  )
  try {
    const text = await fetchSubtitleResource(dashUrl)
    if (text.trimStart().startsWith('WEBVTT')) return parseSubtitleText(text)
  } catch {
    // Fall back to the HLS playlist below.
  }
  const playlistUrl = String(
    videos.getSubtitleManifest({
      videoId,
      subtitleId,
      output: VideoOutput.Hls,
    }),
  )
  const playlist = await fetchSubtitleResource(playlistUrl)
  const segments = playlist
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'))
    .map((uri) => resolvePlaylistUri(uri, playlistUrl))
  const texts = await Promise.all(segments.map(fetchSubtitleResource))
  return dedupeSubtitleCues(texts.flatMap((text) => parseSubtitleText(text)))
}

export async function fetchVideoSubtitleCues(
  projectId: string,
  videoId: string,
  subtitle: SubtitleSource,
): Promise<SubtitleCue[]> {
  if (subtitle.bucketId && subtitle.fileId) {
    const url = String(
      sdk.forProject(projectId).storage.getFileDownload({
        bucketId: subtitle.bucketId,
        fileId: subtitle.fileId,
      }),
    )
    return parseSubtitleText(await fetchSubtitleResource(url))
  }
  return await fetchPackagedSubtitleCues(projectId, videoId, subtitle.$id)
}

/** Keyed by file so a saved track (new file) never reuses the old cues. */
export function videoSubtitleCuesQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  subtitle: SubtitleSource | null,
) {
  return queryOptions({
    queryKey: [
      'video-subtitle-cues',
      'project',
      projectId,
      videoId,
      subtitle?.$id,
      subtitle?.fileId,
    ],
    queryFn: () => fetchVideoSubtitleCues(projectId!, videoId!, subtitle!),
    enabled: !!projectId && !!videoId && !!subtitle,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

/** Reads a track's cues (from cache when fresh) and downloads them as a file. */
export function useDownloadVideoSubtitle(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return async (
    subtitle: SubtitleSource & Pick<Models.VideoSubtitle, 'name' | 'code'>,
    format: SubtitleDownloadFormat,
  ) => {
    const cues = await queryClient.fetchQuery(
      videoSubtitleCuesQueryOptions(projectId, videoId, subtitle),
    )
    downloadSubtitleCues(cues, subtitle.name, subtitle.code, format)
  }
}

export function useVideoSubtitleCues(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  subtitle: SubtitleSource | null,
) {
  return useQuery(videoSubtitleCuesQueryOptions(projectId, videoId, subtitle))
}

/**
 * Saves editor cues as a new WebVTT file in Storage, then creates the subtitle
 * or points the existing one at the new file (which re-packages the track).
 * The previous file is kept.
 */
export function useSaveVideoSubtitleCues(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      subtitleId?: string
      cues: SubtitleCue[]
      bucketId: string
      name: string
      code: string
      isDefault: boolean
    }): Promise<Models.VideoSubtitle> => {
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
      return input.subtitleId
        ? await projectSdk.videos.updateSubtitle({
            ...params,
            subtitleId: input.subtitleId,
          })
        : await projectSdk.videos.createSubtitle(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.subtitles(projectId, videoId),
      })
    },
  })
}
