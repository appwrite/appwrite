import { assetUrl } from '@/lib/asset-url'
import {
  InitGrandPrizeCsvError,
  parseInitGrandPrizeEntries,
  type InitGrandPrizeEntriesParseResult,
  type InitGrandPrizeEntry,
} from '@/lib/init/grand-prize-entries'
import type { LaunchEventGrandPrize } from '@/lib/init/types'
import { buildInitSpinningGiveawayRaffleActivity } from '@/lib/init/init-presence-activity'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { useInitThemeImageSrc } from '@/lib/init/use-init-theme-image'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ArrowUpRight, Loader2, Sparkles, Trophy, Upload, X } from 'lucide-react'
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
  type DragEvent,
} from 'react'
import {
  computeRaffleWheelRotation,
  INIT_GIVEAWAY_RAFFLE_SPIN_MS,
  INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE,
  InitGiveawayRaffleWheel,
} from './InitGiveawayRaffleWheel'
import { useInitGiveawayRaffleContext } from './init-giveaway-raffle-context'
import { PRIZE_CARD_BG } from './prize-image-styles'

const PLATFORM_META = {
  youtube: { label: 'YouTube', icon: assetUrl('/icons/youtube.svg') },
  discord: { label: 'Discord', icon: assetUrl('/icons/discord-simple.svg') },
  reddit: { label: 'Reddit', icon: assetUrl('/icons/reddit.svg') },
} as const

const CSV_ACCEPT = '.csv,text/csv,text/plain'

interface InitGrandPrizeRevealBackProps {
  grandPrize: LaunchEventGrandPrize
  /** Parsed verified-entries CSV, or null before the host uploads one. */
  entries: InitGrandPrizeEntriesParseResult | null
  onEntriesChange: (entries: InitGrandPrizeEntriesParseResult | null) => void
  onClose: () => void
}

function isCsvFile(file: File): boolean {
  if (file.name.toLowerCase().endsWith('.csv')) return true
  return file.type === 'text/csv' || file.type === 'application/vnd.ms-excel'
}

function WinnerLink({ href, label }: { href: string; label: string }) {
  if (!href) return null

  return (
    <a
      href={assetUrl(href)}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center gap-1 text-[12px] font-medium text-foreground underline-offset-4 hover:underline"
    >
      {label}
      <ArrowUpRight className="size-3" aria-hidden />
    </a>
  )
}

