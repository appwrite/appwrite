import { ArrowDown } from 'lucide-react'
import type { CSSProperties } from 'react'
import {
  AppwriteMark,
  ComparisonHeroBackdrop,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { GitHubSolidIcon } from '@/components/pages/marketing/MarketingGitHubStarsLink'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { Button } from '@/components/ui/button'
import type { SecretSuspect, SecretVariantContent } from '@/lib/campaigns/secret/content'
import { SECRET_SUSPECTS } from '@/lib/campaigns/secret/content'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { cn } from '@/lib/utils'
import { MarkerUnderline, SECRET_OUTLINE_BUTTON_CLASS, Stamp, SuspectNames, TryAppwriteButton } from './SecretParts'

type PaperLine = { width: number; redacted?: boolean }

const PAPER_LINES: PaperLine[][] = [
  [{ width: 62 }, { width: 80, redacted: true }, { width: 70 }, { width: 46, redacted: true }, { width: 74 }, { width: 58 }],
  [{ width: 40 }, { width: 72 }, { width: 86, redacted: true }, { width: 64 }, { width: 52, redacted: true }, { width: 78 }],
  [{ width: 58 }, { width: 76, redacted: true }, { width: 66 }, { width: 82 }, { width: 44, redacted: true }, { width: 60 }],
]

/** One document sticking out of the folder: a file label, text lines, and redaction bars. */
function Paper({
  label,
  suspect,
  lines,
  className,
  rotate,
  style,
  mirror = false,
}: {
  label: string
  suspect?: SecretSuspect
  lines: PaperLine[]
  /** Position and width. The rise animation owns `transform` here, so rotation lives on the sheet. */
  className?: string
  rotate: number
  style?: CSSProperties
  /** Puts the label on the far edge, clear of the sheet that overlaps it. */
  mirror?: boolean
}) {
  return (
    <div className={cn('product-hero-rise absolute', className)} style={style}>
      <div
        className="product-tone-shadow rounded-[1.2cqw] border border-border bg-background p-[2.2cqw] text-start dark:bg-card"
        style={{ transform: `rotate(${rotate}deg)` }}
      >
        <div
          className={cn(
            'flex items-center justify-between gap-[1cqw] font-mono text-[1.25cqw] uppercase tracking-[0.14em] text-muted-foreground',
            mirror && 'flex-row-reverse',
          )}
        >
          <span className="flex items-center gap-[0.9cqw]">
            {suspect ? (
              <span className="flex size-[2.3cqw] items-center justify-center rounded-[0.4cqw] border border-border bg-muted/60 text-[1.15cqw] font-semibold">
                {SECRET_SUSPECTS[suspect].name.charAt(0)}
              </span>
            ) : (
              <AppwriteMark className="size-[1.8cqw]" />
            )}
            {label}
          </span>
          <span className="text-muted-foreground/60">p.{lines.length}</span>
        </div>
        <div className="mt-[1.8cqw] space-y-[1.25cqw]">
          {lines.map((line, index) => (
            <div
              key={index}
              className={cn('h-[1.15cqw]', line.redacted ? 'rounded-[0.2cqw] bg-foreground/85' : 'rounded-full bg-foreground/10')}
              style={{ width: `${line.width}%` }}
            />
          ))}
        </div>
      </div>
    </div>
  )
}

/** The case file from the ad, in Appwrite's visual language: documents, a folder, and a brand stamp. */
function CaseFolder({ suspects }: { suspects: SecretSuspect[] }) {
  const t = useT()
  const start = suspects[0] ?? 'supabase'
  const end = suspects[1] ?? start

  return (
    <div className="relative mx-auto mt-14 w-full max-w-[880px] [container-type:inline-size] sm:mt-16" aria-hidden>
      <div className="product-tone-glow absolute inset-x-[2%] -top-[12%] bottom-0" />
      <div className="product-tone2-glow absolute inset-x-[20%] top-[20%] bottom-0 opacity-80" />
      <div className="relative aspect-[16/7.4]">
        <Paper
          label={`${SECRET_SUSPECTS[start].name} / ${t('pricing')}`}
          suspect={start}
          lines={PAPER_LINES[0] ?? []}
          className="start-[6%] top-[14%] w-[34%]"
          rotate={-8}
          style={riseStyle(420)}
        />
        <Paper
          label={`${SECRET_SUSPECTS[end].name} / ${t('docs')}`}
          suspect={end}
          lines={PAPER_LINES[2] ?? []}
          className="end-[6%] top-[14%] w-[34%]"
          rotate={7}
          mirror
          style={riseStyle(520)}
        />
        <Paper
          label={t('Fine print')}
          lines={PAPER_LINES[1] ?? []}
          className="start-[31%] top-[2%] w-[38%]"
          rotate={-1.5}
          style={riseStyle(620)}
        />

        {/* Folder front */}
        <div
          className="product-hero-rise absolute inset-x-[5%] bottom-0 h-[58%] rounded-t-[1.8cqw] border border-b-0 border-border bg-gradient-to-b from-card to-muted shadow-[0_-24px_60px_-28px_rgb(var(--tone-rgb)/0.6)] dark:from-[color-mix(in_srgb,var(--card)_92%,white_4%)] dark:to-card"
          style={riseStyle(240)}
        >
          <div className="absolute -top-[16%] start-[5%] h-[17%] w-[30%] rounded-t-[1.2cqw] border border-b-0 border-border bg-card px-[1.8cqw] pt-[1.3cqw] font-mono text-[1.25cqw] uppercase tracking-[0.2em] text-muted-foreground dark:bg-[color-mix(in_srgb,var(--card)_92%,white_4%)]">
            {t('Case file')}
          </div>
          <div className="product-tone-hairline absolute inset-x-[10%] top-0 h-px" />
          <div className="absolute start-[7%] top-1/2 flex -translate-y-1/2 items-center gap-[2cqw]">
            <span className="flex size-[9cqw] items-center justify-center rounded-[2cqw] border border-border bg-gradient-to-b from-card to-muted/40 shadow-sm dark:from-muted/30 dark:to-background">
              <AppwriteMark className="size-[4.6cqw]" />
            </span>
            <div className="space-y-[1.1cqw]">
              <div className="h-[1.2cqw] w-[18cqw] rounded-full bg-foreground/15" />
              <div className="h-[1.2cqw] w-[12cqw] rounded-full bg-foreground/10" />
            </div>
          </div>
          <div className="absolute end-[7%] top-1/2 -translate-y-1/2">
            <Stamp size="lg" delayMs={1100} rotate={-8}>
              {t('Confidential')}
            </Stamp>
          </div>
        </div>
      </div>
    </div>
  )
}

export function SecretHero({ id, content }: { id: string; content: SecretVariantContent }) {
  const t = useT()
  const [rawBefore = '', rawAfter = ''] = t(content.heroTitle).split('{suspects}')
  const before = rawBefore.trim()
  const after = rawAfter.trim()

  return (
    <section id={id} className="relative isolate overflow-hidden border-b border-border">
      <ComparisonHeroBackdrop />

      <div className="relative z-[1] mx-auto min-w-0 max-w-7xl px-4 pt-12 text-center sm:px-6 sm:pt-20 lg:pt-24">
        <div className="product-hero-rise flex justify-center" style={riseStyle(0)}>
          <p className="inline-flex max-w-full items-center gap-2 rounded-full border border-border bg-background/75 py-1 ps-1 pe-3 text-[12px] shadow-sm backdrop-blur-sm">
            <span className="flex size-6 items-center justify-center rounded-full bg-[var(--brand-cta)]/10">
              <AppwriteMark className="size-3.5" />
            </span>
            <span className="font-medium text-foreground">Appwrite</span>
            <span className="h-3 w-px bg-border" aria-hidden />
            <span className="truncate font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
              {t('Case file, declassified')}
            </span>
          </p>
        </div>

        <h1
          className="secret-document-title product-hero-rise mx-auto mt-6 max-w-5xl text-balance text-[38px] leading-[1.04] text-foreground sm:mt-8 sm:text-[58px] lg:text-[72px] xl:text-[80px]"
          style={riseStyle(120)}
        >
          {before ? <span className="block">{before}</span> : null}
          <span className="block">
            <SuspectNames suspects={content.suspects} />
          </span>
          {after ? (
            <span className="relative inline-block">
              {after}
              <MarkerUnderline delayMs={800} />
            </span>
          ) : null}
        </h1>

        <p
          className="product-hero-rise mx-auto mt-6 max-w-2xl text-pretty text-[15px] leading-7 text-muted-foreground sm:mt-8 sm:text-[17px] sm:leading-8"
          style={riseStyle(220)}
        >
          {t(
            "It's not a conspiracy. It's the fine print. We're Appwrite, the open-source cloud, and we went through their docs and pricing so you don't have to. Every claim links to its source. You make the call.",
          )}
        </p>

        <div className="product-hero-rise mt-8 flex flex-wrap items-center justify-center gap-2" style={riseStyle(300)}>
          <TryAppwriteButton />
          <Button variant="outline" size="lg" className={SECRET_OUTLINE_BUTTON_CLASS} asChild>
            <a href="#case-file" {...analyticsAttrs('secret-open-files')}>
              {t('Open the case file')}
              <ArrowDown className="ms-1.5 size-4" aria-hidden />
            </a>
          </Button>
        </div>

        <p
          className="product-hero-rise mt-5 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[12px] text-muted-foreground"
          style={riseStyle(360)}
        >
          <a
            href={MARKETING_SOCIAL_STATS.github.link}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 transition-colors hover:text-foreground"
          >
            <GitHubSolidIcon className="size-3.5" />
            <bdi>{MARKETING_SOCIAL_STATS.github.stat}</bdi> {t('GitHub stars')}
          </a>
          <span className="text-border" aria-hidden>
            ·
          </span>
          <span>{t('Open source')}</span>
          <span className="text-border" aria-hidden>
            ·
          </span>
          <span>{t('Free plan available')}</span>
        </p>

        <CaseFolder suspects={content.suspects} />
      </div>
    </section>
  )
}
