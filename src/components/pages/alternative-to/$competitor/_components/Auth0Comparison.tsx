import { Fingerprint, KeyRound, Mail, Phone, ShieldCheck } from 'lucide-react'
import { useId, useState } from 'react'
import {
  ArtChip,
  ArtIconBadge,
  ArtToken,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  AppwriteMark,
  CodeFileLabel,
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
import { PlatformBreadthSection } from './PlatformBreadth'

/* -------------------------------------------------------------------------------------------------
 * Hero: sign-in card
 * -----------------------------------------------------------------------------------------------*/

const OAUTH_BUTTONS = [
  { name: 'Google', icon: '/icons/google.svg' },
  { name: 'GitHub', icon: '/icons/github.svg' },
  { name: 'Apple', icon: '/icons/apple.svg' },
] as const

function SignInCard() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-md py-10">
      <div
        className="product-hero-rise product-tone-shadow relative rounded-2xl border border-border bg-background p-6 text-start sm:p-7 dark:bg-card"
        style={riseStyle(120)}
      >
        <span className="product-tone-hairline absolute inset-x-10 top-0 h-px" aria-hidden />
        <div className="flex items-center gap-2">
          <span className="flex size-8 items-center justify-center rounded-lg bg-foreground text-[13px] font-semibold text-background">
            A
          </span>
          <div>
            <p className="text-[15px] font-medium text-foreground">{t('Sign in to Acme')}</p>
            <p className="text-[12px] text-muted-foreground">{t('Welcome back. Pick a method.')}</p>
          </div>
        </div>

        <div className="mt-6 grid grid-cols-3 gap-2">
          {OAUTH_BUTTONS.map((provider, index) => (
            <div
              key={provider.name}
              className="product-hero-rise flex h-10 items-center justify-center rounded-lg border border-border bg-muted/20"
              style={riseStyle(300 + index * 80)}
            >
              <ProductFeaturePublicIcon src={provider.icon} className="size-4" />
              <span className="sr-only">{provider.name}</span>
            </div>
          ))}
        </div>

        <div className="my-5 flex items-center gap-3 text-[11px] text-muted-foreground">
          <span className="h-px flex-1 bg-border" />
          {t('or')}
          <span className="h-px flex-1 bg-border" />
        </div>

        <div className="space-y-2.5">
          <div className="flex h-10 items-center gap-2 rounded-lg border border-border bg-background px-3 dark:bg-muted/20">
            <Mail className="size-4 text-muted-foreground" aria-hidden />
            <span dir="ltr" className="text-[13px] text-foreground/80">
              paige@acme.io
            </span>
          </div>
          <div className="flex h-10 items-center justify-center rounded-lg bg-[var(--brand-cta)] text-[13px] font-medium text-white">
            {t('Email me a magic link')}
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div className="flex h-9 items-center justify-center gap-1.5 rounded-lg border border-border text-[12px] text-foreground/80">
              <KeyRound className="size-3.5" aria-hidden />
              {t('Email code')}
            </div>
            <div className="flex h-9 items-center justify-center gap-1.5 rounded-lg border border-border text-[12px] text-foreground/80">
              <Phone className="size-3.5" aria-hidden />
              {t('Phone')}
            </div>
          </div>
        </div>

        <p className="mt-5 text-center text-[11px] text-muted-foreground">{t('Continue as guest')}</p>
      </div>

      <ArtChip className="end-0 top-2 sm:-end-14" delayMs={900} floatDelayMs={200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Fingerprint} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('MFA with TOTP, email, or SMS')}</p>
        </div>
      </ArtChip>
      <ArtChip className="bottom-2 start-0 sm:-start-14" delayMs={1200} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ShieldCheck} tone="success" />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Breached password check on')}</p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Price gap: Appwrite Pro vs Auth0 B2C Essentials
 * -----------------------------------------------------------------------------------------------*/

/** Auth0 B2C Essentials list price: $35/mo for 500 MAU, about $0.07 per MAU up to its 50,000 MAU self-serve limit. */
const AUTH0_ESSENTIALS_PER_MAU = 0.07
const MAU_STEPS = [500, 1_000, 2_500, 5_000, 10_000, 15_000, 20_000, 30_000, 40_000, 50_000] as const
/** Appwrite Pro includes 200,000 MAU, so every step on this slider is covered by the base price. */
const APPWRITE_PRO_PRICE = 25

function formatMau(value: number) {
  return value >= 1000 ? `${value / 1000}K` : String(value)
}

