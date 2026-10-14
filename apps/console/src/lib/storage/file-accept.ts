/**
 * Match Storage files against accept rules (HTML-style patterns).
 *
 * Supported rule forms:
 * - `video/*`, `audio/*` — MIME type prefix
 * - `text/vtt` — exact MIME type (case insensitive)
 * - `.vtt`, `.srt` — file extension (case insensitive)
 */
export type StorageFileAcceptInput = string | readonly string[]

export const STORAGE_FILE_ACCEPT_VIDEO_AUDIO = ['video/*', 'audio/*'] as const

/** WebVTT / SubRip subtitle files in Storage. */
export const STORAGE_FILE_ACCEPT_SUBTITLE = [
  '.vtt',
  '.srt',
  'text/vtt',
  'application/x-subrip',
] as const

export function normalizeStorageFileAccept(
  accept?: StorageFileAcceptInput,
): string[] | undefined {
  if (accept == null) return undefined
  const rules = (Array.isArray(accept) ? accept : [accept]).map((r) => r.trim())
  const filtered = rules.filter(Boolean)
  return filtered.length > 0 ? filtered : undefined
}

function fileExtension(name: string): string {
  const dot = name.lastIndexOf('.')
  if (dot <= 0 || dot === name.length - 1) return ''
  return name.slice(dot).toLowerCase()
}

export function storageFileMatchesAccept(
  file: { mimeType?: string | null; name?: string | null },
  accept: StorageFileAcceptInput,
): boolean {
  const rules = normalizeStorageFileAccept(accept)
  if (!rules) return true

  const mime =
    (file.mimeType ?? '').split(';')[0]?.trim().toLowerCase() ?? ''
  const ext = fileExtension(file.name ?? '')

  for (const rule of rules) {
    const r = rule.toLowerCase()
    if (r.startsWith('.')) {
      if (ext === r) return true
      continue
    }
    if (r.endsWith('/*')) {
      const prefix = r.slice(0, -1)
      if (mime.startsWith(prefix)) return true
      continue
    }
    if (mime === r) return true
    if (r === 'text/plain' && (ext === '.srt' || ext === '.vtt')) return true
  }

  return false
}

export function storageAcceptToHtmlAccept(accept: StorageFileAcceptInput): string {
  const rules = normalizeStorageFileAccept(accept)
  return rules?.join(',') ?? ''
}

export function isStorageVideoOrAudioFile(file: {
  mimeType?: string | null
  name?: string | null
}): boolean {
  return storageFileMatchesAccept(file, STORAGE_FILE_ACCEPT_VIDEO_AUDIO)
}

export function isStorageSubtitleFile(file: {
  mimeType?: string | null
  name?: string | null
}): boolean {
  if (storageFileMatchesAccept(file, STORAGE_FILE_ACCEPT_SUBTITLE)) return true
  const ext = fileExtension(file.name ?? '')
  if (ext === '.srt' || ext === '.vtt') {
    return (file.mimeType ?? '').split(';')[0]?.trim().toLowerCase() === 'text/plain'
  }
  return false
}
