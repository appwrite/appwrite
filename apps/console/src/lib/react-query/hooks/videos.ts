/**
 * React Query hooks for the Videos product (`sdk.forProject(projectId).videos`).
 *
 * Videos are created from a Storage file, probed for metadata, then encoded
 * into renditions per profile and output (HLS, DASH, CMAF). Worker progress
 * arrives over realtime and is merged into these caches by
 * `@/lib/realtime/video-cache`.
 */

import { useEffect, useRef } from 'react'
import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
  keepPreviousData,
} from '@tanstack/react-query'
import {
  AppwriteException,
  ID,
  Query,
  VideoCodecId,
  VideoOutput,
  type Models,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  publicHlsPlayback,
  type PublicHlsPlayback,
} from '@/lib/videos/public-hls-playback'
import { DEFAULT_STALE_TIME, GRID_DEFAULT_PAGE_SIZE } from './constants'
import { Dependencies } from './dependencies'

export const VIDEOS_DEFAULT_SORT_BY = '$createdAt'
export const VIDEOS_DEFAULT_SORT_ORDER: 'asc' | 'desc' = 'desc'

/** True when probe metadata is available on the video document. */
export function isVideoMetadataReady(
  video: Pick<Models.Video, 'width' | 'height'> | null | undefined,
): boolean {
  if (!video) return false
  return (video.width ?? 0) > 0 && (video.height ?? 0) > 0
}

/** Rendition statuses that mean the worker is still processing. */
export const VIDEO_RENDITION_ACTIVE_STATUSES = [
  'pending',
  'started',
  'ended',
] as const

export function isVideoRenditionActive(status: string | undefined): boolean {
  return (VIDEO_RENDITION_ACTIVE_STATUSES as readonly string[]).includes(
    status ?? '',
  )
}

/** Rendition progress arrives as a string percentage (`"42"`, `"42.5"`). */
export function parseVideoProgress(
  progress: number | string | undefined,
): number {
  const value =
    typeof progress === 'number' ? progress : Number.parseFloat(progress ?? '')
  if (!Number.isFinite(value)) return 0
  return Math.min(100, Math.max(0, value))
}

export const VIDEO_OUTPUTS: VideoOutput[] = [
  VideoOutput.Hls,
  VideoOutput.Dash,
  VideoOutput.Cmaf,
]

export type VideoEncodingStatus = 'none' | 'encoding' | 'ready' | 'error'

/** Videos carry no status of their own; summarize their renditions instead. */
export function getVideoEncodingStatus(
  renditions: Pick<Models.VideoRendition, 'status'>[],
): VideoEncodingStatus {
  if (renditions.some((r) => isVideoRenditionActive(r.status))) {
    return 'encoding'
  }
  if (renditions.some((r) => r.status === 'ready')) return 'ready'
  if (renditions.some((r) => r.status === 'error' || r.status === 'aborted')) {
    return 'error'
  }
  return 'none'
}

/**
 * Timeline generation is queued when a video is created and reported on the
 * video document (`timelineStatus`). Videos from before the field existed
 * report an empty status; treat those as possibly ready.
 */
export type VideoTimelineState =
  'processing' | 'ready' | 'skipped' | 'error' | 'unknown'

export function getVideoTimelineState(
  video: Pick<Models.Video, 'timelineStatus'> | null | undefined,
): VideoTimelineState {
  switch (video?.timelineStatus) {
    case 'pending':
    case 'started':
      return 'processing'
    case 'ready':
      return 'ready'
    case 'skipped':
      return 'skipped'
    case 'error':
      return 'error'
    default:
      return 'unknown'
  }
}

