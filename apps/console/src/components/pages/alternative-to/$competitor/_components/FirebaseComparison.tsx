import {
  ArrowRight,
  Check,
  Folder,
  Globe,
  KeyRound,
  MessageSquare,
  ShieldCheck,
  Table2,
  Users,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtToken,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
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
  comparisonHeroCenteredClassName,
} from './ComparisonParts'
import { DatabaseModelsSection } from './DatabaseModels'
import { PlatformBreadthSection } from './PlatformBreadth'

/* -------------------------------------------------------------------------------------------------
 * Hero: migration console
 * -----------------------------------------------------------------------------------------------*/

type MigrationRow = {
  label: string
  detail: string
  icon: LucideIcon
  manual?: boolean
}

const MIGRATION_ROWS: MigrationRow[] = [
  { label: 'Users', detail: '48,213 accounts, password hashes kept', icon: Users },
  { label: 'Firestore', detail: '37 collections to tables', icon: Table2 },
  { label: 'Storage', detail: '12.4 GB across 6 buckets', icon: Folder },
  { label: 'Cloud Functions', detail: 'Rewrite in any of 13+ runtimes', icon: Zap, manual: true },
]

function MigrationConsole() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-3xl pb-10">
      <ArtWindow
        title={<span className="text-[11px] font-medium text-muted-foreground">{t('Migrations')}</span>}
        trailing={
          <span className="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
            <span className="size-1.5 rounded-full bg-emerald-500" aria-hidden />
            {t('Running in the background')}
          </span>
        }
        className="product-hero-rise text-start"
        bodyClassName="p-0"
        style={riseStyle(200)}
      >
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3 sm:px-5">
          <span className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5">
            <CompetitorMonogram name="Firebase" />
            <span className="text-[12px] text-muted-foreground">
              Firebase <span className="font-mono text-foreground/70">acme-prod</span>
            </span>
          </span>
          <ArrowRight className="size-4 text-muted-foreground rtl:-scale-x-100" aria-hidden />
          <span className="flex items-center gap-2 rounded-lg border border-[rgb(var(--tone-rgb)/0.35)] bg-[rgb(var(--tone-rgb)/0.06)] px-2.5 py-1.5">
            <AppwriteMark className="size-3.5" />
            <span className="text-[12px] text-foreground">
              Appwrite <span className="font-mono text-foreground/70">acme</span>
            </span>
          </span>
        </div>
        <ul className="divide-y divide-border">
          {MIGRATION_ROWS.map((row, index) => {
            const Icon = row.icon
            return (
              <li key={row.label} className="flex items-center gap-3 px-4 py-3.5 sm:px-5">
                <span
                  className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-lg',
                    row.manual
                      ? 'border border-dashed border-foreground/20 text-muted-foreground'
                      : 'bg-[rgb(var(--tone-rgb)/0.12)] text-[var(--tone-ink)]',
                  )}
                >
                  <Icon className="size-4" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="truncate text-[13px] font-medium text-foreground">{t(row.label)}</p>
                    <p className="shrink-0 font-mono text-[11px] text-muted-foreground">
                      {row.manual ? t('Manual') : t('Done')}
                    </p>
                  </div>
                  <p className="mt-0.5 truncate text-[12px] text-muted-foreground">{t(row.detail)}</p>
                  {!row.manual ? (
                    <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted/60">
                      <div
                        className="product-hero-fill h-full rounded-full bg-[var(--tone-ink)]"
                        style={fillStyle(100, 500 + index * 420)}
                      />
                    </div>
                  ) : (
                    <div className="mt-2 h-1 rounded-full border-t border-dashed border-foreground/20" />
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      </ArtWindow>

      <ArtChip className="bottom-0 start-4 sm:-start-6" delayMs={1300} floatDelayMs={200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Check} tone="success" />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Migration usage is not billed')}</p>
        </div>
      </ArtChip>
      <ArtChip className="end-4 top-16 hidden sm:block sm:-end-8" delayMs={1600} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={KeyRound} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('No password resets')}</p>
        </div>
      </ArtChip>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Concept map
 * -----------------------------------------------------------------------------------------------*/

const CONCEPT_MAP: { firebase: string; appwrite: string; icon: LucideIcon }[] = [
  { firebase: 'Firebase Authentication', appwrite: 'Appwrite Auth', icon: Users },
  { firebase: 'Cloud Firestore', appwrite: 'TablesDB and DocumentsDB', icon: Table2 },
  { firebase: 'Security Rules', appwrite: 'Permissions', icon: ShieldCheck },
  { firebase: 'Cloud Storage for Firebase', appwrite: 'Appwrite Storage', icon: Folder },
  { firebase: 'Cloud Functions', appwrite: 'Appwrite Functions', icon: Zap },
  { firebase: 'Firebase Cloud Messaging', appwrite: 'Appwrite Messaging', icon: MessageSquare },
  { firebase: 'Hosting and App Hosting', appwrite: 'Appwrite Sites', icon: Globe },
]

function ConceptMap() {
  const t = useT()
  return (
    <ul className="grid gap-x-10 gap-y-2 lg:grid-cols-2">
      {CONCEPT_MAP.map((item, index) => {
        const Icon = item.icon
        return (
          <li
            key={item.firebase}
            className="product-hero-rise group grid grid-cols-[minmax(0,1fr)_2.5rem_minmax(0,1fr)] items-center gap-2"
            style={riseStyle(80 + index * 60)}
          >
            <span className="truncate rounded-lg border border-border bg-muted/25 px-3 py-2.5 text-[12px] text-muted-foreground sm:text-[13px]">
              {item.firebase}
            </span>
            <ArtConnector travel travelDelayMs={index * 300} />
            <span className="flex min-w-0 items-center gap-2 rounded-lg border border-[rgb(var(--tone-rgb)/0.3)] bg-background px-3 py-2.5 text-[12px] font-medium text-foreground transition-colors duration-200 group-hover:bg-[rgb(var(--tone-rgb)/0.08)] sm:text-[13px] dark:bg-card">
              <Icon className="size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
              <span className="truncate">{t(item.appwrite)}</span>
            </span>
          </li>
        )
      })}
    </ul>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Budget cap chart
 * -----------------------------------------------------------------------------------------------*/

const CHART = { width: 600, height: 260, left: 16, right: 584, top: 24, bottom: 228 }
const CHART_MAX_SPEND = 380
const CHART_BUDGET = 150

/** Illustrative cumulative spend for a month with a traffic spike from day 16 to day 22. */
function spendOnDay(day: number) {
  if (day <= 16) return day * 4
  if (day <= 22) return 64 + (day - 16) * 40
  return 304 + (day - 22) * 6
}

function chartX(day: number) {
  return CHART.left + (day / 30) * (CHART.right - CHART.left)
}

function chartY(spend: number) {
  return CHART.bottom - (spend / CHART_MAX_SPEND) * (CHART.bottom - CHART.top)
}

const SPEND_POINTS = Array.from({ length: 31 }, (_, day) => [chartX(day), chartY(spendOnDay(day))] as const)
const SPEND_LINE = SPEND_POINTS.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')
const SPEND_AREA = `${SPEND_LINE} L${CHART.right} ${CHART.bottom} L${CHART.left} ${CHART.bottom} Z`
const CAP_Y = chartY(CHART_BUDGET)
/** Day the spike crosses the budget: 64 + 40 * (d - 16) = 150. */
const CAP_DAY = 16 + (CHART_BUDGET - 64) / 40
const HALF_DAY = 16 + (CHART_BUDGET / 2 - 64) / 40

function BudgetCapChart() {
  const t = useT()
  const [capped, setCapped] = useState(false)

  return (
    <ProductVisualAura>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div
          role="radiogroup"
          aria-label={t('Spending control')}
          className="inline-flex rounded-lg border border-border bg-muted/30 p-1"
        >
          {[
            { value: false, label: 'Budget alerts only' },
            { value: true, label: 'Appwrite budget cap' },
          ].map((option) => (
            <button
              key={option.label}
              type="button"
              role="radio"
              aria-checked={capped === option.value}
              onClick={() => setCapped(option.value)}
              className={cn(
                'rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors duration-200',
                capped === option.value
                  ? 'bg-background text-foreground shadow-sm dark:bg-card'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {t(option.label)}
            </button>
          ))}
        </div>
        <p className="font-mono text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
          {t('Illustrative month with a traffic spike')}
        </p>
      </div>

      <div className="relative mt-5" dir="ltr">
        <svg
          viewBox={`0 0 ${CHART.width} ${CHART.height}`}
          className="h-auto w-full overflow-visible"
          role="img"
          aria-label={t(
            capped
              ? 'Cumulative spend rises during a traffic spike and flattens at the budget cap.'
              : 'Cumulative spend rises during a traffic spike and keeps growing past the budget.',
          )}
        >
          <defs>
            <linearGradient id="alt-firebase-area" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" style={{ stopColor: 'rgb(var(--tone-rgb))', stopOpacity: 0.28 }} />
              <stop offset="100%" style={{ stopColor: 'rgb(var(--tone-rgb))', stopOpacity: 0 }} />
            </linearGradient>
            <linearGradient id="alt-firebase-area-muted" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.12 }} />
              <stop offset="100%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
            </linearGradient>
            <clipPath id="alt-firebase-cap-clip">
              <rect x="0" y={CAP_Y} width={CHART.width} height={CHART.height - CAP_Y} />
            </clipPath>
            <linearGradient id="alt-firebase-grid" gradientUnits="userSpaceOnUse" x1={CHART.left} x2={CHART.right} y1="0" y2="0">
              <stop offset="0%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
              <stop offset="12%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.1 }} />
              <stop offset="88%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0.1 }} />
              <stop offset="100%" style={{ stopColor: 'var(--foreground)', stopOpacity: 0 }} />
            </linearGradient>
          </defs>

          {[0.25, 0.5, 0.75, 1].map((fraction) => (
            <line
              key={fraction}
              x1={CHART.left}
              x2={CHART.right}
              y1={CHART.bottom - fraction * (CHART.bottom - CHART.top)}
              y2={CHART.bottom - fraction * (CHART.bottom - CHART.top)}
              stroke="url(#alt-firebase-grid)"
              strokeWidth="1"
            />
          ))}
          <line
            x1={CHART.left}
            x2={CHART.right}
            y1={CHART.bottom}
            y2={CHART.bottom}
            stroke="url(#alt-firebase-grid)"
            strokeWidth="1.5"
          />
          {[1, 15, 30].map((day, index) => (
            <text
              key={day}
              x={chartX(day)}
              y={CHART.bottom + 20}
              textAnchor={index === 0 ? 'start' : index === 2 ? 'end' : 'middle'}
              className="fill-muted-foreground font-mono text-[10px]"
            >
              {t(`Day ${day}`)}
            </text>
          ))}

          <g className="transition-opacity duration-500" style={{ opacity: capped ? 0.18 : 1 }}>
            <path d={SPEND_AREA} fill="url(#alt-firebase-area-muted)" />
            <path
              d={SPEND_LINE}
              fill="none"
              strokeWidth="2.5"
              strokeLinejoin="round"
              className="stroke-foreground/45"
            />
          </g>

          <g className="transition-opacity duration-500" style={{ opacity: capped ? 1 : 0 }}>
            <g clipPath="url(#alt-firebase-cap-clip)">
              <path d={SPEND_AREA} fill="url(#alt-firebase-area)" />
              <path
                d={SPEND_LINE}
                fill="none"
                strokeWidth="2.5"
                strokeLinejoin="round"
                className="stroke-[var(--tone-ink)]"
              />
            </g>
            <line
              x1={chartX(CAP_DAY)}
              x2={CHART.right}
              y1={CAP_Y}
              y2={CAP_Y}
              strokeWidth="2.5"
              className="stroke-[var(--tone-ink)]"
            />
          </g>
          <line
            x1={CHART.left}
            x2={CHART.right}
            y1={CAP_Y}
            y2={CAP_Y}
            strokeDasharray="5 6"
            strokeWidth="1.25"
            className="stroke-foreground/35"
          />
          <text x={CHART.left + 4} y={CAP_Y - 8} className="fill-muted-foreground font-mono text-[11px]">
            {t('Your budget')}
          </text>

          {!capped ? (
            <>
              {[HALF_DAY, CAP_DAY].map((day, index) => (
                <g key={day}>
                  <circle cx={chartX(day)} cy={chartY(spendOnDay(day))} r="5" className="fill-background stroke-foreground/60" strokeWidth="2" />
                  <text
                    x={index === 0 ? chartX(day) + 10 : chartX(day) - 10}
                    y={chartY(spendOnDay(day)) + (index === 0 ? 4 : -8)}
                    textAnchor={index === 0 ? 'start' : 'end'}
                    className="fill-muted-foreground font-mono text-[10px]"
                  >
                    {index === 0 ? t('Alert at 50%') : t('Alert at 100%')}
                  </text>
                </g>
              ))}
              <text
                x={CHART.right}
                y={chartY(spendOnDay(30)) - 10}
                textAnchor="end"
                className="fill-foreground font-mono text-[11px]"
              >
                {t('Requests keep succeeding. So does the bill.')}
              </text>
            </>
          ) : (
            <>
              <circle cx={chartX(CAP_DAY)} cy={CAP_Y} r="6" className="fill-[var(--tone-ink)]" />
              <circle cx={chartX(CAP_DAY)} cy={CAP_Y} r="11" className="fill-[var(--tone-ink)] opacity-20" />
              <text
                x={chartX(CAP_DAY) + 16}
                y={CAP_Y - 12}
                className="fill-foreground font-mono text-[11px]"
              >
                {t('Cap reached. Scaling stops here.')}
              </text>
            </>
          )}
        </svg>
      </div>

      <div className="mt-6 grid gap-8 sm:grid-cols-2">
        <div
          className={cn(
            'relative border-t pt-4 transition-colors duration-300',
            !capped ? 'border-foreground/40' : 'border-border',
          )}
        >
          <p className="flex items-center gap-2 text-[12px] font-medium text-foreground">
            <CompetitorMonogram name="Firebase" />
            {t('Firebase budgets')}
          </p>
          <p className="mt-1.5 text-[12px] leading-5 text-muted-foreground">
            {t('Budgets send alerts but do not stop usage. Spend caps are in preview for four services and do not cover Firestore, Storage, or Auth.')}
          </p>
        </div>
        <div
          className={cn(
            'relative border-t pt-4 transition-colors duration-300',
            capped ? 'border-[var(--tone-ink)]' : 'border-border',
          )}
        >
          <p className="flex items-center gap-2 text-[12px] font-medium text-foreground">
            <AppwriteMark className="size-4" />
            {t('Appwrite budget cap')}
          </p>
          <p className="mt-1.5 text-[12px] leading-5 text-muted-foreground">
            {t('One organization-wide cap on Pro. Your team gets emails as usage approaches it, and automatic scaling stops when you reach it.')}
          </p>
        </div>
      </div>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Open source section
 * -----------------------------------------------------------------------------------------------*/

const SUNSETS = [
  { date: 'June 22, 2026', label: 'Firebase Studio stops accepting new workspaces' },
  { date: 'March 22, 2027', label: 'Firebase Studio shuts down' },
  { date: 'March 31, 2027', label: 'Firebase Extensions shut down' },
]

function SelfHostTerminal() {
  return (
    <ArtWindow
      title={<CodeFileLabel>~/appwrite</CodeFileLabel>}
      className="product-hero-rise h-full"
      bodyClassName="overflow-x-auto p-5"
      style={riseStyle(260)}
    >
      <pre dir="ltr" className="font-mono text-[12px] leading-6">
        <ArtToken tone="comment"># Your backend, on your servers</ArtToken>{'\n'}
        <ArtToken tone="function">docker</ArtToken> <ArtToken tone="identifier">run</ArtToken>{' '}
        <ArtToken tone="property">-it --rm</ArtToken> <ArtToken tone="operator">\</ArtToken>{'\n'}
        {'  '}<ArtToken tone="property">--publish</ArtToken> <ArtToken tone="number">20080:20080</ArtToken>{' '}
        <ArtToken tone="operator">\</ArtToken>{'\n'}
        {'  '}<ArtToken tone="property">--volume</ArtToken>{' '}
        <ArtToken tone="string">/var/run/docker.sock:/var/run/docker.sock</ArtToken>{' '}
        <ArtToken tone="operator">\</ArtToken>{'\n'}
        {'  '}<ArtToken tone="property">--volume</ArtToken>{' '}
        <ArtToken tone="string">&quot;$(pwd)&quot;/appwrite:/usr/src/code/appwrite:rw</ArtToken>{' '}
        <ArtToken tone="operator">\</ArtToken>{'\n'}
        {'  '}<ArtToken tone="property">--entrypoint</ArtToken>
        <ArtToken tone="operator">=</ArtToken>
        <ArtToken tone="string">&quot;install&quot;</ArtToken> <ArtToken tone="operator">\</ArtToken>{'\n'}
        {'  '}<ArtToken tone="class">appwrite/appwrite:2.3.0</ArtToken>
      </pre>
    </ArtWindow>
  )
}

export function FirebaseComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={comparisonHeroCenteredClassName}>
          <VersusPill name="Firebase" />
          <ComparisonHeroTitle
            className="mx-auto mt-7 max-w-4xl lg:text-[56px] xl:text-[64px]"
            title="Keep the speed."
            accent="Lose the lock-in."
          />
          <p className="mx-auto mt-6 max-w-2xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8">
            {t('Everything you liked about Firebase (auth, databases, storage, functions, messaging, realtime, and hosting) on an open-source platform you can run anywhere, with a bill you can cap.')}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
            <StartBuildingButton />
            <SecondaryLinkButton
              href="/docs/advanced/migrations/firebase"
              label="Migrate from Firebase"
              analytics="alternative-migrate"
            />
          </div>
          <div className="mt-10 min-w-0 sm:mt-14">
            <MigrationConsole />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Translation guide"
          title="Every Firebase product has a home here"
          description="Moving over is mostly a rename. Here is where each piece of your Firebase project lands in Appwrite."
        />
        <div className="mx-auto mt-12 max-w-6xl">
          <ConceptMap />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="firebase"
        title="Documents are just one way to model data"
        description="Firebase is built around Firestore documents. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute."
      />

      <ComparisonSection backdrop={<FirebaseSideGlow />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.35fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Billing"
            title="A bill you can actually cap"
            description="Pay-per-read pricing means one hot listener or a retry loop can multiply your reads overnight. Appwrite Pro starts at $25/mo with generous included usage, and a budget cap that stops scaling at the number you choose."
          />
          <BudgetCapChart />
        </div>
      </ComparisonSection>

      <PlatformBreadthSection id="firebase" title="Everything Firebase does, and then some" />

      <ComparisonSection backdrop={<FirebaseSideGlow side="start" />}>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <div>
            <ComparisonHeading
              eyebrow="Open source"
              title="Your backend should outlive any roadmap"
              description="Firebase is closed source and cloud only. Appwrite is open source and runs anywhere Docker runs, with the same APIs, SDKs, and Console as Appwrite Cloud. If a product changes direction, your backend does not have to."
            />
            <div className="mt-10">
              <p className="flex items-center gap-2 text-[12px] font-medium text-muted-foreground">
                <CompetitorMonogram name="Firebase" />
                {t('Recent Firebase sunsets')}
              </p>
              <ol className="relative mt-5 space-y-5 border-s border-border ps-5">
                {SUNSETS.map((item, index) => (
                  <li key={item.label} className="product-hero-rise relative" style={riseStyle(120 + index * 120)}>
                    <span
                      className="absolute -start-[25px] top-1.5 size-2.5 rounded-full border-2 border-background bg-foreground/40"
                      aria-hidden
                    />
                    <p className="font-mono text-[11px] text-muted-foreground">{t(item.date)}</p>
                    <p className="mt-0.5 text-[14px] text-foreground/85">{t(item.label)}</p>
                  </li>
                ))}
              </ol>
            </div>
          </div>
          <SelfHostTerminal />
        </div>
      </ComparisonSection>

      <ComparisonTableSection
        id="firebase"
        title="Same building blocks, none of the lock-in"
        description="Feature by feature, with very different defaults for openness, access rules, and billing."
      />

      <ComparisonClosing
        id="firebase"
        cta={{
          title: 'Bring your Firebase project home',
          description: 'Import users, Firestore data, and files in the background, then ship on a platform you control.',
          secondary: (
            <SecondaryLinkButton
              href="/docs/advanced/migrations/firebase"
              label="Read the migration guide"
              analytics="alternative-migrate"
            />
          ),
        }}
      />
    </>
  )
}

function FirebaseSideGlow({ side = 'end' }: { side?: 'start' | 'end' }) {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div
        className={cn(
          'product-tone-glow absolute top-1/2 h-[620px] w-[900px] -translate-y-1/2',
          side === 'end' ? '-end-[25%]' : '-start-[25%]',
        )}
      />
    </div>
  )
}
