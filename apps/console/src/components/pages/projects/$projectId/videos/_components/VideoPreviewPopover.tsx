import type { Models } from '@appwrite.io/console'
import { useId, useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { CodeXml, Copy, SquareArrowOutUpRight, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import {
  embedPlayerSearchParams,
  parseEmbedColor,
  parseEmbedPlayerQuery,
  type EmbedPlayerOptions,
} from '@/lib/videos/embed-player-options'
import {
  buildVideoEmbedIframeHtml,
  getVideoEmbedPlayerQuery,
  getVideoEmbedPlayerUrl,
} from '@/lib/videos/embed-player-url'
import { maxEmbedStartSeconds } from '@/lib/videos/embed-start-time'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { formatPlayerClock } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Accent swatches; the first is the player's own white. */
const ACCENT_SWATCHES = [
  undefined,
  '#fd366e',
  '#3b82f6',
  '#10b981',
  '#f59e0b',
  '#8b5cf6',
] as const

/** Each change reloads the player page; wait until a drag or typing settles. */
const PREVIEW_RELOAD_DELAY_MS = 450

function OptionRow({
  id,
  label,
  param,
  description,
  control,
  children,
}: {
  id?: string
  label: string
  /** The parameter as it appears in the query string. */
  param: string
  description: string
  control?: ReactNode
  children?: ReactNode
}) {
  return (
    <div className="space-y-2.5 rounded-lg px-3 py-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Label htmlFor={id} className="text-[13px] font-medium">
              {label}
            </Label>
            <code
              dir="ltr"
              className="rounded border border-border bg-muted/60 px-1 font-mono text-[11px] leading-4 text-muted-foreground"
            >
              {param}
            </code>
          </div>
          <p className="text-[12px] leading-snug text-muted-foreground">
            {description}
          </p>
        </div>
        {control}
      </div>
      {children}
    </div>
  )
}

/**
 * The Preview button's panel: the public player page live in a frame, with
 * every option it reads from its query string. Options and the query string
 * edit each other, so either one can be changed.
 */
export function VideoPreviewPopover({
  projectId,
  video,
  align = 'end',
  children,
}: {
  projectId: string
  video: Pick<Models.Video, '$id'> & {
    name?: string | null
    duration?: number
  }
  align?: 'start' | 'end'
  /** The Preview button. */
  children: ReactNode
}) {
  const t = useT()
  const id = useId()
  const [open, setOpen] = useState(false)
  const [options, setOptions] = useState<EmbedPlayerOptions>({})
  // What someone is typing in the query string, until they leave the field.
  const [draft, setDraft] = useState<string | null>(null)

  const title = video.name?.trim() || t('Video player')
  const query = getVideoEmbedPlayerQuery(projectId, options)
  const playerUrl = getVideoEmbedPlayerUrl(projectId, video.$id, options)
  const previewUrl = useDebouncedValue(playerUrl, PREVIEW_RELOAD_DELAY_MS)
  const maxStart = maxEmbedStartSeconds(video.duration)
  const start = options.start ?? 0
  const accent = parseEmbedColor(options.color)
  const customAccent =
    accent != null && !ACCENT_SWATCHES.some((swatch) => swatch === accent)
  const isDefault = embedPlayerSearchParams(options).length === 0

  const update = (patch: Partial<EmbedPlayerOptions>) => {
    setDraft(null)
    setOptions((current) => ({ ...current, ...patch }))
  }

  const copyUrl = () => {
    void copyToClipboard(t('URL'), playerUrl, { showToast: false }).then(
      (copied) => {
        if (copied) toast.success(t('URL copied'))
      },
    )
  }

  const copyEmbedCode = () => {
    void copyToClipboard(
      t('Embed code'),
      buildVideoEmbedIframeHtml(playerUrl, title),
      { showToast: false },
    ).then((copied) => {
      if (copied) toast.success(t('Embed code copied'))
    })
  }

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) setDraft(null)
      }}
    >
      <PopoverTrigger asChild>{children}</PopoverTrigger>
      <PopoverContent
        align={align}
        side="bottom"
        sideOffset={8}
        collisionPadding={16}
        className="flex max-h-[min(calc(100dvh-5rem),780px)] w-[min(calc(100vw-2rem),1120px)] flex-col overflow-hidden p-0 shadow-xl"
      >
        <div className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div className="min-w-0">
            <h3 className="text-[14px] font-semibold text-foreground">
              {t('Player preview')}
            </h3>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t(
                'Each option is a parameter in the player URL, so a link or embed that carries it plays the same way.',
              )}
            </p>
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="-me-1.5 -mt-1 size-8 shrink-0"
            onClick={() => setOpen(false)}
            aria-label={t('Close')}
          >
            <X className="size-4" />
          </Button>
        </div>

        <div className="grid min-h-0 flex-1 overflow-y-auto md:grid-cols-[minmax(0,1fr)_19rem] md:overflow-hidden">
          <div className="flex min-w-0 flex-col gap-4 bg-muted/30 p-4 md:p-5">
            <div className="relative aspect-video w-full overflow-hidden rounded-lg bg-black shadow-sm ring-1 ring-border">
              {open ? (
                <iframe
                  src={previewUrl}
                  title={title}
                  allow="autoplay; fullscreen; picture-in-picture"
                  allowFullScreen
                  className="absolute inset-0 size-full border-0"
                />
              ) : null}
            </div>

            <div className="space-y-1.5">
              <Label
                htmlFor={`${id}-query`}
                className="text-[12px] font-medium text-muted-foreground"
              >
                {t('Query string')}
              </Label>
              <div
                dir="ltr"
                className="flex min-w-0 items-center overflow-hidden rounded-md border border-input bg-background shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
              >
                <span className="max-w-[40%] shrink-0 select-none truncate border-e border-border bg-muted/50 px-2.5 py-2 font-mono text-[12px] text-muted-foreground">
                  /player/{video.$id}
                </span>
                <input
                  id={`${id}-query`}
                  value={draft ?? query}
                  spellCheck={false}
                  autoComplete="off"
                  onChange={(event) => {
                    const text = event.target.value
                    setDraft(text)
                    setOptions(parseEmbedPlayerQuery(text))
                  }}
                  onBlur={() => setDraft(null)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') event.currentTarget.blur()
                  }}
                  className="min-w-0 flex-1 bg-transparent px-2.5 py-2 font-mono text-[12px] text-foreground outline-none"
                />
              </div>
            </div>
          </div>

          <div className="min-h-0 divide-y divide-border/60 border-t border-border p-2 md:overflow-y-auto md:border-s md:border-t-0">
            <OptionRow
              label={t('Start time')}
              param="start"
              description={t('Where playback begins, in seconds.')}
              control={
                <span className="shrink-0 pt-0.5 text-[13px] font-medium tabular-nums text-foreground">
                  {formatPlayerClock(start)}
                </span>
              }
            >
              {maxStart != null && maxStart > 0 ? (
                <Slider
                  aria-label={t('Start time')}
                  min={0}
                  max={maxStart}
                  step={1}
                  value={[Math.min(start, maxStart)]}
                  onValueChange={([next]) =>
                    update({ start: next ? next : undefined })
                  }
                />
              ) : null}
            </OptionRow>

            <OptionRow
              id={`${id}-autoplay`}
              label={t('Autoplay')}
              param="autoplay=1"
              description={t(
                'Plays as soon as the player loads. Browsers often allow it only when muted.',
              )}
              control={
                <Switch
                  id={`${id}-autoplay`}
                  checked={options.autoplay === true}
                  onCheckedChange={(checked) =>
                    update({ autoplay: checked || undefined })
                  }
                />
              }
            />

            <OptionRow
              id={`${id}-muted`}
              label={t('Start muted')}
              param="muted=1"
              description={t('Starts with the sound off.')}
              control={
                <Switch
                  id={`${id}-muted`}
                  checked={options.muted === true}
                  onCheckedChange={(checked) =>
                    update({ muted: checked || undefined })
                  }
                />
              }
            />

            <OptionRow
              id={`${id}-loop`}
              label={t('Loop')}
              param="loop=1"
              description={t('Plays again from the start when the video ends.')}
              control={
                <Switch
                  id={`${id}-loop`}
                  checked={options.loop === true}
                  onCheckedChange={(checked) =>
                    update({ loop: checked || undefined })
                  }
                />
              }
            />

            <OptionRow
              id={`${id}-controls`}
              label={t('Player controls')}
              param="controls=0"
              description={t(
                'Shows the control bar. Without it, viewers click the picture to play or pause.',
              )}
              control={
                <Switch
                  id={`${id}-controls`}
                  checked={options.controls !== false}
                  onCheckedChange={(checked) =>
                    update({ controls: checked ? undefined : false })
                  }
                />
              }
            />

            <OptionRow
              label={t('Accent color')}
              param="color"
              description={t('Colors the progress bar and the volume.')}
            >
              <div
                role="group"
                aria-label={t('Accent color')}
                className="flex flex-wrap items-center gap-2"
              >
                {ACCENT_SWATCHES.map((swatch) => {
                  const selected = swatch === accent
                  return (
                    <button
                      key={swatch ?? 'default'}
                      type="button"
                      aria-pressed={selected}
                      aria-label={swatch ?? t('Default')}
                      onClick={() => update({ color: swatch })}
                      className={cn(
                        'size-6 cursor-pointer rounded-full ring-offset-2 ring-offset-popover outline-none transition-shadow focus-visible:ring-2 focus-visible:ring-ring',
                        swatch == null &&
                          'border border-border bg-white shadow-xs',
                        selected && 'ring-2 ring-foreground',
                      )}
                      style={swatch ? { backgroundColor: swatch } : undefined}
                    />
                  )
                })}
                <label
                  className={cn(
                    'relative size-6 cursor-pointer overflow-hidden rounded-full bg-[conic-gradient(from_90deg,#f43f5e,#f59e0b,#84cc16,#06b6d4,#6366f1,#d946ef,#f43f5e)] ring-offset-2 ring-offset-popover focus-within:ring-2 focus-within:ring-ring',
                    customAccent && 'ring-2 ring-foreground',
                  )}
                  style={customAccent ? { background: accent } : undefined}
                >
                  <span className="sr-only">{t('Custom color')}</span>
                  <input
                    type="color"
                    value={accent ?? '#ffffff'}
                    onChange={(event) => update({ color: event.target.value })}
                    className="absolute inset-0 size-full cursor-pointer opacity-0"
                  />
                </label>
              </div>
            </OptionRow>
          </div>
        </div>

        <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/30 px-5 py-3">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 text-[13px]"
            disabled={isDefault}
            onClick={() => {
              setDraft(null)
              setOptions({})
            }}
          >
            {t('Reset')}
          </Button>
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-[13px]"
              onClick={() => openInNewTab(playerUrl)}
            >
              <SquareArrowOutUpRight className="size-3.5" />
              {t('Open in new tab')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-[13px]"
              onClick={copyEmbedCode}
            >
              <CodeXml className="size-3.5" />
              {t('Copy embed code')}
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-8 gap-1.5 text-[13px]"
              onClick={copyUrl}
            >
              <Copy className="size-3.5" />
              {t('Copy URL')}
            </Button>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}
