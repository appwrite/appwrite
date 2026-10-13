import { Captions, FileText, PenLine, Play, Upload } from 'lucide-react'
import { VideoActionButton } from './VideoPage'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: PenLine,
    title: 'Write in the editor',
    description:
      'Type captions while the video plays, then drag cues on the timeline to fine-tune their timing.',
  },
  {
    icon: Upload,
    title: 'Or import a file',
    description:
      'Bring WebVTT or SRT files from Storage. Tracks embedded in the source are extracted automatically.',
  },
  {
    icon: Captions,
    title: 'Ship with every stream',
    description:
      'Captions are listed in the manifest, so viewers can pick a language in any player.',
  },
]

const CUES = [
  { start: '00:01.2', end: '00:03.8', width: 'w-24', active: false },
  { start: '00:04.0', end: '00:06.5', width: 'w-28', active: true },
  { start: '00:06.9', end: '00:09.1', width: 'w-20', active: false },
] as const

/** Decorative player showing a caption, with its cue list and language tracks. */
function SubtitlesVisual() {
  return (
    <ProductEmptyStateVisual className="w-[360px] pb-8">
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xl">
        <div className="relative aspect-video overflow-hidden bg-gradient-to-br from-muted via-muted/70 to-muted-foreground/25">
          <div className="absolute -bottom-1/3 -start-1/4 h-full w-3/4 rounded-full bg-muted-foreground/10 blur-2xl" />
          <span className="absolute inset-0 flex items-center justify-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-background/70 text-foreground/80 backdrop-blur-sm">
              <Play className="ms-0.5 h-4 w-4 fill-current" />
            </span>
          </span>
          <span className="absolute inset-x-0 bottom-4 flex flex-col items-center gap-1">
            <span className="rounded-sm bg-background/80 px-1.5 py-1">
              <span className="block h-1.5 w-32 rounded-full bg-foreground/60" />
            </span>
            <span className="rounded-sm bg-background/80 px-1.5 py-1">
              <span className="block h-1.5 w-20 rounded-full bg-foreground/60" />
            </span>
          </span>
        </div>
      </div>

      <div className="absolute -end-24 top-10 w-48 rounded-lg border border-border bg-popover p-1 text-start shadow-lg">
        {CUES.map((cue) => (
          <div
            key={cue.start}
            className={cn(
              'rounded-md px-2 py-1.5',
              cue.active && 'bg-muted',
            )}
          >
            <span
              dir="ltr"
              className={cn(
                'block font-mono text-[10px] tabular-nums',
                cue.active ? 'text-foreground' : 'text-muted-foreground',
              )}
            >
              {cue.start} → {cue.end}
            </span>
            <span
              className={cn(
                'mt-1 block h-1.5 rounded-full',
                cue.width,
                cue.active
                  ? 'bg-[var(--brand-cta)]'
                  : 'bg-muted-foreground/25',
              )}
            />
          </div>
        ))}
      </div>

      <div className="absolute -start-16 top-0 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Captions className="h-3.5 w-3.5 text-muted-foreground" />
        {['en', 'fr', 'ja'].map((language, index) => (
          <span
            key={language}
            className={cn(
              'rounded px-1 font-mono text-[10px] leading-4',
              index === 0
                ? 'bg-foreground text-background'
                : 'text-muted-foreground',
            )}
          >
            {language}
          </span>
        ))}
      </div>

      <div className="absolute -start-24 bottom-0 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <FileText className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          captions.vtt
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function SubtitlesEmptyState({
  onCreate,
  onUpload,
  disabledReason,
}: {
  onCreate: () => void
  onUpload: () => void
  disabledReason?: string | null
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-2 sm:py-6">
      <ProductEmptyStateHero
        visual={<SubtitlesVisual />}
        icon={Captions}
        title={t('Add captions to this video')}
        description={t(
          'Make this video accessible in every language your viewers speak. Players show the tracks you add here alongside the stream.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={Boolean(disabledReason)}
              disabledTooltip={disabledReason ? t(disabledReason) : undefined}
            >
              {t('Create caption')}
            </ProductEmptyStateCreateButton>
            <VideoActionButton
              variant="outline"
              className="h-9 text-[13px]"
              onClick={onUpload}
              disabledReason={disabledReason}
            >
              {t('Upload caption')}
            </VideoActionButton>
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
