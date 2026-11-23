export type VideoSubtitleSize = 'compact' | 'standard' | 'large'

export const VIDEO_SUBTITLE_SIZE_DEFAULT: VideoSubtitleSize = 'standard'

export const VIDEO_SUBTITLE_SIZES: readonly VideoSubtitleSize[] = [
  'compact',
  'standard',
  'large',
]

const SUBTITLE_SIZE_CSS: Record<VideoSubtitleSize, string> = {
  compact: '0.85em',
  standard: '1em',
  large: '1.2em',
}

/** Lift native cues when controls sit below the video (not overlaid). */
export const VIDEO_SUBTITLE_INSET_CONSOLE_PX = 12

/** Gap between the interactive controls and the cue line in overlay mode. */
export const VIDEO_SUBTITLE_INSET_OVERLAY_GAP_PX = 4

/** Overlay controls include top gradient padding; cues can sit closer to the seek bar. */
export const VIDEO_SUBTITLE_OVERLAY_GRADIENT_PAD_PX = 24

export function parseVideoSubtitleSize(
  value: unknown,
): VideoSubtitleSize | null {
  if (value === 'compact' || value === 'standard' || value === 'large') {
    return value
  }
  return null
}

export function resolveVideoSubtitleSize(
  value: VideoSubtitleSize | null | undefined,
): VideoSubtitleSize {
  return value ?? VIDEO_SUBTITLE_SIZE_DEFAULT
}

export function videoSubtitleSizeCss(size: VideoSubtitleSize): string {
  return SUBTITLE_SIZE_CSS[size]
}

export function videoSubtitleSizeLabelKey(size: VideoSubtitleSize): string {
  switch (size) {
    case 'compact':
      return 'Compact subtitles'
    case 'large':
      return 'Large subtitles'
    default:
      return 'Standard subtitles'
  }
}
