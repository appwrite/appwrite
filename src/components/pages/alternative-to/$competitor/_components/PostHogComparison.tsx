import type { ReactNode } from 'react'
import { Check, Cookie, Fingerprint, Link2, MapPin, X, type LucideIcon } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { AnalyticsAiTrafficVisual } from '@/components/pages/products/features/analytics/AnalyticsAiTrafficVisual'
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
 * Hero: what a default install leaves behind in the browser and in your data
 * -----------------------------------------------------------------------------------------------*/

type Trace = { label: string; detail: string; icon: LucideIcon }

/** A default PostHog web install (cookie persistence, persistent distinct ID, IP and full URLs captured). */
const POSTHOG_TRACES: Trace[] = [
  { label: 'Cookie', detail: 'ph_…_posthog', icon: Cookie },
  { label: 'Persistent ID', detail: 'distinct_id across visits', icon: Fingerprint },
  { label: 'IP address', detail: 'Kept unless you discard it', icon: MapPin },
  { label: 'Full URL', detail: '/reset?token=…&email=…', icon: Link2 },
]

const APPWRITE_TRACES: Trace[] = [
  { label: 'Browser storage', detail: 'Nothing written', icon: Cookie },
  { label: 'Visitor ID', detail: 'Hashed, rotates every 24h', icon: Fingerprint },
  { label: 'Location', detail: 'Country and city only', icon: MapPin },
  { label: 'Page', detail: '/reset', icon: Link2 },
]