/** Open price comparison: slider, two giant readouts, and the gap between them. */
function PriceGap() {
  const t = useT()
  const sliderId = useId()
  const [stepIndex, setStepIndex] = useState(4)
  const mau = MAU_STEPS[stepIndex] ?? 10_000
  const auth0Cost = Math.round(mau * AUTH0_ESSENTIALS_PER_MAU)
  const multiple = Math.max(1, Math.round((auth0Cost / APPWRITE_PRO_PRICE) * 10) / 10)

  return (
    <ProductVisualAura>
      <div className="flex items-end justify-between gap-4">
        <label htmlFor={sliderId} className="text-[13px] font-medium text-foreground">
          {t('Monthly active users')}
        </label>
        <span className="font-aeonik-pro text-[36px] leading-none tabular-nums text-foreground" dir="ltr">
          {formatMau(mau)}
        </span>
      </div>
      <input
        id={sliderId}
        type="range"
        min={0}
        max={MAU_STEPS.length - 1}
        step={1}
        value={stepIndex}
        onChange={(event) => setStepIndex(Number(event.target.value))}
        className="mt-4 w-full cursor-pointer accent-[var(--brand-cta)]"
      />

      <div className="mt-10 grid gap-10 sm:grid-cols-2">
        <div className="border-t border-border pt-5">
          <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <CompetitorMonogram name="Auth0" />
            {t('Auth0 B2C Essentials')}
          </p>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground/60 tabular-nums" dir="ltr">
            ${auth0Cost.toLocaleString('en-US')}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-muted/50">
            <div className="h-full rounded-full bg-foreground/35 transition-[width] duration-500 ease-out" style={fillStyle(100, 0)} />
          </div>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">{t('About $70 per 1,000 users, from the first user.')}</p>
        </div>
        <div className="border-t border-[var(--tone-ink)] pt-5">
          <p className="flex items-center gap-2 text-[13px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite Pro')}
          </p>
          <p className="mt-3 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground tabular-nums" dir="ltr">
            ${APPWRITE_PRO_PRICE}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-muted/50">
            <div
              className="h-full rounded-full bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb)/0.7)] transition-[width] duration-500 ease-out"
              style={fillStyle((APPWRITE_PRO_PRICE / auth0Cost) * 100, 0)}
            />
          </div>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">
            {t('200,000 users included, then $3 per 1,000.')}
          </p>
        </div>
      </div>

      {auth0Cost > APPWRITE_PRO_PRICE ? (
        <div className="relative mt-10 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-border pt-5">
          <span className="absolute start-0 top-0 h-px w-16 bg-[var(--tone-ink)]" aria-hidden />
          <span className="font-aeonik-pro text-[56px] leading-none tracking-tight text-[var(--tone-ink)] tabular-nums" dir="ltr">
            {multiple}x
          </span>
          <span className="text-[14px] text-foreground">{t('more for the same users on Auth0')}</span>
        </div>
      ) : null}

      <p className="mt-5 text-[11px] leading-4 text-muted-foreground">
        {t('Auth0 list price for B2C Essentials, which starts at $35/mo for 500 users. Auth0 Free covers 25,000 users with a limited feature set, and pricing past 50,000 users is a custom quote. Appwrite Free covers 75,000 users, and Appwrite Pro includes 200,000.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Security policies
 * -----------------------------------------------------------------------------------------------*/

const SECURITY_POLICIES: { label: string; detail: string }[] = [
  { label: 'Multi-factor authentication', detail: 'TOTP, email, SMS, and recovery codes' },
  { label: 'Breached password check', detail: 'Have I Been Pwned, using k-anonymity' },
  { label: 'Password dictionary', detail: 'Blocks the 10,000 most common passwords' },
  { label: 'Password history', detail: 'Prevents reusing up to 20 past passwords' },
  { label: 'Personal data check', detail: 'Blocks names, emails, and phone numbers in passwords' },
  { label: 'Email policies', detail: 'Block disposable, aliased, or free-provider addresses' },
  { label: 'Session limits', detail: 'Cap active sessions per user' },
  { label: 'Session alerts', detail: 'Email users when a new session starts' },
]

/** Open list of switches: no window chrome, just rows and hairlines. */
function SecurityPolicies() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="flex items-center justify-between gap-3 pb-3">
        <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Auth security')}</p>
        <span className="font-mono text-[10px] text-[var(--tone-ink)]">Argon2</span>
      </div>
      <ul className="grid gap-x-10 border-t border-border sm:grid-cols-2">
        {SECURITY_POLICIES.map((policy, index) => (
          <li key={policy.label} className="flex items-center gap-4 border-b border-border py-4">
            <div className="min-w-0 flex-1">
              <p className="text-[13px] font-medium text-foreground">{t(policy.label)}</p>
              <p className="mt-0.5 text-[12px] text-muted-foreground">{t(policy.detail)}</p>
            </div>
            <span
              className="product-hero-rise relative flex h-5 w-9 shrink-0 items-center rounded-full bg-[var(--tone-ink)] p-0.5 shadow-[0_0_12px_rgb(var(--tone-rgb)/0.5)]"
              style={riseStyle(200 + index * 70)}
              aria-hidden
            >
              <span className="ms-auto size-4 rounded-full bg-white shadow-sm" />
            </span>
          </li>
        ))}
      </ul>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Migration
 * -----------------------------------------------------------------------------------------------*/

function ImportSnippet() {
  return (
    <ArtWindow
      title={<CodeFileLabel>import-users.ts</CodeFileLabel>}
      className="product-hero-rise h-full"
      bodyClassName="overflow-x-auto p-5"
      style={riseStyle(200)}
    >
      <pre dir="ltr" className="font-mono text-[12px] leading-6">
        <ArtToken tone="comment">{'// Users exported from Auth0 keep their bcrypt hashes'}</ArtToken>{'\n'}
        <ArtToken tone="keyword">for</ArtToken> <ArtToken tone="punctuation">(</ArtToken>
        <ArtToken tone="keyword">const</ArtToken> <ArtToken tone="identifier">user</ArtToken>{' '}
        <ArtToken tone="keyword">of</ArtToken> <ArtToken tone="identifier">auth0Export</ArtToken>
        <ArtToken tone="punctuation">) {'{'}</ArtToken>{'\n'}
        {'  '}<ArtToken tone="keyword">await</ArtToken> <ArtToken tone="identifier">users</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="function">createBcryptUser</ArtToken>
        <ArtToken tone="punctuation">{'({'}</ArtToken>{'\n'}
        {'    '}<ArtToken tone="property">userId</ArtToken><ArtToken tone="punctuation">: </ArtToken>
        <ArtToken tone="class">ID</ArtToken><ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="function">unique</ArtToken><ArtToken tone="punctuation">(),</ArtToken>{'\n'}
        {'    '}<ArtToken tone="property">email</ArtToken><ArtToken tone="punctuation">: </ArtToken>
        <ArtToken tone="identifier">user</ArtToken><ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="property">email</ArtToken><ArtToken tone="punctuation">,</ArtToken>{'\n'}
        {'    '}<ArtToken tone="property">password</ArtToken><ArtToken tone="punctuation">: </ArtToken>
        <ArtToken tone="identifier">user</ArtToken><ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="property">passwordHash</ArtToken><ArtToken tone="punctuation">,</ArtToken>{' '}
        <ArtToken tone="comment">{'// $2b$10$...'}</ArtToken>{'\n'}
        {'  '}<ArtToken tone="punctuation">{'})'}</ArtToken>{'\n'}
        <ArtToken tone="punctuation">{'}'}</ArtToken>{'\n\n'}
        <ArtToken tone="comment">{'// First sign-in upgrades each hash to Argon2'}</ArtToken>
      </pre>
    </ArtWindow>
  )
}

const MIGRATION_STEPS = [
  { title: 'Export', description: 'Request a password hash export from Auth0. Hashes use bcrypt.' },
  { title: 'Import', description: 'Create users with the Users API and their existing bcrypt hashes.' },
  { title: 'Upgrade', description: 'Each password is rehashed with Argon2 on the next successful sign-in.' },
  { title: 'Cut over', description: 'Optionally keep Auth0 as an OAuth provider in Appwrite during the switch.' },
]

export function Auth0Comparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Auth0" />
            <ComparisonHeroTitle className="mt-7" title="Authentication without" accent="the growth penalty." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 lg:mx-0">
              {t('Auth0 charges by the user and stops at sign-in. Appwrite Auth is open source, includes 200,000 monthly active users on Pro, and plugs straight into the database, storage, functions, and hosting in the same project.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 lg:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/auth" label="Explore Appwrite Auth" />
            </div>
            <dl className="mx-auto mt-10 grid max-w-xl grid-cols-1 gap-8 text-start sm:mt-12 sm:grid-cols-3 sm:gap-6 lg:mx-0">
              {[
                { value: '3x', label: 'Free monthly active users' },
                { value: '40+', label: 'OAuth providers' },
                { value: '$3', label: 'Per 1,000 extra users, vs about $70 on Auth0' },
              ].map((stat) => (
                <div key={stat.label} className="relative flex flex-col-reverse gap-2 border-t border-border pt-4">
                  <span className="absolute start-0 top-0 h-px w-8 bg-[var(--tone-ink)]" aria-hidden />
                  <dt className="text-[11px] leading-4 text-muted-foreground">{t(stat.label)}</dt>
                  <dd className="font-aeonik-pro text-[28px] leading-none text-foreground">{stat.value}</dd>
                </div>
              ))}
            </dl>
          </div>
          <div className="min-w-0">
            <SignInCard />
          </div>
        </div>
      </section>

      <ComparisonSection backdrop={<Auth0SideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.25fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Grow your users, not your auth bill"
            description="Auth0 paid plans start at $35/mo for just 500 users and climb with every login. Appwrite Pro starts at $25/mo with 200,000 users included. Slide to see the gap."
          />
          <PriceGap />
        </div>
      </ComparisonSection>

      <IntegrationHubSection
        focus="auth"
        focusLabel="Appwrite Auth"
        title="Identity that powers the rest of your backend"
        description="Auth0 stops at identity. In Appwrite, the same users, teams, roles, and labels decide who can read a row, download a file, run a function, or receive a realtime event."
        items={[
          { id: 'databases', title: 'Databases', description: 'Row and table permissions for users, teams, and roles.' },
          { id: 'storage', title: 'Storage', description: 'File access follows the same users and teams.' },
          { id: 'functions', title: 'Functions', description: 'Run code on sign-ups, sessions, and team changes.' },
          { id: 'realtime', title: 'Realtime', description: 'Events reach only the users allowed to see them.' },
          { id: 'messaging', title: 'Messaging', description: 'Users and teams double as email, SMS, and push targets.' },
          { id: 'sites', title: 'Sites', description: 'Server-side sign-in with secure, HTTP-only session cookies.' },
        ]}
      />

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Security"
            title="Hardened by default, on every plan"
            description="Passwords are hashed with Argon2, and the policies that stop account takeovers are built in. Turn them on from the Console. No plan upgrade required."
          />
          <SecurityPolicies />
        </div>
      </ComparisonSection>

      <PlatformBreadthSection
        id="auth0"
        title="Auth0 sells sign-in. Appwrite ships the whole platform behind it."
        description="Appwrite Auth is one of ten products in the same open-source platform, so users, data, files, functions, and hosting share one Console and one bill."
      />

      <OpenSourceSection
        id="auth0"
        title="Own your identity layer"
        description="Auth0 is closed source, and even Private Cloud runs on Okta's terms. Appwrite Auth is open source, so it runs on Appwrite Cloud or on your own servers with the same APIs."
      />

      <ComparisonTableSection
        id="auth0"
        title="The sign-in your users expect, at a fraction of the price"
        description="Sign-in methods line up closely. The differences are price, openness, and everything identity connects to."
      />

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center lg:gap-14">
          <div>
            <ComparisonHeading eyebrow="Migration" title="Leave without a single password reset" size="md" />
            <ol className="mt-8 grid gap-x-8 gap-y-7 sm:grid-cols-2">
              {MIGRATION_STEPS.map((step, index) => (
                <li
                  key={step.title}
                  className="product-hero-rise relative border-t border-foreground/15 pt-4"
                  style={riseStyle(100 + index * 100)}
                >
                  <span className="absolute start-0 top-0 h-px w-8 bg-[var(--tone-ink)]" aria-hidden />
                  <span className="font-mono text-[11px] tabular-nums text-[var(--tone-ink)]">
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  <span className="mt-2 block text-[15px] font-medium text-foreground">{t(step.title)}</span>
                  <span className="mt-1 block text-[13px] leading-5 text-muted-foreground">{t(step.description)}</span>
                </li>
              ))}
            </ol>
          </div>
          <ImportSnippet />
        </div>
      </ComparisonSection>

      <ComparisonClosing
        id="auth0"
        cta={{
          title: 'Ship sign-in your users will trust',
          description: 'Add authentication in minutes with 75,000 monthly active users free and every security policy included.',
          secondary: <SecondaryLinkButton href="/docs/products/auth/quick-start" label="Auth quick start" />,
        }}
      />
    </>
  )
}

function Auth0SideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
