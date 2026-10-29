import {
  Database,
  Folder,
  GitBranch,
  KeyRound,
  Radio,
  Shield,
  ShieldCheck,
  Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useId, useState } from 'react'
import {
  ArtChip,
  ArtIconBadge,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { PLATFORM_COVERAGE, PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import type { PlatformCoverage } from '@/lib/alternatives/platform'
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
  fillStyle,
  SecondaryLinkButton,
  StartBuildingButton,
  VersusPill,
  comparisonHeroCopyClassName,
  comparisonHeroGridClassName,
} from './ComparisonParts'
import { OpenSourceSection } from './OpenSource'

/* -------------------------------------------------------------------------------------------------
 * Hero: a deployed site with its backend attached
 * -----------------------------------------------------------------------------------------------*/

const ATTACHED_SERVICES: { label: string; detail: string; icon: LucideIcon }[] = [
  { label: 'Auth', detail: '12,403 users', icon: Users },
  { label: 'Databases', detail: '3 tables', icon: Database },
  { label: 'Storage', detail: '2 buckets', icon: Folder },
  { label: 'Realtime', detail: '214 live', icon: Radio },
]

function DeployedSiteVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-12 pt-8">
      <ArtWindow
        title={<span className="text-[11px] font-medium text-muted-foreground">acme-web</span>}
        trailing={
          <span className="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
            <ArtLiveDot className="size-1.5" />
            {t('Ready')}
          </span>
        }
        className="product-hero-rise text-start"
        bodyClassName="p-0"
        style={riseStyle(160)}
      >
        <div className="relative aspect-[16/8] overflow-hidden border-b border-border bg-muted/30">
          <div className="product-dot-grid absolute inset-0 opacity-60" aria-hidden />
          <div className="absolute inset-x-6 top-6 space-y-2" aria-hidden>
            <div className="h-2.5 w-24 rounded-full bg-foreground/15" />
            <div className="h-5 w-3/4 rounded-md bg-foreground/10" />
            <div className="h-5 w-1/2 rounded-md bg-foreground/10" />
            <div className="mt-4 flex gap-2">
              <div className="h-6 w-20 rounded-md bg-[var(--brand-cta)]/70" />
              <div className="h-6 w-16 rounded-md border border-foreground/15" />
            </div>
          </div>
          <div className="absolute inset-x-6 bottom-5 grid grid-cols-3 gap-2" aria-hidden>
            {[0, 1, 2].map((index) => (
              <div key={index} className="h-10 rounded-md border border-foreground/10 bg-background/60" />
            ))}
          </div>
        </div>
        <div className="grid gap-3 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
          <div className="min-w-0">
            <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground">{t('Domain')}</p>
            <p dir="ltr" className="mt-1 truncate font-mono text-[12px] text-foreground">acme-web.appwrite.network</p>
          </div>
          <div className="min-w-0">
            <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground">{t('Source')}</p>
            <p className="mt-1 flex items-center gap-1.5 truncate text-[12px] text-foreground">
              <ProductFeaturePublicIcon src="/icons/nextjs.svg" className="size-3.5" />
              <span dir="ltr">Next.js 16</span>
              <GitBranch className="ms-1 size-3 text-muted-foreground" aria-hidden />
              <span dir="ltr" className="font-mono text-muted-foreground">main</span>
            </p>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-px border-t border-border bg-border sm:grid-cols-4">
          {ATTACHED_SERVICES.map((service, index) => {
            const Icon = service.icon
            return (
              <div
                key={service.label}
                className="product-hero-rise flex items-center gap-2 bg-background px-3 py-2.5 dark:bg-card"
                style={riseStyle(600 + index * 120)}
              >
                <Icon className="size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
                <span className="min-w-0">
                  <span className="block text-[11px] font-medium leading-4 text-foreground">{t(service.label)}</span>
                  <span className="block truncate text-[10px] leading-4 text-muted-foreground">{t(service.detail)}</span>
                </span>
              </div>
            )
          })}
        </div>
      </ArtWindow>

      <ArtChip className="top-0 end-2 sm:-end-6" delayMs={1100} floatDelayMs={300}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={KeyRound} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Ephemeral API key, no secret to store')}</p>
        </div>
      </ArtChip>
      <ArtChip className="bottom-0 start-2 sm:-start-8" delayMs={1400} floatDelayMs={1000}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ShieldCheck} tone="success" />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Same project, CORS already trusted')}</p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Seat calculator
 * -----------------------------------------------------------------------------------------------*/

