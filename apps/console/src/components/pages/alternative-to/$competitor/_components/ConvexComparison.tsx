import { Check, Database, Folder, UserRound, Users, UsersRound, X, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import { useState } from 'react'
import {
  ArtLiveDot,
  ArtToken,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { cn } from '@/lib/utils'
import { DatabaseModelsSection } from './DatabaseModels'
import { PlatformBreadthSection } from './PlatformBreadth'
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
  SecondaryLinkButton,
  StartBuildingButton,
  VersusPill,
  comparisonHeroCenteredClassName,
} from './ComparisonParts'

/* -------------------------------------------------------------------------------------------------
 * Hero: realtime event feed
 * -----------------------------------------------------------------------------------------------*/

type FeedService = 'Databases' | 'Storage' | 'Functions' | 'Auth' | 'Teams'

const SERVICE_META: Record<FeedService, { icon: LucideIcon }> = {
  Databases: { icon: Database },
  Storage: { icon: Folder },
  Functions: { icon: Zap },
  Auth: { icon: Users },
  Teams: { icon: UsersRound },
}

const FEED: { service: FeedService; event: string; detail: string }[] = [
  { service: 'Databases', event: 'databases.main.tables.tasks.rows.*.update', detail: 'status: done' },
  { service: 'Storage', event: 'buckets.avatars.files.*.create', detail: 'paige.avif, 182 KB' },
  { service: 'Functions', event: 'functions.summarize.executions.*.update', detail: 'completed in 412 ms' },
  { service: 'Auth', event: 'users.*.sessions.*.create', detail: 'new session, Safari' },
  { service: 'Teams', event: 'teams.acme.memberships.*.create', detail: 'diego joined as editor' },
  { service: 'Databases', event: 'databases.main.tables.comments.rows.*.create', detail: 'on task #482' },
  { service: 'Functions', event: 'functions.thumbnail.executions.*.create', detail: 'triggered by upload' },
  { service: 'Storage', event: 'buckets.exports.files.*.update', detail: 'report.csv ready' },
]

function FeedLine({ item }: { item: (typeof FEED)[number] }) {
  const t = useT()
  const Icon = SERVICE_META[item.service].icon
  return (
    <li className="grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-3 border-b border-border px-4 py-2.5 sm:grid-cols-[7.5rem_minmax(0,1fr)_10rem]">
      <span className="flex items-center gap-1.5 text-[11px] font-medium text-foreground">
        <Icon className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
        {t(item.service)}
      </span>
      <span dir="ltr" className="truncate font-mono text-[11px] text-foreground/80">
        {item.event}
      </span>
      <span dir="ltr" className="hidden truncate text-end font-mono text-[11px] text-muted-foreground sm:block">
        {item.detail}
      </span>
    </li>
  )
}

function RealtimeFeedVisual() {
  const t = useT()
  return (
    <ArtWindow
      title={<CodeFileLabel>realtime.subscribe(channels, onEvent)</CodeFileLabel>}
      trailing={
        <span className="flex items-center gap-1.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
          <ArtLiveDot className="size-1.5" />
          {t('1 socket, every service')}
        </span>
      }
      className="product-hero-rise mx-auto w-full max-w-4xl text-start"
      bodyClassName="p-0"
      style={riseStyle(200)}
    >
      <div className="flex flex-wrap gap-1.5 border-b border-border px-4 py-3">
        {(Object.keys(SERVICE_META) as FeedService[]).map((service) => {
          const Icon = SERVICE_META[service].icon
          return (
            <span
              key={service}
              className="flex items-center gap-1.5 rounded-full border border-[rgb(var(--tone-rgb)/0.3)] bg-[rgb(var(--tone-rgb)/0.08)] px-2.5 py-1 text-[11px] text-foreground"
            >
              <Icon className="size-3 text-[var(--tone-ink)]" aria-hidden />
              {t(service)}
            </span>
          )
        })}
      </div>
      <div className="relative h-[260px] overflow-hidden [mask-image:linear-gradient(to_bottom,transparent,black_12%,black_88%,transparent)]">
        <ul className="alternative-feed-scroll" style={{ '--feed-duration': '22s' } as CSSProperties}>
          {[...FEED, ...FEED].map((item, index) => (
            <FeedLine key={`${item.event}-${index}`} item={item} />
          ))}
        </ul>
      </div>
    </ArtWindow>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Language switcher
 * -----------------------------------------------------------------------------------------------*/

type LanguageId = 'node' | 'python' | 'go' | 'dart' | 'php' | 'ruby'

const LANGUAGES: { id: LanguageId; name: string; icon: string; file: string; code: ReactNode }[] = [
  {
    id: 'node',
    name: 'Node.js',
    icon: '/icons/node.svg',
    file: 'src/main.js',
    code: (
      <>
        <ArtToken tone="keyword">export default async</ArtToken> <ArtToken tone="punctuation">({'{ '}</ArtToken>
        <ArtToken tone="identifier">req</ArtToken><ArtToken tone="punctuation">, </ArtToken>
        <ArtToken tone="identifier">res</ArtToken> <ArtToken tone="punctuation">{'})'}</ArtToken>{' '}
        <ArtToken tone="operator">=&gt;</ArtToken> <ArtToken tone="punctuation">{'{'}</ArtToken>{'\n'}
        {'  '}<ArtToken tone="keyword">const</ArtToken> <ArtToken tone="identifier">rate</ArtToken>{' '}
        <ArtToken tone="operator">=</ArtToken> <ArtToken tone="keyword">await</ArtToken>{' '}
        <ArtToken tone="function">fetch</ArtToken><ArtToken tone="punctuation">(</ArtToken>
        <ArtToken tone="string">&apos;https://api.example.com/fx&apos;</ArtToken>
        <ArtToken tone="punctuation">)</ArtToken>{'\n'}
        {'  '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">res</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="keyword">await</ArtToken>{' '}
        <ArtToken tone="identifier">rate</ArtToken><ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="function">json</ArtToken><ArtToken tone="punctuation">())</ArtToken>{'\n'}
        <ArtToken tone="punctuation">{'}'}</ArtToken>
      </>
    ),
  },
  {
    id: 'python',
    name: 'Python',
    icon: '/icons/python.svg',
    file: 'src/main.py',
    code: (
      <>
        <ArtToken tone="keyword">import</ArtToken> <ArtToken tone="identifier">requests</ArtToken>{'\n\n'}
        <ArtToken tone="keyword">def</ArtToken> <ArtToken tone="function">main</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">):</ArtToken>{'\n'}
        {'    '}<ArtToken tone="identifier">rate</ArtToken> <ArtToken tone="operator">=</ArtToken>{' '}
        <ArtToken tone="identifier">requests</ArtToken><ArtToken tone="punctuation">.</ArtToken>
        <ArtToken tone="function">get</ArtToken><ArtToken tone="punctuation">(</ArtToken>
        <ArtToken tone="string">&quot;https://api.example.com/fx&quot;</ArtToken>
        <ArtToken tone="punctuation">)</ArtToken>{'\n'}
        {'    '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="property">res</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="identifier">rate</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">())</ArtToken>
      </>
    ),
  },
  {
    id: 'go',
    name: 'Go',
    icon: '/icons/go.svg',
    file: 'main.go',
    code: (
      <>
        <ArtToken tone="keyword">package</ArtToken> <ArtToken tone="identifier">handler</ArtToken>{'\n\n'}
        <ArtToken tone="keyword">import</ArtToken>{' '}
        <ArtToken tone="string">&quot;github.com/open-runtimes/types-for-go/v4/openruntimes&quot;</ArtToken>{'\n\n'}
        <ArtToken tone="keyword">func</ArtToken> <ArtToken tone="function">Main</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="identifier">Context</ArtToken>{' '}
        <ArtToken tone="type">openruntimes.Context</ArtToken><ArtToken tone="punctuation">)</ArtToken>{' '}
        <ArtToken tone="type">openruntimes.Response</ArtToken> <ArtToken tone="punctuation">{'{'}</ArtToken>{'\n'}
        {'    '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">Context</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="property">Res</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">Json</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="type">map</ArtToken>
        <ArtToken tone="punctuation">[</ArtToken><ArtToken tone="type">string</ArtToken>
        <ArtToken tone="punctuation">]</ArtToken><ArtToken tone="type">any</ArtToken>
        <ArtToken tone="punctuation">{'{'}</ArtToken><ArtToken tone="string">&quot;ok&quot;</ArtToken>
        <ArtToken tone="punctuation">: </ArtToken><ArtToken tone="keyword">true</ArtToken>
        <ArtToken tone="punctuation">{'})'}</ArtToken>{'\n'}
        <ArtToken tone="punctuation">{'}'}</ArtToken>
      </>
    ),
  },
  {
    id: 'dart',
    name: 'Dart',
    icon: '/icons/dart.svg',
    file: 'lib/main.dart',
    code: (
      <>
        <ArtToken tone="type">Future</ArtToken><ArtToken tone="punctuation">&lt;</ArtToken>
        <ArtToken tone="keyword">dynamic</ArtToken><ArtToken tone="punctuation">&gt;</ArtToken>{' '}
        <ArtToken tone="function">main</ArtToken><ArtToken tone="punctuation">(</ArtToken>
        <ArtToken tone="keyword">final</ArtToken> <ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">)</ArtToken> <ArtToken tone="keyword">async</ArtToken>{' '}
        <ArtToken tone="punctuation">{'{'}</ArtToken>{'\n'}
        {'  '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="property">res</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">({'{'}</ArtToken><ArtToken tone="string">&apos;ok&apos;</ArtToken>
        <ArtToken tone="punctuation">: </ArtToken><ArtToken tone="keyword">true</ArtToken>
        <ArtToken tone="punctuation">{'});'}</ArtToken>{'\n'}
        <ArtToken tone="punctuation">{'}'}</ArtToken>
      </>
    ),
  },
  {
    id: 'php',
    name: 'PHP',
    icon: '/icons/php.svg',
    file: 'src/index.php',
    code: (
      <>
        <ArtToken tone="keyword">return function</ArtToken> <ArtToken tone="punctuation">(</ArtToken>
        <ArtToken tone="identifier">$context</ArtToken><ArtToken tone="punctuation">)</ArtToken>{' '}
        <ArtToken tone="punctuation">{'{'}</ArtToken>{'\n'}
        {'    '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">$context</ArtToken>
        <ArtToken tone="operator">-&gt;</ArtToken><ArtToken tone="property">res</ArtToken>
        <ArtToken tone="operator">-&gt;</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">([</ArtToken><ArtToken tone="string">&apos;ok&apos;</ArtToken>{' '}
        <ArtToken tone="operator">=&gt;</ArtToken> <ArtToken tone="keyword">true</ArtToken>
        <ArtToken tone="punctuation">]);</ArtToken>{'\n'}
        <ArtToken tone="punctuation">{'};'}</ArtToken>
      </>
    ),
  },
  {
    id: 'ruby',
    name: 'Ruby',
    icon: '/icons/ruby.svg',
    file: 'lib/main.rb',
    code: (
      <>
        <ArtToken tone="keyword">def</ArtToken> <ArtToken tone="function">main</ArtToken>
        <ArtToken tone="punctuation">(</ArtToken><ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">)</ArtToken>{'\n'}
        {'  '}<ArtToken tone="keyword">return</ArtToken> <ArtToken tone="identifier">context</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="property">res</ArtToken>
        <ArtToken tone="punctuation">.</ArtToken><ArtToken tone="function">json</ArtToken>
        <ArtToken tone="punctuation">({'{ '}</ArtToken><ArtToken tone="string">&apos;ok&apos;</ArtToken>{' '}
        <ArtToken tone="operator">=&gt;</ArtToken> <ArtToken tone="keyword">true</ArtToken>
        <ArtToken tone="punctuation">{' })'}</ArtToken>{'\n'}
        <ArtToken tone="keyword">end</ArtToken>
      </>
    ),
  },
]

function LanguageSwitcher() {
  const t = useT()
  const [active, setActive] = useState<LanguageId>('python')
  const language = LANGUAGES.find((item) => item.id === active) ?? LANGUAGES[0]

  return (
    <div className="space-y-3">
      <div className="product-tone-shadow overflow-hidden rounded-2xl border border-[rgb(var(--tone-rgb)/0.35)] bg-background dark:bg-card">
        <div role="tablist" aria-label={t('Function runtime')} className="flex gap-1 overflow-x-auto border-b border-border bg-muted/20 p-1.5 [scrollbar-width:none]">
          {LANGUAGES.map((item) => (
            <button
              key={item.id}
              type="button"
              role="tab"
              aria-selected={item.id === active}
              onClick={() => setActive(item.id)}
              className={cn(
                'flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12px] font-medium transition-colors duration-200',
                item.id === active
                  ? 'bg-background text-foreground shadow-sm dark:bg-card'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              <img src={item.icon} alt="" className="size-3.5 object-contain brightness-[0.55] dark:brightness-100" />
              <span dir="ltr">{item.name}</span>
            </button>
          ))}
        </div>
        <div className="flex items-center justify-between border-b border-border px-4 py-2">
          <CodeFileLabel>{language.file}</CodeFileLabel>
          <span className="flex items-center gap-1.5 text-[11px] text-foreground">
            <AppwriteMark className="size-3" />
            {t('Appwrite Functions')}
          </span>
        </div>
        <pre key={language.id} dir="ltr" className="min-h-[156px] overflow-x-auto p-5 font-mono text-[12px] leading-6 [animation:product-hero-rise_0.35s_ease-out] motion-reduce:[animation:none]">
          {language.code}
        </pre>
      </div>
      <p className="flex items-start gap-3 px-1 pt-2 text-[13px] leading-6 text-muted-foreground">
        <CompetitorMonogram name="Convex" className="mt-0.5" />
        {t('Convex server functions are TypeScript and JavaScript only, and only actions can call external APIs.')}
      </p>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Licenses
 * -----------------------------------------------------------------------------------------------*/

type LicenseRow = { label: string; appwrite: true | string; convex: false | string }

const LICENSE_ROWS: LicenseRow[] = [
  { label: 'OSI-approved open source license', appwrite: true, convex: false },
  { label: 'Self-hosting support', appwrite: 'Official guides, same Console as Cloud', convex: 'Community support on Discord' },
  { label: 'Auth, hosting, and messaging when self-hosted', appwrite: true, convex: false },
]

function LicenseVerdict() {
  const t = useT()
  const github = MARKETING_SOCIAL_STATS.github
  return (
    <div className="mx-auto max-w-5xl">
      <div className="grid gap-12 md:grid-cols-2 md:gap-0 md:divide-x md:divide-border rtl:md:divide-x-reverse">
        <div className="product-hero-rise relative isolate md:pe-12" style={riseStyle(80)}>
          <span
            className="pointer-events-none absolute -inset-10 -z-10 rounded-full bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.16),transparent_70%)]"
            aria-hidden
          />
          <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
            <AppwriteMark className="size-4" />
            Appwrite
          </p>
          <p className="mt-6 font-aeonik-pro text-[52px] leading-none tracking-tight text-[var(--tone-ink)] sm:text-[64px]">
            {t('Open source')}
          </p>
          <p className="mt-3 font-mono text-[12px] text-muted-foreground">{t('OSI approved, free to self-host')}</p>
          <p className="mt-6 flex gap-6 text-[13px] text-foreground">
            <span>
              <bdi className="font-aeonik-pro text-[22px]">{github.stat}</bdi>{' '}
              <span className="text-muted-foreground">{t('GitHub stars')}</span>
            </span>
            <span>
              <bdi className="font-aeonik-pro text-[22px]">{github.contributors}</bdi>{' '}
              <span className="text-muted-foreground">{t('Contributors')}</span>
            </span>
          </p>
        </div>
        <div className="product-hero-rise md:ps-12" style={riseStyle(200)}>
          <p className="flex items-center gap-2 text-[13px] font-medium text-muted-foreground">
            <CompetitorMonogram name="Convex" />
            Convex
          </p>
          <p className="mt-6 font-aeonik-pro text-[52px] leading-none tracking-tight text-foreground/45 sm:text-[64px]">
            {t('Not open source')}
          </p>
          <p className="mt-3 font-mono text-[12px] text-muted-foreground">FSL-1.1 · {t('Source-available')}</p>
          <p className="mt-6 max-w-sm text-[13px] leading-6 text-muted-foreground">
            {t('The Functional Source License is not approved by the Open Source Initiative and restricts how you can use the code for two years after each release.')}
          </p>
        </div>
      </div>

      <div className="mt-14 hidden gap-6 pb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground sm:grid sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)]" aria-hidden>
        <span />
        <span className="flex items-center gap-2 text-foreground">
          <AppwriteMark className="size-3.5" />
          Appwrite
        </span>
        <span className="flex items-center gap-2">
          <CompetitorMonogram name="Convex" className="size-4 text-[9px]" />
          Convex
        </span>
      </div>
      <dl className="mt-14 divide-y divide-foreground/10 border-y border-foreground/15 sm:mt-0">
        {LICENSE_ROWS.map((row, index) => (
          <div
            key={row.label}
            className="product-hero-rise grid gap-3 py-4 sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:gap-6"
            style={riseStyle(320 + index * 80)}
          >
            <dt className="text-[13px] font-medium text-foreground">{t(row.label)}</dt>
            <dd className="flex items-center gap-2 text-[13px] text-foreground">
              {row.appwrite === true ? (
                <span className="flex size-5 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
                  <Check className="size-3" strokeWidth={3} aria-hidden />
                  <span className="sr-only">{t('Yes')}</span>
                </span>
              ) : (
                <>
                  <AppwriteMark className="size-3.5 sm:hidden" />
                  {t(row.appwrite)}
                </>
              )}
            </dd>
            <dd className="flex items-center gap-2 text-[13px] text-muted-foreground">
              {row.convex === false ? (
                <span className="flex size-5 items-center justify-center text-muted-foreground/70">
                  <X className="size-4" aria-hidden />
                  <span className="sr-only">{t('No')}</span>
                </span>
              ) : (
                <>
                  <CompetitorMonogram name="Convex" className="size-4 text-[9px] sm:hidden" />
                  {t(row.convex)}
                </>
              )}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Per-developer pricing
 * -----------------------------------------------------------------------------------------------*/

const TEAM_SIZE = 8

/** Open seat visual: one row of seats per platform, priced or included. */
function TeamPricing() {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="space-y-10">
        <div>
          <div className="flex items-baseline justify-between gap-3">
            <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
              <CompetitorMonogram name="Convex" />
              {t('Convex Professional')}
            </p>
            <p className="font-aeonik-pro text-[40px] leading-none text-foreground/55 tabular-nums" dir="ltr">
              $200<span className="text-[13px] text-muted-foreground">/mo</span>
            </p>
          </div>
          <div className="mt-4 flex flex-wrap gap-3" aria-hidden>
            {Array.from({ length: TEAM_SIZE }, (_, index) => (
              <span
                key={index}
                className="product-hero-rise flex flex-col items-center gap-1.5"
                style={riseStyle(80 + index * 50)}
              >
                <span className="flex size-9 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground">
                  <UserRound className="size-4" strokeWidth={1.75} />
                </span>
                <span className="font-mono text-[10px] text-muted-foreground">$25</span>
              </span>
            ))}
          </div>
          <p className="mt-3 text-[12px] text-muted-foreground">{t('$25 per developer, 8 developers')}</p>
        </div>
        <div className="border-t border-[var(--tone-ink)] pt-8">
          <div className="flex items-baseline justify-between gap-3">
            <p className="flex items-center gap-2 text-[13px] text-foreground">
              <AppwriteMark className="size-4" />
              {t('Appwrite Pro')}
            </p>
            <p className="font-aeonik-pro text-[40px] leading-none text-foreground tabular-nums" dir="ltr">
              $25<span className="text-[13px] text-muted-foreground">/mo</span>
            </p>
          </div>
          <div className="mt-4 flex flex-wrap gap-3" aria-hidden>
            {Array.from({ length: TEAM_SIZE }, (_, index) => (
              <span
                key={index}
                className="product-hero-rise flex flex-col items-center gap-1.5"
                style={riseStyle(200 + index * 50)}
              >
                <span className="flex size-9 items-center justify-center rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-[rgb(var(--tone-rgb)/0.18)] text-[var(--tone-ink)] shadow-[0_0_14px_rgb(var(--tone-rgb)/0.35)]">
                  <UserRound className="size-4" strokeWidth={1.75} />
                </span>
                <span className="font-mono text-[10px] text-[var(--tone-ink)]">{t('Included')}</span>
              </span>
            ))}
          </div>
          <p className="mt-3 text-[12px] text-muted-foreground">{t('From $25/mo, unlimited members')}</p>
        </div>
      </div>
    </ProductVisualAura>
  )
}

export function ConvexComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={comparisonHeroCenteredClassName}>
          <VersusPill name="Convex" />
          <ComparisonHeroTitle
            className="mx-auto mt-7 max-w-4xl lg:pb-1.5 lg:text-[56px] lg:leading-[1.06] xl:text-[64px]"
            title="Reactive everywhere."
            accent="Open by design."
          />
          <p className="mx-auto mt-6 max-w-2xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8">
            {t('Convex makes database queries reactive in TypeScript. Appwrite streams realtime events from every service, runs functions in 13+ runtimes, and ships auth, storage, hosting, and messaging in one platform. And unlike Convex, Appwrite is fully open source.')}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
            <StartBuildingButton />
            <SecondaryLinkButton href="/products/realtime" label="Explore Appwrite Realtime" />
          </div>
          <div className="mt-10 min-w-0 sm:mt-14">
            <RealtimeFeedVisual />
          </div>
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Functions"
            title="Write backend logic in the language you already use"
            description="Pick Python for AI libraries, Go for throughput, or Dart to share code with your Flutter app. Every Appwrite function can call external APIs, run on a schedule, react to platform events, or serve HTTP."
          />
          <LanguageSwitcher />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ConvexSideGlow />}>
        <ComparisonHeading
          align="center"
          eyebrow="License"
          title="Appwrite is open source. Convex is not."
          description="Appwrite is fully open source, so you can read, run, and change every line. Convex is source-available under the Functional Source License, which is not an open source license and comes with usage restrictions."
        />
        <div className="mt-14">
          <LicenseVerdict />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="convex"
        title="More than one way to model your data"
        description="Convex stores everything as documents on its own serverless database. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute."
      />

      <PlatformBreadthSection
        id="convex"
        title="A reactive database is a start. Appwrite is the whole backend."
        description="Convex covers data and TypeScript functions. Appwrite adds first-party sign-in, file storage, PostgreSQL, web hosting, messaging, domains, and a firewall, all wired to the same realtime events."
      />

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:gap-12 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Team pricing"
            title="Your team grows. Your plan does not."
            description="Convex Professional is priced per developer. Appwrite Pro covers your whole organization with unlimited members, so adding a teammate is free."
          />
          <TeamPricing />
        </div>
      </ComparisonSection>

      <ComparisonTableSection
        id="convex"
        title="Everything Convex does, in more languages"
        description="Two very different takes on a modern backend, side by side."
      />

      <ComparisonClosing
        id="convex"
        cta={{
          title: 'Build reactive apps on an open platform',
          description: 'Realtime on every service, functions in 13+ runtimes, and auth and hosting included from day one.',
          secondary: <SecondaryLinkButton href="/products/realtime" label="Explore Appwrite Realtime" />,
        }}
      />
    </>
  )
}

function ConvexSideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute left-1/2 top-[-30%] h-[620px] w-[min(1100px,140%)] -translate-x-1/2" />
    </div>
  )
}
