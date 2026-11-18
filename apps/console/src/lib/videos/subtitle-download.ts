import {
  serializeSubRip,
  serializeWebVtt,
  subtitleFileName,
  type SubtitleCue,
} from '@/lib/videos/subtitle-cues'

export type SubtitleDownloadFormat = 'vtt' | 'srt'

export const SUBTITLE_DOWNLOAD_FORMATS: ReadonlyArray<{
  id: SubtitleDownloadFormat
  label: string
}> = [
  { id: 'vtt', label: 'Download WebVTT' },
  { id: 'srt', label: 'Download SRT' },
]

export function downloadTextFile(content: string, fileName: string, type: string) {
  const url = URL.createObjectURL(new Blob([content], { type }))
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  anchor.click()
  URL.revokeObjectURL(url)
}

export function downloadSubtitleCues(
  cues: SubtitleCue[],
  name: string,
  code: string,
  format: SubtitleDownloadFormat,
) {
  const fileName = subtitleFileName(name || 'subtitles', code, format)
  if (format === 'vtt') {
    downloadTextFile(serializeWebVtt(cues), fileName, 'text/vtt')
  } else {
    downloadTextFile(serializeSubRip(cues), fileName, 'application/x-subrip')
  }
}
