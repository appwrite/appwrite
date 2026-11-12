import { BellRing, Mail, MessageSquare, Terminal } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
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
import { OpenSourceSection } from './OpenSource'
import { RuntimeWall } from './RuntimeWall'

/* -------------------------------------------------------------------------------------------------
 * Hero: what an Amplify backend deploys
 * -----------------------------------------------------------------------------------------------*/

const AMPLIFY_RESOURCES: { service: string; resource: string }[] = [
  { service: 'Amazon Cognito', resource: 'User pool' },
  { service: 'Amazon Cognito', resource: 'Identity pool' },
  { service: 'AWS IAM', resource: 'Roles and policies' },
  { service: 'AWS AppSync', resource: 'GraphQL API' },
  { service: 'Amazon DynamoDB', resource: 'Model tables' },
  { service: 'AWS Lambda', resource: 'Functions' },
  { service: 'Amazon S3', resource: 'Storage bucket' },
  { service: 'AWS CloudFormation', resource: 'Nested stacks' },
]

function ProvisioningVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-6 pt-8 text-start">
      <div className="product-hero-rise flex items-center justify-between gap-3" style={riseStyle(80)}>
        <p dir="ltr" className="flex min-w-0 items-center gap-2 font-mono text-[12px] text-muted-foreground">
          <Terminal className="size-3.5 shrink-0" aria-hidden />
          <span className="truncate">npx ampx sandbox</span>
        </p>
        <span className="flex shrink-0 items-center gap-2 text-[11px] text-muted-foreground">
          <CompetitorMonogram name="AWS Amplify" />
          {t('One Amplify backend')}
        </span>
      </div>

      <ol className="mt-4 divide-y divide-foreground/10 border-y border-foreground/15" dir="ltr">
        {AMPLIFY_RESOURCES.map((row, index) => (
          <li
            key={`${row.service}-${row.resource}`}
            className="product-hero-rise grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-2.5"
            style={riseStyle(160 + index * 110)}
          >
            <span className="min-w-0 truncate text-[12px]">
              <span className="font-medium text-foreground/80">{row.service}</span>
              <span className="text-muted-foreground"> · {t(row.resource)}</span>
            </span>
            <span className="font-mono text-[10px] tracking-wide text-emerald-600/80 dark:text-emerald-400/80">CREATE_COMPLETE</span>
          </li>
        ))}
      </ol>

      <div
        className="product-hero-rise relative isolate mt-6 rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] px-4 py-4 shadow-[0_18px_48px_-24px_rgb(var(--tone-rgb)/0.8)] sm:px-5"
        style={riseStyle(1200)}
      >
        <span
          className="pointer-events-none absolute -inset-6 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.2),transparent_70%)]"
          aria-hidden
        />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
            <AppwriteMark className="size-4" />
            {t('The same backend on Appwrite')}
          </p>
          <span className="font-aeonik-pro text-[22px] leading-none text-[var(--tone-ink)]">{t('1 project')}</span>
        </div>
        <ul className="mt-4 flex flex-wrap gap-1.5" aria-label={t('Appwrite products')}>
          {PLATFORM_PRODUCTS.map((product) => {
            const Icon = PRODUCT_NAV_REGISTRY[product.id].icon
            return (
              <li
                key={product.id}
                title={t(product.name)}
                className="flex size-8 items-center justify-center rounded-lg border border-[rgb(var(--tone-rgb)/0.35)] bg-background text-[var(--tone-ink)] dark:bg-card"
              >
                <Icon className="size-4" strokeWidth={1.75} aria-hidden />
                <span className="sr-only">{t(product.name)}</span>
              </li>
            )
          })}
        </ul>
      </div>

      <p className="mt-4 text-[11px] leading-4 text-muted-foreground">
        {t('Illustrative. Amplify Gen 2 deploys your backend as AWS CloudFormation stacks across these services.')}
      </p>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * The monthly bill
 * -----------------------------------------------------------------------------------------------*/

const AMPLIFY_BILL: { service: string; meter: string; value: string }[] = [
  { service: 'Amplify Hosting', meter: 'Build minutes, bandwidth, SSR', value: 'Metered' },
  { service: 'Amazon Cognito', meter: 'Monthly active users', value: 'Metered' },
  { service: 'AWS AppSync', meter: 'Requests and realtime minutes', value: 'Metered' },
  { service: 'Amazon DynamoDB', meter: 'Reads, writes, and storage', value: 'Metered' },
  { service: 'AWS Lambda', meter: 'Requests and duration', value: 'Metered' },
  { service: 'Amazon S3', meter: 'Storage and requests', value: 'Metered' },
  { service: 'AWS WAF', meter: 'Per app, plus usage', value: '$15+' },
]

