import { Check, Database, Fingerprint, Folder, Lock, Server, Table, UsersRound, Webhook } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useId, useState } from 'react'
import { ArtChip, ArtConnector, ArtIconBadge, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
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
import { OpenSourceSection } from './OpenSource'
import { PlatformBreadthSection } from './PlatformBreadth'

/* -------------------------------------------------------------------------------------------------
 * Hero: where your users live
 * -----------------------------------------------------------------------------------------------*/

const CLERK_SYNC: { label: string; detail: string; icon: LucideIcon }[] = [
  { label: 'Clerk', detail: 'users live here', icon: UsersRound },
  { label: 'Webhook', detail: 'user.created', icon: Webhook },
  { label: 'Your API', detail: 'verify and retry', icon: Server },
  { label: 'Your database', detail: 'a copy of each user', icon: Database },
]

const APPWRITE_PROJECT: { label: string; icon: LucideIcon }[] = [
  { label: 'Users', icon: UsersRound },
  { label: 'Rows', icon: Table },
  { label: 'Files', icon: Folder },
]

function UserHomeVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-12 pt-10 text-start">
      <p className="product-hero-rise flex items-center gap-2 text-[12px] text-muted-foreground" style={riseStyle(80)}>
        <CompetitorMonogram name="Clerk" />
        {t('With Clerk, users and data live apart')}
      </p>
      <ol className="relative mt-5 grid grid-cols-4 gap-2" dir="ltr">
        {CLERK_SYNC.map((step, index) => {
          const Icon = step.icon
          return (
            <li
              key={step.label}
              className="product-hero-rise relative flex min-w-0 flex-col items-center text-center"
              style={riseStyle(160 + index * 120)}
            >
              {index < CLERK_SYNC.length - 1 ? (
                <ArtConnector
                  travel
                  travelDelayMs={700 + index * 450}
                  className="absolute start-[calc(50%+1.5rem)] top-5 w-[calc(100%-2.5rem)]"
                />
              ) : null}
              <span className="flex size-10 items-center justify-center rounded-xl border border-dashed border-foreground/25 bg-background text-muted-foreground dark:bg-card">
                <Icon className="size-[18px]" strokeWidth={1.75} aria-hidden />
              </span>
              <span className="mt-2 truncate text-[11px] font-medium text-foreground/75">{t(step.label)}</span>
              <span className="truncate font-mono text-[9px] text-muted-foreground">{t(step.detail)}</span>
            </li>
          )
        })}
      </ol>
      <p className="product-hero-rise mt-4 text-[11px] leading-4 text-muted-foreground" style={riseStyle(700)}>
        {t('Sync code you write and maintain: signatures, retries, and backfills.')}
      </p>

      <div className="my-7 border-t border-dashed border-foreground/15" aria-hidden />

      <p className="product-hero-rise flex items-center gap-2 text-[12px] font-medium text-foreground" style={riseStyle(820)}>
        <AppwriteMark className="size-4" />
        {t('With Appwrite, they share one project')}
      </p>
      <div
        className="product-hero-rise relative isolate mt-5 rounded-2xl border border-[rgb(var(--tone-rgb)/0.4)] px-4 py-5 shadow-[0_18px_48px_-24px_rgb(var(--tone-rgb)/0.8)] sm:px-6"
        style={riseStyle(940)}
      >
        <span
          className="pointer-events-none absolute -inset-6 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.18),transparent_70%)]"
          aria-hidden
        />
        <ul className="relative grid grid-cols-3 gap-2" dir="ltr">
          {APPWRITE_PROJECT.map((item, index) => {
            const Icon = item.icon
            return (
              <li key={item.label} className="relative flex flex-col items-center text-center">
                {index < APPWRITE_PROJECT.length - 1 ? (
                  <span
                    className="absolute start-[calc(50%+1.5rem)] top-5 h-px w-[calc(100%-2.5rem)] bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))]"
                    aria-hidden
                  />
                ) : null}
                <span className="flex size-10 items-center justify-center rounded-xl border border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] dark:bg-card">
                  <Icon className="size-[18px]" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="mt-2 text-[11px] font-medium text-foreground">{t(item.label)}</span>
              </li>
            )
          })}
        </ul>
        <p dir="ltr" className="mt-5 truncate text-center font-mono text-[11px] text-foreground/80">
          <span className="text-[var(--tone-ink)]">Permission</span>.read(Role.user(<span className="text-emerald-600 dark:text-emerald-400">&apos;u_8f2a&apos;</span>))
        </p>
      </div>

      <ArtChip className="bottom-0 end-0 sm:-end-6" delayMs={1300} floatDelayMs={400}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Fingerprint} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('MFA on every plan')}</p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Growth curve: Clerk Pro vs Appwrite Pro
 * -----------------------------------------------------------------------------------------------*/

