/**
 * React Query hooks for the Videos product (`sdk.forProject(projectId).videos`).
 *
 * Videos are created from a Storage file, downloaded into a working copy
 * (`createSource`), then encoded into renditions per profile and output
 * (HLS, DASH, CMAF). Worker progress arrives over realtime and is merged into
 * these caches by `@/lib/realtime/video-cache`.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
  keepPreviousData,
} from '@tanstack/react-query'
import { Query, VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, GRID_DEFAULT_PAGE_SIZE } from './constants'
import { Dependencies } from './dependencies'

export const VIDEOS_DEFAULT_SORT_BY = '$createdAt'
export const VIDEOS_DEFAULT_SORT_ORDER: 'asc' | 'desc' = 'desc'

/** Source (working copy) statuses reported on `Models.Video.status`. */
export const VIDEO_SOURCE_STATUSES = [
  'pending',
  'downloading',
  'ready',
  'removed',
  'error',
  'aborted',
] as const
export type VideoSourceStatus = (typeof VIDEO_SOURCE_STATUSES)[number]

/** Rendition statuses that mean the worker is still processing. */
export const VIDEO_RENDITION_ACTIVE_STATUSES = [
  'pending',
  'started',
  'ended',
  'uploading',
] as const

export function isVideoRenditionActive(status: string | undefined): boolean {
  return (VIDEO_RENDITION_ACTIVE_STATUSES as readonly string[]).includes(
    status ?? '',
  )
}

export function isVideoSourceActive(status: string | undefined): boolean {
  return status === 'downloading'
}

/** Rendition progress arrives as a string percentage (`"42"`, `"42.5"`). */
export function parseVideoProgress(progress: string | undefined): number {
  const value = Number.parseFloat(progress ?? '')
  if (!Number.isFinite(value)) return 0
  return Math.min(100, Math.max(0, value))
}

export const VIDEO_OUTPUTS: VideoOutput[] = [
  VideoOutput.Hls,
  VideoOutput.Dash,
  VideoOutput.Cmaf,
]

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

export const videoKeys = {
  list: (projectId: string | null | undefined) =>
    ['videos', 'project', projectId] as const,
  detail: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video', 'project', projectId, videoId] as const,
  renditions: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video-renditions', 'project', projectId, videoId] as const,
  subtitles: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video-subtitles', 'project', projectId, videoId] as const,
  profiles: (projectId: string | null | undefined) =>
    ['video-profiles', 'project', projectId] as const,
  timeline: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video-timeline', 'project', projectId, videoId] as const,
}

// ---------------------------------------------------------------------------
// Query functions
// ---------------------------------------------------------------------------

export async function fetchProjectVideos(
  projectId: string,
  page: number = 0,
  limit: number = GRID_DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = VIDEOS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = VIDEOS_DEFAULT_SORT_ORDER,
): Promise<{ videos: Models.Video[]; total: number }> {
  if (!projectId) return { videos: [], total: 0 }
  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const response = await sdk.forProject(projectId).videos.list({
    queries: [
      ...(filterQueries ?? []),
      orderQuery,
      Query.limit(limit),
      Query.offset(page * limit),
    ],
    search: search?.trim() || undefined,
  })
  return { videos: response.videos ?? [], total: response.total ?? 0 }
}

export async function fetchProjectVideo(
  projectId: string,
  videoId: string,
): Promise<Models.Video> {
  return await sdk.forProject(projectId).videos.get({ videoId })
}

export async function fetchVideoRenditions(
  projectId: string,
  videoId: string,
): Promise<Models.VideoRenditionList> {
  return await sdk.forProject(projectId).videos.listRenditions({ videoId })
}

export async function fetchVideoSubtitles(
  projectId: string,
  videoId: string,
): Promise<Models.VideoSubtitleList> {
  return await sdk.forProject(projectId).videos.listSubtitles({ videoId })
}

export async function fetchVideoProfiles(
  projectId: string,
): Promise<Models.VideoProfileList> {
  return await sdk.forProject(projectId).videos.listProfiles()
}

export type VideoTimelineCue = {
  start: number
  end: number
  imageUrl: string
  x: number
  y: number
  width: number
  height: number
}

function parseVttTimestamp(value: string): number {
  const parts = value.trim().split(':').map(Number)
  if (parts.some((n) => !Number.isFinite(n))) return 0
  return parts.reduce((total, part) => total * 60 + part, 0)
}

