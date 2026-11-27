import { buildConsoleUrl } from '@/lib/utils/context-menu'
import {
  embedPlayerSearchParams,
  type EmbedPlayerOptions,
} from './embed-player-options'

/** Public embed player (`/player` and `/player/:videoId`). */
export function isEmbedPlayerPath(
  pathname: string | null | undefined,
): boolean {
  if (!pathname) return false
  return pathname === '/player' || pathname.startsWith('/player/')
}

/** Query string of the public embed player: the project, then its options. */
export function getVideoEmbedPlayerQuery(
  projectId: string,
  options: EmbedPlayerOptions = {},
): string {
  const params = new URLSearchParams()
  params.set('project', projectId)
  for (const [key, value] of embedPlayerSearchParams(options)) {
    params.set(key, value)
  }
  return `?${params.toString()}`
}

/** Full console URL for the public embed player (`/player/:videoId?project=…`). */
export function getVideoEmbedPlayerUrl(
  projectId: string,
  videoId: string,
  options?: EmbedPlayerOptions,
): string {
  const path = `/player/${encodeURIComponent(videoId)}${getVideoEmbedPlayerQuery(projectId, options)}`
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
