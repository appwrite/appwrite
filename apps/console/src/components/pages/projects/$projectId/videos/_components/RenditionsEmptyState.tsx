import { Link } from '@tanstack/react-router'
import { Check, FileVideo, Layers, ListVideo, Radio, Settings2 } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { VideoTermHint } from './VideoTermHint'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Settings2,
    title: 'Pick encoding profiles',
    description:
      'Each profile you select becomes one rendition at its own resolution and bitrate.',
    hint: <VideoTermHint term="profile" />,
  },
  {
    icon: Layers,
    title: 'Choose an output format',
    description:
      'Package the renditions as HLS, DASH, or CMAF. Appwrite encodes them in the background.',
  },
  {
    icon: Radio,
    title: 'Stream the manifest',
    description:
      'Point any player at the manifest URL. It switches renditions as bandwidth changes.',
    hint: <VideoTermHint term="manifest" />,
  },
]

const RENDITIONS = [
  { label: '1080p', bitrate: '5.0 Mbps', progress: 100 },
  { label: '720p', bitrate: '2.8 Mbps', progress: 100 },
  { label: '480p', bitrate: '1.2 Mbps', progress: 64 },
] as const

/** Decorative renditions encoding from one source into a streamable manifest. */
function RenditionsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[360px] pb-6">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Layers className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
          <span
            dir="ltr"
            className="ms-auto rounded border border-border bg-background px-1.5 font-mono text-[10px] leading-4 text-muted-foreground"
          >
            HLS
          </span>
        </div>
        <ul dir="ltr" className="divide-y divide-border">
          {RENDITIONS.map((rendition) => {
            const ready = rendition.progress === 100
            return (
              <li
                key={rendition.label}
                className="grid grid-cols-[3rem_1fr_auto] items-center gap-3 px-3 py-2.5 font-mono text-[10px]"
              >
                <span className="text-foreground">{rendition.label}</span>
                <div className="h-1 overflow-hidden rounded-full bg-muted">
                  <div
                    className={cn(
                      'h-full rounded-full',
                      ready
                        ? 'bg-muted-foreground/40'
                        : 'bg-[var(--brand-cta)]',
                    )}
                    style={{ width: `${rendition.progress}%` }}
                  />
                </div>
                <span className="flex items-center gap-1.5 text-muted-foreground">
                  {rendition.bitrate}
                  {ready ? (
                    <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                  ) : (
                    <span className="w-3 text-end tabular-nums text-foreground">
                      {rendition.progress}
                    </span>
                  )}
                </span>
              </li>
            )
          })}
        </ul>
      </div>

      <div className="absolute -start-28 top-8 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <FileVideo className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          source.mp4
        </span>
      </div>

      <div className="absolute -end-20 bottom-0 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <ListVideo className="h-3 w-3 text-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          master.m3u8
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function RenditionsEmptyState({
  projectId,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<RenditionsVisual />}
        icon={Layers}
        title={t('Encode this video for streaming')}
        description={t(
          'Renditions are the quality levels a player switches between. Encode a few to stream this video smoothly on any device and connection.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create renditions')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <Link
                to="/projects/$projectId/videos/profiles"
                params={{ projectId }}
              >
                {t('Encoding profiles')}
              </Link>
            </Button>
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
