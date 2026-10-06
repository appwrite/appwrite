import { Link } from '@tanstack/react-router'
import {
  ArrowRight,
  ArrowUpRight,
  BookOpen,
  Boxes,
  Check,
  Minus,
  Newspaper,
} from 'lucide-react'
import type { ComponentProps, CSSProperties, ReactNode } from 'react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { useRevealOnScroll } from '@/components/pages/products/_components/useRevealOnScroll'
import { Button } from '@/components/ui/button'
import { getAlternativeContent } from '@/lib/alternatives/content'
import {
  ALTERNATIVE_IDS,
  ALTERNATIVE_REGISTRY,
  ALTERNATIVES_VERIFIED_ON,
} from '@/lib/alternatives/registry'
import type {
  AlternativeId,
  AlternativeLink,
  AlternativeLinkKind,
  ComparisonCell,
  ComparisonGroup,
  ComparisonValue,
  FairPlayPoint,
} from '@/lib/alternatives/types'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { productToneAttrs } from '@/lib/products/theme'
import { cn } from '@/lib/utils'

function fairPlayPointText(point: FairPlayPoint): string {
  return typeof point === 'string' ? point : point.text
}

function fairPlayPointAside(point: FairPlayPoint): string | undefined {
  return typeof point === 'string' ? undefined : point.aside
}

/* -------------------------------------------------------------------------------------------------
 * Brand marks
 * -----------------------------------------------------------------------------------------------*/

/** Appwrite logomark that inherits `currentColor` (brand pink by default). */
export function AppwriteMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 112 98"
      fill="currentColor"
      className={cn('shrink-0 text-[var(--brand-cta)]', className)}
      aria-hidden
    >
      <path d="M111.1 73.4729V97.9638H48.8706C30.7406 97.9638 14.9105 88.114 6.44112 73.4729C5.2099 71.3444 4.13229 69.1113 3.22835 66.7935C1.45387 62.2516 0.338421 57.3779 0 52.2926V45.6712C0.0734729 44.5379 0.189248 43.4135 0.340647 42.3025C0.650124 40.0227 1.11768 37.7918 1.73218 35.6232C7.54544 15.0641 26.448 0 48.8706 0C71.2932 0 90.1935 15.0641 96.0068 35.6232H69.3985C65.0302 28.9216 57.4692 24.491 48.8706 24.491C40.272 24.491 32.711 28.9216 28.3427 35.6232C27.0113 37.6604 25.9782 39.9069 25.3014 42.3025C24.7002 44.4266 24.3796 46.6664 24.3796 48.9819C24.3796 56.0019 27.3319 62.3295 32.0653 66.7935C36.4515 70.9369 42.3649 73.4729 48.8706 73.4729H111.1Z" />
      <path d="M111.1 42.3027V66.7937H65.6759C70.4094 62.3297 73.3616 56.0021 73.3616 48.9821C73.3616 46.6666 73.041 44.4268 72.4399 42.3027H111.1Z" />
    </svg>
  )
}

/** Neutral monogram for the other platform. We never draw third-party logos. */
export function CompetitorMonogram({
  name,
  className,
}: {
  name: string
  className?: string
}) {
  return (
    <span
      className={cn(
        'flex size-5 shrink-0 items-center justify-center rounded-md border border-border bg-muted/60 font-mono text-[10px] font-semibold uppercase text-muted-foreground',
        className,
      )}
      aria-hidden
    >
      {name.charAt(0)}
    </span>
  )
}