/** Parse a sprite-sheet WebVTT (`previews/{id}#xywh=x,y,w,h` cues). */
export function parseVideoTimelineVtt(
  vtt: string,
  timelineUrl: string,
  projectId: string,
): VideoTimelineCue[] {
  const cues: VideoTimelineCue[] = []
  const blocks = vtt.replace(/\r/g, '').split(/\n{2,}/)
  for (const block of blocks) {
    const lines = block.split('\n').filter(Boolean)
    const timingIdx = lines.findIndex((line) => line.includes('-->'))
    if (timingIdx < 0 || !lines[timingIdx + 1]) continue
    const [startRaw, endRaw] = lines[timingIdx].split('-->')
    const [path, fragment = ''] = lines[timingIdx + 1].trim().split('#xywh=')
    const [x, y, width, height] = fragment.split(',').map(Number)
    const imageUrl = new URL(path, timelineUrl)
    imageUrl.searchParams.set('project', projectId)
    imageUrl.searchParams.set('mode', 'admin')
    cues.push({
      start: parseVttTimestamp(startRaw),
      end: parseVttTimestamp(endRaw),
      imageUrl: imageUrl.toString(),
      x: x || 0,
      y: y || 0,
      width: width || 0,
      height: height || 0,
    })
  }
  return cues
}

export function getVideoTimelineUrl(
  projectId: string,
  videoId: string,
): string {
  const endpoint = sdk.forProject(projectId).client.config.endpoint
  const url = new URL(
    `${endpoint}/videos/${encodeURIComponent(videoId)}/timeline`,
  )
  url.searchParams.set('project', projectId)
  url.searchParams.set('mode', 'admin')
  return url.toString()
}

/** `null` when no timeline has been generated yet (404). */
export async function fetchVideoTimeline(
  projectId: string,
  videoId: string,
): Promise<{ url: string; vtt: string; cues: VideoTimelineCue[] } | null> {
  const url = getVideoTimelineUrl(projectId, videoId)
  const response = await fetch(url, { credentials: 'include' })
  if (response.status === 404) return null
  if (!response.ok) {
    throw new Error(`Timeline request failed (HTTP ${response.status})`)
  }
  const vtt = await response.text()
  return { url, vtt, cues: parseVideoTimelineVtt(vtt, url, projectId) }
}

// ---------------------------------------------------------------------------
// Query options (shared by route loaders and hooks)
// ---------------------------------------------------------------------------

const PREFETCHED_QUERY_DEFAULTS = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false,
  refetchOnWindowFocus: false,
  refetchOnReconnect: false,
} as const

export function videosQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = GRID_DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = VIDEOS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = VIDEOS_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      ...videoKeys.list(projectId),
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchProjectVideos(
        projectId!,
        page,
        limit,
        search,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled: !!projectId,
    placeholderData: keepPreviousData,
    ...PREFETCHED_QUERY_DEFAULTS,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function videoQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.detail(projectId, videoId),
    queryFn: () => fetchProjectVideo(projectId!, videoId!),
    enabled: !!projectId && !!videoId,
    ...PREFETCHED_QUERY_DEFAULTS,
  })
}

export function videoRenditionsQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.renditions(projectId, videoId),
    queryFn: () => fetchVideoRenditions(projectId!, videoId!),
    enabled: !!projectId && !!videoId,
    ...PREFETCHED_QUERY_DEFAULTS,
  })
}

export function videoSubtitlesQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.subtitles(projectId, videoId),
    queryFn: () => fetchVideoSubtitles(projectId!, videoId!),
    enabled: !!projectId && !!videoId,
    ...PREFETCHED_QUERY_DEFAULTS,
  })
}

export function videoProfilesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.profiles(projectId),
    queryFn: () => fetchVideoProfiles(projectId!),
    enabled: !!projectId,
    ...PREFETCHED_QUERY_DEFAULTS,
  })
}

export function videoTimelineQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.timeline(projectId, videoId),
    queryFn: () => fetchVideoTimeline(projectId!, videoId!),
    enabled: !!projectId && !!videoId,
    ...PREFETCHED_QUERY_DEFAULTS,
  })
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

/** Timeline generation emits no realtime events, so poll while a request is pending. */
export function useVideoTimeline(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  options: { pollWhileMissing?: boolean } = {},
) {
  return useQuery({
    ...videoTimelineQueryOptions(projectId, videoId),
    refetchInterval: (query) =>
      options.pollWhileMissing && query.state.data === null ? 3000 : false,
  })
}