/** Clerk Pro list price: $25/mo with 50,000 retained users, then tiered per-user pricing. */
const CLERK_TIERS: [cap: number, rate: number][] = [
  [50_000, 0],
  [100_000, 0.02],
  [1_000_000, 0.018],
  [10_000_000, 0.015],
]
const PRO_PRICE = 25
const APPWRITE_INCLUDED_USERS = 200_000
const APPWRITE_PER_THOUSAND = 3
const USER_STEPS = [50_000, 75_000, 100_000, 150_000, 200_000, 250_000, 300_000, 400_000, 500_000, 750_000, 1_000_000] as const
const MAX_USERS = 1_000_000

function clerkMonthly(users: number) {
  let cost = PRO_PRICE
  let previous = 0
  for (const [cap, rate] of CLERK_TIERS) {
    cost += Math.max(0, Math.min(users, cap) - previous) * rate
    previous = cap
    if (users <= cap) break
  }
  return Math.round(cost)
}

function appwriteMonthly(users: number) {
  return PRO_PRICE + Math.ceil(Math.max(0, users - APPWRITE_INCLUDED_USERS) / 1000) * APPWRITE_PER_THOUSAND
}

function formatUsers(value: number) {
  return value >= 1_000_000 ? `${value / 1_000_000}M` : `${value / 1000}K`
}

const CURVE = { width: 600, height: 240, left: 8, right: 592, top: 16, bottom: 216 }
const CURVE_MAX = clerkMonthly(MAX_USERS)

function curveX(users: number) {
  return CURVE.left + (users / MAX_USERS) * (CURVE.right - CURVE.left)
}

function curveY(cost: number) {
  return CURVE.bottom - (cost / CURVE_MAX) * (CURVE.bottom - CURVE.top)
}

function curvePath(points: number[], cost: (users: number) => number) {
  return points.map((users, index) => `${index === 0 ? 'M' : 'L'}${curveX(users).toFixed(1)} ${curveY(cost(users)).toFixed(1)}`).join(' ')
}

/** Breakpoints where each price line changes slope, so straight segments are exact. */
const CLERK_POINTS = [0, 50_000, 100_000, MAX_USERS]
const APPWRITE_POINTS = [0, APPWRITE_INCLUDED_USERS, MAX_USERS]
const CLERK_LINE = curvePath(CLERK_POINTS, clerkMonthly)
const APPWRITE_LINE = curvePath(APPWRITE_POINTS, appwriteMonthly)
const CLERK_AREA = `${CLERK_LINE} L${CURVE.right} ${CURVE.bottom} L${CURVE.left} ${CURVE.bottom} Z`

