import {
  Activity,
  Captions,
  Clapperboard,
  GalleryHorizontal,
  HardDriveUpload,
  MonitorPlay,
  SlidersHorizontal,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { ArtConnector, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  AppwriteMark,
  CodeFileLabel,
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

/* -------------------------------------------------------------------------------------------------
 * Example ladder (illustrative bitrates, not Appwrite defaults)
 * -----------------------------------------------------------------------------------------------*/

type Profile = { id: string; width: number; height: number; kbps: number }

const PROFILES: Profile[] = [
  { id: '2160p', width: 3840, height: 2160, kbps: 16000 },
  { id: '1080p', width: 1920, height: 1080, kbps: 5000 },
  { id: '720p', width: 1280, height: 720, kbps: 2800 },
  { id: '480p', width: 854, height: 480, kbps: 1400 },
  { id: '360p', width: 640, height: 360, kbps: 800 },
]

const MAX_KBPS = PROFILES[0].kbps

/* -------------------------------------------------------------------------------------------------
 * Hero: one source, a ladder, three outputs
 * -----------------------------------------------------------------------------------------------*/

const HERO_LADDER = PROFILES.slice(1)

const HERO_OUTPUTS: { label: string; file: string }[] = [
  { label: 'HLS', file: 'master.m3u8' },
  { label: 'DASH', file: 'master.mpd' },
  { label: 'CMAF', file: 'm3u8 + mpd' },
]

function LadderVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-2xl">
      <div className="grid items-center gap-5 sm:grid-cols-[minmax(0,0.8fr)_3rem_minmax(0,1.2fr)]">
        <figure className="product-hero-rise" style={riseStyle(120)}>
          <div className="product-tone-shadow relative flex aspect-video flex-col justify-between overflow-hidden rounded-2xl border border-border bg-muted p-4">
            <span className="absolute inset-0 bg-[radial-gradient(ellipse_at_30%_20%,rgb(var(--tone-rgb)/0.22),transparent_65%)]" aria-hidden />
            <Clapperboard className="relative size-6 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
            <span className="relative">
              <span dir="ltr" className="block font-mono text-[12px] text-foreground">trailer.mp4</span>
              <span className="mt-0.5 block text-[11px] text-muted-foreground">{t('In your Storage bucket')}</span>
            </span>
          </div>
          <figcaption className="mt-3 text-center font-mono text-[11px] text-muted-foreground">{t('1 source file')}</figcaption>
        </figure>

        <div className="hidden sm:block">
          <ArtConnector travel travelDelayMs={500} />
        </div>

        <ul className="space-y-2.5" aria-label={t('Encoding ladder')}>
          {HERO_LADDER.map((profile, index) => (
            <li
              key={profile.id}
              className="product-hero-rise grid grid-cols-[3rem_minmax(0,1fr)_4.5rem] items-center gap-3"
              style={riseStyle(400 + index * 110)}
            >
              <span dir="ltr" className="font-mono text-[11px] text-foreground">{profile.id}</span>
              <span className="h-2 overflow-hidden rounded-full bg-muted">
                <span
                  className="block h-full rounded-full bg-[var(--tone-ink)]"
                  style={{ width: `${Math.max(12, (profile.kbps / HERO_LADDER[0].kbps) * 100)}%`, opacity: 1 - index * 0.15 }}
                />
              </span>
              <span dir="ltr" className="text-end font-mono text-[10px] tabular-nums text-muted-foreground">
                {(profile.kbps / 1000).toFixed(1)} Mbps
              </span>
            </li>
          ))}
        </ul>
      </div>

      <div className="mt-8 grid grid-cols-3 gap-2.5">
        {HERO_OUTPUTS.map((output, index) => (
          <div
            key={output.label}
            className="product-hero-rise rounded-xl border border-border bg-background/70 px-3 py-2.5 text-center dark:bg-card/40"
            style={riseStyle(900 + index * 100)}
          >
            <p className="text-[13px] font-medium text-foreground">{output.label}</p>
            <p dir="ltr" className="mt-0.5 truncate font-mono text-[10px] text-muted-foreground">{output.file}</p>
          </div>
        ))}
      </div>

      <div className="mt-10 grid grid-cols-2 gap-6">
        <div className="product-hero-rise border-t border-[var(--tone-ink)] pt-4" style={riseStyle(1200)}>
          <p className="flex items-center gap-2 text-[12px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite packages')}
          </p>
          <p dir="ltr" className="mt-2 font-aeonik-pro text-[22px] leading-tight text-[var(--tone-ink)] sm:text-[26px]">
            HLS · DASH · CMAF
          </p>
        </div>
        <div className="product-hero-rise border-t border-border pt-4" style={riseStyle(1300)}>
          <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
            <CompetitorMonogram name="Mux" />
            {t('Mux delivers')}
          </p>
          <p dir="ltr" className="mt-2 font-aeonik-pro text-[22px] leading-tight text-foreground/55 sm:text-[26px]">
            HLS
          </p>
        </div>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Playground: build a ladder, read the manifest
 * -----------------------------------------------------------------------------------------------*/

const OUTPUTS = ['hls', 'dash', 'cmaf'] as const
type Output = (typeof OUTPUTS)[number]

const OUTPUT_LABEL: Record<Output, string> = { hls: 'HLS', dash: 'DASH', cmaf: 'CMAF' }

const MANIFEST_PATHS: Record<Output, string[]> = {
  hls: ['outputs/hls/master.m3u8'],
  dash: ['outputs/dash/master.mpd'],
  cmaf: ['outputs/cmaf/master.m3u8', 'outputs/cmaf/master.mpd'],
}

function hlsManifest(profiles: Profile[], version: number): string[] {
  return [
    '#EXTM3U',
    `#EXT-X-VERSION:${version}`,
    ...profiles.flatMap((profile) => [
      `#EXT-X-STREAM-INF:BANDWIDTH=${profile.kbps * 1000},RESOLUTION=${profile.width}x${profile.height}`,
      `${profile.id}/index.m3u8`,
    ]),
  ]
}

function dashManifest(profiles: Profile[]): string[] {
  return [
    '<MPD type="static">',
    '  <AdaptationSet mimeType="video/mp4">',
    ...profiles.map(
      (profile) =>
        `    <Representation id="${profile.id}" bandwidth="${profile.kbps * 1000}" width="${profile.width}" height="${profile.height}"/>`,
    ),
    '  </AdaptationSet>',
    '</MPD>',
  ]
}

function manifestLines(output: Output, profiles: Profile[]): string[] {
  if (output === 'dash') return dashManifest(profiles)
  if (output === 'cmaf') return hlsManifest(profiles, 7)
  return hlsManifest(profiles, 3)
}

function muxMaxResolutionTier(profiles: Profile[]): string {
  const tallest = Math.max(...profiles.map((profile) => profile.height))
  if (tallest > 1440) return '2160p'
  if (tallest > 1080) return '1440p'
  return '1080p'
}

function LadderPlayground() {
  const t = useT()
  const [selected, setSelected] = useState<string[]>(['1080p', '720p', '480p'])
  const [output, setOutput] = useState<Output>('cmaf')

  const profiles = PROFILES.filter((profile) => selected.includes(profile.id))
  const lines = manifestLines(output, profiles)

  const toggle = (id: string) => {
    setSelected((current) => {
      if (current.includes(id)) return current.length > 1 ? current.filter((item) => item !== id) : current
      return [...current, id]
    })
  }

  const muxRequest = [
    '{',
    '  "inputs": [{ "url": "https://…/trailer.mp4" }],',
    '  "playback_policies": ["public"],',
    '  "video_quality": "plus",',
    `  "max_resolution_tier": "${muxMaxResolutionTier(profiles)}"`,
    '}',
  ]

  return (
    <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-16">
      <div className="text-start">
        <p className="text-[12px] font-medium text-foreground">{t('Profiles')}</p>
        <div className="mt-3 space-y-2" role="group" aria-label={t('Profiles')}>
          {PROFILES.map((profile) => {
            const active = selected.includes(profile.id)
            return (
              <button
                key={profile.id}
                type="button"
                aria-pressed={active}
                onClick={() => toggle(profile.id)}
                className={cn(
                  'grid w-full grid-cols-[3.25rem_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-lg border px-3 py-2 text-start transition-colors duration-200',
                  active
                    ? 'border-[rgb(var(--tone-rgb)/0.5)] bg-[rgb(var(--tone-rgb)/0.08)]'
                    : 'border-border bg-muted/20 hover:bg-muted/40',
                )}
              >
                <span dir="ltr" className={cn('font-mono text-[12px]', active ? 'text-[var(--tone-ink)]' : 'text-muted-foreground')}>
                  {profile.id}
                </span>
                <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                  <span
                    className={cn(
                      'block h-full rounded-full transition-[width,background-color] duration-300',
                      active ? 'bg-[var(--tone-ink)]' : 'bg-foreground/20',
                    )}
                    style={{ width: `${Math.max(6, (profile.kbps / MAX_KBPS) * 100)}%` }}
                  />
                </span>
                <span dir="ltr" className="text-end font-mono text-[11px] tabular-nums text-muted-foreground">
                  {(profile.kbps / 1000).toFixed(1)} Mbps
                </span>
              </button>
            )
          })}
        </div>

        <p className="mt-6 text-[12px] font-medium text-foreground">{t('Output')}</p>
        <div className="mt-2 flex flex-wrap gap-1" role="radiogroup" aria-label={t('Output')}>
          {OUTPUTS.map((option) => (
            <button
              key={option}
              type="button"
              role="radio"
              aria-checked={output === option}
              onClick={() => setOutput(option)}
              className={cn(
                'rounded-md border px-3 py-1.5 font-mono text-[11px] transition-colors duration-200',
                output === option
                  ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                  : 'border-border text-muted-foreground hover:text-foreground',
              )}
            >
              {OUTPUT_LABEL[option]}
            </button>
          ))}
        </div>

        <p className="mt-6 border-t border-border pt-4 text-[12px] leading-5 text-muted-foreground">
          {t('Bitrates are an example. Every project starts with a default ladder, and each profile sets its own resolution, video and audio bitrate, and codec.')}
        </p>
      </div>

      <div className="flex min-w-0 flex-col gap-5 text-start">
        <div className="product-tone-shadow overflow-hidden rounded-xl border border-border bg-background dark:bg-card/60">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border bg-muted/30 px-4 py-2.5">
            <AppwriteMark className="size-3.5" />
            {MANIFEST_PATHS[output].map((path) => (
              <CodeFileLabel key={path}>{path}</CodeFileLabel>
            ))}
          </div>
          <pre dir="ltr" className="min-h-[13rem] overflow-x-auto px-4 py-3 font-mono text-[11px] leading-5 text-foreground/85">
            {lines.join('\n')}
          </pre>
        </div>

        <div className="border-t border-border pt-3">
          <p className="flex items-center gap-2 text-[11px] font-medium text-muted-foreground">
            <CompetitorMonogram name="Mux" />
            {t('Closest Mux request')}
          </p>
          <pre dir="ltr" className="mt-1.5 overflow-x-auto font-mono text-[11px] leading-5 text-muted-foreground">
            {muxRequest.join('\n')}
          </pre>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">
            {output === 'hls'
              ? t('Mux picks the renditions under that tier for you.')
              : t('Mux delivers HLS only, so there is no DASH manifest to request. Mux picks the renditions under that tier for you.')}
          </p>
        </div>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Video features
 * -----------------------------------------------------------------------------------------------*/

const VIDEO_FEATURES: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: 'Start from any file in Storage',
    description: 'Create a video from a bucket and file ID. Codecs, resolution, frame rate, and duration are probed for you.',
    icon: HardDriveUpload,
  },
  {
    title: 'Profiles you control',
    description: 'Start from the default ladder, then add profiles with your own resolution, bitrates, and codec.',
    icon: SlidersHorizontal,
  },
  {
    title: 'Subtitles, uploaded or extracted',
    description: 'Upload WebVTT or SubRip, or use the tracks embedded in the source. Tag each one with a language.',
    icon: Captions,
  },
  {
    title: 'Scrubbing thumbnails',
    description: 'Sprite sheets with a WebVTT index show a frame while viewers scrub, and give every video a poster.',
    icon: GalleryHorizontal,
  },
  {
    title: 'Built-in player',
    description: 'Copy an iframe from the Console. Set a start time, choose captions, or lock a rendition.',
    icon: MonitorPlay,
  },
  {
    title: 'Inspector',
    description: 'Watch a live experience score for any session, then drill into buffer, bitrate, and dropped frames.',
    icon: Activity,
  },
]

export function MuxComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Mux" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-md xl:mx-0"
              title="Video that streams from"
              accent="your own backend"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Mux is a separate video API that delivers HLS. Appwrite Videos turns files in your Storage buckets into HLS, DASH, and CMAF streams, in the same project as your users, permissions, and data.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/videos" label="Explore Appwrite Videos" />
            </div>
          </div>
          <div className="min-w-0 overflow-x-clip">
            <LadderVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Playground"
          title="Pick the ladder, pick the format"
          description="Toggle profiles and outputs to see the master manifest players will load. On Mux, the closest you get is a quality level and a resolution cap."
        />
        <div className="mt-12">
          <LadderPlayground />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<MuxSideGlow />}>
        <ComparisonHeading
          eyebrow="Videos"
          title="Everything between upload and play"
          description="Appwrite Videos covers encoding, packaging, subtitles, thumbnails, playback, and quality, with the source file left untouched in Storage."
        />
        <div className="mt-12 grid gap-x-10 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
          {VIDEO_FEATURES.map((feature, index) => {
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
        focus="videos"
        focusLabel="Appwrite Videos"
        title="Videos that know who your users are"
        description="Mux sits outside your backend, so users, files, and data live somewhere else and sync through webhooks. Appwrite Videos shares them with every other product in your project."
        items={[
          { id: 'auth', title: 'Auth', description: 'Sign in viewers and creators with the same users and teams.' },
          { id: 'storage', title: 'Storage', description: 'Sources, subtitles, and thumbnails stay in your buckets.' },
          { id: 'functions', title: 'Functions', description: 'Run moderation, tagging, or AI on every upload event.' },
          { id: 'realtime', title: 'Realtime', description: 'Show encoding progress in every open client as it happens.' },
          { id: 'databases', title: 'Databases', description: 'Keep video IDs, titles, and metadata in rows next to your data.' },
          { id: 'sites', title: 'Sites', description: 'Host the pages your videos play on in the same project.' },
        ]}
      />

      <PlatformBreadthSection
        id="mux"
        title="Mux handles video. Appwrite runs the whole app."
        description="Swap a video vendor for a platform. Appwrite Videos comes with auth, databases, storage, functions, realtime, messaging, and hosting in the same project."
      />

      <OpenSourceSection
        id="mux"
        title="A backend you can read, run, and extend"
        description="Mux open sources its player, but encoding and delivery are a closed service. Appwrite is open source, so the backend your videos depend on is code you can audit and run."
      />

      <ComparisonTableSection
        id="mux"
        title="Streaming features, inside your backend"
        description="Mux is a dedicated video API. Appwrite Videos is video streaming built into your backend."
      />

      <ComparisonClosing
        id="mux"
        cta={{
          title: 'Upload a file, get a stream',
          description: 'Upload to Storage, create a video, and play an adaptive stream in any HLS or DASH player.',
          secondary: <SecondaryLinkButton href="/products/videos" label="Explore Appwrite Videos" />,
        }}
      />
    </>
  )
}

function MuxSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
