import { Clapperboard, FileLock2, HardDriveUpload, KeyRound, Lock, Server, ShieldCheck } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useId, useState } from 'react'
import { ArtConnector, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  AppwriteMark,
  ComparisonClosing,
  ComparisonHeading,
  ComparisonHeroBackdrop,
  ComparisonHeroTitle,
  ComparisonSection,
  ComparisonTableSection,
  CompetitorMonogram,
  SecondaryLinkButton,
  StartBuildingButton,
  VersusPill,
  comparisonHeroCopyClassName,
  comparisonHeroGridClassName,
} from './ComparisonParts'
import { IntegrationHubSection } from './IntegrationHub'
import { OpenSourceSection } from './OpenSource'
import { PlatformBreadthSection } from './PlatformBreadth'

const SOURCE_IMAGE = '/images/blog/introducing-autogravity/original.avif'
/** Where the subject sits in the source image, used to mimic gravity=auto. */
const SUBJECT_POSITION = '12% 52%'

/* -------------------------------------------------------------------------------------------------
 * Hero: one origin, many variants
 * -----------------------------------------------------------------------------------------------*/

const VARIANTS: { label: string; aspect: string; radius: string; span?: string }[] = [
  { label: 'avif · 400×400', aspect: 'aspect-square', radius: 'rounded-xl' },
  { label: 'webp · 64×64', aspect: 'aspect-square', radius: 'rounded-full' },
  { label: 'heic · 1080×1080', aspect: 'aspect-square', radius: 'rounded-[28%]' },
  { label: 'jpg · 1200×630', aspect: 'aspect-[1200/630]', radius: 'rounded-lg', span: 'col-span-2' },
  { label: 'png · 320×320', aspect: 'aspect-square', radius: 'rounded-md' },
]

function VariantsVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-2xl">
      <div className="grid items-center gap-5 sm:grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)]">
        <figure className="product-hero-rise" style={riseStyle(120)}>
          <div className="product-tone-shadow relative aspect-[16/10] overflow-hidden rounded-2xl border border-border bg-muted">
            <img
              src={SOURCE_IMAGE}
              alt={t('Original photo of a dog in a field')}
              className="absolute inset-0 size-full object-cover"
            />
            <span className="absolute start-2 top-2 rounded-md bg-background/90 px-1.5 py-0.5 text-[10px] font-medium text-foreground">
              {t('Original')}
            </span>
          </div>
          <figcaption className="mt-3 text-center font-mono text-[11px] text-muted-foreground">{t('1 origin image')}</figcaption>
        </figure>

        <div className="hidden sm:block">
          <ArtConnector travel travelDelayMs={500} />
        </div>

        <div className="grid grid-cols-3 gap-2.5">
          {VARIANTS.map((variant, index) => (
            <figure
              key={variant.label}
              className={cn('product-hero-rise min-w-0', variant.span)}
              style={riseStyle(400 + index * 110)}
            >
              <div className={cn('relative overflow-hidden border border-border bg-muted', variant.aspect, variant.radius)}>
                <img
                  src={SOURCE_IMAGE}
                  alt=""
                  className="absolute inset-0 size-full object-cover"
                  style={{ objectPosition: SUBJECT_POSITION }}
                />
              </div>
              <figcaption dir="ltr" className="mt-1.5 truncate text-center font-mono text-[9px] text-muted-foreground">
                {variant.label}
              </figcaption>
            </figure>
          ))}
        </div>
      </div>

      <div className="mt-10 grid grid-cols-2 gap-6">
        <div className="product-hero-rise border-t border-[var(--tone-ink)] pt-4" style={riseStyle(1000)}>
          <p className="flex items-center gap-2 text-[12px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite bills')}
          </p>
          <p className="mt-2 font-aeonik-pro text-[22px] leading-tight text-[var(--tone-ink)] sm:text-[26px]">
            {t('1 origin image')}
          </p>
        </div>
        <div className="product-hero-rise border-t border-border pt-4" style={riseStyle(1100)}>
          <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
            <CompetitorMonogram name="Cloudinary" />
            {t('Cloudinary counts')}
          </p>
          <p className="mt-2 font-aeonik-pro text-[22px] leading-tight text-foreground/55 sm:text-[26px]">
            {t('5 transformations')}
          </p>
        </div>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Playground
 * -----------------------------------------------------------------------------------------------*/