function TraceColumn({
  title,
  icon,
  traces,
  appwrite,
  delayMs,
}: {
  title: string
  icon: ReactNode
  traces: Trace[]
  appwrite: boolean
  delayMs: number
}) {
  const t = useT()
  return (
    <div className="product-hero-rise min-w-0" style={riseStyle(delayMs)}>
      <p
        className={cn(
          'flex items-center gap-2 border-b pb-3 text-[13px]',
          appwrite ? 'border-[var(--tone-ink)] text-foreground' : 'border-border text-muted-foreground',
        )}
      >
        {icon}
        {title}
      </p>
      <ul className="divide-y divide-border">
        {traces.map((trace, index) => {
          const Icon = trace.icon
          return (
            <li
              key={trace.label}
              className="product-hero-rise flex items-center gap-3 py-3"
              style={riseStyle(delayMs + 120 + index * 90)}
            >
              <span
                className={cn(
                  'flex size-7 shrink-0 items-center justify-center rounded-md',
                  appwrite
                    ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                    : 'border border-border bg-muted/40 text-muted-foreground',
                )}
              >
                <Icon className="size-3.5" aria-hidden />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-[12.5px] font-medium text-foreground">{t(trace.label)}</span>
                <span dir="ltr" className="block truncate font-mono text-[11px] text-muted-foreground">
                  {t(trace.detail)}
                </span>
              </span>
              {appwrite ? (
                <Check className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
              ) : (
                <X className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function TracesVisual() {
  const t = useT()
  return (
    <ProductVisualAura className="text-start">
      <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
        {t('One pageview, default settings')}
      </p>
      <div className="mt-6 grid gap-8 sm:grid-cols-2">
        <TraceColumn
          title="PostHog"
          icon={<CompetitorMonogram name="PostHog" />}
          traces={POSTHOG_TRACES}
          appwrite={false}
          delayMs={120}
        />
        <TraceColumn
          title="Appwrite Analytics"
          icon={<AppwriteMark className="size-4" />}
          traces={APPWRITE_TRACES}
          appwrite
          delayMs={320}
        />
      </div>
      <p className="mt-6 text-[11px] leading-4 text-muted-foreground">
        {t('PostHog can be configured for cookieless tracking and IP discarding. Appwrite Analytics works this way out of the box, with no setting to forget.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Scope: what each tool is built to answer
 * -----------------------------------------------------------------------------------------------*/

const QUESTIONS: { question: string; appwrite: boolean; posthog: boolean }[] = [
  { question: 'How many people visited this week?', appwrite: true, posthog: true },
  { question: 'Which pages, sources, and campaigns brought them?', appwrite: true, posthog: true },
  { question: 'How much of my traffic is bots and AI agents?', appwrite: true, posthog: true },
  { question: 'Did sign-ups go up after the launch?', appwrite: true, posthog: true },
  { question: 'Where do users drop off in onboarding?', appwrite: false, posthog: true },
  { question: 'What did this specific user do last Tuesday?', appwrite: false, posthog: true },
]

function ScopeList() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="grid grid-cols-[minmax(0,1fr)_5.5rem_5.5rem] pb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground">
        <span>{t('Question')}</span>
        <span className="text-center">Appwrite</span>
        <span className="text-center">PostHog</span>
      </div>
      <ul className="divide-y divide-border border-y border-border">
        {QUESTIONS.map((row, index) => (
          <li
            key={row.question}
            className="product-hero-rise grid grid-cols-[minmax(0,1fr)_5.5rem_5.5rem] items-center py-3"
            style={riseStyle(100 + index * 90)}
          >
            <span className="text-[13px] text-foreground/90">{t(row.question)}</span>
            {[row.appwrite, row.posthog].map((supported, column) => (
              <span key={column} className="flex justify-center">
                {supported ? (
                  <Check
                    className={cn(
                      'size-4',
                      column === 0 ? 'text-[var(--tone-ink)]' : 'text-muted-foreground',
                    )}
                    aria-hidden
                  />
                ) : (
                  <span className="h-px w-3 bg-muted-foreground/50" aria-hidden />
                )}
              </span>
            ))}
          </li>
        ))}
      </ul>
      <p className="mt-4 text-[12px] leading-5 text-muted-foreground">
        {t('Appwrite answers traffic questions without identifying anyone. Questions about individual users need identity, which is what PostHog is built around.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Page
 * -----------------------------------------------------------------------------------------------*/

export function PostHogComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="PostHog" />
            <ComparisonHeroTitle className="mt-7" title="Know your traffic." accent="Not your visitors." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('PostHog is a product toolkit built around identifying users. Appwrite Analytics answers the traffic questions most sites actually ask, cookieless and anonymous by default, with bots and AI agents in plain view and your backend and hosting in the same project.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/analytics" label="Explore Appwrite Analytics" />
            </div>
          </div>
          <div className="min-w-0">
            <TracesVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Scope"
            title="Answers, not a product suite"
            description="PostHog bundles replay, flags, experiments, surveys, and a warehouse around user identity. If you mostly need to know how your site is doing, Appwrite gives you that on one page, without identifying anyone."
          />
          <ScopeList />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<PostHogSideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Bots and AI"
            title="Every agent, labeled by name"
            description="Appwrite separates people from crawlers and agents on the main dashboard, names them, and gives AI assistant referrals their own channel."
          />
          <AnalyticsAiTrafficVisual />
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
            title="Analytics that can act"
            description="Appwrite Analytics shares a project with your Sites, Functions, and Firewall. See traffic on each site’s overview, track server-side from a Function, and turn an abusive country, path, or bot into a Firewall rule in two clicks."
          />
        </div>
      </ComparisonSection>

      <IntegrationHubSection
        focus="analytics"
        focusLabel="Appwrite Analytics"
        eyebrow="One platform"
        title="PostHog measures your product. Appwrite also runs it."
        description="PostHog sits beside your stack. Appwrite Analytics sits inside it, in the same project as your hosting, backend, and firewall, with one Console and one bill."
        items={[
          { id: 'sites', title: 'Sites', description: 'Last-24-hour traffic on every site’s overview, one click from the full dashboard.' },
          { id: 'firewall', title: 'Firewall', description: 'Turn a country, path, or agent from any report into a rule for your site.' },
          { id: 'functions', title: 'Functions', description: 'Send server-side events from a function with an API key, no client code.' },
          { id: 'auth', title: 'Auth', description: 'Attach your own user ID to server events when you choose to, nothing by default.' },
          { id: 'databases', title: 'Databases', description: 'Keep the orders and sign-ups your custom events count in the same project.' },
          { id: 'domains', title: 'Domains', description: 'Buy or connect the domain, serve the site, and measure it from one Console.' },
        ]}
      />

      <ComparisonTableSection
        id="posthog"
        title="Privacy by default, not by configuration"
        description="Both measure web traffic well. The difference is what happens before you change a single setting, and what sits next to your analytics."
      />

      <ComparisonClosing
        id="posthog"
        cta={{
          title: 'Measure your traffic without tracking people',
          description: 'Create a property, add one snippet, and see visitors, sources, bots, and AI agents in your Appwrite project.',
          secondary: <SecondaryLinkButton href="/products/analytics" label="Explore Appwrite Analytics" />,
        }}
      />
    </>
  )
}

function PostHogSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
