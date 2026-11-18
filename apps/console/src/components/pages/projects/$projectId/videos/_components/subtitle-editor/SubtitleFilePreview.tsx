import { useMemo, useState } from 'react'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import {
  serializeSubRip,
  serializeWebVtt,
  stripCueMarkup,
  type SubtitleCue,
} from '@/lib/videos/subtitle-cues'

type FileFormat = 'vtt' | 'srt'

export function SubtitleFilePreview({
  cues,
  languageCode,
}: {
  cues: SubtitleCue[]
  languageCode: string
}) {
  const t = useT()
  const [format, setFormat] = useState<FileFormat>('vtt')
  const content = useMemo(
    () => (format === 'vtt' ? serializeWebVtt(cues) : serializeSubRip(cues)),
    [cues, format],
  )
  const hasEmptyCues = useMemo(
    () => cues.some((cue) => !stripCueMarkup(cue.text).trim()),
    [cues],
  )

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex shrink-0 items-center gap-2 border-b border-border px-4 py-2">
        <Select
          value={format}
          onValueChange={(value) => setFormat(value as FileFormat)}
        >
          <SelectTrigger className="h-8 w-[120px] text-[12px]" aria-label={t('Format')}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="vtt" className="text-[12px]">
              WebVTT
            </SelectItem>
            <SelectItem value="srt" className="text-[12px]">
              SRT
            </SelectItem>
          </SelectContent>
        </Select>
        <span className="truncate text-[11px] text-muted-foreground">
          {t('Read-only')}
        </span>
        <div className="ms-auto">
          <CodeSnippetCopyButton content={content} />
        </div>
      </div>
      {hasEmptyCues ? (
        <p className="shrink-0 border-b border-border bg-muted/30 px-4 py-2 text-[11px] text-muted-foreground">
          {t('Empty cues are left out of the file.')}
        </p>
      ) : null}
      <pre
        dir="ltr"
        lang={languageCode || undefined}
        className="min-h-0 flex-1 overflow-auto whitespace-pre px-4 py-3 font-mono text-[12px] leading-relaxed text-foreground [unicode-bidi:plaintext]"
      >
        {content}
      </pre>
    </div>
  )
}