function GrowthCurve() {
  const t = useT()
  const sliderId = useId()
  const [stepIndex, setStepIndex] = useState(5)
  const users = USER_STEPS[stepIndex] ?? 250_000
  const clerk = clerkMonthly(users)
  const appwrite = appwriteMonthly(users)
  const markerX = curveX(users)

  return (
    <ProductVisualAura>
      <div className="flex items-end justify-between gap-4">
        <label htmlFor={sliderId} className="text-[13px] font-medium text-foreground">
          {t('Monthly users')}
        </label>
        <span className="font-aeonik-pro text-[36px] leading-none tabular-nums text-foreground" dir="ltr">
          {formatUsers(users)}
        </span>
      </div>
      <input
        id={sliderId}
        type="range"
        min={0}
        max={USER_STEPS.length - 1}
        step={1}
        value={stepIndex}
        onChange={(event) => setStepIndex(Number(event.target.value))}
        className="mt-4 w-full cursor-pointer accent-[var(--brand-cta)]"
      />

      <div className="relative mt-6" dir="ltr">
        <svg
          viewBox={`0 0 ${CURVE.width} ${CURVE.height}`}
          className="h-auto w-full overflow-visible"
          role="img"
          aria-label={t('Monthly cost as users grow, Clerk Pro compared with Appwrite Pro.')}
        >
          <defs>
            <linearGradient id="alt-clerk-area" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.12 }} />
              <stop offset="100%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
            </linearGradient>
            <linearGradient id="alt-clerk-grid" gradientUnits="userSpaceOnUse" x1={CURVE.left} x2={CURVE.right} y1="0" y2="0">
              <stop offset="0%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
              <stop offset="12%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.1 }} />
              <stop offset="88%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.1 }} />
              <stop offset="100%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
            </linearGradient>
          </defs>
          {[0.25, 0.5, 0.75, 1].map((fraction) => (
            <line
              key={fraction}
              x1={CURVE.left}
              x2={CURVE.right}
              y1={CURVE.bottom - fraction * (CURVE.bottom - CURVE.top)}
              y2={CURVE.bottom - fraction * (CURVE.bottom - CURVE.top)}
              stroke="url(#alt-clerk-grid)"
              strokeWidth="1"
            />
          ))}
          <path d={CLERK_AREA} fill="url(#alt-clerk-area)" />
          <path d={CLERK_LINE} fill="none" strokeWidth="2.5" strokeLinejoin="round" className="stroke-foreground/45" />
          <path
            d={APPWRITE_LINE}
            fill="none"
            strokeWidth="3"
            strokeLinejoin="round"
            className="stroke-[var(--tone-ink)]"
            style={{ filter: 'drop-shadow(0 0 6px rgb(var(--tone-rgb) / 0.7))' }}
          />
          <line
            x1={markerX}
            x2={markerX}
            y1={CURVE.top}
            y2={CURVE.bottom}
            strokeDasharray="4 5"
            className="stroke-foreground/30 transition-all duration-300"
          />
          <circle cx={markerX} cy={curveY(clerk)} r="5" className="fill-background stroke-foreground/60 transition-all duration-300" strokeWidth="2" />
          <circle cx={markerX} cy={curveY(appwrite)} r="6" className="fill-[var(--tone-ink)] transition-all duration-300" />
          {[0, 250_000, 500_000, 750_000, MAX_USERS].map((value, index, list) => (
            <text
              key={value}
              x={curveX(value)}
              y={CURVE.bottom + 18}
              textAnchor={index === 0 ? 'start' : index === list.length - 1 ? 'end' : 'middle'}
              className="fill-muted-foreground font-mono text-[10px]"
            >
              {value === 0 ? '0' : formatUsers(value)}
            </text>
          ))}
        </svg>
      </div>

      <div className="mt-8 grid gap-8 sm:grid-cols-2">
        <div className="border-t border-foreground/15 pt-4">
          <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <CompetitorMonogram name="Clerk" />
            {t('Clerk Pro')}
          </p>
          <p className="mt-3 font-aeonik-pro text-[44px] leading-none tracking-tight text-foreground/60 tabular-nums" dir="ltr">
            ${clerk.toLocaleString('en-US')}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">{t('50,000 users included, then about $20 per 1,000.')}</p>
        </div>
        <div className="border-t border-[var(--tone-ink)] pt-4">
          <p className="flex items-center gap-2 text-[13px] text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite Pro')}
          </p>
          <p className="mt-3 font-aeonik-pro text-[44px] leading-none tracking-tight text-foreground tabular-nums" dir="ltr">
            ${appwrite.toLocaleString('en-US')}
            <span className="text-[14px] text-muted-foreground">/mo</span>
          </p>
          <p className="mt-2 text-[12px] leading-5 text-muted-foreground">{t('200,000 users included, then $3 per 1,000.')}</p>
        </div>
      </div>

      <p className="mt-6 text-[11px] leading-4 text-muted-foreground">
        {t('Clerk list prices for Pro, which counts monthly retained users. Appwrite counts monthly active users, so treat this as a guide. Both plans start at $25/mo.')}
      </p>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Free plan, unlocked
 * -----------------------------------------------------------------------------------------------*/

const FREE_PLAN_ROWS: { label: string; appwrite: string; clerk: string; locked: boolean }[] = [
  { label: 'Monthly users', appwrite: '75,000', clerk: '50,000 per app', locked: false },
  { label: 'Multi-factor authentication', appwrite: 'Included', clerk: 'Pro only', locked: true },
  { label: 'Social sign-in providers', appwrite: '40+', clerk: 'Up to 3', locked: true },
  { label: 'Session length', appwrite: 'Configurable', clerk: 'Fixed to 7 days', locked: true },
  { label: 'Ban or block users', appwrite: 'Included', clerk: 'Pro only', locked: true },
  { label: 'Vendor badge on your sign-in', appwrite: 'None', clerk: 'Secured by Clerk', locked: true },
]