/** Whether a timeline may exist, so it is worth requesting the WebVTT. */
export function canFetchVideoTimeline(
  video: Pick<Models.Video, 'timelineStatus'> | null | undefined,
): boolean {
  const state = getVideoTimelineState(video)
  return state === 'ready' || state === 'unknown'
}

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
  captions: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video-captions', 'project', projectId, videoId] as const,
  publicPlayback: (
    projectId: string | null | undefined,
    videoId: string | null | undefined,
  ) => ['video-public-playback', 'project', projectId, videoId] as const,
  profiles: (projectId: string | null | undefined) =>
    ['video-profiles', 'project', projectId] as const,
  codecs: (projectId: string | null | undefined) =>
    ['video-codecs', 'project', projectId] as const,
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
  const term = search?.trim()
  const response = await sdk.forProject(projectId).videos.list({
    queries: [
      ...(filterQueries ?? []),
      ...(term ? [Query.search('search', term)] : []),
      orderQuery,
      Query.limit(limit),
      Query.offset(page * limit),
    ],
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

export async function fetchVideoCaptions(
  projectId: string,
  videoId: string,
): Promise<Models.VideoCaptionList> {
  return await sdk.forProject(projectId).videos.listCaptions({ videoId })
}

/** Codecs enabled on this instance, with the outputs each can be packaged as. */
export async function fetchVideoCodecs(
  projectId: string,
): Promise<Models.VideoCodec[]> {
  const response = await sdk.forProject(projectId).videos.listCodecs()
  return response.codecs ?? []
}

const VIDEO_PROFILES_LIMIT = 100

/**
 * Profiles of every enabled codec, filtered with `Query.equal('codec', ...)`
 * so profiles left over from a codec the instance has since disabled stay out
 * of the pickers.
 */
export async function fetchVideoProfiles(
  projectId: string,
): Promise<Models.VideoProfileList> {
  const codecs = await fetchVideoCodecs(projectId)
  const ids = codecs.length
    ? codecs.map((codec) => codec.$id)
    : [VideoCodecId.H264]
  const project = sdk.forProject(projectId).project
  // Page through every profile: the pickers and the profiles page list them all.
  const profiles: Models.VideoProfile[] = []
  for (;;) {
    const page = await project.listVideoProfiles({
      queries: [
        Query.equal('codec', ids),
        Query.limit(VIDEO_PROFILES_LIMIT),
        Query.offset(profiles.length),
      ],
    })
    const batch = page.profiles ?? []
    profiles.push(...batch)
    if (batch.length < VIDEO_PROFILES_LIMIT || profiles.length >= page.total)
      return { total: profiles.length, profiles }
  }
}

/** Whether a profile's codec can be packaged as `output` on this instance. */
export function videoProfileSupportsOutput(
  profile: Pick<Models.VideoProfile, 'codec'>,
  output: VideoOutput,
  codecs: Models.VideoCodec[] | undefined,
): boolean {
  const codec = codecs?.find((item) => item.$id === (profile.codec || 'h264'))
  return codec ? codec.outputs.includes(output) : true
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

/**
 * Parse a thumbnail timeline WebVTT. Cues are relative to the timeline URL
 * (`thumbnails/{id}?project=…#xywh=x,y,w,h`).
 */
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
    const cueLine = lines[timingIdx + 1].trim()
    const xywhIdx = cueLine.indexOf('#xywh=')
    const path = xywhIdx >= 0 ? cueLine.slice(0, xywhIdx) : cueLine
    const fragment =
      xywhIdx >= 0 ? cueLine.slice(xywhIdx + '#xywh='.length) : ''
    const [x, y, width, height] = fragment.split(',').map(Number)
    const imageUrl = new URL(path, timelineUrl)
    if (!imageUrl.searchParams.has('project')) {
      imageUrl.searchParams.set('project', projectId)
    }
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
    `${endpoint}/videos/${encodeURIComponent(videoId)}/timelines`,
  )
  url.searchParams.set('project', projectId)
  url.searchParams.set('mode', 'admin')
  return url.toString()
}

/** `null` while no timeline exists (`video_timelines_not_found`). */
export async function fetchVideoTimeline(
  projectId: string,
  videoId: string,
): Promise<{ url: string; vtt: string; cues: VideoTimelineCue[] } | null> {
  const url = getVideoTimelineUrl(projectId, videoId)
  try {
    const vtt = await sdk.forProject(projectId).videos.getTimelines({ videoId })
    return { url, vtt, cues: parseVideoTimelineVtt(vtt, url, projectId) }
  } catch (error) {
    if (error instanceof AppwriteException && error.code === 404) return null
    throw error
  }
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

/**
 * A visitor's playback when the video document is refused: the ready HLS
 * renditions from the public master playlist, or null when that is refused too.
 */
export async function fetchPublicHlsPlayback(
  projectId: string,
  videoId: string,
): Promise<PublicHlsPlayback | null> {
  const url = new URL(
    sdk.forProject(projectId).videos.getHlsManifest({ videoId }),
  )
  url.searchParams.delete('mode')
  const response = await fetch(url, { credentials: 'include' })
  if (!response.ok) return null
  return publicHlsPlayback(videoId, await response.text())
}

export function publicHlsPlaybackQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.publicPlayback(projectId, videoId),
    queryFn: () => fetchPublicHlsPlayback(projectId!, videoId!),
    enabled: !!projectId && !!videoId,
    retry: false,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function usePublicHlsPlayback(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
  enabled = true,
) {
  const options = publicHlsPlaybackQueryOptions(projectId, videoId)
  return useQuery({ ...options, enabled: enabled && options.enabled })
}

export function videoCaptionsQueryOptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return queryOptions({
    queryKey: videoKeys.captions(projectId, videoId),
    queryFn: () => fetchVideoCaptions(projectId!, videoId!),
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

export function videoCodecsQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: videoKeys.codecs(projectId),
    queryFn: () => fetchVideoCodecs(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
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

const TIMELINE_POLL_INTERVAL = 5000

/**
 * The video's thumbnail timeline. Progress arrives on the video document
 * (`timelineStatus`) over `videos.[videoId].timelines.update`, which
 * `@/lib/realtime/video-cache` merges and uses to refetch this query. The
 * video is also polled while the timeline is processing, in case realtime
 * is not connected.
 */
export function useVideoTimeline(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const { data: video } = useQuery({
    ...videoQueryOptions(projectId, videoId),
    refetchInterval: (query) =>
      getVideoTimelineState(query.state.data) === 'processing'
        ? TIMELINE_POLL_INTERVAL
        : false,
  })
  const state = getVideoTimelineState(video)
  const query = useQuery({
    ...videoTimelineQueryOptions(projectId, videoId),
    enabled:
      !!projectId && !!videoId && !!video && canFetchVideoTimeline(video),
  })
  // A timeline cached as missing while it was generating is stale once the
  // video reports it ready (realtime may be down, so polling sees it first).
  const queryClient = useQueryClient()
  const previousState = useRef(state)
  useEffect(() => {
    const before = previousState.current
    previousState.current = state
    if (state === 'ready' && before === 'processing') {
      void queryClient.invalidateQueries({
        queryKey: videoKeys.timeline(projectId, videoId),
      })
    }
  }, [state, queryClient, projectId, videoId])
  return {
    ...query,
    state,
    pending: state === 'processing',
    timelineError: state === 'error' ? video?.timelineError || null : null,
  }
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

export function useVideoCaptions(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  return useQuery(videoCaptionsQueryOptions(projectId, videoId))
}

export function useVideoProfiles(projectId: string | null | undefined) {
  return useQuery(videoProfilesQueryOptions(projectId))
}

export function useVideoCodecs(projectId: string | null | undefined) {
  return useQuery(videoCodecsQueryOptions(projectId))
}

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

/** Turns public playback (read for Any) on or off, keeping the other permissions. */
/** Replaces the video's permissions; the backend stores the list as given. */
export function useUpdateVideoPermissions(
  projectId: string | null | undefined,
  video: Models.Video,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (permissions: string[]) =>
      sdk.forProject(projectId!).videos.update({
        videoId: video.$id,
        permissions,
      }),
    onSuccess: async (updated) => {
      queryClient.setQueryData(videoKeys.detail(projectId, video.$id), updated)
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

export function useCreateVideoCaption(
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
      sdk.forProject(projectId!).videos.createCaption({
        videoId: videoId!,
        bucketId: input.bucketId,
        fileId: input.fileId,
        name: input.name,
        code: input.code,
        xdefault: input.isDefault,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.captions(projectId, videoId),
      })
    },
  })
}

export function useUpdateVideoCaption(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: {
      captionId: string
      bucketId?: string
      fileId?: string
      name?: string
      code?: string
      isDefault?: boolean
    }) =>
      sdk.forProject(projectId!).videos.updateCaption({
        videoId: videoId!,
        captionId: input.captionId,
        bucketId: input.bucketId,
        fileId: input.fileId,
        name: input.name,
        code: input.code,
        xdefault: input.isDefault,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.captions(projectId, videoId),
      })
    },
  })
}

export function useDeleteVideoCaption(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (captionId: string) =>
      sdk.forProject(projectId!).videos.deleteCaption({
        videoId: videoId!,
        captionId,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.captions(projectId, videoId),
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
  /** Set on create only; a profile keeps its codec. */
  codec?: VideoCodecId
}

/** True when probe metadata includes an audio stream (after prepare / encode probe). */
export function videoSourceHasAudio(
  video: Pick<Models.Video, 'audioCodec' | 'duration'> | null | undefined,
): boolean {
  if (!video) return false
  if (video.audioCodec?.trim()) return true
  return false
}

export function useSaveVideoProfile(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: VideoProfileInput & { profileId?: string }) => {
      const project = sdk.forProject(projectId!).project
      const { profileId, codec, ...payload } = input
      return profileId
        ? project.updateVideoProfile({ profileId, ...payload })
        : project.createVideoProfile({
            profileId: ID.unique(),
            ...payload,
            codec,
          })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.profiles(projectId),
      })
    },
  })
}

/**
 * Create several profiles at once. Every create is attempted; the result
 * lists the inputs the server rejected with their error.
 */
export function useCreateVideoProfiles(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (inputs: VideoProfileInput[]) => {
      const project = sdk.forProject(projectId!).project
      const results = await Promise.allSettled(
        inputs.map((input) =>
          project.createVideoProfile({ profileId: ID.unique(), ...input }),
        ),
      )
      return results.flatMap((result, index) =>
        result.status === 'rejected'
          ? [{ input: inputs[index], error: result.reason as unknown }]
          : [],
      )
    },
    onSettled: async () => {
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
      sdk.forProject(projectId!).project.deleteVideoProfile({ profileId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.profiles(projectId),
      })
    },
  })
}
