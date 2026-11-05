import { ArrowRight, Check } from 'lucide-react'
import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonSection,
  fillStyle,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { Button } from '@/components/ui/button'
import { SECRET_SUSPECTS } from '@/lib/campaigns/secret/content'
import type { SecretSuspect, SecretVariantContent } from '@/lib/campaigns/secret/content'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { BrandTitle, SECRET_OUTLINE_BUTTON_CLASS, Stamp, SuspectLabel, TryAppwriteButton } from './SecretParts'

const MIGRATION_ITEMS: Record<SecretSuspect, string[]> = {
  supabase: ['Users', 'Databases', 'Files'],
  firebase: ['Users', 'Firestore collections', 'Storage files'],
}

/** One migration lane: the other platform on the start side, Appwrite on the end, items filling across. */
function MigrationLane({ suspect, delayMs }: { suspect: SecretSuspect; delayMs: number }) {
  const t = useT()
  const meta = SECRET_SUSPECTS[suspect]
  return (
    <div className="relative min-w-0 border-t border-foreground/15 pt-5">
      <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
      <div className="flex items-center justify-between gap-3 text-[13px] font-medium">
        <SuspectLabel suspect={suspect} className="text-muted-foreground" />
        <ArrowRight className="size-4 text-muted-foreground/60 rtl:-scale-x-100" aria-hidden />
        <span className="inline-flex items-center gap-2 text-foreground">
          <span className="flex size-5 items-center justify-center rounded-md bg-[var(--brand-cta)]/10">
            <AppwriteMark className="size-3" />
          </span>
          Appwrite
        </span>
      </div>
      <ul className="mt-5 space-y-3">
        {MIGRATION_ITEMS[suspect].map((item, index) => (
          <li key={item} className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_auto] items-center gap-3 text-[13px]">
            <span className="truncate text-muted-foreground">{t(item)}</span>
            <span className="relative h-1.5 overflow-hidden rounded-full bg-muted">
              <span
                className="product-hero-fill absolute inset-y-0 start-0 rounded-full bg-[var(--tone-ink)]"
                style={fillStyle(100, delayMs + index * 260)}
              />
            </span>
            <Check className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          </li>
        ))}
      </ul>
      <p className="mt-5 text-[14px] leading-6 text-muted-foreground">{t(meta.migrationDetail)}</p>
      <MarketingSiteLink
        href={meta.migrationHref}
        className="link-unstyled group mt-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--tone-ink)]"
        {...analyticsAttrs('secret-migrate')}
      >
        {t('Read the migration guide')}
        <ArrowRight
          className="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5"
          aria-hidden
        />
      </MarketingSiteLink>
    </div>
  )
}

export function SecretClosing({ content }: { content: SecretVariantContent }) {
  const t = useT()
  return (
    <>
      <ComparisonSection id="switching" className="scroll-mt-16">
        <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.3fr)] lg:gap-16">
          <ComparisonHeading
            eyebrow="Switching"
            title="Bring your project to Appwrite"
            description="The Migrations tool in the Console moves your users, data, and files in the background, and migration usage does not count toward your Appwrite Cloud bill."
            size="md"
          />
          <div className={content.suspects.length > 1 ? 'grid min-w-0 gap-8 md:grid-cols-2' : 'min-w-0'}>
            {content.suspects.map((suspect, index) => (
              <MigrationLane key={suspect} suspect={suspect} delayMs={200 + index * 300} />
            ))}
          </div>
        </div>
      </ComparisonSection>

      <ComparisonSection
        id="case-closed"
        className="scroll-mt-16"
        bordered={false}
        backdrop={
          <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
            <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
            <div className="product-tone-glow absolute -bottom-[35%] left-1/2 h-[640px] w-[min(1100px,160%)] -translate-x-1/2" />
            <div className="product-tone2-glow absolute -bottom-[55%] left-1/2 h-[560px] w-[min(1000px,160%)] -translate-x-1/2" />
          </div>
        }
      >
        <div className="mx-auto max-w-3xl text-center">
          <div className="product-hero-rise flex justify-center" style={riseStyle(0)}>
            <Stamp rotate={-6} delayMs={300}>
              {t('Your verdict')}
            </Stamp>
          </div>
          <h2
            className="product-hero-rise mt-8 text-balance font-aeonik-pro text-[30px] font-normal leading-[1.06] tracking-[-0.022em] text-foreground sm:text-[44px] lg:text-[56px]"
            style={riseStyle(120)}
          >
            <BrandTitle>{t('Case closed? That part is up to you.')}</BrandTitle>
          </h2>
          <p
            className="product-hero-rise mx-auto mt-6 max-w-xl text-pretty text-[15px] leading-7 text-muted-foreground sm:text-[17px] sm:leading-8"
            style={riseStyle(200)}
          >
            {t('Create a free Appwrite project, poke around the Console, and make the call yourself.')}
          </p>
          <div className="product-hero-rise mt-8 flex flex-wrap items-center justify-center gap-2" style={riseStyle(280)}>
            <TryAppwriteButton />
            <Button variant="outline" size="lg" className={SECRET_OUTLINE_BUTTON_CLASS} asChild>
              <MarketingSiteLink href="/pricing" {...analyticsAttrs('secret-view-pricing')}>
                {t('See Appwrite pricing')}
              </MarketingSiteLink>
            </Button>
          </div>
          <div
            className="product-hero-rise relative mx-auto mt-14 flex max-w-md flex-col items-center gap-3 border-t border-border pt-8"
            style={riseStyle(360)}
          >
            <span className="product-tone-hairline absolute inset-x-12 -top-px h-px" aria-hidden />
            <AppwriteWordmark className="h-7" />
            <p className="text-[13px] leading-5 text-muted-foreground">
              {t('The open-source cloud for agents and developers')}            </p>
          </div>
        </div>
      </ComparisonSection>
    </>
  )
}