function FreePlanLedger() {
  const t = useT()
  return (
    <div>
      <div className="hidden gap-6 pb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground sm:grid sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)]" aria-hidden>
        <span />
        <span className="flex items-center gap-2 text-foreground">
          <AppwriteMark className="size-3.5" />
          {t('Appwrite Free')}
        </span>
        <span className="flex items-center gap-2">
          <CompetitorMonogram name="Clerk" className="size-4 text-[9px]" />
          {t('Clerk Hobby')}
        </span>
      </div>
      <dl className="divide-y divide-foreground/10 border-y border-foreground/15">
        {FREE_PLAN_ROWS.map((row, index) => (
          <div
            key={row.label}
            className="product-hero-rise grid gap-2 py-4 sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:gap-6"
            style={riseStyle(120 + index * 80)}
          >
            <dt className="text-[13px] font-medium text-foreground">{t(row.label)}</dt>
            <dd className="flex items-center gap-2 text-[13px] text-foreground">
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
                <Check className="size-3" strokeWidth={3} aria-hidden />
              </span>
              <span className="sm:hidden">
                <AppwriteMark className="me-1 inline size-3.5 align-[-2px]" />
              </span>
              {t(row.appwrite)}
            </dd>
            <dd className={cn('flex items-center gap-2 text-[13px]', row.locked ? 'text-muted-foreground' : 'text-foreground/70')}>
              <span className="flex size-5 shrink-0 items-center justify-center text-muted-foreground/70">
                {row.locked ? <Lock className="size-3.5" aria-hidden /> : <Check className="size-3.5" aria-hidden />}
              </span>
              <span className="sm:hidden">
                <CompetitorMonogram name="Clerk" className="me-1 inline-flex size-4 text-[9px] align-[-3px]" />
              </span>
              {t(row.clerk)}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

export function ClerkComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Clerk" />
            <ComparisonHeroTitle className="mt-7" title="Your users," accent="right next to your data." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Clerk keeps your users in its own cloud, so most apps copy them into a database with webhooks. Appwrite Auth stores users in the same open-source project as your databases, files, and functions, with MFA on every plan and 200,000 users on Pro.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/products/auth" label="Explore Appwrite Auth" />
            </div>
          </div>
          <div className="min-w-0">
            <UserHomeVisual />
          </div>
        </div>
      </section>

      <ComparisonSection backdrop={<ClerkSideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.25fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Growth should not be a pricing event"
            description="Both Pro plans start at $25/mo. Clerk includes 50,000 users and then charges about $20 for every 1,000 more. Appwrite includes 200,000 and then charges $3. Slide to see where the lines go."
          />
          <GrowthCurve />
        </div>
      </ComparisonSection>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Free plan"
            title="The security basics, unlocked from day one"
            description="Clerk keeps MFA, extra social providers, custom sessions, and user bans behind Pro. On Appwrite, they are part of the Free plan."
          />
          <FreePlanLedger />
        </div>
      </ComparisonSection>

      <PlatformBreadthSection
        id="clerk"
        title="Clerk is sign-in. Appwrite is the whole backend behind it."
        description="Appwrite Auth is one of ten products in the same open-source platform, so your users, data, files, functions, and hosting share one Console and one bill."
      />

      <OpenSourceSection
        id="clerk"
        title="Keep your user table yours"
        description="Clerk is closed source and cloud only. Appwrite Auth is open source, so your users can live on Appwrite Cloud or on your own servers with the same APIs."
      />

      <ComparisonTableSection
        id="clerk"
        title="The sign-in you expect, without the per-user surprise"
        description="Sign-in methods line up closely. The gaps are price at scale, what the free plan unlocks, and where your users live."
      />

      <ComparisonClosing
        id="clerk"
        cta={{
          title: 'Put your users next to your data',
          description: 'Add sign-in in minutes with 75,000 monthly active users free and MFA on every plan.',
          secondary: <SecondaryLinkButton href="/docs/products/auth/quick-start" label="Auth quick start" />,
        }}
      />
    </>
  )
}

function ClerkSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