export function InitGrandPrizeRevealBack({
  grandPrize,
  entries,
  onEntriesChange,
  onClose,
}: InitGrandPrizeRevealBackProps) {
  const t = useT()
  const prizeImageSrc = useInitThemeImageSrc(
    grandPrize.visual?.imageSrcLight ?? '',
    grandPrize.visual?.imageSrcDark ?? '',
  )
  const hasPrizeImage = Boolean(
    grandPrize.visual?.imageSrcLight && grandPrize.visual?.imageSrcDark,
  )
  const platformMeta = grandPrize.platform ? PLATFORM_META[grandPrize.platform] : null

  const [isReadingFile, setIsReadingFile] = useState(false)
  const [fileError, setFileError] = useState<string | null>(null)
  const [isDragOver, setIsDragOver] = useState(false)
  const [rotation, setRotation] = useState(0)
  const [isSpinning, setIsSpinning] = useState(false)
  const [winner, setWinner] = useState<InitGrandPrizeEntry | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const spinTimeoutRef = useRef<number | null>(null)
  const raffleContext = useInitGiveawayRaffleContext()
  const { setTransientActivity } = useInitPresenceActivity()

  // Closing the card mid-spin must not publish a winner into the shared context later.
  useEffect(
    () => () => {
      if (spinTimeoutRef.current != null) window.clearTimeout(spinTimeoutRef.current)
    },
    [],
  )

  useEffect(() => {
    if (!isSpinning) {
      setTransientActivity(null)
      return
    }

    setTransientActivity(buildInitSpinningGiveawayRaffleActivity())
    return () => setTransientActivity(null)
  }, [isSpinning, setTransientActivity])

  const eligible = useMemo(() => entries?.entries ?? [], [entries])
  const wheelSegments = useMemo(
    () => eligible.map((entry) => ({ id: entry.id, label: entry.name })),
    [eligible],
  )

  const canSpin = eligible.length > 0 && !isSpinning && !isReadingFile
  const canUpload = !isSpinning && !isReadingFile

  const loadFile = useCallback(
    async (file: File) => {
      if (!isCsvFile(file)) {
        setFileError(t('Only CSV files are accepted.'))
        return
      }

      setIsReadingFile(true)
      setFileError(null)
      setWinner(null)
      raffleContext?.clearRaffleWinner()

      try {
        const text = await file.text()
        const parsed = parseInitGrandPrizeEntries(text)
        onEntriesChange(parsed)
        setRotation(0)

        if (parsed.entries.length === 0) {
          setFileError(t('The CSV has no eligible entries.'))
        }
      } catch (error) {
        // A rejected file must never leave the previous upload drawable.
        onEntriesChange(null)
        setRotation(0)

        if (error instanceof InitGrandPrizeCsvError) {
          setFileError(
            error.code === 'missing-columns'
              ? `${t('Missing required CSV columns:')} ${error.missingColumns.join(', ')}`
              : error.code === 'unterminated-quote'
                ? t('The CSV has an unterminated quoted value.')
                : t('The CSV file is empty.'),
          )
        } else {
          setFileError(t('Could not read that file.'))
        }
      } finally {
        setIsReadingFile(false)
      }
    },
    [onEntriesChange, raffleContext, t],
  )

  const handleFileInputChange = useCallback(
    (changeEvent: ChangeEvent<HTMLInputElement>) => {
      const file = changeEvent.target.files?.[0]
      // Reset so picking the same file again re-triggers the change event.
      changeEvent.target.value = ''
      if (file) void loadFile(file)
    },
    [loadFile],
  )

  const handleDrop = useCallback(
    (dropEvent: DragEvent<HTMLDivElement>) => {
      dropEvent.preventDefault()
      setIsDragOver(false)
      if (!canUpload) return

      const file = dropEvent.dataTransfer.files?.[0]
      if (file) void loadFile(file)
    },
    [canUpload, loadFile],
  )

  const handleDragOver = useCallback(
    (dragEvent: DragEvent<HTMLDivElement>) => {
      dragEvent.preventDefault()
      if (canUpload) setIsDragOver(true)
    },
    [canUpload],
  )

  const handleSpin = useCallback(() => {
    if (!canSpin) return

    const winnerIndex = Math.floor(Math.random() * eligible.length)
    const nextWinner = eligible[winnerIndex]
    const nextRotation = computeRaffleWheelRotation(winnerIndex, eligible.length, rotation)

    setWinner(null)
    raffleContext?.clearRaffleWinner()
    setIsSpinning(true)
    setRotation(nextRotation)

    spinTimeoutRef.current = window.setTimeout(() => {
      spinTimeoutRef.current = null
      setWinner(nextWinner)
      setIsSpinning(false)
      raffleContext?.celebrateRaffleWinner(nextWinner.id)
    }, INIT_GIVEAWAY_RAFFLE_SPIN_MS)
  }, [canSpin, eligible, raffleContext, rotation])

  return (
    <div
      className={cn(
        'flex h-full min-h-[min(100%,640px)] flex-col overflow-hidden rounded-xl border border-border transition-colors',
        PRIZE_CARD_BG,
        isDragOver && 'border-[var(--brand-cta)]',
      )}
      onDragOver={handleDragOver}
      onDragLeave={() => setIsDragOver(false)}
      onDrop={handleDrop}
    >
      <input
        ref={fileInputRef}
        type="file"
        accept={CSV_ACCEPT}
        className="hidden"
        onChange={handleFileInputChange}
        aria-label={t('Upload CSV')}
      />

      <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4 sm:px-6">
        <div className="min-w-0 space-y-1">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
            Grand prize · Day {grandPrize.day} · {grandPrize.dateLabel}
          </p>
          <h4 className="text-[18px] font-semibold tracking-tight text-foreground">
            {t('Live winner draw')}
          </h4>
          <p className="text-[13px] text-muted-foreground">
            {t('Drawn only from the verified social entries in the uploaded CSV. Online users are not part of this draw.')}
          </p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-8 shrink-0"
          onClick={onClose}
          aria-label="Close winner reveal"
        >
          <X className="size-4" />
        </Button>
      </div>

      <div className="grid flex-1 gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_280px] xl:grid-cols-[minmax(0,1fr)_320px] lg:items-center">
        <div className="flex flex-col items-center justify-center gap-5 py-2">
          {isReadingFile ? (
            <div
              className="flex items-center justify-center rounded-full border border-border bg-muted/20"
              style={{ width: INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE, height: INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE }}
            >
              <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden />
            </div>
          ) : (
            <InitGiveawayRaffleWheel
              segments={wheelSegments}
              rotation={rotation}
              emptyMessage={t('Upload the verified entries CSV to build the wheel.')}
            />
          )}

          <div className="flex flex-col items-center gap-2">
            {fileError ? (
              <p className="max-w-[360px] text-center text-[12px] text-destructive" role="alert">
                {fileError}
              </p>
            ) : null}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-[12px]"
              disabled={!canUpload}
              onClick={() => fileInputRef.current?.click()}
            >
              <Upload className="size-3.5" aria-hidden />
              {entries ? t('Replace CSV') : t('Upload CSV')}
            </Button>
          </div>

          <Button
            type="button"
            className="h-10 min-w-[180px] text-[13px]"
            disabled={!canSpin}
            onClick={handleSpin}
          >
            {isSpinning ? (
              <>
                <Loader2 className="size-4 animate-spin" aria-hidden />
                Spinning…
              </>
            ) : winner ? (
              t('Spin again')
            ) : (
              t('Spin the wheel')
            )}
          </Button>
        </div>

        <div className="flex flex-col gap-4">
          <div
            className={cn(
              'overflow-hidden rounded-xl border border-border bg-card',
              winner && 'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))]',
            )}
          >
            {hasPrizeImage ? (
              <div className="relative aspect-[4/3] overflow-hidden bg-muted/20">
                <img
                  src={assetUrl(prizeImageSrc)}
                  alt={grandPrize.visual?.imageAlt ?? grandPrize.title}
                  className="absolute inset-0 size-full object-cover object-center"
                />
              </div>
            ) : null}
            <div className="space-y-2 p-4">
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                Grand prize
              </p>
              <p className="text-[15px] font-semibold text-foreground">{grandPrize.title}</p>
              <p className="text-[13px] text-muted-foreground">{grandPrize.description}</p>
              {grandPrize.sessionTitle && platformMeta ? (
                <div className="flex min-w-0 items-center gap-1.5 pt-1 text-[11px] text-muted-foreground">
                  <img src={assetUrl(platformMeta.icon)} alt="" className="size-3 shrink-0 opacity-70" aria-hidden />
                  <span className="truncate">
                    {platformMeta.label} · {grandPrize.sessionTitle}
                  </span>
                </div>
              ) : null}
            </div>
          </div>

          <div
            className={cn(
              'rounded-xl border border-border bg-muted/20 p-4 transition-colors',
              winner &&
                'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_8%,transparent)]',
            )}
          >
            {winner ? (
              <div className="flex items-start gap-3">
                <InitialsAvatar name={winner.name} size="md" className="rounded-full" />
                <div className="min-w-0 space-y-1.5">
                  <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--brand-cta)]">
                    <Trophy className="size-3.5" aria-hidden />
                    Grand prize winner
                  </div>
                  <p className="truncate text-[15px] font-semibold text-foreground" title={winner.name}>
                    {winner.name}
                  </p>
                  {winner.username ? (
                    <p className="truncate text-[12px] text-muted-foreground">@{winner.username}</p>
                  ) : null}
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <WinnerLink href={winner.postUrl} label={t('View post')} />
                    <WinnerLink href={winner.ticketUrl} label={t('View ticket')} />
                  </div>
                  {winner.ticketId ? (
                    <CopyableId
                      id={winner.ticketId}
                      size="xs"
                      maxWidth={140}
                      copyToastLabel="Ticket ID"
                    />
                  ) : null}
                </div>
              </div>
            ) : (
              <div className="flex items-start gap-3 text-muted-foreground">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background/70">
                  <Sparkles className="size-4" aria-hidden />
                </span>
                <p className="text-[13px] leading-relaxed">
                  {isSpinning
                    ? 'Hold tight - the wheel is spinning.'
                    : entries
                      ? t('Press spin the wheel when you are ready to draw the grand prize winner.')
                      : t('Upload the CSV first. Drop it anywhere on this card or use Upload CSV.')}
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
