import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import {
  buildTar,
  gzip,
  isGzipSupported,
  type ArchiveEntry,
} from '@/lib/analytics/export/archive'

const SHEET_EXTENSIONS: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/avif': 'avif',
}

/** `Launch keynote` → `launch-keynote`, for download file names. */
export function videoFileSlug(name: string): string {
  let slug = ''
  for (const char of name.toLowerCase()) {
    const plain = (char >= 'a' && char <= 'z') || (char >= '0' && char <= '9')
    if (plain) slug += char
    else if (!slug.endsWith('-')) slug += '-'
  }
  while (slug.startsWith('-')) slug = slug.slice(1)
  while (slug.endsWith('-')) slug = slug.slice(0, -1)
  return slug || 'video'
}

function vttTimestamp(seconds: number): string {
  const total = Math.max(0, Math.round(seconds * 1000))
  const ms = total % 1000
  const s = Math.floor(total / 1000) % 60
  const m = Math.floor(total / 60_000) % 60
  const h = Math.floor(total / 3_600_000)
  const pad = (value: number, size = 2) => String(value).padStart(size, '0')
  return `${pad(h)}:${pad(m)}:${pad(s)}.${pad(ms, 3)}`
}

/** A thumbnail WebVTT whose cues point at `sheetName(cue.imageUrl)`. */
export function serializeTimelineVtt(
  cues: VideoTimelineCue[],
  sheetName: (imageUrl: string) => string,
): string {
  const blocks = cues.map(
    (cue) =>
      `${vttTimestamp(cue.start)} --> ${vttTimestamp(cue.end)}\n` +
      `${sheetName(cue.imageUrl)}#xywh=${cue.x},${cue.y},${cue.width},${cue.height}`,
  )
  return `WEBVTT\n\n${blocks.join('\n\n')}\n`
}

export type TimelineSheet = { bytes: Uint8Array; type: string }

/**
 * Every sprite sheet plus a `timeline.vtt` pointing at them by file name, so
 * the thumbnails work offline next to a local copy of the video.
 */
export async function buildTimelineArchive(
  cues: VideoTimelineCue[],
  fetchSheet: (url: string) => Promise<TimelineSheet>,
): Promise<{ blob: Blob; extension: 'tar.gz' | 'tar' }> {
  const urls = [...new Set(cues.map((cue) => cue.imageUrl))]
  const sheets = await Promise.all(urls.map(fetchSheet))
  const names = new Map(
    urls.map((url, index) => [
      url,
      `sprite-${index + 1}.${SHEET_EXTENSIONS[sheets[index].type] ?? 'jpg'}`,
    ]),
  )
  const entries: ArchiveEntry[] = [
    {
      path: 'timeline.vtt',
      content: serializeTimelineVtt(cues, (url) => names.get(url)!),
    },
    ...urls.map((url, index) => ({
      path: names.get(url)!,
      content: sheets[index].bytes,
    })),
  ]
  const tar = buildTar(entries)
  if (!isGzipSupported()) {
    return {
      blob: new Blob([tar as BlobPart], { type: 'application/x-tar' }),
      extension: 'tar',
    }
  }
  return { blob: await gzip(tar), extension: 'tar.gz' }
}

/** Loads a sheet with the Console session, as the thumbnails on screen do. */
export async function fetchTimelineSheet(url: string): Promise<TimelineSheet> {
  const response = await fetch(url, { credentials: 'include' })
  if (!response.ok) {
    throw new Error(`Sprite sheet request failed (HTTP ${response.status})`)
  }
  const type = (response.headers.get('content-type') ?? '').split(';')[0]
  return { bytes: new Uint8Array(await response.arrayBuffer()), type }
}