type Gravity =
  | 'auto'
  | 'center'
  | 'top-left'
  | 'top'
  | 'top-right'
  | 'left'
  | 'right'
  | 'bottom-left'
  | 'bottom'
  | 'bottom-right'

const GRAVITY: Record<Gravity, { position: string; cloudinary: string }> = {
  auto: { position: SUBJECT_POSITION, cloudinary: 'auto' },
  center: { position: '50% 50%', cloudinary: 'center' },
  'top-left': { position: '0% 0%', cloudinary: 'north_west' },
  top: { position: '50% 0%', cloudinary: 'north' },
  'top-right': { position: '100% 0%', cloudinary: 'north_east' },
  left: { position: '0% 50%', cloudinary: 'west' },
  right: { position: '100% 50%', cloudinary: 'east' },
  'bottom-left': { position: '0% 100%', cloudinary: 'south_west' },
  bottom: { position: '50% 100%', cloudinary: 'south' },
  'bottom-right': { position: '100% 100%', cloudinary: 'south_east' },
}

const GRAVITY_GRID: Gravity[] = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right']
const OUTPUTS = ['avif', 'webp', 'jpg', 'png', 'heic'] as const
type Output = (typeof OUTPUTS)[number]

function RangeControl({
  label,
  value,
  min,
  max,
  step,
  onChange,
  suffix = '',
}: {
  label: string
  value: number
  min: number
  max: number
  step: number
  onChange: (value: number) => void
  suffix?: string
}) {
  const t = useT()
  const id = useId()
  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <label htmlFor={id} className="text-[12px] font-medium text-foreground">
          {t(label)}
        </label>
        <span className="font-mono text-[12px] tabular-nums text-muted-foreground" dir="ltr">
          {value}
          {suffix}
        </span>
      </div>
      <input
        id={id}
        type="range"
        min={min}
        max={max}
        step={step}
        value={value}
        onChange={(event) => onChange(Number(event.target.value))}
        className="mt-2 w-full cursor-pointer accent-[var(--brand-cta)]"
      />
    </div>
  )
}

