import { ListChecks, Plug, Rocket } from 'lucide-react'
import { ArtToken, ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
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
  comparisonHeroCopyClassName,
  comparisonHeroGridClassName,
  fillStyle,
  SecondaryLinkButton,
  StartBuildingButton,
  VersusPill,
} from './ComparisonParts'
import { DatabaseModelsSection } from './DatabaseModels'
import { OpenSourceSection } from './OpenSource'
import { RuntimeWall } from './RuntimeWall'
import { SupabaseHeroArt } from './SupabaseHeroArt'

function PermissionsCompare() {
  const t = useT()
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="product-hero-rise flex flex-col" style={riseStyle(80)}>
        <ArtWindow
          title={<CodeFileLabel>policies.sql</CodeFileLabel>}
          trailing={<span className="text-[11px] text-muted-foreground">Supabase</span>}
          className="flex-1 shadow-none"
          bodyClassName="overflow-x-auto p-5"
        >
          <pre dir="ltr" className="font-mono text-[12px] leading-6">
            <ArtToken tone="sqlKeyword">alter table</ArtToken> <ArtToken tone="sqlTable">documents</ArtToken>{'\n'}
            {'  '}<ArtToken tone="sqlKeyword">enable row level security</ArtToken>
            <ArtToken tone="punctuation">;</ArtToken>{'\n\n'}
            <ArtToken tone="sqlKeyword">create policy</ArtToken>{' '}
            <ArtToken tone="string">&quot;Team members can read&quot;</ArtToken>{'\n'}
            {'  '}<ArtToken tone="sqlKeyword">on</ArtToken> <ArtToken tone="sqlTable">documents</ArtToken>{' '}
            <ArtToken tone="sqlKeyword">for select</ArtToken>{'\n'}
            {'  '}<ArtToken tone="sqlKeyword">using</ArtToken> <ArtToken tone="punctuation">(</ArtToken>
            <ArtToken tone="sqlFunction">exists</ArtToken> <ArtToken tone="punctuation">(</ArtToken>{'\n'}
            {'    '}<ArtToken tone="sqlKeyword">select</ArtToken> <ArtToken tone="number">1</ArtToken>{' '}
            <ArtToken tone="sqlKeyword">from</ArtToken> <ArtToken tone="sqlTable">memberships</ArtToken>{' '}
            <ArtToken tone="sqlAlias">m</ArtToken>{'\n'}
            {'    '}<ArtToken tone="sqlKeyword">where</ArtToken> <ArtToken tone="sqlAlias">m</ArtToken>
            <ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="identifier">team_id</ArtToken> <ArtToken tone="operator">=</ArtToken>{' '}
            <ArtToken tone="sqlTable">documents</ArtToken>
            <ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="identifier">team_id</ArtToken>{'\n'}
            {'      '}<ArtToken tone="sqlKeyword">and</ArtToken> <ArtToken tone="sqlAlias">m</ArtToken>
            <ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="identifier">user_id</ArtToken> <ArtToken tone="operator">=</ArtToken>{' '}
            <ArtToken tone="sqlFunction">auth.uid</ArtToken>
            <ArtToken tone="punctuation">()</ArtToken>{'\n'}
            {'  '}<ArtToken tone="punctuation">));</ArtToken>
          </pre>
        </ArtWindow>
        <p className="mt-4 text-[13px] leading-6 text-muted-foreground">
          {t('Row Level Security is powerful, but access lives in SQL policies. One wrong join and a policy that looks right grants too much, especially when an AI agent writes it.')}
        </p>
      </div>

      <div className="product-hero-rise flex flex-col" style={riseStyle(220)}>
        <ArtWindow
          title={<CodeFileLabel>documents.ts</CodeFileLabel>}
          trailing={
            <span className="flex items-center gap-1.5 text-[11px] text-foreground">
              <AppwriteMark className="size-3" />
              Appwrite
            </span>
          }
          className="flex-1 border-[rgb(var(--tone-rgb)/0.35)]"
          bodyClassName="overflow-x-auto p-5"
        >
          <pre dir="ltr" className="font-mono text-[12px] leading-6">
            <ArtToken tone="keyword">await</ArtToken> <ArtToken tone="identifier">tablesDB</ArtToken>
            <ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="function">createRow</ArtToken>
            <ArtToken tone="punctuation">{'({'}</ArtToken>{'\n'}
            {'  '}<ArtToken tone="property">databaseId</ArtToken><ArtToken tone="punctuation">: </ArtToken>
            <ArtToken tone="string">&apos;main&apos;</ArtToken><ArtToken tone="punctuation">,</ArtToken>{'\n'}
            {'  '}<ArtToken tone="property">tableId</ArtToken><ArtToken tone="punctuation">: </ArtToken>
            <ArtToken tone="string">&apos;documents&apos;</ArtToken><ArtToken tone="punctuation">,</ArtToken>{'\n'}
            {'  '}<ArtToken tone="property">rowId</ArtToken><ArtToken tone="punctuation">: </ArtToken>
            <ArtToken tone="class">ID</ArtToken><ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="function">unique</ArtToken><ArtToken tone="punctuation">(),</ArtToken>{'\n'}
            {'  '}<ArtToken tone="property">data</ArtToken><ArtToken tone="punctuation">: {'{ '}</ArtToken>
            <ArtToken tone="property">title</ArtToken><ArtToken tone="punctuation">: </ArtToken>
            <ArtToken tone="string">&apos;Q3 roadmap&apos;</ArtToken>
            <ArtToken tone="punctuation">{' },'}</ArtToken>{'\n'}
            {'  '}<ArtToken tone="property">permissions</ArtToken><ArtToken tone="punctuation">: [</ArtToken>{'\n'}
            <span className="-mx-5 block bg-[rgb(var(--tone-rgb)/0.08)] px-5">
              {'    '}<ArtToken tone="class">Permission</ArtToken><ArtToken tone="punctuation">.</ArtToken>
              <ArtToken tone="function">read</ArtToken><ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="class">Role</ArtToken><ArtToken tone="punctuation">.</ArtToken>
              <ArtToken tone="function">team</ArtToken><ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="identifier">teamId</ArtToken><ArtToken tone="punctuation">)),</ArtToken>{'\n'}
              {'    '}<ArtToken tone="class">Permission</ArtToken><ArtToken tone="punctuation">.</ArtToken>
              <ArtToken tone="function">update</ArtToken><ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="class">Role</ArtToken><ArtToken tone="punctuation">.</ArtToken>
              <ArtToken tone="function">team</ArtToken><ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="identifier">teamId</ArtToken><ArtToken tone="punctuation">, </ArtToken>
              <ArtToken tone="string">&apos;editor&apos;</ArtToken><ArtToken tone="punctuation">)),</ArtToken>
            </span>
            {'  '}<ArtToken tone="punctuation">],</ArtToken>{'\n'}
            <ArtToken tone="punctuation">{'})'}</ArtToken>
          </pre>
        </ArtWindow>
        <p className="mt-4 text-[13px] leading-6 text-muted-foreground">
          {t('Appwrite permissions are role strings that sit next to the data. Anyone, or any agent, can review who can read and write a row in a single line, and the same rules apply to the API, Realtime, and every SDK.')}
        </p>
      </div>
    </div>
  )
}

