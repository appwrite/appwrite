import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { DEDICATED_DB_REPLICA_AND_PITR_PRICING_SUMMARY } from '@/lib/pricing/dedicated-databases'
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
import { DatabaseModelsSection } from './DatabaseModels'
import { OpenSourceSection } from './OpenSource'
import { PlatformBreadthSection } from './PlatformBreadth'

/* -------------------------------------------------------------------------------------------------
 * Hero: one request, traced across providers
 * -----------------------------------------------------------------------------------------------*/

type TraceSpan = { step: string; provider: string; start: number; end: number }

/** Illustrative span positions, as a percentage of the request timeline. */
const VENDOR_TRACE: TraceSpan[] = [
  { step: 'Session check', provider: 'Auth vendor', start: 0, end: 18 },
  { step: 'Function', provider: 'Function host', start: 25, end: 47 },
  { step: 'Query', provider: 'PlanetScale', start: 54, end: 62 },
  { step: 'File link', provider: 'Storage vendor', start: 69, end: 88 },
]

const APPWRITE_TRACE: TraceSpan[] = [
  { step: 'Session check', provider: 'Auth', start: 0, end: 9 },
  { step: 'Function', provider: 'Functions', start: 9, end: 29 },
  { step: 'Query', provider: 'PostgreSQL', start: 29, end: 37 },
  { step: 'File link', provider: 'Storage', start: 37, end: 45 },
]

function TraceLane({ spans, highlighted }: { spans: TraceSpan[]; highlighted: boolean }) {
  const t = useT()
  return (
    <ul className="mt-3 space-y-2.5" dir="ltr">
      {spans.map((span, index) => {
        const next = spans[index + 1]
        return (
          <li
            key={span.step}
            className="product-hero-rise grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[8rem_minmax(0,1fr)]"
            style={riseStyle((highlighted ? 900 : 200) + index * 110)}
          >
            <span className="min-w-0">
              <span className={cn('block truncate text-[11px] font-medium', highlighted ? 'text-foreground' : 'text-foreground/70')}>
                {t(span.step)}
              </span>
              <span className="block truncate font-mono text-[9px] text-muted-foreground">{t(span.provider)}</span>
            </span>
            <span className="relative h-2.5">
              <span className="absolute inset-y-0 start-0 end-0 rounded-full bg-foreground/[0.04]" aria-hidden />
              <span
                className={cn(
                  'absolute inset-y-0 rounded-full',
                  highlighted
                    ? 'bg-[var(--tone-ink)] shadow-[0_0_10px_rgb(var(--tone-rgb)/0.7)]'
                    : 'bg-foreground/35',
                )}
                style={{ left: `${span.start}%`, width: `${span.end - span.start}%` }}
                aria-hidden
              />
              {!highlighted && next ? (
                <span
                  className="absolute top-1/2 border-t border-dashed border-amber-500/70"
                  style={{ left: `${span.end}%`, width: `${next.start - span.end}%` }}
                  aria-hidden
                />
              ) : null}
            </span>
          </li>
        )
      })}
    </ul>
  )
}

function RequestTraceVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-6 pt-8 text-start">
      <div className="product-hero-rise flex flex-wrap items-center justify-between gap-3" style={riseStyle(80)}>
        <p dir="ltr" className="font-mono text-[12px] text-muted-foreground">GET /orders/8f2a/receipt</p>
        <span className="flex items-center gap-1.5 font-mono text-[10px] text-muted-foreground">
          <span className="w-4 border-t border-dashed border-amber-500/80" aria-hidden />
          {t('Network hop between providers')}
        </span>
      </div>

      <div className="mt-6">
        <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
          <CompetitorMonogram name="PlanetScale" />
          {t('PlanetScale plus three other vendors')}
        </p>
        <TraceLane spans={VENDOR_TRACE} highlighted={false} />
      </div>

      <div className="relative isolate mt-8 border-t border-dashed border-foreground/15 pt-6">
        <span
          className="pointer-events-none absolute -inset-x-8 -bottom-8 top-0 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.16),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[12px] font-medium text-foreground">
          <AppwriteMark className="size-4" />
          {t('Appwrite, one project and one region')}
        </p>
        <TraceLane spans={APPWRITE_TRACE} highlighted />
      </div>

      <p className="mt-6 text-[11px] leading-4 text-muted-foreground">
        {t('Illustrative. Every extra provider adds a network hop, a set of credentials, and a bill.')}
      </p>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Price ladder
 * -----------------------------------------------------------------------------------------------*/

type LadderStep = { price: string; title: string; detail: string; missing?: boolean }

const PLANETSCALE_LADDER: LadderStep[] = [
  { price: '$0', title: 'No free tier', detail: 'Removed in 2024', missing: true },
  { price: '$5/mo', title: 'Single-node Postgres', detail: 'For development and low traffic' },
  { price: '$50/mo', title: 'Metal', detail: 'Local NVMe storage' },
  { price: '+ vendors', title: 'Auth, storage, functions, hosting', detail: 'Bought and billed elsewhere', missing: true },
]

const APPWRITE_LADDER: LadderStep[] = [
  { price: '$0', title: 'Free', detail: 'Serverless TablesDB, auth, storage, functions, and hosting' },
  { price: '$25/mo', title: 'Pro', detail: '$10/mo in compute credits covers the smallest dedicated PostgreSQL or MySQL tier' },
  {
    price: '+ add-ons',
    title: 'Replicas and PITR',
    detail: DEDICATED_DB_REPLICA_AND_PITR_PRICING_SUMMARY,
  },
  { price: 'Included', title: 'The rest of the backend', detail: 'Already in the same project' },
]

