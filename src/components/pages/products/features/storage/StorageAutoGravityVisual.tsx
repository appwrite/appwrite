import type { ReactNode } from 'react'
import { Crosshair } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { MockPermissionChip } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { API_EXPLORER_PILL_CLASS } from '@/lib/api-explorer/form-field-type-badge'
import {
  getHttpMethodAccentClasses,
  getHttpMethodBadgeVariant,
} from '@/lib/http-method-badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ORIGINAL_SRC = '/images/blog/introducing-autogravity/original.avif'
const CENTER_CROP_SRC = '/images/blog/introducing-autogravity/center-crop.avif'
const AUTO_CROP_SRC = '/images/blog/introducing-autogravity/automatic-crop.avif'

const PREVIEW_PARAMS = [
  { label: 'width=400', accent: false },
  { label: 'height=400', accent: false },
  { label: 'gravity=auto', accent: true },
  { label: 'quality=85', accent: false },
  { label: 'output=webp', accent: false },
  { label: 'rotation=0', accent: false },
  { label: 'border=0', accent: false },
] as const

function ImageCard({
  src,
  alt,
  label,
  caption,
  wide = false,
  overlay,
}: {
  src: string
  alt: string
  label: string
  caption: string
  wide?: boolean
  overlay?: ReactNode
}) {
  return (
    <figure
      className={cn(
        'flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card/50',
        wide && 'col-span-2 lg:col-span-1',
      )}
    >
      <div
        className={cn(
          'relative min-h-0 overflow-hidden bg-muted',
          wide
            ? 'aspect-[16/7] sm:aspect-[2.4/1] lg:aspect-auto lg:flex-1'
            : 'aspect-square',
        )}
      >
        <img
          src={src}
          alt={alt}
          className={cn(
            'absolute inset-0 block size-full object-cover',
            wide && 'object-[12%_center]',
          )}
        />
        {overlay}
        <span className="absolute start-2 top-2 rounded-md bg-background/90 px-1.5 py-0.5 font-mono text-[10px] text-foreground">
          {label}
        </span>
      </div>
      <figcaption className="shrink-0 truncate border-t border-border px-3 py-2 text-[11px] leading-4 text-muted-foreground">
        {caption}
      </figcaption>
    </figure>
  )
}

export function StorageAutoGravityVisual() {
  const t = useT()

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 items-stretch gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <ImageCard
          src={ORIGINAL_SRC}
          alt={t('Wide source photograph with the subject on the left')}
          label={t('Original')}
          caption={t('YuNet, then U²-Net. File is not cropped.')}
          wide
          overlay={
            <span
              className="absolute z-[1] flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-background/80 bg-background/70"
              style={{ left: '18%', top: '52%' }}
              aria-hidden
            >
              <Crosshair className="size-4 text-foreground" />
            </span>
          }
        />
        <ImageCard
          src={CENTER_CROP_SRC}
          alt={t('Square crop using gravity=center, mostly empty field')}
          label="gravity=center"
          caption={t('Misses the subject.')}
        />
        <ImageCard
          src={AUTO_CROP_SRC}
          alt={t('Square crop using gravity=auto, subject kept in view')}
          label="gravity=auto"
          caption={t('Keeps the subject.')}
        />
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {PREVIEW_PARAMS.map((param) => (
          <span key={param.label} className="inline-flex items-center gap-1.5">
            <MockPermissionChip
              label={param.label}
              tone={param.accent ? 'accent' : 'muted'}
            />
            {param.accent ? (
              <Badge variant="info" className="text-[10px] shrink-0">
                {t('New')}
              </Badge>
            ) : null}
          </span>
        ))}
      </div>

      <div className="flex justify-center pt-4">
        <div
          className={cn(
            'w-fit max-w-full overflow-x-auto rounded-lg px-3 py-2.5',
            getHttpMethodAccentClasses('GET').endpointBox,
          )}
        >
          <p className="flex min-w-0 items-center justify-center gap-2.5 font-mono text-[12px] leading-relaxed text-foreground">
            <Badge
              variant={getHttpMethodBadgeVariant('GET')}
              className={cn(
                'shrink-0 font-mono text-[10px] uppercase',
                API_EXPLORER_PILL_CLASS,
              )}
            >
              GET
            </Badge>
            <span className="whitespace-nowrap">
              /v1/storage/buckets/photos/files/golden-retriever.jpg/preview
              <span className="text-muted-foreground">
                ?width=400&height=400&gravity=auto&output=webp
              </span>
            </span>
          </p>
        </div>
      </div>
    </div>
  )
}