const APPWRITE_ALLOWANCES = [
  '200,000 monthly active users',
  '2TB bandwidth',
  '150GB storage',
  '3.5M function executions',
  'Firewall rules included',
]

function BillLine({ label, detail, value, muted }: { label: string; detail?: string; value: string; muted: boolean }) {
  const t = useT()
  return (
    <li className="flex items-baseline gap-3 py-2.5">
      <span className="min-w-0">
        <span className={cn('block truncate text-[13px] font-medium', muted ? 'text-foreground/75' : 'text-foreground')}>
          {t(label)}
        </span>
        {detail ? <span className="block truncate text-[11px] text-muted-foreground">{t(detail)}</span> : null}
      </span>
      <span className="mb-1 h-px min-w-6 flex-1 border-b border-dotted border-foreground/25" aria-hidden />
      <span className={cn('shrink-0 font-mono text-[12px]', muted ? 'text-muted-foreground' : 'text-[var(--tone-ink)]')}>
        {t(value)}
      </span>
    </li>
  )
}

function MonthlyBill() {
  const t = useT()
  return (
    <div className="grid gap-12 md:grid-cols-2 md:gap-0 md:divide-x md:divide-foreground/10 rtl:md:divide-x-reverse">
      <div className="product-hero-rise md:pe-10" style={riseStyle(80)}>
        <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
          <CompetitorMonogram name="AWS Amplify" />
          {t('An Amplify month')}
        </p>
        <ul className="mt-5 divide-y divide-foreground/10 border-y border-foreground/15">
          {AMPLIFY_BILL.map((line) => (
            <BillLine key={line.service} label={line.service} detail={line.meter} value={line.value} muted />
          ))}
        </ul>
        <p className="mt-4 text-[12px] leading-5 text-muted-foreground">
          {t('Seven meters, seven sets of limits, one AWS invoice to decode.')}
        </p>
      </div>

      <div className="product-hero-rise relative isolate md:ps-10" style={riseStyle(220)}>
        <span
          className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.14),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[13px] text-foreground">
          <AppwriteMark className="size-4" />
          {t('An Appwrite month')}
        </p>
        <div className="mt-5 flex items-baseline justify-between gap-3 border-y border-[var(--tone-ink)] py-4">
          <span className="text-[15px] font-medium text-foreground">{t('Appwrite Pro')}</span>
          <span className="font-aeonik-pro text-[40px] leading-none text-foreground" dir="ltr">
            $25<span className="text-[14px] text-muted-foreground">/mo</span>
          </span>
        </div>
        <ul className="mt-4 space-y-2.5">
          {APPWRITE_ALLOWANCES.map((line) => (
            <li key={line} className="flex items-center gap-2.5 text-[13px] text-foreground/85">
              <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
              {t(line)}
            </li>
          ))}
        </ul>
        <p className="mt-5 text-[12px] leading-5 text-muted-foreground">
          {t('Pay as you go above the allowances, up to a budget cap you set.')}
        </p>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Messaging: Pinpoint end of support
 * -----------------------------------------------------------------------------------------------*/

const PINPOINT_TIMELINE = [
  { date: 'May 20, 2025', label: 'Amazon Pinpoint stops accepting new customers' },
  { date: 'October 30, 2026', label: 'Pinpoint reaches end of support, and Amplify analytics and push notifications stop working' },
]

const MESSAGING_CHANNELS: { label: string; detail: string; icon: LucideIcon }[] = [
  { label: 'Push', detail: 'FCM and APNs', icon: BellRing },
  { label: 'Email', detail: 'SMTP and email providers', icon: Mail },
  { label: 'SMS', detail: 'Global SMS providers', icon: MessageSquare },
]

function MessagingShift() {
  const t = useT()
  return (
    <div className="grid min-w-0 gap-12 lg:grid-cols-2 lg:gap-16">
      <div>
        <p className="flex items-center gap-2 text-[12px] font-medium text-muted-foreground">
          <CompetitorMonogram name="AWS Amplify" />
          {t('Amplify messaging timeline')}
        </p>
        <ol className="relative mt-5 space-y-6 border-s border-foreground/15 ps-5">
          {PINPOINT_TIMELINE.map((item, index) => (
            <li key={item.date} className="product-hero-rise relative" style={riseStyle(120 + index * 140)}>
              <span
                className={cn(
                  'absolute -start-[25px] top-1.5 size-2.5 rounded-full border-2 border-background',
                  index === PINPOINT_TIMELINE.length - 1 ? 'bg-amber-500' : 'bg-foreground/40',
                )}
                aria-hidden
              />
              <p className="font-mono text-[11px] text-muted-foreground">{t(item.date)}</p>
              <p className="mt-0.5 text-[14px] leading-6 text-foreground/85">{t(item.label)}</p>
            </li>
          ))}
        </ol>
      </div>

      <div className="relative isolate">
        <span
          className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.14),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[12px] font-medium text-foreground">
          <AppwriteMark className="size-4" />
          {t('Appwrite Messaging')}
        </p>
        <ul className="mt-5 grid gap-x-6 gap-y-6 sm:grid-cols-3">
          {MESSAGING_CHANNELS.map((channel, index) => {
            const Icon = channel.icon
            return (
              <li
                key={channel.label}
                className="product-hero-rise relative border-t border-foreground/15 pt-4"
                style={riseStyle(200 + index * 100)}
              >
                <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
                <Icon className="size-5 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
                <p className="mt-3 text-[15px] font-medium text-foreground">{t(channel.label)}</p>
                <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(channel.detail)}</p>
              </li>
            )
          })}
        </ul>
        <p className="mt-6 text-[13px] leading-6 text-muted-foreground">
          {t('One API for push, email, and SMS, with topics, scheduling, and your users and teams as targets.')}
        </p>
      </div>
    </div>
  )
}

