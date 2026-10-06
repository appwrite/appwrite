import { Terminal } from 'lucide-react'
import { ArtChip, ArtIconBadge, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { PLATFORM_COVERAGE, PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import type { PlatformCoverage, PlatformProductId } from '@/lib/alternatives/platform'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
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
import { IntegrationHubSection } from './IntegrationHub'
import { OpenSourceSection } from './OpenSource'

/* -------------------------------------------------------------------------------------------------
 * Hero: the backend, layer by layer
 * -----------------------------------------------------------------------------------------------*/

/** Bottom to top: the database first, then everything an app needs around it. */
const STACK_ORDER: PlatformProductId[] = [
  'postgres',
  'databases',
  'auth',
  'storage',
  'functions',
  'realtime',
  'messaging',
  'sites',
  'domains',
  'firewall',
]

const LAYER_STYLE: Record<'appwrite' | PlatformCoverage, string> = {
  appwrite:
    'border-[rgb(var(--tone-rgb)/0.45)] bg-[color-mix(in_srgb,rgb(var(--tone-rgb))_12%,var(--background))] text-foreground shadow-[0_8px_24px_-14px_rgb(var(--tone-rgb)/0.9)]',
  yes: 'border-border bg-muted/60 text-foreground/75',
  partial:
    'border-border text-muted-foreground bg-[repeating-linear-gradient(135deg,transparent_0_5px,color-mix(in_srgb,var(--foreground)_7%,transparent)_5px_10px)]',
  no: 'border-dashed border-foreground/20 bg-transparent text-muted-foreground/70',
}

function StackTower({ side }: { side: 'neon' | 'appwrite' }) {
  const t = useT()
  const isAppwrite = side === 'appwrite'
  const coverage = PLATFORM_COVERAGE.neon
  const builtIn = isAppwrite ? STACK_ORDER.length : STACK_ORDER.filter((id) => coverage[id] === 'yes').length

  return (
    <div className="relative isolate min-w-0">
      {isAppwrite ? (
        <span
          className="pointer-events-none absolute -inset-x-8 -inset-y-6 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.2),transparent_70%)]"
          aria-hidden
        />
      ) : null}
      <p
        className={cn(
          'flex items-center gap-2 text-[13px] font-medium',
          isAppwrite ? 'text-foreground' : 'text-muted-foreground',
        )}
      >
        {isAppwrite ? <AppwriteMark className="size-4" /> : <CompetitorMonogram name="Neon" />}
        {isAppwrite ? 'Appwrite' : 'Neon'}
      </p>

      <ol className="mt-4 flex flex-col-reverse gap-1.5">
        {STACK_ORDER.map((id, index) => {
          const product = PLATFORM_PRODUCTS.find((item) => item.id === id)
          const Icon = PRODUCT_NAV_REGISTRY[id].icon
          const status: PlatformCoverage = isAppwrite ? 'yes' : coverage[id]
          return (
            <li
              key={id}
              className={cn(
                'product-hero-rise flex h-9 min-w-0 items-center gap-2 rounded-lg border px-2.5 text-[12px]',
                LAYER_STYLE[isAppwrite ? 'appwrite' : status],
              )}
              style={riseStyle((isAppwrite ? 380 : 120) + index * 70)}
            >
              <Icon
                className={cn('size-3.5 shrink-0', isAppwrite && 'text-[var(--tone-ink)]')}
                strokeWidth={1.75}
                aria-hidden
              />
              <span className="truncate">{t(product?.name ?? id)}</span>
              {status !== 'yes' ? (
                <span className="ms-auto hidden shrink-0 font-mono text-[9px] uppercase tracking-wider sm:inline">
                  {status === 'partial' ? t('Limited') : t('Another vendor')}
                </span>
              ) : null}
            </li>
          )
        })}
      </ol>

      <div className="mt-5 border-t border-border pt-4">
        <p
          className={cn(
            'font-aeonik-pro text-[40px] leading-none tracking-tight tabular-nums',
            isAppwrite ? 'text-[var(--tone-ink)]' : 'text-foreground/45',
          )}
          dir="ltr"
        >
          {builtIn}
          <span className="text-[18px] text-muted-foreground">/{STACK_ORDER.length}</span>
        </p>
        <p className="mt-1.5 text-[12px] text-muted-foreground">
          {isAppwrite ? t('Built in, one project') : t('Built in, the rest is up to you')}
        </p>
      </div>
    </div>
  )
}

function StackVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-14 text-start">
      <p className="product-hero-rise font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground" style={riseStyle(60)}>
        {t('Your backend, layer by layer')}
      </p>
      <div className="mt-6 grid grid-cols-2 gap-5 sm:gap-10">
        <StackTower side="neon" />
        <StackTower side="appwrite" />
      </div>
      <ArtChip className="bottom-0 end-0 max-w-full sm:-end-6" delayMs={1300} floatDelayMs={400}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Terminal} />
          <p dir="ltr" className="truncate font-mono text-[10px] leading-4 text-foreground">
            psql postgresql://db-4f2a.fra.appwrite.center
          </p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Spec sheet
 * -----------------------------------------------------------------------------------------------*/

const SPEC_SHEET: { label: string; value: string }[] = [
  { label: 'Engine', value: 'PostgreSQL 18 (17 available)' },
  { label: 'Regions', value: 'Frankfurt, New York, San Francisco, Singapore, Sydney, Toronto' },
  { label: 'High availability', value: 'Up to 5 replicas, async, sync, or quorum' },
  { label: 'Point-in-time recovery', value: 'Any moment in a 1 to 35 day window' },
  { label: 'Extensions', value: 'Up to 50, including pgvector, PostGIS, pg_trgm' },
  { label: 'Connections', value: 'Direct or through the built-in pooler' },
  { label: 'Branches', value: 'Snapshot copies, 24 hours by default' },
  { label: 'Upgrades', value: 'Online major version upgrades' },
  { label: 'Access', value: 'TLS hostname per database and an IP allowlist' },
]

function SpecSheet() {
  const t = useT()
  return (
    <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
      {SPEC_SHEET.map((row, index) => (
        <div
          key={row.label}
          className="product-hero-rise bg-background px-4 py-4 sm:px-5 sm:py-5"
          style={riseStyle(80 + index * 45)}
        >
          <dt className="font-mono text-[11px] uppercase tracking-[0.14em] text-muted-foreground">
            <span className="text-[var(--tone-ink)]">{String(index + 1).padStart(2, '0')}</span>{' '}
            {t(row.label)}
          </dt>
          <dd className="mt-2 text-[13px] leading-6 text-foreground">{t(row.value)}</dd>
        </div>
      ))}
    </dl>
  )
}

export function NeonComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Neon" />
            <ComparisonHeroTitle className="mt-7" title="Postgres, plus" accent="everything around it." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 lg:mx-0">
              {t('Neon gives you serverless Postgres and leaves most of the stack to you. Appwrite runs managed PostgreSQL inside a complete open-source backend, with auth, storage, functions in 13+ runtimes, realtime, messaging, and hosting in one project.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 lg:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/postgres" label="Explore managed PostgreSQL" />
            </div>
          </div>
          <div className="min-w-0">
            <StackVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          eyebrow="Spec sheet"
          title="Standard PostgreSQL, no proprietary layer"
          description="Connect with psql, pgAdmin, Prisma, Drizzle, or any driver. Your schema, migrations, roles, and extensions are standard PostgreSQL, so you can leave the same way you arrived: pg_dump."
          align="center"
          className="mx-auto max-w-3xl"
        />
        <div className="mx-auto mt-10 max-w-5xl">
          <SpecSheet />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="neon"
        title="Postgres, plus four more database models"
        description="Neon runs one engine, PostgreSQL, on serverless compute. Appwrite adds tables, documents, and vectors on serverless or dedicated compute, plus MySQL, next to managed PostgreSQL in the same project."
      />

      <IntegrationHubSection
        focus="postgres"
        focusLabel="Managed PostgreSQL"
        title="Postgres that sits inside your backend"
        description="Neon stops at the database and a few add-ons. On Appwrite, your database lives in the same project, region, and Console as everything that talks to it."
        items={[
          { id: 'functions', title: 'Functions', description: 'Connect from 13+ runtimes over the pooler, in the same region.' },
          { id: 'sites', title: 'Sites', description: 'Server-rendered routes query Postgres from the same project.' },
          { id: 'auth', title: 'Auth', description: 'Sign users in with Appwrite and key your rows by user and team.' },
          { id: 'storage', title: 'Storage', description: 'Keep files in buckets and their metadata in Postgres.' },
          { id: 'messaging', title: 'Messaging', description: 'Send email, SMS, or push from the code that writes your data.' },
          { id: 'realtime', title: 'Realtime', description: 'Push changes to every open client the moment your data moves.' },
        ]}
      />

      <OpenSourceSection
        id="neon"
        title="The whole platform is yours to run"
        description="Neon publishes its storage engine, but the platform around it only runs on Neon. Appwrite is open source end to end, so Postgres and every other product run on Appwrite Cloud or on your own servers."
      />

      <ComparisonTableSection
        id="neon"
        title="Postgres on both sides. A backend on one."
        description="Both run real PostgreSQL. Only one gives you the rest of the backend in the same project."
      />

      <ComparisonClosing
        id="neon"
        cta={{
          title: 'Postgres and the whole backend, in one project',
          description: 'Create a database in your project region, copy the connection string, and run your first query in minutes.',
          secondary: <SecondaryLinkButton href="/products/postgres" label="Explore managed PostgreSQL" />,
        }}
      />
    </>
  )
}
