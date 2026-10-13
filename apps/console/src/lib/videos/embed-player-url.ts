import { buildConsoleUrl } from '@/lib/utils/context-menu'

/** Public embed player (`/player` and `/player/:videoId`). */
export function isEmbedPlayerPath(
  pathname: string | null | undefined,
): boolean {
  if (!pathname) return false
  return pathname === '/player' || pathname.startsWith('/player/')
}

/** Full console URL for the public embed player (`/player/:videoId?project=…`). */
export function getVideoEmbedPlayerUrl(
  projectId: string,
  videoId: string,
  options?: { startSeconds?: number },
): string {
  const params = new URLSearchParams()
  params.set('project', projectId)
  const start = options?.startSeconds
  if (start != null && Number.isFinite(start) && start > 0) {
    params.set('start', String(start))
  }
  const path = `/player/${encodeURIComponent(videoId)}?${params.toString()}`
  return buildConsoleUrl(path)
}

export function buildVideoEmbedIframeHtml(
  playerUrl: string,
  title: string,
): string {
  const safeTitle = title.replace(/"/g, '&quot;')
  return `<iframe
  src="${playerUrl}"
  title="${safeTitle}"
  allow="autoplay; fullscreen; picture-in-picture"
  allowfullscreen
  style="width:100%;aspect-ratio:16/9;border:0;"
></iframe>`
}