function TransformPlayground() {
  const t = useT()
  const [width, setWidth] = useState(640)
  const [height, setHeight] = useState(640)
  const [gravity, setGravity] = useState<Gravity>('auto')
  const [radius, setRadius] = useState(24)
  const [quality, setQuality] = useState(80)
  const [output, setOutput] = useState<Output>('avif')

  const appwriteUrl = `/v1/storage/buckets/photos/files/retriever/preview?width=${width}&height=${height}&gravity=${gravity}&quality=${quality}&borderRadius=${radius}&output=${output}`
  const cloudinaryUrl = `/image/upload/c_fill,g_${GRAVITY[gravity].cloudinary},w_${width},h_${height},q_${quality},r_${radius}/f_${output}/retriever.jpg`
  const previewRadius = `${(radius / Math.max(width, height)) * 100}%`

  return (
    <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:gap-16">
      <div className="text-start">
        <div className="grid gap-x-8 gap-y-6 sm:grid-cols-2">
          <RangeControl label="Width" value={width} min={100} max={1200} step={20} onChange={setWidth} suffix="px" />
          <RangeControl label="Height" value={height} min={100} max={1200} step={20} onChange={setHeight} suffix="px" />
          <RangeControl label="Border radius" value={radius} min={0} max={300} step={4} onChange={setRadius} suffix="px" />
          <RangeControl label="Quality" value={quality} min={10} max={100} step={5} onChange={setQuality} />
        </div>

        <div className="mt-6 grid gap-5 sm:grid-cols-[auto_minmax(0,1fr)]">
          <div>
            <p className="text-[12px] font-medium text-foreground">{t('Gravity')}</p>
            <div className="mt-2 flex items-start gap-3">
              <div className="grid grid-cols-3 gap-1" role="radiogroup" aria-label={t('Gravity')}>
                {GRAVITY_GRID.map((option) => (
                  <button
                    key={option}
                    type="button"
                    role="radio"
                    aria-checked={gravity === option}
                    aria-label={option}
                    title={option}
                    onClick={() => setGravity(option)}
                    className={cn(
                      'flex size-7 items-center justify-center rounded-md border transition-colors duration-200',
                      gravity === option
                        ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.14)]'
                        : 'border-border bg-muted/20 hover:bg-muted/50',
                    )}
                  >
                    <span
                      className={cn(
                        'size-1.5 rounded-full',
                        gravity === option ? 'bg-[var(--tone-ink)]' : 'bg-foreground/30',
                      )}
                    />
                  </button>
                ))}
              </div>
              <button
                type="button"
                role="radio"
                aria-checked={gravity === 'auto'}
                onClick={() => setGravity('auto')}
                className={cn(
                  'rounded-md border px-2.5 py-1.5 font-mono text-[11px] transition-colors duration-200',
                  gravity === 'auto'
                    ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                    : 'border-border text-muted-foreground hover:text-foreground',
                )}
              >
                auto
              </button>
            </div>
          </div>
          <div>
            <p className="text-[12px] font-medium text-foreground">{t('Output')}</p>
            <div className="mt-2 flex flex-wrap gap-1" role="radiogroup" aria-label={t('Output')}>
              {OUTPUTS.map((option) => (
                <button
                  key={option}
                  type="button"
                  role="radio"
                  aria-checked={output === option}
                  onClick={() => setOutput(option)}
                  className={cn(
                    'rounded-md border px-2.5 py-1.5 font-mono text-[11px] transition-colors duration-200',
                    output === option
                      ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                      : 'border-border text-muted-foreground hover:text-foreground',
                  )}
                >
                  {option}
                </button>
              ))}
            </div>
          </div>
        </div>

        <p className="mt-6 border-t border-border pt-4 text-[12px] leading-5 text-muted-foreground">
          {t('gravity=auto runs AutoGravity: face detection first, then saliency, so the subject stays in frame at any size. Every variant of this file still counts as one origin image.')}
        </p>
      </div>

      <div className="flex flex-col gap-5 text-start">
        <div className="relative flex min-h-[320px] flex-1 items-center justify-center p-6">
          <div className="product-dot-grid product-dot-grid-fade absolute -inset-6" aria-hidden />
          <div className="product-tone-glow absolute inset-[10%] opacity-70" aria-hidden />
          <div
            className="product-tone-shadow relative max-w-full overflow-hidden border border-border bg-muted transition-[width,border-radius] duration-300"
            style={{
              aspectRatio: `${width} / ${height}`,
              width: `${Math.round(Math.min(420, (280 * width) / height))}px`,
              borderRadius: previewRadius,
            }}
          >
            <img
              src={SOURCE_IMAGE}
              alt={t('Live preview of the transformed image')}
              className="absolute inset-0 size-full object-cover transition-[object-position] duration-500"
              style={{ objectPosition: GRAVITY[gravity].position }}
            />
          </div>
          <span className="absolute bottom-2 end-2 font-mono text-[10px] text-muted-foreground" dir="ltr">
            {width}×{height} · {output}
          </span>
        </div>

        <div className="border-t border-[var(--tone-ink)] pt-3">
          <p className="flex items-center gap-2 text-[11px] font-medium text-foreground">
            <AppwriteMark className="size-3.5" />
            {t('Appwrite preview URL')}
          </p>
          <p dir="ltr" className="mt-1.5 font-mono text-[11px] leading-5 text-foreground/85 [overflow-wrap:anywhere]">
            {appwriteUrl}
          </p>
        </div>
        <div className="border-t border-border pt-3">
          <p className="flex items-center gap-2 text-[11px] font-medium text-muted-foreground">
            <CompetitorMonogram name="Cloudinary" />
            {t('Cloudinary equivalent')}
          </p>
          <p dir="ltr" className="mt-1.5 font-mono text-[11px] leading-5 text-muted-foreground [overflow-wrap:anywhere]">
            {cloudinaryUrl}
          </p>
        </div>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Price tags
 * -----------------------------------------------------------------------------------------------*/

