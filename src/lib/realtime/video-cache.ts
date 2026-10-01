/**
 * Merge Videos worker realtime payloads into React Query caches.
 *
 * The worker publishes raw documents (not response models) on:
 * - `videos.{videoId}.update` (probe metadata and document updates)
 * - `videos.{videoId}.renditions.{renditionId}.update` (encode status/progress)
 * - `videos.{videoId}.subtitles.{subtitleId}.update` (subtitle processing)
 *
 * Progress ticks arrive often, so payloads are merged by `$id` rather than
 * refetching. Unknown ids (e.g. a rendition created from another tab) fall back
 * to a single list refetch.
 */

import type { QueryClient } from '@tanstack/react-query'
import { videoKeys } from '@/lib/react-query/hooks/videos'

const VIDEO_EVENT = /^videos\.([^.*]+)\.update$/
const RENDITION_EVENT = /^videos\.([^.*]+)\.renditions\.([^.*]+)\.[a-z]+$/
const SUBTITLE_EVENT = /^videos\.([^.*]+)\.subtitles\.([^.*]+)\.[a-z]+$/

export function isVideoRealtimeSignal(events: string[]): boolean {
  return events.some((e) => e.startsWith('videos.'))
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
    const [, videoId] = rendition
    const key = videoKeys.renditions(projectId, videoId)
    const merged = payload?.$id
      ? mergeIntoList(queryClient, key, 'renditions', payload)
      : false
    if (!merged) void queryClient.refetchQueries({ queryKey: key })
    return
  }

  const subtitle = findMatch(events, SUBTITLE_EVENT)
  if (subtitle) {
    const [, videoId] = subtitle
    const key = videoKeys.subtitles(projectId, videoId)
    const merged = payload?.$id
      ? mergeIntoList(queryClient, key, 'subtitles', payload)
      : false
    if (!merged) void queryClient.refetchQueries({ queryKey: key })
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
    queryClient.setQueryData(
      videoKeys.detail(projectId, videoId),
      (old: unknown) =>
        old && typeof old === 'object'
          ? { ...(old as Record<string, unknown>), ...payload }
          : old,
    )
    mergeIntoList(queryClient, videoKeys.list(projectId), 'videos', payload)
  }
}
