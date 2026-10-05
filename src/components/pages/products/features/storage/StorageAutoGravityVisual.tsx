import { Check, ScanFace, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { API_EXPLORER_PILL_CLASS } from '@/lib/api-explorer/form-field-type-badge'
import { getHttpMethodAccentClasses, getHttpMethodBadgeVariant } from '@/lib/http-method-badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ORIGINAL_SRC = '/images/blog/introducing-autogravity/original.avif'
const CENTER_CROP_SRC = '/images/blog/introducing-autogravity/center-crop.avif'
const AUTO_CROP_SRC = '/images/blog/introducing-autogravity/automatic-crop.avif'

const PREVIEW_PARAMS = [
  'width=400',
  'height=400',
  'gravity=auto',
  'quality=85',
  'output=webp',
] as const

function CropTile({
  src,
  alt,
  label,
  caption,
  auto,
  delayMs,
  className,
}: {
  src: string
  alt: string
  label: string
  caption: string
  auto: boolean
  delayMs: number
  className?: string
}) {
  const t = useT()
  return (
    <figure className={cn('product-hero-rise min-w-0', className)} style={riseStyle(delayMs)}>
      <div
        className={cn(
          'relative aspect-square overflow-hidden rounded-xl border bg-muted',
          auto
            ? 'product-tone-shadow border-[rgb(var(--tone-rgb)/0.55)] ring-4 ring-[rgb(var(--tone-rgb)/0.12)]'
            : 'border-border opacity-75',
        )}
      >
        <img src={src} alt={alt} className="absolute inset-0 block size-full object-cover" />
        <span
          dir="ltr"
          className="absolute start-2 top-2 rounded-md bg-background/90 px-1.5 py-0.5 font-mono text-[10px] text-foreground"
        >
          {label}
        </span>
        {auto ? (
          <Badge variant="info" className="absolute end-2 top-2 shrink-0 text-[10px]">
            {t('New')}
          </Badge>
        ) : null}
      </div>
      <figcaption className="mt-2.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
        <span
          className={cn(
            'flex size-4 shrink-0 items-center justify-center rounded-full',
            auto ? 'bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]' : 'bg-muted text-muted-foreground',
          )}
          aria-hidden
        >
          {auto ? <Check className="size-2.5" strokeWidth={3} /> : <X className="size-2.5" strokeWidth={3} />}
        </span>
        <span className="truncate">{caption}</span>
      </figcaption>
    </figure>
  )
}

export function StorageAutoGravityVisual() {
  const t = useT()

  return (
    <div className="mx-auto w-full max-w-6xl">
      <div className="grid items-center gap-8 lg:grid-cols-[minmax(0,1.6fr)_56px_minmax(0,1fr)] lg:gap-5">
        <div className="relative pb-8 sm:pb-6">
          <figure
            className="product-hero-rise product-tone-shadow relative aspect-[16/9] overflow-hidden rounded-2xl border border-border bg-muted"
            style={riseStyle(60)}
          >
            <img
              src={ORIGINAL_SRC}
              alt={t('Wide source photograph with the subject on the left')}
              className="absolute inset-0 block size-full object-cover"
            />
            {/* Overlays track image pixels, which never mirror, so they use physical offsets. */}
            <span
              className="product-hero-rise absolute rounded-md border-2 border-[var(--tone-ink)] shadow-[0_0_0_9999px_rgb(0_0_0/0.18)]"
              style={riseStyle(500, { left: '2%', top: '22%', width: '19%', height: '66%' })}
              aria-hidden
            />
            <span
              className="absolute z-[1] flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center"
              style={{ left: '11.5%', top: '52%' }}
              aria-hidden
            >
              <span className="absolute inset-0 animate-ping rounded-full bg-[rgb(var(--tone-rgb)/0.45)] motion-reduce:animate-none" />
              <span className="relative size-3 rounded-full border-2 border-white bg-[var(--tone-ink)]" />
            </span>
            <span
              dir="ltr"
              className="product-hero-rise absolute hidden rounded-md bg-background/90 px-1.5 py-0.5 font-mono text-[10px] text-foreground sm:block"
              style={riseStyle(800, { left: '23%', top: '22%' })}
            >
              x 0.12 · y 0.52
            </span>
            <span className="absolute start-3 top-3 rounded-md bg-background/90 px-1.5 py-0.5 text-[10px] font-medium text-foreground">
              {t('Original')}
            </span>
          </figure>

          <ArtChip className="bottom-0 end-3 max-w-[calc(100%-1.5rem)] sm:end-6" delayMs={900}>
            <div className="flex items-center gap-2">
              <ArtIconBadge icon={ScanFace} />
              <p className="min-w-0 text-[11px] font-medium leading-4 text-foreground">
                {t('YuNet, then U²-Net. File is not cropped.')}
              </p>
            </div>
          </ArtChip>
        </div>

        <div className="hidden lg:block">
          <ArtConnector travel travelDelayMs={600} />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <CropTile
            src={CENTER_CROP_SRC}
            alt={t('Square crop using gravity=center, mostly empty field')}
            label="gravity=center"
            caption={t('Misses the subject.')}
            auto={false}
            delayMs={300}
            className="lg:mt-12"
          />
          <CropTile
            src={AUTO_CROP_SRC}
            alt={t('Square crop using gravity=auto, subject kept in view')}
            label="gravity=auto"
            caption={t('Keeps the subject.')}
            auto
            delayMs={450}
            className="lg:-mt-12"
          />
        </div>
      </div>

      <div className="mt-10 flex flex-col items-center gap-3">
        <div
          className={cn(
            'product-hero-rise w-fit max-w-full overflow-x-auto rounded-lg px-3 py-2.5 [scrollbar-width:none]',
            getHttpMethodAccentClasses('GET').endpointBox,
          )}
          style={riseStyle(1000)}
        >
          <p dir="ltr" className="flex min-w-0 items-center gap-2.5 font-mono text-[12px] leading-relaxed text-foreground">
            <Badge
              variant={getHttpMethodBadgeVariant('GET')}
              className={cn('shrink-0 font-mono text-[10px] uppercase', API_EXPLORER_PILL_CLASS)}
            >
              GET
            </Badge>
            <span className="whitespace-nowrap">
              /v1/storage/buckets/photos/files/golden-retriever.jpg/preview
              <span className="text-muted-foreground">?width=400&amp;height=400&amp;</span>
              <span className="text-[var(--tone-ink)]">gravity=auto</span>
              <span className="text-muted-foreground">&amp;output=webp</span>
            </span>
          </p>
        </div>

        <div dir="ltr" className="flex flex-wrap justify-center gap-1.5">
          {PREVIEW_PARAMS.map((param, index) => {
            const accent = param === 'gravity=auto'
            return (
              <span
                key={param}
                className={cn(
                  'product-hero-rise inline-flex items-center rounded-full border px-2.5 py-1 font-mono text-[10px] shadow-sm',
                  accent
                    ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]'
                    : 'border-border bg-background text-muted-foreground dark:bg-card',
                )}
                style={riseStyle(1150 + index * 80)}
              >
                {param}
              </span>
            )
          })}
        </div>
      </div>
    </div>
  )
}
