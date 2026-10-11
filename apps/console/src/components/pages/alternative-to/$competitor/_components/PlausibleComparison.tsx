import type { CSSProperties } from 'react'
import { Bot, Check, EyeOff, Sparkles, User } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { AnalyticsPlatformVisual } from '@/components/pages/products/features/analytics/AnalyticsPlatformVisual'
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

/* -------------------------------------------------------------------------------------------------
 * Hero: the shared privacy model, then where the two part ways
 * -----------------------------------------------------------------------------------------------*/

const SHARED = [
  'No cookies or browser storage',
  'IP addresses never stored',
  'Visitor IDs rotate every 24 hours',
  'No cross-site or cross-device tracking',
] as const

const DIFFERENCES: { label: string; plausible: string; appwrite: string }[] = [
  { label: 'Free plan', plausible: '30-day trial', appwrite: '50K events a month' },
  { label: 'Custom properties', plausible: 'Business plan', appwrite: 'Every plan' },
  { label: 'Bots and AI agents', plausible: 'Filtered out', appwrite: 'Labeled and shown' },
  { label: 'Next to your backend', plausible: 'Separate tool', appwrite: 'Same project' },
]

function PrivacyModelVisual() {
  const t = useT()
  return (
    <ProductVisualAura className="text-start">
      <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
        {t('Same privacy model')}
      </p>
      <ul className="mt-4 grid gap-2 sm:grid-cols-2">
        {SHARED.map((item, index) => (
          <li
            key={item}
            className="product-hero-rise flex items-center gap-2.5 rounded-lg border border-border bg-background/70 px-3 py-2.5 text-[12.5px] text-foreground dark:bg-card/70"
            style={riseStyle(120 + index * 90)}
          >
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
              <Check className="size-3" strokeWidth={3} aria-hidden />
            </span>
            {t(item)}
          </li>
        ))}
      </ul>

      <p className="mt-8 font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
        {t('Where they differ')}
      </p>
      <div className="mt-3 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] border-b border-border pb-2 text-[12px]">
        <span aria-hidden />
        <span className="flex items-center gap-1.5 text-muted-foreground">
          <CompetitorMonogram name="Plausible" />
          Plausible
        </span>
        <span className="flex items-center gap-1.5 text-foreground">
          <AppwriteMark className="size-3.5" />
          Appwrite
        </span>
      </div>
      <ul className="divide-y divide-border">
        {DIFFERENCES.map((row, index) => (
          <li
            key={row.label}
            className="product-hero-rise grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 py-2.5 text-[12.5px]"
            style={riseStyle(520 + index * 90)}
          >
            <span className="text-muted-foreground">{t(row.label)}</span>
            <span className="text-muted-foreground">{t(row.plausible)}</span>
            <span className="font-medium text-[var(--tone-ink)]">{t(row.appwrite)}</span>
          </li>
        ))}
      </ul>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Bots: the same traffic, filtered vs labeled
 * -----------------------------------------------------------------------------------------------*/

const AGENTS = [
  { name: 'GPTBot', share: 38 },
  { name: 'ClaudeBot', share: 24 },
  { name: 'Googlebot', share: 22 },
  { name: 'PerplexityBot', share: 16 },
] as const

function BotsVisual() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="grid gap-10 sm:grid-cols-2">
        <div className="border-t border-border pt-5">
          <span className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <CompetitorMonogram name="Plausible" />
            Plausible
          </span>
          <p className="mt-4 flex items-center gap-2 text-[12px] text-muted-foreground">
            <User className="size-3.5" aria-hidden />
            {t('Visitors')}
          </p>
          <p dir="ltr" className="mt-1 font-aeonik-pro text-[44px] leading-none tracking-tight text-foreground/70 tabular-nums">
            4,210
          </p>
          <div className="mt-6 flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-3 text-[12px] text-muted-foreground">
            <EyeOff className="size-3.5 shrink-0" aria-hidden />
            {t('Bots and AI crawlers removed. No report of what they read.')}
          </div>
        </div>

        <div className="border-t border-[var(--tone-ink)] pt-5">
          <span className="flex items-center gap-2 text-[13px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite Analytics')}
          </span>
          <p className="mt-4 flex items-center gap-2 text-[12px] text-muted-foreground">
            <User className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
            {t('Visitors')}
          </p>
          <p dir="ltr" className="mt-1 font-aeonik-pro text-[44px] leading-none tracking-tight text-foreground tabular-nums">
            4,210
          </p>
          <div className="mt-6 space-y-1.5">
            <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
              <Bot className="size-3.5" aria-hidden />
              {t('Plus 1,030 bot visits, labeled')}
            </p>
            {AGENTS.map((agent, index) => (
              <div key={agent.name} className="flex items-center gap-2 text-[11.5px]">
                <span dir="ltr" className="w-24 shrink-0 font-mono text-foreground">
                  {agent.name}
                </span>
                <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted/50">
                  <span
                    className="product-hero-fill block h-full rounded-full bg-amber-500/80"
                    style={{ width: `${agent.share * 2.4}%`, '--fill-delay': `${300 + index * 120}ms` } as CSSProperties}
                  />
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <p className="mt-6 flex items-center gap-2 text-[11.5px] text-muted-foreground">
        <Sparkles className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
        {t('Your visitor count stays human either way. Appwrite just shows you the rest.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Plans: starting out
 * -----------------------------------------------------------------------------------------------*/

function PlansVisual() {
  const t = useT()
  return (
    <ProductVisualAura className="text-start">
      <div className="grid gap-10 sm:grid-cols-2">
        <div className="border-t border-border pt-5">
          <span className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <CompetitorMonogram name="Plausible" />
            {t('Plausible Starter')}
          </span>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground/70 tabular-nums" dir="ltr">
            $9
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <ul className="mt-5 space-y-1.5 text-[12.5px] text-muted-foreground">
            <li>{t('10K pageviews a month')}</li>
            <li>{t('One site, after a 30-day trial')}</li>
            <li>{t('Custom properties on Business')}</li>
          </ul>
        </div>
        <div className="border-t border-[var(--tone-ink)] pt-5">
          <span className="flex items-center gap-2 text-[13px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite Free')}
          </span>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground tabular-nums" dir="ltr">
            $0
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <ul className="mt-5 space-y-1.5 text-[12.5px] text-muted-foreground">
            <li>{t('50K events a month')}</li>
            <li>{t('One property, no trial clock')}</li>
            <li>{t('Custom properties included')}</li>
          </ul>
        </div>
      </div>
      <p className="mt-6 text-[11px] leading-4 text-muted-foreground">
        {t('Plausible counts pageviews and Appwrite counts events, which include pageviews and custom events. Appwrite Pro includes 100K events and five properties, then $3 per additional 100K events.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Page
 * -----------------------------------------------------------------------------------------------*/

export function PlausibleComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Plausible" />
            <ComparisonHeroTitle className="mt-7" title="The same privacy." accent="A whole platform behind it." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Appwrite Analytics counts visitors the way Plausible does: no cookies, no stored IP addresses, identifiers that expire daily. Then it adds a free plan, bots and AI agents in plain view, and analytics that live next to your hosting, backend, and Firewall.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/analytics" label="Explore Appwrite Analytics" />
            </div>
          </div>
          <div className="min-w-0">
            <PrivacyModelVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Bots and AI"
            title="Filtered out vs. shown to you"
            description="Plausible removes automated traffic from your stats. Appwrite keeps your visitor count human too, but labels the rest, so you can see which AI crawlers read your pages and block the ones you don’t want."
          />
          <BotsVisual />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<PlausibleSideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Start free, stay private"
            description="Plausible has no free cloud plan. Appwrite Analytics is included in every Appwrite plan, starting with Free, with 50K events a month before you pay anything."
          />
          <PlansVisual />
        </div>
      </ComparisonSection>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)] lg:items-center lg:gap-16">
          <div className="order-last lg:order-first">
            <AnalyticsPlatformVisual />
          </div>
          <ComparisonHeading
            eyebrow="Platform"
            title="Analytics in the project that runs your site"
            description="Plausible is a great standalone dashboard. Appwrite Analytics sits next to your Sites and Firewall: traffic on each site’s overview, and a Firewall rule from any country, path, or bot in two clicks."
          />
        </div>
      </ComparisonSection>

      <IntegrationHubSection
        focus="analytics"
        focusLabel="Appwrite Analytics"
        eyebrow="One platform"
        title="Plausible counts your traffic. Appwrite also serves it."
        description="Plausible is a standalone dashboard. Appwrite Analytics shares a project with the site you measure, the backend behind it, and the firewall in front of it."
        items={[
          { id: 'sites', title: 'Sites', description: 'Host the site you measure and see its traffic right on the site’s overview.' },
          { id: 'firewall', title: 'Firewall', description: 'Block a country, path, or agent straight from the report that surfaced it.' },
          { id: 'domains', title: 'Domains', description: 'Buy or connect the domain, serve the site, and measure it from one Console.' },
          { id: 'storage', title: 'Storage', description: 'Serve the images and downloads on the site you measure, with transforms built in.' },
          { id: 'auth', title: 'Auth', description: 'Sign-in, MFA, and teams for the app your visitors sign up to.' },
          { id: 'databases', title: 'Databases', description: 'Keep the orders and sign-ups your custom events count in the same project.' },
        ]}
      />

      <ComparisonTableSection
        id="plausible"
        title="Same privacy, different strengths"
        description="Both are cookieless and privacy-first. Plausible goes deeper on reporting history and funnels; Appwrite adds a free plan, bot visibility, and a platform around your analytics."
      />

      <ComparisonClosing
        id="plausible"
        cta={{
          title: 'Privacy-first analytics, free to start',
          description: 'Create a property on the Free plan and see visitors, sources, bots, and AI agents without a cookie in sight.',
          secondary: <SecondaryLinkButton href="/products/analytics" label="Explore Appwrite Analytics" />,
        }}
      />
    </>
  )
}

function PlausibleSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -start-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
