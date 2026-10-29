import { Film, Gauge, Layers, SlidersHorizontal, Wifi } from 'lucide-react'
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
    icon: SlidersHorizontal,
    title: 'Start from a preset',
    description:
      'Pick a ready-made rung like 1080p or 720p, or set the width, height, and bitrates yourself.',
  },
  {
    icon: Layers,
    title: 'Encode renditions',
    description:
      'Each profile produces one rendition of a video. Combine several to build a quality ladder.',
  },
  {
    icon: Wifi,
    title: 'Stream adaptively',
    description:
      'Players switch between renditions as bandwidth changes, so playback stays smooth on any network.',
  },
]

const LADDER = [
  {
    label: '1080p',
    size: '1920×1080',
    video: '5 Mbps',
    audio: '192 kbps',
    bar: 100,
  },
  {
    label: '720p',
    size: '1280×720',
    video: '2.8 Mbps',
    audio: '128 kbps',
    bar: 58,
  },
  {
    label: '480p',
    size: '854×480',
    video: '1.2 Mbps',
    audio: '96 kbps',
    bar: 26,
  },
] as const

/** Decorative bitrate ladder fed by a single source file. */
function LadderVisual() {
  return (
    <ProductEmptyStateVisual className="w-[380px] pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Layers className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
        </div>
        <ul dir="ltr" className="divide-y divide-border">
          {LADDER.map((rung, index) => (
            <li
              key={rung.label}
              className="grid grid-cols-[3rem_1fr_auto] items-center gap-3 px-3 py-2.5 font-mono text-[10px]"
            >
              <span className="text-foreground">{rung.label}</span>
              <div className="min-w-0">
                <div className="h-1 overflow-hidden rounded-full bg-muted">
                  <div
                    className={cn(
                      'h-full rounded-full',
                      index === 0
                        ? 'bg-[var(--brand-cta)]'
                        : 'bg-muted-foreground/40',
                    )}
                    style={{ width: `${rung.bar}%` }}
                  />
                </div>
                <span className="mt-1 block text-muted-foreground">
                  {rung.size}
                </span>
              </div>
              <span className="text-end text-muted-foreground">
                <span className="text-foreground">{rung.video}</span>
                <span className="block">{rung.audio}</span>
              </span>
            </li>
          ))}
        </ul>
      </div>

      <div className="absolute -start-32 top-10 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Film className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          source.mov
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          4K
        </span>
      </div>

      <div className="absolute -bottom-3 -end-16 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Gauge className="h-3 w-3 text-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          ABR
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          HLS · DASH
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function ProfilesEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<LadderVisual />}
        icon={Layers}
        title={t('Define your quality ladder')}
        description={t(
          'Encoding profiles set the resolution and bitrate of every rendition, so each viewer gets the best quality their connection can handle.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onCreate}
            disabled={createDisabled}
            disabledTooltip={createDisabledTooltip}
          >
            {t('Create profile')}
          </ProductEmptyStateCreateButton>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
