import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Slider } from '@/components/ui/slider'
import {
  TimeInput,
  type TimeInputValue,
} from '@/components/global/shared/TimeInput'
import {
  buildVideoEmbedIframeHtml,
  getVideoEmbedPlayerUrl,
} from '@/lib/videos/embed-player-url'
import {
  clampSecondsToDuration,
  clampTimeInputToDuration,
  maxEmbedStartSeconds,
  secondsToTimeInputValue,
  timeInputValueToSeconds,
} from '@/lib/videos/embed-start-time'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  formatPlayerClock,
  formatVideoDuration,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'

type VideoEmbedProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  videoId: string
  videoTitle: string
  durationMs?: number | null
}

export function VideoEmbed({
  open,
  onOpenChange,
  projectId,
  videoId,
  videoTitle,
  durationMs,
}: VideoEmbedProps) {
  const t = useT()
  const [customStartTime, setCustomStartTime] = useState(false)
  const [startSeconds, setStartSeconds] = useState(0)
  const [manualStartTime, setManualStartTime] = useState<TimeInputValue>(
    secondsToTimeInputValue(0),
  )

  const maxStartSeconds = maxEmbedStartSeconds(durationMs)
  const hasKnownDuration = maxStartSeconds != null && maxStartSeconds > 0

  useEffect(() => {
    if (!open) return
    setCustomStartTime(false)
    setStartSeconds(0)
    setManualStartTime(secondsToTimeInputValue(0))
  }, [open, videoId])

  const effectiveStartSeconds = customStartTime
    ? hasKnownDuration
      ? startSeconds
      : timeInputValueToSeconds(manualStartTime)
    : 0

  const playerUrl = useMemo(
    () =>
      getVideoEmbedPlayerUrl(projectId, videoId, {
        startSeconds:
          effectiveStartSeconds > 0 ? effectiveStartSeconds : undefined,
      }),
    [projectId, videoId, effectiveStartSeconds],
  )
  const embedCode = useMemo(
    () => buildVideoEmbedIframeHtml(playerUrl, videoTitle),
    [playerUrl, videoTitle],
  )

  const handleManualStartChange = (next: TimeInputValue) => {
    setManualStartTime(clampTimeInputToDuration(next, durationMs))
  }

  const handleSliderChange = (value: number[]) => {
    const next = value[0] ?? 0
    setStartSeconds(clampSecondsToDuration(next, durationMs))
  }

  const copyCode = () => {
    void copyToClipboard(t('Embed code'), embedCode).then(() =>
      toast.success(t('Embed code copied')),
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(92dvh,720px)] w-full max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Embed video')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Copy the iframe snippet below. Optionally set a start time for playback.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="shrink-0 border-t border-border" />
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
          <div className="space-y-3">
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0 space-y-1">
                <Label
                  htmlFor="embed-custom-start"
                  className="text-[13px] font-medium"
                >
                  {t('Custom start time')}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {customStartTime
                    ? t('Playback begins at this time when the embed loads.')
                    : t('Embed plays from the beginning.')}
                </p>
              </div>
              <Switch
                id="embed-custom-start"
                checked={customStartTime}
                onCheckedChange={(checked) => {
                  setCustomStartTime(checked)
                  if (!checked) {
                    setStartSeconds(0)
                    setManualStartTime(secondsToTimeInputValue(0))
                  }
                }}
                className="shrink-0"
              />
            </div>

            {customStartTime ? (
              hasKnownDuration ? (
                <div className="space-y-2 pt-1">
                  <div className="flex items-center justify-between gap-3 font-mono text-[13px] tabular-nums text-foreground">
                    <span>{formatPlayerClock(startSeconds)}</span>
                    <span className="text-muted-foreground">
                      {formatVideoDuration(durationMs)}
                    </span>
                  </div>
                  <Slider
                    aria-label={t('Start time')}
                    min={0}
                    max={maxStartSeconds ?? 0}
                    step={1}
                    value={[startSeconds]}
                    onValueChange={handleSliderChange}
                  />
                </div>
              ) : (
                <div className="space-y-2 pt-1">
                  <Label className="text-[13px]">{t('Start time')}</Label>
                  <TimeInput
                    value={manualStartTime}
                    onChange={handleManualStartChange}
                    showSeconds
                    className="max-w-[14rem]"
                  />
                </div>
              )
            ) : null}
          </div>
          <div className="min-w-0 space-y-2">
            <Label className="text-[13px]">{t('Embed code')}</Label>
            <CodeBlock
              code={embedCode}
              language="markup"
              showCopy={false}
              className="min-w-0"
            />
          </div>
        </div>
        <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t('Close')}
          </Button>
          <Button type="button" onClick={copyCode}>
            {t('Copy embed code')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