function Ladder({ steps, highlighted }: { steps: LadderStep[]; highlighted: boolean }) {
  const t = useT()
  return (
    <ol className="relative">
      {steps.map((step, index) => (
        <li
          key={step.title}
          className={cn('product-hero-rise relative grid grid-cols-[1rem_minmax(0,1fr)] gap-4', index < steps.length - 1 && 'pb-7')}
          style={riseStyle((highlighted ? 260 : 80) + index * 110)}
        >
          {index < steps.length - 1 ? (
            <span
              className={cn(
                'absolute bottom-0 start-[7.5px] top-4 w-px',
                highlighted ? 'bg-[rgb(var(--tone-rgb)/0.45)]' : 'bg-foreground/15',
              )}
              aria-hidden
            />
          ) : null}
          <span
            className={cn(
              'relative z-[1] mt-1.5 size-4 rounded-full border-2',
              step.missing
                ? 'border-dashed border-foreground/25 bg-background'
                : highlighted
                  ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.3)] shadow-[0_0_12px_rgb(var(--tone-rgb)/0.6)]'
                  : 'border-foreground/40 bg-background',
            )}
            aria-hidden
          />
          <div className="min-w-0">
            <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <span
                dir="ltr"
                className={cn(
                  'font-aeonik-pro text-[26px] leading-none tracking-tight',
                  step.missing ? 'text-foreground/35 line-through decoration-1' : highlighted ? 'text-foreground' : 'text-foreground/65',
                )}
              >
                {t(step.price)}
              </span>
              <span className={cn('text-[14px] font-medium', highlighted ? 'text-foreground' : 'text-foreground/75')}>
                {t(step.title)}
              </span>
            </p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(step.detail)}</p>
          </div>
        </li>
      ))}
    </ol>
  )
}

function PriceLadder() {
  const t = useT()
  return (
    <div className="grid gap-12 md:grid-cols-2 md:gap-0 md:divide-x md:divide-foreground/10 rtl:md:divide-x-reverse">
      <div className="md:pe-10">
        <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
          <CompetitorMonogram name="PlanetScale" />
          {t('Growing on PlanetScale')}
        </p>
        <div className="mt-6">
          <Ladder steps={PLANETSCALE_LADDER} highlighted={false} />
        </div>
      </div>
      <div className="relative isolate md:ps-10">
        <span
          className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.14),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[13px] text-foreground">
          <AppwriteMark className="size-4" />
          {t('Growing on Appwrite')}
        </p>
        <div className="mt-6">
          <Ladder steps={APPWRITE_LADDER} highlighted />
        </div>
      </div>
    </div>
  )
}

export function PlanetScaleComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="PlanetScale" />
            <ComparisonHeroTitle className="mt-7" title="Your queries are fast." accent="Is the rest of the request?" />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('PlanetScale runs fast MySQL and Postgres, and leaves auth, files, functions, and hosting to other vendors, each one another network hop and another bill. Appwrite runs managed PostgreSQL and MySQL next to the rest of your backend, in the same open-source project.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/postgres" label="Explore managed PostgreSQL" />
            </div>
          </div>
          <div className="min-w-0">
            <RequestTraceVisual />
          </div>
        </div>
      </section>

      <ComparisonSection backdrop={<PlanetScaleSideGlow />}>
        <ComparisonHeading
          eyebrow="Pricing"
          title="Start free. Pay for a database when you need one."
          description="PlanetScale removed its free tier in 2024, so every database starts as a paid cluster. Appwrite starts free with serverless TablesDB and the whole backend, and Pro adds managed PostgreSQL and MySQL with $10/mo in compute credits."
        />
        <div className="mt-12">
          <PriceLadder />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="planetscale"
        title="Relational is one model. Appwrite has five."
        description="PlanetScale runs PostgreSQL and MySQL. Appwrite runs both, plus TablesDB on serverless or dedicated compute, DocumentsDB, and VectorsDB, all in one project."
      />

      <PlatformBreadthSection
        id="planetscale"
        title="A great database still needs a backend"
        description="PlanetScale stops at the database. Appwrite adds auth, storage, functions, realtime, messaging, hosting, domains, and a firewall in the same project, with one Console and one bill."
      />

      <OpenSourceSection
        id="planetscale"
        title="Run the whole stack anywhere"
        description="Vitess is open source, but the PlanetScale platform runs only on PlanetScale. Appwrite is open source end to end, so your databases and the backend around them run on Appwrite Cloud or on your own servers."
      />

      <ComparisonTableSection
        id="planetscale"
        title="Managed SQL on both sides. A backend on one."
        description="Both run managed PostgreSQL and MySQL. Only one gives you the rest of the backend in the same project."
      />

      <ComparisonClosing
        id="planetscale"
        cta={{
          title: 'Bring your database home to a full backend',
          description: 'Start free, add managed PostgreSQL or MySQL on Pro, and keep auth, files, and functions in the same project.',
          secondary: <SecondaryLinkButton href="/products/postgres" label="Explore managed PostgreSQL" />,
        }}
      />
    </>
  )
}

function PlanetScaleSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
