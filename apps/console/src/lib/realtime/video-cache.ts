/**
 * Merge Videos worker realtime payloads into React Query caches.
 *
 * The worker publishes raw documents (not response models) on:
 * - `videos.{videoId}.update` (probe metadata and document updates)
 * - `videos.{videoId}.renditions.{renditionId}.update` (encode status/progress)
 * - `videos.{videoId}.captions.{captionId}.update` (caption processing)
 * - `videos.{videoId}.timelines.update` (the video, with `timelineStatus`)
 *
 * Progress ticks arrive often, so payloads are merged by `$id` rather than
 * refetching. Unknown ids (e.g. a rendition created from another tab) fall back
 * to a single list refetch.
 */

import type { QueryClient } from '@tanstack/react-query'
import { videoKeys } from '@/lib/react-query/hooks/videos'

const VIDEO_EVENT = /^videos\.([^.*]+)\.update$/
const RENDITION_EVENT = /^videos\.([^.*]+)\.renditions\.([^.*]+)\.[a-z]+$/
const CAPTION_EVENT = /^videos\.([^.*]+)\.captions\.([^.*]+)\.[a-z]+$/

export function isVideoRealtimeSignal(events: string[]): boolean {
  return events.some((e) => e.startsWith('videos.'))
}

/** Video id of a `videos.{videoId}.timelines.update` event, if any. */
function timelineVideoId(events: string[]): string | null {
  for (const event of events) {
    const [service, videoId, resource, action, ...rest] = event.split('.')
    if (
      rest.length === 0 &&
      service === 'videos' &&
      resource === 'timelines' &&
      action === 'update' &&
      videoId &&
      videoId !== '*'
    ) {
      return videoId
    }
  }
  return null
}

function mergeVideo(
  queryClient: QueryClient,
  projectId: string,
  videoId: string,
  payload: Record<string, unknown>,
): void {
  queryClient.setQueryData(
    videoKeys.detail(projectId, videoId),
    (old: unknown) =>
      old && typeof old === 'object'
        ? { ...(old as Record<string, unknown>), ...payload }
        : old,
  )
  mergeIntoList(queryClient, videoKeys.list(projectId), 'videos', payload)
}

function findMatch(events: string[], pattern: RegExp): RegExpExecArray | null {
  for (const event of events) {
    const match = pattern.exec(event)
    if (match) return match
  }
  return null
}

function mergeIntoList<T extends string>(
  queryClient: QueryClient,
  queryKey: readonly unknown[],
  listField: T,
  payload: Record<string, unknown>,
  remove = false,
): boolean {
  const id = payload.$id
  let merged = false
  queryClient.setQueriesData({ queryKey, exact: false }, (old: unknown) => {
    const data = old as Record<string, unknown> | undefined
    const items = data?.[listField]
    if (!Array.isArray(items)) return old
    const idx = items.findIndex(
      (item) => (item as Record<string, unknown>).$id === id,
    )
    if (idx < 0) return old
    merged = true
    const next = [...items]
    if (remove) {
      next.splice(idx, 1)
      const total = data?.total
      return {
        ...data,
        [listField]: next,
        ...(typeof total === 'number' ? { total: Math.max(0, total - 1) } : {}),
      }
    }
    next[idx] = { ...(next[idx] as Record<string, unknown>), ...payload }
    return { ...data, [listField]: next }
  })
  return merged
}

export function handleVideoRealtimeEvents(
  queryClient: QueryClient,
  projectId: string,
  events: string[],
  payload: Record<string, unknown> | null,
): void {
  const rendition = findMatch(events, RENDITION_EVENT)
  if (rendition) {
    const [event, videoId] = rendition
    const key = videoKeys.renditions(projectId, videoId)
    const merged = payload?.$id
      ? mergeIntoList(
          queryClient,
          key,
          'renditions',
          payload,
          event.endsWith('.delete'),
        )
      : false
    if (!merged) void queryClient.refetchQueries({ queryKey: key })
    return
  }

  const caption = findMatch(events, CAPTION_EVENT)
  if (caption) {
    const [event, videoId] = caption
    const key = videoKeys.captions(projectId, videoId)
    const merged = payload?.$id
      ? mergeIntoList(
          queryClient,
          key,
          'captions',
          payload,
          event.endsWith('.delete'),
        )
      : false
    if (!merged) void queryClient.refetchQueries({ queryKey: key })
    return
  }

  const timelineId = timelineVideoId(events)
  if (timelineId) {
    if (payload?.$id) {
      mergeVideo(queryClient, projectId, timelineId, payload)
    } else {
      void queryClient.refetchQueries({
        queryKey: videoKeys.detail(projectId, timelineId),
      })
    }
    if (payload?.timelineStatus === 'ready') {
      void queryClient.invalidateQueries({
        queryKey: videoKeys.timeline(projectId, timelineId),
      })
    }
    return
  }

  const video = findMatch(events, VIDEO_EVENT)
  if (video) {
    const [, videoId] = video
    if (!payload?.$id) {
      void queryClient.refetchQueries({
        queryKey: videoKeys.detail(projectId, videoId),
      })
      return
    }
    mergeVideo(queryClient, projectId, videoId, payload)
  }
}
