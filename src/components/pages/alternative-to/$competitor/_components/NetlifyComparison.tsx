import { GitCommitHorizontal, Globe, Hammer, Layers, Rocket } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { useId, useState } from 'react'
import { ArtChip, ArtIconBadge, ArtLiveDot, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import type { PlatformProductId } from '@/lib/alternatives/platform'
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
import { IntegrationHubSection } from './IntegrationHub'
import { OpenSourceSection } from './OpenSource'

/* -------------------------------------------------------------------------------------------------
 * Usage calculator
 * -----------------------------------------------------------------------------------------------*/

/** Public Netlify credit rates (credit-based plans). */
const NETLIFY_CREDITS_PER_GB = 20
const NETLIFY_CREDITS_PER_DEPLOY = 15
const NETLIFY_PRO_TIERS = [
  { credits: 3000, price: 20 },
  { credits: 5000, price: 33 },
  { credits: 10000, price: 63 },
  { credits: 15000, price: 95 },
  { credits: 20000, price: 126 },
] as const
const NETLIFY_PACK = { credits: 1500, price: 10 }

/** Appwrite Pro allowances. */
const APPWRITE_PRO_PRICE = 25
const APPWRITE_INCLUDED_GB = 2000
const APPWRITE_OVERAGE_PER_100GB = 15

const BANDWIDTH_STEPS = [5, 10, 25, 50, 100, 150, 250, 500, 750, 1000, 1500, 2000, 2500, 3000] as const

function netlifyMonthlyCost(credits: number) {
  return Math.min(
    ...NETLIFY_PRO_TIERS.map((tier) =>
      credits <= tier.credits
        ? tier.price
        : tier.price + Math.ceil((credits - tier.credits) / NETLIFY_PACK.credits) * NETLIFY_PACK.price,
    ),
  )
}

function appwriteMonthlyCost(gigabytes: number) {
  const extra = Math.max(0, gigabytes - APPWRITE_INCLUDED_GB)
  return APPWRITE_PRO_PRICE + Math.ceil(extra / 100) * APPWRITE_OVERAGE_PER_100GB
}

function formatGigabytes(value: number) {
  return value >= 1000 ? `${value / 1000}TB` : `${value}GB`
}

/** Open calculator: sliders and two big readouts floating on the hero, no card. */
function UsageCalculator() {
  const t = useT()
  const bandwidthId = useId()
  const deploysId = useId()
  const [bandwidthIndex, setBandwidthIndex] = useState(5)
  const [deploys, setDeploys] = useState(60)

  const gigabytes = BANDWIDTH_STEPS[bandwidthIndex] ?? 150
  const credits = gigabytes * NETLIFY_CREDITS_PER_GB + deploys * NETLIFY_CREDITS_PER_DEPLOY
  const includedCredits = NETLIFY_PRO_TIERS[0].credits
  const netlifyCost = netlifyMonthlyCost(credits)
  const appwriteCost = appwriteMonthlyCost(gigabytes)
  const creditsShare = Math.min(100, (credits / includedCredits) * 100)
  const bandwidthShare = Math.min(100, (gigabytes / APPWRITE_INCLUDED_GB) * 100)
  const overCredits = credits > includedCredits

  return (
    <ProductVisualAura className="text-start">
      <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Estimate your month')}</p>

      <div className="mt-6 grid gap-7 sm:grid-cols-2">
        <div>
          <div className="flex items-baseline justify-between gap-3">
            <label htmlFor={bandwidthId} className="text-[13px] font-medium text-foreground">
              {t('Bandwidth')}
            </label>
            <span className="font-mono text-[15px] tabular-nums text-foreground" dir="ltr">
              {formatGigabytes(gigabytes)}
            </span>
          </div>
          <input
            id={bandwidthId}
            type="range"
            min={0}
            max={BANDWIDTH_STEPS.length - 1}
            step={1}
            value={bandwidthIndex}
            onChange={(event) => setBandwidthIndex(Number(event.target.value))}
            className="mt-3 w-full cursor-pointer accent-[var(--brand-cta)]"
          />
        </div>
        <div>
          <div className="flex items-baseline justify-between gap-3">
            <label htmlFor={deploysId} className="text-[13px] font-medium text-foreground">
              {t('Production deploys')}
            </label>
            <span className="font-mono text-[15px] tabular-nums text-foreground">{deploys}</span>
          </div>
          <input
            id={deploysId}
            type="range"
            min={0}
            max={300}
            step={5}
            value={deploys}
            onChange={(event) => setDeploys(Number(event.target.value))}
            className="mt-3 w-full cursor-pointer accent-[var(--brand-cta)]"
          />
        </div>
      </div>

      <div className="mt-10 grid gap-10 sm:grid-cols-2">
        <div className="relative border-t border-border pt-5">
          <span className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <CompetitorMonogram name="Netlify" />
            {t('Netlify Pro')}
          </span>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground/70 tabular-nums" dir="ltr">
            ${netlifyCost}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-muted/50">
            <div
              className={cn(
                'h-full rounded-full transition-[width] duration-500 ease-out',
                overCredits ? 'bg-amber-500/80' : 'bg-foreground/35',
              )}
              style={fillStyle(creditsShare, 0)}
            />
          </div>
          <p className="mt-2 font-mono text-[11px] tabular-nums text-muted-foreground" dir="ltr">
            {credits.toLocaleString('en-US')} / {includedCredits.toLocaleString('en-US')} {t('credits')}
          </p>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">
            {overCredits
              ? t('Over the included credits. Without auto-recharge, projects pause until you add more.')
              : t('Inside the included credits for now.')}
          </p>
        </div>

        <div className="relative border-t border-[var(--tone-ink)] pt-5">
          <span className="flex items-center gap-2 text-[13px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite Pro')}
          </span>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground tabular-nums" dir="ltr">
            ${appwriteCost}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-muted/50">
            <div
              className="h-full rounded-full bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb)/0.7)] transition-[width] duration-500 ease-out"
              style={fillStyle(bandwidthShare, 0)}
            />
          </div>
          <p className="mt-2 font-mono text-[11px] tabular-nums text-muted-foreground" dir="ltr">
            {formatGigabytes(gigabytes)} / 2TB {t('bandwidth')}
          </p>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">
            {t('Deploys are not metered, and the backend is included.')}
          </p>
        </div>
      </div>

      <p className="mt-6 text-[11px] leading-4 text-muted-foreground">
        {t('Counts only bandwidth (20 credits per GB) and production deploys (15 credits each). Netlify also bills compute and requests in credits, so real usage is higher. Netlify cost uses the cheapest Pro credit tier or add-on packs. Appwrite overage is $15 per 100GB above 2TB.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Hero: one push, from commit to a live site with its backend already attached
 * -----------------------------------------------------------------------------------------------*/

const PIPELINE_BACKEND: PlatformProductId[] = ['auth', 'databases', 'storage', 'functions', 'realtime', 'messaging']

function PipelineStep({
  icon: Icon,
  label,
  status,
  delayMs,
  highlighted = false,
  last = false,
  children,
}: {
  icon: LucideIcon
  label: string
  status?: ReactNode
  delayMs: number
  highlighted?: boolean
  last?: boolean
  children: ReactNode
}) {
  return (
    <li
      className={cn('product-hero-rise relative grid grid-cols-[2rem_minmax(0,1fr)] gap-4', !last && 'pb-8')}
      style={riseStyle(delayMs)}
    >
      {!last ? (
        <span
          className="pointer-events-none absolute bottom-0 start-[15.5px] top-8 w-px bg-gradient-to-b from-foreground/20 to-[rgb(var(--tone-rgb)/0.55)]"
          aria-hidden
        />
      ) : null}
      <span
        className={cn(
          'relative z-[1] flex size-8 items-center justify-center rounded-full border bg-background dark:bg-card',
          highlighted
            ? 'border-[rgb(var(--tone-rgb)/0.6)] text-[var(--tone-ink)] shadow-[0_0_22px_rgb(var(--tone-rgb)/0.55)]'
            : 'border-foreground/15 text-muted-foreground',
        )}
      >
        <Icon className="size-4" strokeWidth={1.75} aria-hidden />
      </span>
      <div className="min-w-0 pt-1">
        <div className="flex items-center justify-between gap-3">
          <p
            className={cn(
              'font-mono text-[10px] uppercase tracking-[0.18em]',
              highlighted ? 'text-[var(--tone-ink)]' : 'text-muted-foreground',
            )}
          >
            {label}
          </p>
          {status ? <span className="shrink-0 font-mono text-[11px] text-muted-foreground">{status}</span> : null}
        </div>
        <div className="mt-2">{children}</div>
      </div>
    </li>
  )
}

/** An open pipeline: push, build, deploy, and the backend that is already in the same project. */
function PushPipelineVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-6 pt-12 text-start">
      <ol className="relative">
        <PipelineStep icon={GitCommitHorizontal} label={t('Push')} delayMs={120}>
          <p className="flex min-w-0 flex-wrap items-center gap-2 text-[14px] text-foreground">
            <span dir="ltr" className="rounded-md border border-foreground/15 px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
              main
            </span>
            <span dir="ltr" className="font-mono text-[11px] text-muted-foreground">
              a41f9c2
            </span>
            <span dir="ltr" className="truncate">
              feat: add checkout page
            </span>
          </p>
        </PipelineStep>

        <PipelineStep icon={Hammer} label={t('Build')} status="38s" delayMs={360}>
          <p className="flex items-center gap-2 text-[14px] text-foreground">
            <ProductFeaturePublicIcon src="/icons/nextjs.svg" className="size-4" />
            <span dir="ltr">Next.js 16</span>
            <span className="text-muted-foreground">{t('Server-rendered')}</span>
          </p>
        </PipelineStep>

        <PipelineStep
          icon={Globe}
          label={t('Deploy')}
          status={
            <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
              <ArtLiveDot className="size-1.5" />
              {t('Live')}
            </span>
          }
          delayMs={600}
        >
          <p dir="ltr" className="truncate font-mono text-[13px] text-foreground">
            acme.appwrite.network
          </p>
        </PipelineStep>

        <PipelineStep icon={Layers} label={t('Same project')} delayMs={860} highlighted last>
          <div className="relative">
            <span
              className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.2),transparent_70%)]"
              aria-hidden
            />
            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {PIPELINE_BACKEND.map((id, index) => {
                const Icon = PRODUCT_NAV_REGISTRY[id].icon
                const name = PLATFORM_PRODUCTS.find((product) => product.id === id)?.name ?? id
                return (
                  <li
                    key={id}
                    className="product-hero-rise flex items-center gap-2 rounded-lg border border-[rgb(var(--tone-rgb)/0.4)] bg-[color-mix(in_srgb,rgb(var(--tone-rgb))_10%,var(--background))] px-2.5 py-2 text-[12px] font-medium text-foreground"
                    style={riseStyle(1000 + index * 90)}
                  >
                    <Icon className="size-3.5 shrink-0 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
                    {t(name)}
                  </li>
                )
              })}
            </ul>
            <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
              {t('Already connected to your site. No add-ons, no keys to paste.')}
            </p>
          </div>
        </PipelineStep>
      </ol>

      <ArtChip className="top-0 end-0 sm:-end-6" delayMs={1300} floatDelayMs={300}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Rocket} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Deploys are never metered')}</p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Deploy feed
 * -----------------------------------------------------------------------------------------------*/

const COMMITS = [
  { hash: 'a41f9c2', message: 'feat: add checkout page' },
  { hash: '7be03d1', message: 'fix: typo in pricing copy' },
  { hash: 'c9d2e88', message: 'chore: bump dependencies' },
  { hash: '1f0a6b4', message: 'feat: dark mode toggle' },
  { hash: 'e57c310', message: 'fix: hero image on mobile' },
  { hash: '90ab7fe', message: 'revert: dark mode toggle' },
]

const FEED_GRID = 'grid grid-cols-[minmax(0,1fr)_6.5rem_6.5rem] sm:grid-cols-[minmax(0,1fr)_8rem_8rem]'

/** An open git log: hairline rows, no frame. */
function DeployFeed() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className={cn(FEED_GRID, 'pb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground')}>
        <span>{t('Push to main')}</span>
        <span className="text-end">Netlify</span>
        <span className="text-end">Appwrite</span>
      </div>
      <ul className="divide-y divide-border border-y border-border">
        {COMMITS.map((commit, index) => (
          <li
            key={commit.hash}
            className={cn(FEED_GRID, 'product-hero-rise items-center py-3.5')}
            style={riseStyle(120 + index * 110)}
          >
            <span className="flex min-w-0 items-center gap-2.5">
              <GitCommitHorizontal className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span dir="ltr" className="font-mono text-[11px] text-muted-foreground">
                {commit.hash}
              </span>
              <span dir="ltr" className="truncate text-[13px] text-foreground/85">
                {commit.message}
              </span>
            </span>
            <span className="text-end font-mono text-[11px] tabular-nums text-amber-600 dark:text-amber-400" dir="ltr">
              -15 {t('credits')}
            </span>
            <span className="text-end font-mono text-[11px] text-[var(--tone-ink)]">{t('Included')}</span>
          </li>
        ))}
      </ul>
      <div className={cn(FEED_GRID, 'pt-4 text-[13px]')}>
        <span className="font-medium text-foreground">{t('One afternoon')}</span>
        <span className="text-end font-mono tabular-nums text-foreground" dir="ltr">
          -90 {t('credits')}
        </span>
        <span className="text-end font-mono text-[var(--tone-ink)]">$0</span>
      </div>
    </ProductVisualAura>
  )
}