const VERCEL_SEAT_PRICE = 20
const APPWRITE_PRO_PRICE = 25
const MAX_TEAM = 30

function SeatCalculator() {
  const t = useT()
  const sliderId = useId()
  const [team, setTeam] = useState(5)
  const vercel = team * VERCEL_SEAT_PRICE
  const appwrite = APPWRITE_PRO_PRICE
  const max = MAX_TEAM * VERCEL_SEAT_PRICE
  const yearlyDifference = Math.abs(vercel - appwrite) * 12
  const appwriteCostsLess = appwrite < vercel

  return (
    <ProductVisualAura>
      <div className="flex items-end justify-between gap-4">
        <label htmlFor={sliderId} className="text-[13px] font-medium text-foreground">
          {t('Developers who deploy')}
        </label>
        <span className="font-aeonik-pro text-[32px] leading-none tabular-nums text-foreground">{team}</span>
      </div>
      <input
        id={sliderId}
        type="range"
        min={1}
        max={MAX_TEAM}
        step={1}
        value={team}
        onChange={(event) => setTeam(Number(event.target.value))}
        className="mt-4 w-full cursor-pointer accent-[var(--brand-cta)]"
      />
      <div className="mt-1 flex justify-between font-mono text-[10px] text-muted-foreground" dir="ltr">
        <span>1</span>
        <span>{MAX_TEAM}</span>
      </div>

      <div className="mt-7 space-y-5">
        <div>
          <div className="flex items-baseline justify-between gap-3">
            <span className="flex items-center gap-2 text-[13px] text-muted-foreground">
              <CompetitorMonogram name="Vercel" />
              {t('Vercel Pro seats')}
            </span>
            <span className="font-mono text-[15px] tabular-nums text-foreground" dir="ltr">
              ${vercel}
              <span className="text-[11px] text-muted-foreground">/mo</span>
            </span>
          </div>
          <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-muted/50">
            <div
              className="h-full rounded-full bg-foreground/30 transition-[width] duration-500 ease-out"
              style={fillStyle((vercel / max) * 100, 0)}
            />
          </div>
        </div>
        <div>
          <div className="flex items-baseline justify-between gap-3">
            <span className="flex items-center gap-2 text-[13px] text-foreground">
              <AppwriteMark className="size-4" />
              {t('Appwrite Pro, unlimited members')}
            </span>
            <span className="font-mono text-[15px] tabular-nums text-foreground" dir="ltr">
              ${appwrite}
              <span className="text-[11px] text-muted-foreground">/mo</span>
            </span>
          </div>
          <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-muted/50">
            <div
              className="h-full rounded-full bg-[var(--tone-ink)] transition-[width] duration-500 ease-out"
              style={fillStyle((appwrite / max) * 100, 0)}
            />
          </div>
        </div>
      </div>

      <div className="relative mt-8 flex flex-wrap items-end justify-between gap-3 border-t border-border pt-5">
        <span className="absolute start-0 top-0 h-px w-16 bg-[var(--tone-ink)]" aria-hidden />
        <span className="text-[13px] text-foreground">
          {appwriteCostsLess
            ? t('Saved on platform fees per year')
            : t('Vercel costs less per year for a solo developer')}
        </span>
        <span className="font-aeonik-pro text-[40px] leading-none tabular-nums text-foreground" dir="ltr">
          ${yearlyDifference.toLocaleString('en-US')}
        </span>
      </div>
      <p className="mt-3 text-[11px] leading-4 text-muted-foreground">
        {t('Platform fees only, before usage. Vercel Pro is $20/mo per deploying seat and includes $20 of usage credit. Viewer seats are free. Appwrite Pro starts at $25/mo and includes the backend.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Stack map
 * -----------------------------------------------------------------------------------------------*/

const VERCEL_TILE: Record<PlatformCoverage, { tile: string; label: string; note?: string }> = {
  yes: { tile: 'border-border bg-muted/40 text-foreground/70', label: 'text-foreground/75' },
  partial: {
    tile: 'border-border text-muted-foreground bg-[repeating-linear-gradient(135deg,transparent_0_5px,color-mix(in_srgb,var(--foreground)_7%,transparent)_5px_10px)]',
    label: 'text-foreground/65',
    note: 'Marketplace',
  },
  no: {
    tile: 'border-dashed border-foreground/25 bg-transparent text-muted-foreground/60',
    label: 'text-muted-foreground',
    note: 'Another vendor',
  },
}

/** The same ten products as the Appwrite side, marked by how a Vercel project gets them. */
function VercelStackMap() {
  const t = useT()
  const coverage = PLATFORM_COVERAGE.vercel
  return (
    <div className="relative flex h-full flex-col">
      <p className="flex items-center gap-2 text-[13px] font-medium text-muted-foreground">
        <CompetitorMonogram name="Vercel" />
        {t('A typical Vercel stack')}
      </p>
      <ul className="relative mt-6 grid flex-1 grid-cols-3 content-start gap-x-3 gap-y-6 sm:grid-cols-5">
        {PLATFORM_PRODUCTS.map((product, index) => {
          const Icon = PRODUCT_NAV_REGISTRY[product.id].icon
          const style = VERCEL_TILE[coverage[product.id]]
          return (
            <li
              key={product.id}
              className="product-hero-rise flex flex-col items-center gap-2 text-center"
              style={riseStyle(120 + index * 50)}
            >
              <span className={cn('flex size-11 items-center justify-center rounded-2xl border', style.tile)}>
                <Icon className="size-5" strokeWidth={1.75} aria-hidden />
              </span>
              <span className={cn('text-[12px] font-medium', style.label)}>{t(product.name)}</span>
              {style.note ? (
                <span className="-mt-1 whitespace-nowrap font-mono text-[9px] text-muted-foreground">{t(style.note)}</span>
              ) : null}
            </li>
          )
        })}
      </ul>
      <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-t border-dashed border-foreground/20 pt-4 text-[12px] text-muted-foreground">
        {['Several dashboards', 'Several bills', 'Keys to wire up'].map((label) => (
          <span key={label} className="flex items-center gap-2">
            <span className="size-1.5 rounded-full bg-foreground/30" aria-hidden />
            {t(label)}
          </span>
        ))}
      </div>
    </div>
  )
}

function AppwriteStackMap() {
  const t = useT()
  return (
    <div className="relative flex h-full flex-col">
      <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
        <AppwriteMark className="size-4" />
        {t('The same app on Appwrite')}
      </p>
      <div className="relative mt-6 flex-1">
        <div
          className="pointer-events-none absolute -inset-6 rounded-[40px] bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.16),transparent_70%)]"
          aria-hidden
        />
        <ul className="relative grid grid-cols-3 gap-x-3 gap-y-6 sm:grid-cols-5">
          {PLATFORM_PRODUCTS.map((product, index) => {
            const Icon = PRODUCT_NAV_REGISTRY[product.id].icon
            return (
              <li
                key={product.id}
                className="product-hero-rise flex flex-col items-center gap-2 text-center"
                style={riseStyle(300 + index * 60)}
              >
                <span className="flex size-11 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] shadow-[0_10px_26px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
                  <Icon className="size-5" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="text-[12px] font-medium text-foreground">{t(product.name)}</span>
              </li>
            )
          })}
        </ul>
      </div>
      <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-t border-border pt-4 text-[12px] font-medium text-foreground">
        {['One Console', 'One bill', 'One budget cap'].map((label) => (
          <span key={label} className="flex items-center gap-2">
            <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
            {t(label)}
          </span>
        ))}
      </div>
    </div>
  )
}

const FRAMEWORKS = [
  { name: 'Next.js', icon: '/icons/nextjs.svg' },
  { name: 'Nuxt', icon: '/icons/nuxt.svg' },
  { name: 'SvelteKit', icon: '/icons/svelte.svg' },
  { name: 'Astro', icon: '/icons/astro.svg' },
  { name: 'Remix', icon: '/icons/remix.svg' },
  { name: 'TanStack Start', icon: '/icons/tanstack.svg' },
  { name: 'Angular', icon: '/icons/angular.svg' },
  { name: 'Analog', icon: '/icons/analog.svg' },
  { name: 'React', icon: '/icons/react.svg' },
  { name: 'Vue', icon: '/icons/vue.svg' },
  { name: 'Flutter Web', icon: '/icons/flutter.svg' },
  { name: 'React Native', icon: '/icons/react-native.svg' },
] as const

const SECURITY_DEFAULTS: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: 'CORS that already knows your site',
    description:
      'Sites in the same project are trusted by your backend automatically, so there is no list of allowed origins to maintain and no wildcard that lets every preview call your API.',
    icon: ShieldCheck,
  },
  {
    title: 'API keys you never paste',
    description:
      'Every build and server render gets an ephemeral API key, scoped to the site and expiring on its own, so there is no long-lived secret to store or rotate.',
    icon: KeyRound,
  },
  {
    title: 'Firewall rules per site',
    description:
      'Deny, rate limit, redirect, or challenge traffic with priority-ordered rules scoped to a site, a function, or the API. 50 rules per project on Pro.',
    icon: Shield,
  },
]

export function VercelComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Vercel" />
            <ComparisonHeroTitle className="mt-7" title="Deploy the frontend." accent="Bring the whole backend." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Vercel hosts your frontend and sends you elsewhere for the rest. Appwrite Sites hosts the same frameworks next to first-party auth, databases, storage, functions, messaging, and realtime. Open source, in one project, with no per-seat pricing.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton
                href="/docs/products/sites/migrations/vercel"
                label="Migrate from Vercel"
                analytics="alternative-migrate"
              />
            </div>
          </div>
          <div className="min-w-0">
            <DeployedSiteVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Architecture"
          title="One project instead of five vendors"
          description="Vercel focuses on the frontend and points you to Marketplace partners for data, and to other providers for auth, email, and realtime. Appwrite ships ten products first-party, in one project."
        />
        <div className="mt-14 grid gap-14 lg:grid-cols-2 lg:gap-0 lg:divide-x lg:divide-border rtl:lg:divide-x-reverse">
          <div className="lg:pe-12">
            <VercelStackMap />
          </div>
          <div className="lg:ps-12">
            <AppwriteStackMap />
          </div>
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<VercelSideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Team pricing"
            title="Grow the team, not the invoice"
            description="Vercel Pro charges for every developer who deploys. Appwrite Pro includes unlimited organization members, so hiring your next engineer does not change your plan."
          />
          <SeatCalculator />
        </div>
      </ComparisonSection>

      <ComparisonSection>
        <ComparisonHeading
          eyebrow="Secure by default"
          title="Hosting that already knows your backend"
          description="When the site and the API live in the same project, the safe setup is the default one."
        />
        <div className="mt-12 grid gap-10 md:grid-cols-3">
          {SECURITY_DEFAULTS.map((item, index) => {
            const Icon = item.icon
            return (
              <article
                key={item.title}
                className="product-hero-rise relative border-t border-foreground/15 pt-6"
                style={riseStyle(100 + index * 120)}
              >
                <span className="absolute start-0 top-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
                <ArtIconBadge icon={Icon} />
                <h3 className="mt-5 text-[15px] font-medium text-foreground">{t(item.title)}</h3>
                <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(item.description)}</p>
              </article>
            )
          })}
        </div>

        <div className="mt-16">
          <p className="text-center font-mono text-[11px] uppercase tracking-[0.2em] text-muted-foreground">
            {t('Presets for the frameworks you already use')}
          </p>
          <ul className="mx-auto mt-8 flex max-w-5xl flex-wrap justify-center gap-x-8 gap-y-6">
            {FRAMEWORKS.map((framework, index) => (
              <li
                key={framework.name}
                className="product-hero-rise group flex items-center gap-2.5"
                style={riseStyle(200 + index * 40)}
              >
                <ProductFeaturePublicIcon
                  src={framework.icon}
                  tone="muted-foreground"
                  className="size-5 transition-colors group-hover:bg-foreground"
                />
                <span dir="ltr" className="text-[13px] font-medium text-foreground/80">
                  {framework.name}
                </span>
              </li>
            ))}
          </ul>
        </div>
      </ComparisonSection>

      <OpenSourceSection
        id="vercel"
        title="Host on our cloud, or on yours"
        description="Vercel only runs on Vercel. Appwrite is open source, so the same Sites, backend, and Console run on Appwrite Cloud or on any server you control."
      />

      <ComparisonTableSection
        id="vercel"
        title="Hosting is a tie. The backend is not."
        description="Hosting features line up closely. The difference is everything behind the frontend."
      />

      <ComparisonClosing
        id="vercel"
        cta={{
          title: 'Move your frontend next to your backend',
          description: 'Connect a repository, pick a preset, and ship with auth, data, and storage already in the project.',
          secondary: (
            <SecondaryLinkButton
              href="/docs/products/sites/migrations/vercel"
              label="Read the migration guide"
              analytics="alternative-migrate"
            />
          ),
        }}
      />
    </>
  )
}

function VercelSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -start-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