export function useProjectVideos(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = GRID_DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = VIDEOS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = VIDEOS_DEFAULT_SORT_ORDER,
) {
  const query = useQuery(
    videosQueryOptions(
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )
  return {
    videos: query.data?.videos ?? [],
    total: query.data?.total ?? 0,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isFetched: query.isFetched,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useProjectVideo(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return useQuery(videoQueryOptions(projectId, videoId))
}

export function useVideoRenditions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return useQuery(videoRenditionsQueryOptions(projectId, videoId))
}

export function useVideoSubtitles(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return useQuery(videoSubtitlesQueryOptions(projectId, videoId))
}

export function useVideoProfiles(projectId: string | null | undefined) {
  return useQuery(videoProfilesQueryOptions(projectId))
}

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

/** Register a video from a Storage file and start downloading its working copy. */
export function useCreateVideo(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      bucketId: string
      fileId: string
      name?: string
    }) => {
      const videos = sdk.forProject(projectId!).videos
      const video = await videos.create({
        bucketId: input.bucketId,
        fileId: input.fileId,
        name: input.name?.trim() || undefined,
      })
      return await videos.createSource({ videoId: video.$id })
    },
    onSuccess: async (video) => {
      queryClient.setQueryData(videoKeys.detail(projectId, video.$id), video)
      await queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
    },
  })
}

export function useUpdateVideo(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (name: string) =>
      sdk.forProject(projectId!).videos.update({ videoId: videoId!, name }),
    onSuccess: async (video) => {
      queryClient.setQueryData(videoKeys.detail(projectId, videoId), video)
      await queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
    },
  })
}

export function useDeleteVideo(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (videoId: string) =>
      sdk.forProject(projectId!).videos.delete({ videoId }),
    onSuccess: async (_data, videoId) => {
      queryClient.removeQueries({
        queryKey: videoKeys.detail(projectId, videoId),
      })
      await queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
    },
  })
}

/** Re-download the working copy (after `removed`, `error`, or `aborted`). */
export function useCreateVideoSource(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () =>
      sdk.forProject(projectId!).videos.createSource({ videoId: videoId! }),
    onSuccess: async (video) => {
      queryClient.setQueryData(videoKeys.detail(projectId, videoId), video)
      await queryClient.refetchQueries({ queryKey: Dependencies.VIDEOS })
    },
  })
}

export function useCreateVideoTimeline(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () =>
      sdk.forProject(projectId!).videos.createTimeline({ videoId: videoId! }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: videoKeys.timeline(projectId, videoId),
      })
    },
  })
}

export function useCreateVideoRenditions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      profileIds: string[]
      output: VideoOutput
    }) => {
      const videos = sdk.forProject(projectId!).videos
      const results = await Promise.allSettled(
        input.profileIds.map((profileId) =>
          videos.createRendition({
            videoId: videoId!,
            profileId,
            output: input.output,
          }),
        ),
      )
      const failed = results.filter(
        (r): r is PromiseRejectedResult => r.status === 'rejected',
      )
      if (failed.length === results.length && failed.length > 0) {
        throw failed[0].reason
      }
      return {
        created: results.length - failed.length,
        failed: failed.length,
      }
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
    },
  })
}

export function useDeleteVideoRendition(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (renditionId: string) =>
      sdk
        .forProject(projectId!)
        .videos.deleteRendition({ videoId: videoId!, renditionId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
    },
  })
}

export function useCreateVideoSubtitle(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: {
      bucketId: string
      fileId: string
      name: string
      code: string
      isDefault: boolean
    }) =>
      sdk.forProject(projectId!).videos.createSubtitle({
        videoId: videoId!,
        bucketId: input.bucketId,
        fileId: input.fileId,
        name: input.name,
        code: input.code,
        xdefault: input.isDefault,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.subtitles(projectId, videoId),
      })
    },
  })
}

export function useUpdateVideoSubtitle(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: {
      subtitleId: string
      name?: string
      code?: string
      isDefault?: boolean
    }) =>
      sdk.forProject(projectId!).videos.updateSubtitle({
        videoId: videoId!,
        subtitleId: input.subtitleId,
        name: input.name,
        code: input.code,
        xdefault: input.isDefault,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.subtitles(projectId, videoId),
      })
    },
  })
}

export function useDeleteVideoSubtitle(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (subtitleId: string) =>
      sdk
        .forProject(projectId!)
        .videos.deleteSubtitle({ videoId: videoId!, subtitleId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.subtitles(projectId, videoId),
      })
    },
  })
}

export type VideoProfileInput = {
  name: string
  width: number
  height: number
  videoBitRate: number
  audioBitRate: number
}

export function useSaveVideoProfile(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: VideoProfileInput & { profileId?: string }) => {
      const videos = sdk.forProject(projectId!).videos
      const { profileId, ...values } = input
      return profileId
        ? videos.updateProfile({ profileId, ...values })
        : videos.createProfile(values)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.profiles(projectId),
      })
    },
  })
}

export function useDeleteVideoProfile(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (profileId: string) =>
      sdk.forProject(projectId!).videos.deleteProfile({ profileId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.profiles(projectId),
      })
    },
  })
}