export function NetlifyComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Netlify" />
            <ComparisonHeroTitle className="mt-7" title="Push the site." accent="The backend is already there." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Netlify hosts your site and sends you to add-ons and other vendors for the rest. Appwrite Sites deploys the same frameworks from Git, right next to first-party auth, databases, storage, functions, realtime, and messaging. Open source, in one project, with no credits to count.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/sites" label="Explore Appwrite Sites" />
            </div>
          </div>
          <div className="min-w-0">
            <PushPipelineVisual />
          </div>
        </div>
      </section>

      <IntegrationHubSection
        focus="sites"
        focusLabel="Appwrite Sites"
        title="Hosting that is wired into a real backend"
        description="Netlify bolts on identity, blobs, and a database. Appwrite Sites lives in the same project as a complete backend, so every piece already knows about the others."
        items={[
          { id: 'auth', title: 'Auth', description: 'SSR sessions with secure cookies, 40+ OAuth providers, MFA, and teams.' },
          { id: 'databases', title: 'Databases', description: 'Query rows from server routes with an ephemeral API key.' },
          { id: 'storage', title: 'Storage', description: 'Serve resized, modern image formats straight into your pages.' },
          { id: 'functions', title: 'Functions', description: 'Backend endpoints and cron jobs in 13+ runtimes, next to your site.' },
          { id: 'domains', title: 'Domains', description: 'Buy a domain and point it at your site from the same Console.' },
          { id: 'firewall', title: 'Firewall', description: 'Deny, rate limit, and challenge traffic with rules scoped to each site.' },
        ]}
      />

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Stop counting credits"
            description="On Netlify credit plans, bandwidth, deploys, compute, and requests all draw from one balance. Appwrite Pro gives each resource its own allowance, with 2TB of bandwidth and unmetered deploys. Slide to your month and compare."
          />
          <UsageCalculator />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<NetlifySideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Deploys"
            title="Push as often as you like"
            description="Every production deploy on a Netlify credit plan costs 15 credits, so small fixes add up. On Appwrite Sites, deploys are part of the plan. Builds are limited by duration, not by count."
          />
          <DeployFeed />
        </div>
      </ComparisonSection>

      <OpenSourceSection
        id="netlify"
        title="A host you can take with you"
        description="Netlify only runs on Netlify. Appwrite is open source, so your sites and backend can move between Appwrite Cloud and your own servers without a rewrite."
      />

      <ComparisonTableSection
        id="netlify"
        title="Same deploy workflow, far more included"
        description="Both host modern web apps well. The gap is everything behind the frontend, and how it is billed."
      />

      <ComparisonClosing
        id="netlify"
        cta={{
          title: 'Ship the site and the backend together',
          description: 'Deploy from Git next to auth, databases, storage, and functions, with 2TB of bandwidth on Pro and unmetered deploys.',
          secondary: <SecondaryLinkButton href="/products/sites" label="Explore Appwrite Sites" />,
        }}
      />
    </>
  )
}

function NetlifySideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