export function AmplifyComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="AWS Amplify" />
            <ComparisonHeroTitle className="mt-7" title="One project," accent="not a stack of AWS services." />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t('Amplify assembles your backend from Cognito, AppSync, DynamoDB, Lambda, and S3, each with its own console, limits, and bill, and it only runs on AWS. Appwrite gives you auth, five database models, storage, functions in 13+ runtimes, messaging, and hosting in one open-source platform.')}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton href="/pricing" label="View pricing" />
            </div>
          </div>
          <div className="min-w-0">
            <ProvisioningVisual />
          </div>
        </div>
      </section>

      <ComparisonSection backdrop={<AmplifySideGlow />}>
        <ComparisonHeading
          eyebrow="Pricing"
          title="One plan instead of seven meters"
          description="Every AWS service behind Amplify bills on its own. Appwrite Pro starts at $25/mo with allowances for each resource, including 2TB of bandwidth that would cost about $300 a month on Amplify Hosting."
        />
        <div className="mt-12">
          <MonthlyBill />
        </div>
      </ComparisonSection>

      <DatabaseModelsSection
        id="amplify"
        title="More than DynamoDB behind a GraphQL API"
        description="Amplify Data puts every model in DynamoDB, and SQL means running your own database. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute."
      />

      <ComparisonSection>
        <div className="grid min-w-0 gap-8 sm:gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Functions"
            title="Any language, no CDK required"
            description="Amplify functions are defined for Node.js, and every other language means writing custom CDK code. Appwrite Functions run in 13+ runtimes with Git deploys, cron, events, and local development built in."
          />
          <RuntimeWall
            competitorName="AWS Amplify"
            competitorRuntime="Node.js"
            competitorIcon="/icons/node.svg"
            competitorNote="Other languages need custom CDK code"
          />
        </div>
      </ComparisonSection>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" aria-hidden />}
      >
        <ComparisonHeading
          eyebrow="Messaging"
          title="Push notifications with no end-of-support date"
          description="Amplify analytics and push notifications run on Amazon Pinpoint, which reaches end of support on October 30, 2026. Appwrite Messaging is part of the platform, next to your users and data."
        />
        <div className="mt-12">
          <MessagingShift />
        </div>
      </ComparisonSection>

      <OpenSourceSection
        id="amplify"
        title="Your backend should not need an AWS account"
        description="Amplify libraries are open source, but everything behind them runs only on AWS. Appwrite is open source end to end, so the same backend runs on Appwrite Cloud, any other cloud, or your own servers."
      />

      <ComparisonTableSection
        id="amplify"
        title="The full-stack features, without the AWS overhead"
        description="Both cover auth, data, storage, functions, and hosting. The difference is how many services, consoles, and bills it takes."
      />

      <ComparisonClosing
        id="amplify"
        cta={{
          title: 'Ship a full stack from one Console',
          description: 'Create a project and get auth, databases, storage, functions, messaging, and hosting, with no AWS account required.',
        }}
      />
    </>
  )
}

function AmplifySideGlow() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className="product-tone2-glow absolute -start-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
    </div>
  )
}