type PlanMetric = {
  label: string
  appwrite: number
  supabase: number
  appwriteLabel: string
  supabaseLabel: string
}

const PRO_PLAN_METRICS: PlanMetric[] = [
  { label: 'Monthly active users', appwrite: 200, supabase: 100, appwriteLabel: '200K', supabaseLabel: '100K' },
  { label: 'Bandwidth', appwrite: 2000, supabase: 250, appwriteLabel: '2TB', supabaseLabel: '250GB' },
  { label: 'File storage', appwrite: 150, supabase: 100, appwriteLabel: '150GB', supabaseLabel: '100GB' },
  { label: 'Function executions', appwrite: 3.5, supabase: 2, appwriteLabel: '3.5M', supabaseLabel: '2M' },
  { label: 'Realtime messages', appwrite: 6, supabase: 5, appwriteLabel: '6M', supabaseLabel: '5M' },
]

/** Open bar chart: no frame, just the legend, rows, and hairlines. */
function ProPlanBars() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="font-mono text-[11px] uppercase tracking-[0.18em] text-muted-foreground">
          {t('Included on Pro, from $25/mo')}
        </p>
        <div className="flex items-center gap-4 text-[12px]">
          <span className="flex items-center gap-1.5 text-foreground">
            <span className="size-2 rounded-full bg-[var(--tone-ink)]" aria-hidden />
            Appwrite
          </span>
          <span className="flex items-center gap-1.5 text-muted-foreground">
            <span className="size-2 rounded-full bg-foreground/25" aria-hidden />
            Supabase
          </span>
        </div>
      </div>
      <div className="mt-4 divide-y divide-border border-t border-border">
        {PRO_PLAN_METRICS.map((metric, index) => {
          const max = Math.max(metric.appwrite, metric.supabase)
          return (
            <div key={metric.label} className="grid gap-2 py-5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-center sm:gap-6">
              <p className="text-[13px] font-medium text-foreground">{t(metric.label)}</p>
              <div className="space-y-2">
                <div className="flex items-center gap-3">
                  <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted/40">
                    <div
                      className="product-hero-fill h-full rounded-full bg-[var(--tone-ink)] shadow-[0_0_14px_rgb(var(--tone-rgb)/0.6)]"
                      style={fillStyle((metric.appwrite / max) * 100, 200 + index * 120)}
                    />
                  </div>
                  <span className="w-14 text-end font-mono text-[12px] tabular-nums text-foreground">{metric.appwriteLabel}</span>
                </div>
                <div className="flex items-center gap-3">
                  <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted/40">
                    <div
                      className="product-hero-fill h-full rounded-full bg-foreground/25"
                      style={fillStyle((metric.supabase / max) * 100, 260 + index * 120)}
                    />
                  </div>
                  <span className="w-14 text-end font-mono text-[12px] tabular-nums text-muted-foreground">
                    {metric.supabaseLabel}
                  </span>
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </ProductVisualAura>
  )
}

const MIGRATION_STEPS = [
  {
    title: 'Connect',
    description: 'Paste your Supabase pooler credentials and service key in the Migrations tab.',
    icon: Plug,
  },
  {
    title: 'Select',
    description: 'Pick users, databases, and files. Usage during the import is not billed.',
    icon: ListChecks,
  },
  {
    title: 'Ship',
    description: 'Move Edge Functions to any runtime, then deploy your frontend with Sites.',
    icon: Rocket,
  },
]

export function SupabaseComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:pb-20 lg:pt-20',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="Supabase" />
            <ComparisonHeroTitle className="mt-7" title="Your whole stack," accent="not just the database" />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 lg:mx-0">
              {t('Supabase is Postgres with tools around it. Appwrite is the complete open-source platform: auth, databases, storage, functions in 13+ runtimes, and realtime, plus web hosting, messaging, domains, and a firewall that Supabase does not offer. One project, one Console, one bill.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 lg:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton
                href="/docs/advanced/migrations/supabase"
                label="Migrate from Supabase"
                analytics="alternative-migrate"
              />
            </div>
          </div>
          <div className="min-w-0">
            <SupabaseHeroArt />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Access control"
          title="Permissions you can read in one line"
          description="Same goal, very different ergonomics. Here is a team-scoped document in both platforms."
        />
        <div className="mt-12">
          <PermissionsCompare />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="supabase"
        title="The right database for every kind of data"
        description="Supabase puts everything in one PostgreSQL instance per project. Appwrite gives you five database models in the same project: tables, documents, and vectors on serverless or dedicated compute, plus managed PostgreSQL and MySQL."
      />

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Functions"
            title="Thirteen runtimes, not one"
            description="Supabase Edge Functions run TypeScript on a Deno-compatible runtime. Appwrite Functions run the language your team and your libraries already use, including Python for AI work, with Git deploys, cron, events, and local development."
          />
          <RuntimeWall competitorName="Supabase" competitorRuntime="TypeScript (Deno)" />
        </div>
      </ComparisonSection>

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Pricing"
            title="Same $25. More room to grow."
            description="Both Pro plans start at $25/mo and dedicated database compute starts at $10/mo on both. Appwrite includes more of almost everything, plus hosting and messaging Supabase does not sell at all."
          />
          <ProPlanBars />
        </div>
      </ComparisonSection>

      <OpenSourceSection
        id="supabase"
        title="Open source that is easy to run yourself"
        description="Both platforms publish their code. Appwrite is the one built to be self-hosted: one install command brings up every product, with the same APIs and Console as Appwrite Cloud."
      />

      <ComparisonTableSection
        id="supabase"
        title="More of your stack, built in"
        description="Where the two platforms overlap, and everything Appwrite adds on top."
      />

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] lg:items-center lg:gap-14">
          <ComparisonHeading
            eyebrow="Migration"
            title="Bring your Supabase project with you"
            description="The Migrations tool imports users, databases, and files straight from your Supabase project."
            size="md"
          />
          <ol className="grid gap-8 sm:grid-cols-3 sm:gap-6">
            {MIGRATION_STEPS.map((step, index) => {
              const Icon = step.icon
              return (
                <li
                  key={step.title}
                  className="product-hero-rise relative border-t border-foreground/15 pt-5"
                  style={riseStyle(120 + index * 140)}
                >
                  <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
                  <span className="flex items-center justify-between gap-2">
                    <span className="font-mono text-[11px] tabular-nums text-[var(--tone-ink)]">
                      {String(index + 1).padStart(2, '0')}
                    </span>
                    <Icon className="size-4 text-muted-foreground" strokeWidth={1.75} aria-hidden />
                  </span>
                  <p className="mt-3 font-aeonik-pro text-[20px] text-foreground">{t(step.title)}</p>
                  <p className="mt-2 text-[13px] leading-5 text-muted-foreground">{t(step.description)}</p>
                </li>
              )
            })}
          </ol>
        </div>
      </ComparisonSection>

      <ComparisonClosing
        id="supabase"
        cta={{
          title: 'Ship the whole stack from one place',
          description: 'Create a project, import from Supabase, and deploy your frontend next to your backend.',
          secondary: (
            <SecondaryLinkButton
              href="/docs/advanced/migrations/supabase"
              label="Read the migration guide"
              analytics="alternative-migrate"
            />
          ),
        }}
      />
    </>
  )
}
