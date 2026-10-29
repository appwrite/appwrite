/** Distinct colors for HLS, DASH, and CMAF across the Videos console. */
export type VideoOutputKey = 'hls' | 'dash' | 'cmaf'

export type VideoStreamingFormatKey =
  | VideoOutputKey
  | 'cmaf-hls'
  | 'cmaf-dash'

export type VideoStreamingFormatStyle = {
  /** Display hex for swatches and dev reference. */
  hex: string
  shortLabel: string
  badgeClassName: string
  labelClassName: string
}

export const VIDEO_STREAMING_FORMAT_STYLES: Record<
  VideoOutputKey,
  VideoStreamingFormatStyle
> = {
  hls: {
    hex: '#7C3AED',
    shortLabel: 'HLS',
    badgeClassName:
      'border-violet-500/25 bg-violet-500/10 text-violet-700 dark:text-violet-300',
    labelClassName: 'text-violet-700 dark:text-violet-300',
  },
  dash: {
    hex: '#2563EB',
    shortLabel: 'DASH',
    badgeClassName:
      'border-blue-500/25 bg-blue-500/10 text-blue-700 dark:text-blue-300',
    labelClassName: 'text-blue-700 dark:text-blue-300',
  },
  cmaf: {
    hex: '#0D9488',
    shortLabel: 'CMAF',
    badgeClassName:
      'border-teal-500/25 bg-teal-500/10 text-teal-700 dark:text-teal-300',
    labelClassName: 'text-teal-700 dark:text-teal-300',
  },
}

export function normalizeVideoOutputKey(value: string): VideoOutputKey | null {
  const lower = value.toLowerCase()
  if (lower === 'hls' || lower === 'dash' || lower === 'cmaf') {
    return lower
  }
  if (lower === 'cmaf-hls' || lower === 'cmaf-dash') {
    return 'cmaf'
  }
  return null
}

export function getVideoStreamingFormatStyle(
  value: string,
): VideoStreamingFormatStyle | null {
  const key = normalizeVideoOutputKey(value)
  return key ? VIDEO_STREAMING_FORMAT_STYLES[key] : null
}
