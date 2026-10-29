import { ArrowRight, ArrowUpRight, Check } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonSection,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { useRevealOnScroll } from '@/components/pages/products/_components/useRevealOnScroll'
import {
  SECRET_ANSWERS,
  SECRET_FINDINGS,
  SECRET_TOPIC_LABELS,
} from '@/lib/campaigns/secret/content'
import type {
  SecretExhibit,
  SecretFinding,
  SecretSuspect,
  SecretVariantContent,
} from '@/lib/campaigns/secret/content'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { Redacted, Stamp, SuspectLabel, TryAppwriteButton } from './SecretParts'

const LETTERS = 'ABCDEFGHIJ'

function exhibitAnchor(index: number): string {
  return `exhibit-${(LETTERS[index] ?? String(index)).toLowerCase()}`
}

function FindingBlock({
  suspect,
  finding,
  delayMs,
}: {
  suspect: SecretSuspect
  finding: SecretFinding
  delayMs: number
}) {
  const t = useT()
  return (
    <div className="min-w-0 border-s border-foreground/15 ps-4 sm:ps-5">
      <SuspectLabel suspect={suspect} className="text-[13px] font-medium text-muted-foreground" />
      <p className="mt-3 text-[17px] font-medium leading-snug text-foreground sm:text-[19px]">
        <Redacted delayMs={delayMs}>{t(finding.claim)}</Redacted>
      </p>
      <p className="mt-2.5 text-[14px] leading-6 text-muted-foreground">{t(finding.detail)}</p>
      <a
        href={finding.source.href}
        target="_blank"
        rel="noopener noreferrer"
        className="group mt-3 inline-flex max-w-full items-center gap-1.5 font-mono text-[11px] text-muted-foreground/80 transition-colors hover:text-foreground"
        {...analyticsAttrs('secret-source')}
      >
        <span className="shrink-0 uppercase tracking-[0.14em]">{t('Source')}</span>
        <span dir="ltr" className="truncate underline decoration-border underline-offset-4 group-hover:decoration-foreground/40">
          {finding.source.label}
        </span>
        <ArrowUpRight className="size-3 shrink-0 rtl:-scale-x-100" aria-hidden />
      </a>
    </div>
  )
}

function ExhibitArticle({
  exhibit,
  index,
  suspects,
  onDeclassified,
}: {
  exhibit: SecretExhibit
  index: number
  suspects: SecretSuspect[]
  onDeclassified: (index: number) => void
}) {
  const t = useT()
  const { ref, reveal } = useRevealOnScroll<HTMLElement>()
  const letter = LETTERS[index] ?? String(index + 1)
  const findings = suspects.flatMap((suspect) => {
    const finding = SECRET_FINDINGS[exhibit.topic][suspect]
    return finding ? [{ suspect, finding }] : []
  })
  const answer = exhibit.answer ?? SECRET_ANSWERS[exhibit.topic]
  const single = findings.length === 1
  const declassified = reveal === undefined ? undefined : reveal === 'in' ? 'true' : 'false'

  useEffect(() => {
    if (reveal === 'in') onDeclassified(index)
  }, [reveal, index, onDeclassified])

  return (
    <article
      ref={ref}
      id={exhibitAnchor(index)}
      data-declassified={declassified}
      className="relative scroll-mt-28 border-t border-foreground/15 pb-12 pt-6 last:pb-0 sm:pb-16"
    >
      <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-[11px] uppercase tracking-[0.2em]">
        <span className="text-[var(--tone-ink)]">
          {t('Exhibit')} {letter}
        </span>
        <span className="text-muted-foreground/50" aria-hidden>
          /
        </span>
        <span className="text-muted-foreground">{t(SECRET_TOPIC_LABELS[exhibit.topic])}</span>
        <Stamp size="sm" rotate={-4} delayMs={900} className="ms-auto">
          {t('Declassified')}
        </Stamp>
      </div>

      <h3 className="mt-4 max-w-3xl text-balance font-aeonik-pro text-[24px] font-normal leading-[1.1] tracking-tight text-foreground sm:text-[32px]">
        {t(exhibit.headline)}
      </h3>

      <div className="mt-8 grid min-w-0 gap-8 md:grid-cols-2 md:gap-10">
        {findings.map(({ suspect, finding }, findingIndex) => (
          <FindingBlock key={suspect} suspect={suspect} finding={finding} delayMs={250 + findingIndex * 220} />
        ))}

        <div
          className={cn(
            'relative isolate min-w-0 border-s-2 border-[var(--tone-ink)] ps-4 sm:ps-5',
            !single && 'md:col-span-2 md:mt-2',
          )}
        >
          <div
            className="pointer-events-none absolute -inset-y-6 -start-1 -z-10 w-[min(36rem,100%)] bg-[radial-gradient(ellipse_at_0%_50%,rgb(var(--tone-rgb)/0.12),transparent_70%)]"
            aria-hidden
          />
          <p className="flex items-center gap-2 text-[13px] font-medium text-[var(--tone-ink)]">
            <AppwriteMark className="size-3.5" />
            Appwrite
          </p>
          <p className="mt-3 text-[17px] font-medium leading-snug text-foreground sm:text-[19px]">{t(answer.claim)}</p>
          <p className="mt-2.5 max-w-2xl text-[14px] leading-6 text-muted-foreground">{t(answer.detail)}</p>
          <MarketingSiteLink
            href={answer.href}
            className="link-unstyled group mt-4 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--tone-ink)]"
          >
            {t('See how Appwrite does it')}
            <ArrowRight
              className="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5"
              aria-hidden
            />
          </MarketingSiteLink>
        </div>
      </div>
    </article>
  )
}