function PriceTags() {
  const t = useT()
  return (
    <div className="grid gap-12 md:grid-cols-2 md:gap-0 md:divide-x md:divide-border rtl:md:divide-x-reverse">
      <div className="product-hero-rise md:pe-10" style={riseStyle(80)}>
        <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
          <CompetitorMonogram name="Cloudinary" />
          {t('Cloudinary Plus')}
        </p>
        <p className="mt-4 font-aeonik-pro text-[40px] leading-none text-foreground/80" dir="ltr">
          $89<span className="text-[14px] text-muted-foreground">/mo</span>
        </p>
        <p className="mt-1.5 text-[12px] text-muted-foreground">{t('Billed yearly, $99 month to month')}</p>
        <ul className="mt-6 space-y-3 border-t border-border pt-5 text-[13px]">
          {[
            '225 credits a month, shared by everything',
            '1 credit = 1GB storage, 1GB bandwidth, or 1,000 transformations',
            'Images up to 20MB',
            'Custom domain and token auth start on Advanced',
          ].map((line) => (
            <li key={line} className="flex gap-2.5 text-foreground/75">
              <span className="mt-2 size-1 shrink-0 rounded-full bg-foreground/40" aria-hidden />
              {t(line)}
            </li>
          ))}
        </ul>
      </div>
      <div className="product-hero-rise relative isolate md:ps-10" style={riseStyle(200)}>
        <span
          className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.14),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[13px] text-foreground">
          <AppwriteMark className="size-4" />
          {t('Appwrite Pro')}
        </p>
        <p className="mt-4 font-aeonik-pro text-[40px] leading-none text-foreground" dir="ltr">
          $25<span className="text-[14px] text-muted-foreground">/mo</span>
        </p>
        <p className="mt-1.5 text-[12px] text-muted-foreground">{t('Starting price, pay as you go above it')}</p>
        <ul className="mt-6 space-y-3 border-t border-border pt-5 text-[13px]">
          {[
            '150GB storage and 2TB bandwidth, separately',
            '100 origin images transformed, then $5 per 1,000',
            'Files up to 5GB',
            'Permissions, file tokens, and the whole backend included',
          ].map((line) => (
            <li key={line} className="flex gap-2.5 text-foreground/90">
              <span className="mt-2 size-1 shrink-0 rounded-full bg-[var(--tone-ink)]" aria-hidden />
              {t(line)}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Storage features
 * -----------------------------------------------------------------------------------------------*/

const STORAGE_FEATURES: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: 'Private by default',
    description: 'Grant read and write access to any user, a specific user, a team, or a team role.',
    icon: Lock,
  },
  {
    title: 'File tokens',
    description: 'Share a single file without a session using a token with an optional expiry.',
    icon: KeyRound,
  },
  {
    title: 'Encryption and compression',
    description: 'Encrypt files at rest and compress them with gzip or zstd, per bucket.',
    icon: FileLock2,
  },
  {
    title: 'Large, resumable uploads',
    description: 'Chunked uploads up to 5GB per file on Pro, resumed after a dropped connection.',
    icon: HardDriveUpload,
  },
  {
    title: 'S3-compatible endpoint',
    description: 'Use the AWS CLI, rclone, or any S3 client with SigV4 signing.',
    icon: Server,
  },
  {
    title: 'Same rules everywhere',
    description: 'One permission model across the REST API, Realtime, and every SDK.',
    icon: ShieldCheck,
  },
]

export function CloudinaryComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Cloudinary" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-md xl:mx-0"
              title="Media lives with"
              accent="your app stack"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Cloudinary is a separate media service priced in shared credits. Appwrite Storage keeps files next to your auth and database, transforms images on the fly, and bills per origin image, not per variant.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/storage" label="Explore Appwrite Storage" />
            </div>
            <p className="mt-7 flex flex-wrap items-start justify-center gap-2.5 text-start text-[13px] leading-6 text-muted-foreground sm:items-center xl:justify-start">
              <Clapperboard className="size-4 shrink-0 text-[var(--tone-ink)]" aria-hidden />
              <span>
                <span className="font-medium text-foreground">{t('Appwrite Videos')}</span>{' '}
                {t('is coming soon, with video transcoding and processing.')}
              </span>
            </p>
          </div>
          <div className="min-w-0 overflow-x-clip">
            <VariantsVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Playground"
          title="One clean URL for every transformation"
          description="Resize, crop with smart gravity, round the corners, and switch formats. The preview URL is the whole API, and the Cloudinary equivalent shows how deep transformation syntax gets into every link you ship."
        />
        <div className="mt-12">
          <TransformPlayground />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<CloudinarySideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Allowances, not a credit puzzle"
            description="Cloudinary pools storage, bandwidth, and transformations into one credit balance. Appwrite gives each resource its own allowance, so a busy month for images does not eat your storage."
          />
          <PriceTags />
        </div>
      </ComparisonSection>

      <ComparisonSection>
        <ComparisonHeading
          eyebrow="Storage"
          title="More than a media CDN"
          description="Appwrite Storage handles private files, signed sharing, encryption, and huge uploads, with the same permission model as the rest of your app."
        />
        <div className="mt-12 grid gap-x-10 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
          {STORAGE_FEATURES.map((feature, index) => {
            const Icon = feature.icon
            return (
              <article
                key={feature.title}
                className="product-hero-rise border-t border-foreground/15 pt-6"
                style={riseStyle(80 + index * 80)}
              >
                <Icon className="size-5 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
                <h3 className="mt-4 text-[15px] font-medium text-foreground">{t(feature.title)}</h3>
                <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(feature.description)}</p>
              </article>
            )
          })}
        </div>
      </ComparisonSection>

      <IntegrationHubSection
        focus="storage"
        focusLabel="Appwrite Storage"
        title="Files that know who your users are"
        description="Cloudinary sits outside your backend, so users, permissions, and events live somewhere else. Appwrite Storage shares them with every other product in your project."
        items={[
          { id: 'auth', title: 'Auth', description: 'Read and write access per user, team, or role, with no extra sync.' },
          { id: 'functions', title: 'Functions', description: 'Run moderation, tagging, or AI on every upload event.' },
          { id: 'realtime', title: 'Realtime', description: 'Show new uploads in every open client the moment they land.' },
          { id: 'databases', title: 'Databases', description: 'Store file IDs and metadata in rows that follow the same rules.' },
          { id: 'sites', title: 'Sites', description: 'Serve transformed images straight into your hosted pages.' },
          { id: 'videos', title: 'Videos', description: 'Video transcoding and processing, next to your images.', soon: true },
        ]}
      />

      <PlatformBreadthSection
        id="cloudinary"
        title="Cloudinary handles media. Appwrite runs the whole app."
        description="Swap a media vendor for a platform. Appwrite Storage comes with auth, databases, functions, realtime, messaging, and hosting in the same project, with Appwrite Videos on the way."
      />

      <OpenSourceSection
        id="cloudinary"
        title="Your media pipeline, on any cloud"
        description="Cloudinary is a proprietary cloud service. Appwrite is open source, so the same storage and image transformations run on Appwrite Cloud or your own servers."
      />

      <ComparisonTableSection
        id="cloudinary"
        title="Every image feature you need, inside your backend"
        description="Cloudinary is a dedicated media platform. Appwrite Storage is media handling built into your backend."
      />

      <ComparisonClosing
        id="cloudinary"
        cta={{
          title: 'Upload, transform, and deliver from one backend',
          description: 'Create a bucket, upload a file, and request any size or format with a single preview URL.',
          secondary: <SecondaryLinkButton href="/docs/products/storage/images" label="Image transformation docs" />,
        }}
      />
    </>
  )
}

function CloudinarySideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