/** "Appwrite vs X" pill used at the top of every hero. */
export function VersusPill({ name, className }: { name: string; className?: string }) {
  return (
    <div
      dir="ltr"
      className={cn(
        'inline-flex max-w-full flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-full border border-border bg-background/75 py-1 ps-1 pe-3 text-[12px] shadow-sm backdrop-blur-sm',
        className,
      )}
    >
      <span className="flex size-6 items-center justify-center rounded-full bg-[var(--brand-cta)]/10">
        <AppwriteMark className="size-3.5" />
      </span>
      <span className="font-medium text-foreground">Appwrite</span>
      <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">vs</span>
      <span className="font-medium text-muted-foreground">{name}</span>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Layout
 * -----------------------------------------------------------------------------------------------*/

/** Shared hero grid: copy column + visual; stacks on small screens. */
export const comparisonHeroGridClassName =
  'relative z-[1] mx-auto grid min-w-0 max-w-7xl items-center gap-10 px-4 pb-12 pt-12 sm:gap-12 sm:px-6 sm:pb-16 sm:pt-20 xl:gap-14 xl:pb-24 xl:pt-24'

export const comparisonHeroCopyClassName =
  'relative z-[1] min-w-0 overflow-x-clip text-center xl:text-start'

/** Centered hero (single column + art below). */
export const comparisonHeroCenteredClassName =
  'relative z-[1] mx-auto min-w-0 max-w-7xl px-4 pb-12 pt-12 text-center sm:px-6 sm:pb-16 sm:pt-20 lg:pb-20 lg:pt-24'

/** Page wrapper that scopes the brand tone used by glows, hairlines, and accents. */
export function ComparisonShell({ id, children }: { id: AlternativeId; children: ReactNode }) {
  const meta = ALTERNATIVE_REGISTRY[id]
  return (
    <div
      className="relative min-w-0 overflow-x-clip bg-background"
      {...productToneAttrs({ tone: meta.tone, secondaryTone: meta.secondaryTone })}
    >
      {children}
    </div>
  )
}

/** Section that pauses its entrance animations until it scrolls into view. */
export function ComparisonSection({
  id,
  children,
  className,
  innerClassName,
  backdrop,
  bordered = true,
}: {
  id?: string
  children: ReactNode
  className?: string
  innerClassName?: string
  backdrop?: ReactNode
  bordered?: boolean
}) {
  const { ref, reveal } = useRevealOnScroll<HTMLElement>()
  return (
    <section
      ref={ref}
      id={id}
      data-reveal={reveal}
      className={cn(
        'relative isolate overflow-x-clip py-12 sm:py-20 lg:py-24',
        bordered && 'border-b border-border',
        className,
      )}
    >
      {backdrop}
      <div className={cn('relative z-[1] mx-auto max-w-7xl px-4 sm:px-6', innerClassName)}>
        {children}
      </div>
    </section>
  )
}

export function ComparisonEyebrow({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <p
      className={cn(
        'font-mono text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground',
        className,
      )}
    >
      {children}
      <span className="text-[var(--brand-cta)]">_</span>
    </p>
  )
}

export function ComparisonHeading({
  eyebrow,
  title,
  description,
  align = 'left',
  size = 'lg',
  className,
}: {
  eyebrow?: string
  title: string
  description?: string
  align?: 'left' | 'center'
  size?: 'lg' | 'md'
  className?: string
}) {
  const t = useT()
  const centered = align === 'center'
  return (
    <div className={cn(centered ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl text-start', className)}>
      {eyebrow ? <ComparisonEyebrow className="mb-4">{t(eyebrow)}</ComparisonEyebrow> : null}
      <h2
        className={cn(
          'font-aeonik-pro text-balance font-normal leading-[1.02] tracking-tight text-foreground',
          size === 'lg' ? 'text-[28px] sm:text-[36px] lg:text-[44px]' : 'text-[24px] sm:text-[28px] lg:text-[32px]',
        )}
      >
        {t(title)}
        <span className="text-[var(--brand-cta)]">_</span>
      </h2>
      {description ? (
        <p
          className={cn(
            'mt-4 text-[15px] leading-7 text-muted-foreground',
            centered && 'mx-auto max-w-2xl text-balance',
          )}
        >
          {t(description)}
        </p>
      ) : null}
    </div>
  )
}

/** Hero title with the brand underscore. Pass `accent` to tint the trailing words. */
export function ComparisonHeroTitle({
  title,
  accent,
  className,
}: {
  title: string
  accent?: string
  className?: string
}) {
  const t = useT()
  return (
    <h1
      className={cn(
        'font-aeonik-pro min-w-0 max-w-full text-pretty pb-0.5 text-[32px] font-normal leading-[1.1] tracking-[-0.02em] text-foreground sm:text-[44px] sm:pb-1 sm:leading-[1.08] sm:tracking-[-0.022em] lg:text-[48px] xl:text-[56px] 2xl:text-[64px]',
        className,
      )}
    >
      {t(title)}
      {accent ? (
        <>
          {' '}
          <span className="text-gradient-brand">
            {t(accent)}
            <span className="text-[var(--brand-cta)]">_</span>
          </span>
        </>
      ) : (
        <>
          {'\u00A0'}
          <span className="text-[var(--brand-cta)]">_</span>
        </>
      )}
    </h1>
  )
}

export function ComparisonHeroBackdrop() {
  return (
    <>
      <ProductToneBackdrop variant="hero" />
      <div
        className="product-tone-hairline absolute inset-x-0 bottom-0 z-[1] h-px opacity-50"
        aria-hidden
      />
    </>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Calls to action
 * -----------------------------------------------------------------------------------------------*/

export function StartBuildingButton({ label = 'Start building for free' }: { label?: string }) {
  const t = useT()
  return (
    <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
      <Link to="/sign-up" search={{ redirect: '/' }} {...analyticsAttrs('alternative-start-building')}>
        {t(label)}
      </Link>
    </Button>
  )
}

export function SecondaryLinkButton({
  href,
  label,
  analytics = 'alternative-view-pricing',
}: {
  href: string
  label: string
  analytics?: 'alternative-view-pricing' | 'alternative-migrate'
}) {
  const t = useT()
  return (
    <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
      <MarketingSiteLink href={href} {...analyticsAttrs(analytics)}>
        {t(label)}
      </MarketingSiteLink>
    </Button>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Comparison table
 * -----------------------------------------------------------------------------------------------*/

const TABLE_GRID = 'grid grid-cols-2 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)]'
const APPWRITE_COLUMN_TINT = 'bg-[rgb(var(--tone-rgb)/0.05)]'

function normalizeCell(cell: ComparisonCell): { value: ComparisonValue; note?: string } {
  if (typeof cell === 'object') return cell
  return { value: cell }
}

function PartialMark() {
  return (
    <svg viewBox="0 0 20 20" className="size-5" aria-hidden>
      <circle cx="10" cy="10" r="7.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
      <path d="M10 2.75a7.25 7.25 0 0 1 0 14.5Z" fill="currentColor" />
    </svg>
  )
}

export function ComparisonMark({
  value,
  side,
}: {
  value: ComparisonValue
  side: 'appwrite' | 'competitor'
}) {
  const t = useT()

  if (value === true) {
    return (
      <span
        className={cn(
          'inline-flex size-5 items-center justify-center rounded-full',
          side === 'appwrite'
            ? 'bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]'
            : 'bg-muted text-foreground/70',
        )}
      >
        <Check className="size-3" strokeWidth={3} aria-hidden />
        <span className="sr-only">{t('Yes')}</span>
      </span>
    )
  }

  if (value === false) {
    return (
      <span className="inline-flex size-5 items-center justify-center text-muted-foreground/60">
        <Minus className="size-4" aria-hidden />
        <span className="sr-only">{t('No')}</span>
      </span>
    )
  }

  if (value === 'partial') {
    return (
      <span className="inline-flex size-5 items-center justify-center text-muted-foreground">
        <PartialMark />
        <span className="sr-only">{t('Partial')}</span>
      </span>
    )
  }

  if (value === 'soon') {
    return (
      <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-[rgb(var(--tone-rgb)/0.14)] px-2 py-0.5 text-[11px] font-medium text-[var(--tone-ink)]">
        <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
        {t('Coming soon')}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'break-words text-[13px] font-medium leading-5',
        side === 'appwrite' ? 'text-foreground' : 'text-foreground/70',
      )}
    >
      <bdi>{t(value)}</bdi>
    </span>
  )
}

function ComparisonTableCell({
  cell,
  side,
}: {
  cell: ComparisonCell
  side: 'appwrite' | 'competitor'
}) {
  const t = useT()
  const { value, note } = normalizeCell(cell)
  return (
    <div
      className={cn(
        'flex min-w-0 flex-col justify-center gap-1 px-4 pb-4 pt-2.5 sm:px-5 sm:py-4',
        side === 'appwrite' && APPWRITE_COLUMN_TINT,
      )}
    >
      <ComparisonMark value={value} side={side} />
      {note ? <p className="text-[11px] leading-4 text-muted-foreground">{t(note)}</p> : null}
    </div>
  )
}

export function ComparisonTable({
  competitorName,
  groups,
  className,
}: {
  competitorName: string
  groups: ComparisonGroup[]
  className?: string
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'overflow-hidden rounded-2xl border border-border bg-background/70 shadow-[0_1px_2px_rgba(0,0,0,0.03),0_24px_48px_-28px_rgba(0,0,0,0.18)] dark:bg-card/30',
        className,
      )}
    >
      <div className={cn(TABLE_GRID, 'border-b border-border')}>
        <div className="hidden items-end px-5 py-4 font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground sm:flex">
          {t('Compared')}
        </div>
        <div className={cn('relative flex items-center gap-2 px-4 py-4 sm:px-5', APPWRITE_COLUMN_TINT)}>
          <span className="product-tone-hairline absolute inset-x-0 top-0 h-px" aria-hidden />
          <AppwriteMark className="size-4" />
          <span className="font-aeonik-pro text-[16px] text-foreground">Appwrite</span>
        </div>
        <div className="flex items-center gap-2 px-4 py-4 sm:px-5">
          <CompetitorMonogram name={competitorName} />
          <span className="font-aeonik-pro text-[16px] text-muted-foreground">{competitorName}</span>
        </div>
      </div>

      {groups.map((group, groupIndex) => {
        const lastGroup = groupIndex === groups.length - 1
        return (
          <div key={group.title}>
            <div className={cn(TABLE_GRID, 'border-b border-border')}>
              <div className="col-span-2 bg-muted/25 px-4 py-2.5 font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground sm:col-span-1 sm:px-5">
                {t(group.title)}
              </div>
              <div className={cn('hidden sm:block', APPWRITE_COLUMN_TINT)} aria-hidden />
              <div className="hidden bg-muted/25 sm:block" aria-hidden />
            </div>
            {group.rows.map((row, rowIndex) => {
              const lastRow = lastGroup && rowIndex === group.rows.length - 1
              return (
                <div
                  key={row.label}
                  className={cn(
                    TABLE_GRID,
                    'transition-colors duration-200 hover:bg-muted/15',
                    !lastRow && 'border-b border-border',
                  )}
                >
                  <div className="col-span-2 flex items-center px-4 pt-4 text-[13px] font-medium leading-5 text-foreground sm:col-span-1 sm:px-5 sm:py-4">
                    {t(row.label)}
                  </div>
                  <ComparisonTableCell cell={row.appwrite} side="appwrite" />
                  <ComparisonTableCell cell={row.competitor} side="competitor" />
                </div>
              )
            })}
          </div>
        )
      })}
    </div>
  )
}

/** Standard "at a glance" section with the full comparison table. */
export function ComparisonTableSection({
  id,
  title = 'Feature by feature',
  description,
  className,
}: {
  id: AlternativeId
  title?: string
  description?: string
  className?: string
}) {
  const t = useT()
  const meta = ALTERNATIVE_REGISTRY[id]
  const content = getAlternativeContent(id)
  return (
    <ComparisonSection
      id="compare"
      className={cn('scroll-mt-24', className)}
      backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 -z-0 opacity-60" aria-hidden />}
    >
      <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.6fr)] lg:gap-14">
        <div className="min-w-0 lg:sticky lg:top-28 lg:self-start">
          <ComparisonHeading
            eyebrow={`Appwrite vs ${meta.name}`}
            title={title}
            description={description}
            size="md"
          />
          <p className="mt-6 text-[12px] leading-5 text-muted-foreground">
            {t(`Checked against public pricing and documentation in ${ALTERNATIVES_VERIFIED_ON}.`)}
          </p>
        </div>
        <div className="-mx-4 min-w-0 overflow-x-auto px-4 sm:mx-0 sm:overflow-visible sm:px-0">
          <ComparisonTable
            competitorName={meta.name}
            groups={content.comparison}
            className="min-w-[20rem] sm:min-w-0"
          />
        </div>
      </div>
    </ComparisonSection>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Fair play, related reading, other comparisons
 * -----------------------------------------------------------------------------------------------*/

export function FairPlaySection({ id }: { id: AlternativeId }) {
  const t = useT()
  const { fairPlay } = getAlternativeContent(id)
  return (
    <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="start" />}>
      <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.4fr)] lg:items-start lg:gap-14">
        <ComparisonHeading eyebrow="Fair play" title={fairPlay.title} description={fairPlay.description} size="md" />
        <ol className="grid min-w-0 gap-y-8 sm:grid-cols-2 sm:gap-x-8 lg:gap-x-10">
          {fairPlay.points.map((point, index) => {
            const text = fairPlayPointText(point)
            const aside = fairPlayPointAside(point)
            return (
              <li key={text} className="relative border-t border-foreground/15 pt-5">
                <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
                <span className="font-mono text-[11px] tabular-nums text-[var(--tone-ink)]">
                  {String(index + 1).padStart(2, '0')}
                </span>
                <p className="mt-3 text-[14px] leading-6 text-foreground/85">{t(text)}</p>
                {aside ? (
                  <p className="mt-2 text-[13px] leading-5 text-muted-foreground">{t(aside)}</p>
                ) : null}
              </li>
            )
          })}
        </ol>
      </div>
    </ComparisonSection>
  )
}

const LINK_KIND_META: Record<AlternativeLinkKind, { label: string; icon: typeof Newspaper }> = {
  blog: { label: 'Blog', icon: Newspaper },
  docs: { label: 'Docs', icon: BookOpen },
  product: { label: 'Product', icon: Boxes },
}

function RelatedCard({ link }: { link: AlternativeLink }) {
  const t = useT()
  const kind = LINK_KIND_META[link.kind]
  const Icon = kind.icon
  return (
    <MarketingSiteLink
      href={link.href}
      className="link-unstyled group relative flex h-full flex-col border-t border-foreground/15 pt-5 transition-colors duration-200 hover:border-[var(--tone-ink)]"
    >
      <span className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-[0.16em] text-muted-foreground">
        <Icon className="size-3.5" aria-hidden />
        {t(kind.label)}
        <ArrowUpRight
          className="ms-auto size-4 text-muted-foreground transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-[var(--tone-ink)] rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5"
          aria-hidden
        />
      </span>
      <span className="mt-4 text-[15px] font-medium leading-6 text-foreground transition-colors group-hover:text-[var(--tone-ink)]">
        {t(link.title)}
      </span>
      <span className="mt-2 text-[13px] leading-5 text-muted-foreground">{t(link.description)}</span>
    </MarketingSiteLink>
  )
}

export function RelatedSection({ id }: { id: AlternativeId }) {
  const { related } = getAlternativeContent(id)
  return (
    <ComparisonSection>
      <ComparisonHeading
        eyebrow="Go deeper"
        title="See why teams make the switch"
        description="Guides, deep dives, and product pages from the Appwrite team."
        size="md"
      />
      <div className="mt-10 grid gap-x-10 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
        {related.map((link) => (
          <RelatedCard key={link.href} link={link} />
        ))}
      </div>
    </ComparisonSection>
  )
}

export function ComparisonFaq({ id }: { id: AlternativeId }) {
  const meta = ALTERNATIVE_REGISTRY[id]
  const { faq } = getAlternativeContent(id)
  return (
    <div className="border-b border-border">
      <MarketingFaqSection items={faq} title={`Why developers choose Appwrite over ${meta.name}`} />
    </div>
  )
}

export function OtherComparisons({ current }: { current: AlternativeId }) {
  const t = useT()
  const others = ALTERNATIVE_IDS.filter((id) => id !== current)
  return (
    <ComparisonSection bordered={false}>
      <ComparisonHeading
        eyebrow="Compare"
        title="See how Appwrite stacks up elsewhere"
        size="md"
      />
      <div className="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {others.map((id) => {
          const meta = ALTERNATIVE_REGISTRY[id]
          return (
            <Link
              key={id}
              to="/alternative-to/$competitor"
              params={{ competitor: id }}
              className="link-unstyled group relative flex flex-col overflow-hidden rounded-xl border border-border bg-background/70 p-5 transition-[border-color,transform] duration-300 hover:-translate-y-0.5 hover:border-[rgb(var(--tone-rgb)/0.45)] dark:bg-card/40"
              {...analyticsAttrs('alternative-compare-other')}
            >
              <span className="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                {t('Appwrite vs')}
                <ArrowRight
                  className="ms-auto size-3.5 -translate-x-1 opacity-0 transition-all duration-300 group-hover:translate-x-0 group-hover:opacity-100 rtl:-scale-x-100"
                  aria-hidden
                />
              </span>
              <span className="mt-2 font-aeonik-pro text-[22px] leading-none tracking-tight text-foreground">
                {meta.name}
              </span>
              <span className="mt-1.5 text-[12px] text-muted-foreground">{t(meta.category)}</span>
              <span className="mt-4 text-[13px] leading-5 text-foreground/80">{t(meta.summary)}</span>
            </Link>
          )
        })}
        <MarketingSiteLink
          href="/pricing"
          className="link-unstyled group relative flex flex-col justify-between overflow-hidden rounded-xl border border-dashed border-border p-5 transition-colors duration-300 hover:border-[rgb(var(--tone-rgb)/0.45)]"
          {...analyticsAttrs('alternative-view-pricing')}
        >
          <span className="font-mono text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
            {t('Pricing')}
          </span>
          <span className="mt-6 text-[15px] font-medium leading-6 text-foreground">
            {t('One plan for your whole backend and hosting.')}
          </span>
          <span className="mt-4 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--tone-ink)]">
            {t('Compare plans')}
            <ArrowRight className="size-3.5 transition-transform duration-300 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" aria-hidden />
          </span>
        </MarketingSiteLink>
      </div>
    </ComparisonSection>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Closing CTA and footnote
 * -----------------------------------------------------------------------------------------------*/

export function ComparisonCta({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children?: ReactNode
}) {
  const t = useT()
  return (
    <section className="relative px-4 py-12 sm:px-6 sm:py-20 lg:py-24">
      <div className="relative isolate mx-auto max-w-5xl overflow-hidden rounded-3xl border border-border bg-card/40 px-5 py-10 text-center sm:px-12 sm:py-20">
        <ProductToneBackdrop variant="cta" />
        <div className="product-tone-hairline absolute inset-x-12 top-0 h-px" aria-hidden />
        <div className="relative z-[1]">
          <div className="flex justify-center">
            <span className="flex size-14 items-center justify-center rounded-2xl border border-border bg-gradient-to-b from-card to-muted/40 shadow-sm dark:from-muted/30 dark:to-background">
              <AppwriteMark className="size-7" />
            </span>
          </div>
          <h2 className="mx-auto mt-6 max-w-2xl text-balance font-aeonik-pro text-[28px] font-normal leading-[1.08] tracking-tight text-foreground sm:mt-7 sm:text-[36px] sm:leading-none lg:text-[44px]">
            {t(title)}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-[15px] leading-7 text-muted-foreground">{t(description)}</p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
            <StartBuildingButton />
            {children}
          </div>
        </div>
      </div>
    </section>
  )
}

/** Shared closing sequence: fair play, FAQ, related reading, other comparisons, CTA. */
export function ComparisonClosing({
  id,
  cta,
}: {
  id: AlternativeId
  cta: { title: string; description: string; secondary?: ReactNode }
}) {
  return (
    <>
      <FairPlaySection id={id} />
      <ComparisonFaq id={id} />
      <RelatedSection id={id} />
      <OtherComparisons current={id} />
      <ComparisonCta title={cta.title} description={cta.description}>
        {cta.secondary ?? <SecondaryLinkButton href="/pricing" label="View pricing" />}
      </ComparisonCta>
    </>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Small shared visuals
 * -----------------------------------------------------------------------------------------------*/

/** Inline style for a `product-hero-fill` bar: width plus a staggered start. */
export function fillStyle(widthPercent: number, delayMs: number): CSSProperties {
  return {
    width: `${Math.max(0, Math.min(100, widthPercent))}%`,
    '--fill-delay': `${delayMs}ms`,
  } as CSSProperties
}

/** Code panel title bar label, e.g. a filename. */
export function CodeFileLabel({ children, ...props }: ComponentProps<'span'>) {
  return (
    <span dir="ltr" className="font-mono text-[11px] text-muted-foreground" {...props}>
      {children}
    </span>
  )
}