/** Sticky evidence index: one line per exhibit, checked off as each one is declassified. */
function ExhibitIndex({ exhibits, declassified }: { exhibits: SecretExhibit[]; declassified: Set<number> }) {
  const t = useT()
  return (
    <nav aria-label={t('Exhibits')} className="hidden lg:sticky lg:top-28 lg:block lg:self-start">
      <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Evidence index')}</p>
      <ol className="mt-4 space-y-0.5">
        {exhibits.map((exhibit, index) => {
          const done = declassified.has(index)
          return (
            <li key={exhibit.topic}>
              <a
                href={`#${exhibitAnchor(index)}`}
                className={cn(
                  'link-unstyled group flex items-center gap-3 rounded-md py-1.5 text-[13px] transition-colors',
                  done ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                )}
              >
                <span
                  className={cn('w-4 font-mono text-[11px]', done ? 'text-[var(--tone-ink)]' : 'text-muted-foreground/60')}
                >
                  {LETTERS[index]}
                </span>
                <span className="flex-1">{t(SECRET_TOPIC_LABELS[exhibit.topic])}</span>
                <span
                  className={cn(
                    'flex size-4 items-center justify-center rounded-full transition-all duration-300',
                    done
                      ? 'scale-100 bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]'
                      : 'scale-75 bg-muted text-transparent',
                  )}
                  aria-hidden
                >
                  <Check className="size-2.5" strokeWidth={3} />
                </span>
              </a>
            </li>
          )
        })}
      </ol>
      <p className="mt-6 max-w-[14rem] text-[12px] leading-5 text-muted-foreground">
        {t('Every finding links to their own docs or pricing page.')}
      </p>
      <div className="mt-8 border-t border-border pt-6">
        <p className="text-[13px] font-medium leading-5 text-foreground">{t('Seen enough?')}</p>
        <TryAppwriteButton size="md" className="mt-3" />
      </div>
    </nav>
  )
}

export function SecretExhibits({ content }: { content: SecretVariantContent }) {
  const [declassified, setDeclassified] = useState<Set<number>>(() => new Set())

  const markDeclassified = useCallback((index: number) => {
    setDeclassified((previous) => {
      if (previous.has(index)) return previous
      const next = new Set(previous)
      next.add(index)
      return next
    })
  }, [])

  return (
    <ComparisonSection
      id="case-file"
      className="scroll-mt-16"
      backdrop={<div className="product-dot-grid product-dot-grid-fade pointer-events-none absolute inset-0 opacity-60" aria-hidden />}
    >
      <ComparisonHeading
        eyebrow="The case file"
        title="Six things in the fine print"
        description="Nothing here is a leak. It is all public, just easy to miss. Scroll to lift the black marker, and see how Appwrite answers each one."
      />

      <div className="mt-12 grid min-w-0 gap-10 lg:mt-16 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-16">
        <ExhibitIndex exhibits={content.exhibits} declassified={declassified} />
        <div className="min-w-0">
          {content.exhibits.map((exhibit, index) => (
            <ExhibitArticle
              key={exhibit.topic}
              exhibit={exhibit}
              index={index}
              suspects={content.suspects}
              onDeclassified={markDeclassified}
            />
          ))}
        </div>
      </div>
    </ComparisonSection>
  )
}
