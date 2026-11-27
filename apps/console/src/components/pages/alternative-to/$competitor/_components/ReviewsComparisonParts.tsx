import {
  BookMarked,
  Check,
  CircleDot,
  FileText,
  GitPullRequest,
  MessageSquareWarning,
  Minus,
  ShieldCheck,
  SlidersHorizontal,
  Trophy,
  type LucideIcon,
} from 'lucide-react'
import type { ReactNode } from 'react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import type { ProductNavItemId } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonSection,
  CompetitorMonogram,
} from './ComparisonParts'
import { IntegrationHubSection } from './IntegrationHub'

/**
 * Sections shared by the Appwrite Reviews comparisons. The reviewer reads the
 * pull request, not deployments or production data, so the platform story is
 * about what surrounds the review: one Git connection, one Console, one bill,
 * and the same project hosting, monitoring, and paging after the merge.
 */

/* -------------------------------------------------------------------------------------------------
 * Hero: one pull request, from review to production
 * -----------------------------------------------------------------------------------------------*/

export type ReviewsLaneStep = {
  /** Stage of the pull request's life: Review, Deploy, Watch, or Page. */
  stage: string
  name: string
  status: string
  /** `included`: part of this product. `partial`: a narrower option. `other`: another vendor. */
  coverage: 'included' | 'partial' | 'other'
}

const APPWRITE_LANE: (ReviewsLaneStep & { product: ProductNavItemId })[] = [
  {
    stage: 'Review',
    name: 'Appwrite Reviews',
    status: 'Tier A · Approved',
    coverage: 'included',
    product: 'reviews',
  },
  {
    stage: 'Deploy',
    name: 'Appwrite Sites',
    status: 'Preview ready',
    coverage: 'included',
    product: 'sites',
  },
  {
    stage: 'Observe',
    name: 'Appwrite Monitors',
    status: 'OK',
    coverage: 'included',
    product: 'monitors',
  },
  {
    stage: 'Respond',
    name: 'Appwrite Beep',
    status: 'On your phone',
    coverage: 'included',
    product: 'beep',
  },
]

function LaneRow({
  step,
  icon,
  appwrite,
  delayMs,
}: {
  step: ReviewsLaneStep
  icon: ReactNode
  appwrite: boolean
  delayMs: number
}) {
  const t = useT()
  const other = step.coverage === 'other'
  return (
    <li
      className={cn(
        'product-hero-rise flex items-center gap-3 rounded-xl px-3 py-2.5',
        appwrite && 'bg-background/80 dark:bg-card/70',
        !appwrite && !other && 'border border-border bg-muted/20',
        other && 'border border-dashed border-foreground/20',
      )}
      style={riseStyle(delayMs)}
    >
      {icon}
      <span className="min-w-0 flex-1">
        <span className="block font-mono text-[9.5px] uppercase tracking-[0.16em] text-muted-foreground">
          {t(step.stage)}
        </span>
        <span
          className={cn(
            'block text-[12.5px] font-medium leading-4',
            other ? 'text-muted-foreground' : 'text-foreground',
          )}
        >
          {t(step.name)}
        </span>
      </span>
      <span
        className={cn(
          'shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[10.5px] font-medium',
          appwrite &&
            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
          !appwrite &&
            step.coverage === 'included' &&
            'bg-muted text-foreground/70',
          !appwrite &&
            step.coverage === 'partial' &&
            'bg-amber-400/15 text-amber-700 dark:text-amber-300',
          other && 'text-muted-foreground',
        )}
      >
        {t(step.status)}
      </span>
    </li>
  )
}

/**
 * The same pull request on both sides: Appwrite carries it from review to the
 * phone in one project, the other product covers the review and leaves the
 * rest to other tools.
 */
export function ReviewsLanesVisual({
  competitorName,
  competitorLane,
  competitorFooter,
}: {
  competitorName: string
  competitorLane: ReviewsLaneStep[]
  competitorFooter: string
}) {
  const t = useT()
  return (
    <ProductVisualAura className="mx-auto w-full max-w-2xl text-start">
      <ArtPanel
        delayMs={60}
        innerClassName="flex items-center gap-3 px-3.5 py-3"
      >
        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
          <GitPullRequest className="size-3.5" aria-hidden />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-baseline gap-1.5 text-[13px] font-semibold text-foreground">
            <span className="truncate">{t('Retry sign-in with backoff')}</span>
            <span
              dir="ltr"
              className="shrink-0 font-mono text-[11px] font-normal text-muted-foreground"
            >
              #482
            </span>
          </span>
          <span
            dir="ltr"
            className="block truncate font-mono text-[10.5px] text-muted-foreground"
          >
            feat/retry-sign-in → main
          </span>
        </span>
      </ArtPanel>

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <div
          className="product-hero-rise rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.06)] p-2"
          style={riseStyle(160)}
        >
          <p className="flex items-center gap-2 px-2 pb-2 pt-1 text-[12px] font-medium text-foreground">
            <AppwriteMark className="size-4" />
            {t('One Appwrite project')}
          </p>
          <ul className="space-y-1.5">
            {APPWRITE_LANE.map((step, index) => {
              const Icon = PRODUCT_NAV_REGISTRY[step.product].icon
              return (
                <LaneRow
                  key={step.stage}
                  step={step}
                  appwrite
                  delayMs={260 + index * 120}
                  icon={
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
                      <Icon className="size-4" strokeWidth={1.75} aria-hidden />
                    </span>
                  }
                />
              )
            })}
          </ul>
          <p className="px-2 pb-1 pt-2.5 text-[11px] text-muted-foreground">
            {t('One Git connection, one Console, one bill')}
          </p>
        </div>

        <div
          className="product-hero-rise rounded-2xl border border-border p-2"
          style={riseStyle(320)}
        >
          <p className="flex items-center gap-2 px-2 pb-2 pt-1 text-[12px] font-medium text-muted-foreground">
            <CompetitorMonogram name={competitorName} />
            {competitorName}
          </p>
          <ul className="space-y-1.5">
            {competitorLane.map((step, index) => (
              <LaneRow
                key={step.stage}
                step={step}
                appwrite={false}
                delayMs={420 + index * 120}
                icon={
                  <span
                    className={cn(
                      'flex size-8 shrink-0 items-center justify-center rounded-lg border',
                      step.coverage === 'other'
                        ? 'border-dashed border-foreground/20 text-muted-foreground/70'
                        : 'border-border bg-muted/40 text-muted-foreground',
                    )}
                  >
                    {step.coverage === 'other' ? (
                      <Minus className="size-4" aria-hidden />
                    ) : (
                      <Check className="size-4" aria-hidden />
                    )}
                  </span>
                }
              />
            ))}
          </ul>
          <p className="px-2 pb-1 pt-2.5 text-[11px] text-muted-foreground">
            {t(competitorFooter)}
          </p>
        </div>
      </div>
    </ProductVisualAura>
  )
}

/* -------------------------------------------------------------------------------------------------
 * The deployment comment and the review comment, from the same Git app
 * -----------------------------------------------------------------------------------------------*/

function BotHeader({ title, time }: { title: string; time: string }) {
  const t = useT()
  return (
    <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-3.5 py-2">
      <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border bg-background dark:bg-card">
        <AppwriteMark className="size-3" />
      </span>
      <span className="text-[11.5px] font-semibold text-foreground">
        appwrite
      </span>
      <span className="rounded-full border border-border px-1.5 text-[9.5px] leading-4 text-muted-foreground">
        {t('bot')}
      </span>
      <span className="text-[11px] text-muted-foreground">{t(time)}</span>
      <span className="ms-auto font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground">
        {t(title)}
      </span>
    </div>
  )
}

function CommentTable({
  columns,
  cells,
}: {
  columns: string[]
  cells: ReactNode[]
}) {
  const t = useT()
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[26rem] text-start text-[11.5px]">
        <thead>
          <tr className="border-b border-border text-[10.5px] text-muted-foreground">
            {columns.map((column) => (
              <th key={column} className="px-3 py-1.5 text-start font-medium">
                {t(column)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          <tr>
            {cells.map((cell, index) => (
              <td
                key={index}
                className="px-3 py-2 align-middle text-foreground"
              >
                {cell}
              </td>
            ))}
          </tr>
        </tbody>
      </table>
    </div>
  )
}

function LinkText({ children }: { children: ReactNode }) {
  return (
    <span className="text-[var(--tone-ink)] underline decoration-[rgb(var(--tone-rgb)/0.4)] underline-offset-2">
      {children}
    </span>
  )
}

/** The pull request conversation: Appwrite's deployment comment, then its review, laid out the same way. */
export function ReviewAndDeployVisual() {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-3xl space-y-4 text-start">
      <ArtPanel delayMs={60} innerClassName="overflow-hidden p-0">
        <BotHeader title="Deployments" time="2 min ago" />
        <CommentTable
          columns={['Site', 'Commit', 'Status', 'Preview', 'Details']}
          cells={[
            <span key="site" dir="ltr" className="font-semibold">
              storefront
            </span>,
            <code
              key="commit"
              dir="ltr"
              className="font-mono text-[11px] text-muted-foreground"
            >
              a1b2c3d
            </code>,
            <span key="status" className="inline-flex items-center gap-1.5">
              <span
                className="size-1.5 rounded-full bg-emerald-500"
                aria-hidden
              />
              {t('Ready')}
            </span>,
            <LinkText key="preview">{t('Preview URL')}</LinkText>,
            <LinkText key="details">{t('View logs')}</LinkText>,
          ]}
        />
      </ArtPanel>

      <ArtPanel
        delayMs={260}
        innerClassName="overflow-hidden border-[rgb(var(--tone-rgb)/0.45)] p-0 dark:border-[rgb(var(--tone-rgb)/0.45)]"
      >
        <BotHeader title="Code review" time="1 min ago" />
        <CommentTable
          columns={['Commit', 'Status', 'Tier', 'Verdict', 'Details']}
          cells={[
            <code
              key="commit"
              dir="ltr"
              className="font-mono text-[11px] text-muted-foreground"
            >
              a1b2c3d
            </code>,
            t('Completed'),
            <span key="tier" className="inline-flex items-center gap-1.5">
              <span className="flex size-4 items-center justify-center rounded bg-emerald-500/15 font-mono text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                A
              </span>
              <span className="text-muted-foreground">
                {t('Mergeable after minor fixes')}
              </span>
            </span>,
            <span key="verdict" className="inline-flex items-center gap-1.5">
              <Check
                className="size-3.5 text-emerald-600 dark:text-emerald-400"
                aria-hidden
              />
              {t('Approved')}
            </span>,
            <LinkText key="details">{t('View review')}</LinkText>,
          ]}
        />
        <div className="space-y-2 border-t border-border px-3.5 py-3">
          <p className="text-[12px] leading-5 text-foreground/90">
            {t(
              'Adds exponential backoff to sign-in retries and stops after five attempts. One minor finding: the new retry path has no test yet.',
            )}
          </p>
          <div className="flex flex-wrap gap-1.5">
            {['Findings (1)', 'Walkthrough', 'Fix with agent prompt'].map(
              (section) => (
                <span
                  key={section}
                  className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-[10.5px] text-muted-foreground"
                >
                  <span aria-hidden>▸</span>
                  {t(section)}
                </span>
              ),
            )}
          </div>
        </div>
      </ArtPanel>
    </div>
  )
}

export function ReviewAndDeploySection({
  description,
}: {
  description: string
}) {
  return (
    <ComparisonSection
      backdrop={
        <div
          className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70"
          aria-hidden
        />
      }
    >
      <ComparisonHeading
        align="center"
        eyebrow="Same Git connection"
        title="Your preview and your review, side by side"
        description={description}
      />
      <div className="mt-12">
        <ReviewAndDeployVisual />
      </div>
    </ComparisonSection>
  )
}

/* -------------------------------------------------------------------------------------------------
 * What every review includes
 * -----------------------------------------------------------------------------------------------*/

const REVIEW_FEATURES: {
  title: string
  description: string
  icon: LucideIcon
}[] = [
  {
    title: 'Summary and walkthrough',
    description:
      'One comment per pull request, updated on every review, with what changed, what is still open, and what got fixed.',
    icon: FileText,
  },
  {
    title: 'Findings on the lines',
    description:
      'Critical, major, minor, or info, with a category, a suggested change when the fix is certain, and a prompt for your coding agent.',
    icon: MessageSquareWarning,
  },
  {
    title: 'Checked before posting',
    description:
      'A second model verifies every finding against the code before it is posted, and broken suggestions are removed.',
    icon: ShieldCheck,
  },
  {
    title: 'A grade and a verdict',
    description:
      'Merge confidence from S to F, and approve, request changes, or comment, decided by code from the findings.',
    icon: Trophy,
  },
  {
    title: 'Learns your rules',
    description:
      'Reply with a lasting preference and the reviewer saves a learning that later reviews of the repository follow.',
    icon: BookMarked,
  },
  {
    title: 'Three strengths',
    description:
      'Basic reads the code, Normal runs commands in a sandbox to confirm a bug, and Expert splits the work across subagents.',
    icon: SlidersHorizontal,
  },
]

export function EveryReviewSection({
  title,
  description,
}: {
  title: string
  description: string
}) {
  const t = useT()
  return (
    <ComparisonSection
      backdrop={
        <div
          className="pointer-events-none absolute inset-0 z-0 overflow-hidden"
          aria-hidden
        >
          <div className="product-tone2-glow absolute -end-[25%] top-1/2 h-[620px] w-[900px] -translate-y-1/2" />
        </div>
      }
    >
      <ComparisonHeading
        eyebrow="Appwrite Reviews"
        title={title}
        description={description}
      />
      <div className="mt-12 grid gap-x-10 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
        {REVIEW_FEATURES.map((feature, index) => {
          const Icon = feature.icon
          return (
            <article
              key={feature.title}
              className="product-hero-rise border-t border-foreground/15 pt-6"
              style={riseStyle(80 + index * 80)}
            >
              <Icon
                className="size-5 text-[var(--tone-ink)]"
                strokeWidth={1.75}
                aria-hidden
              />
              <h3 className="mt-4 text-[15px] font-medium text-foreground">
                {t(feature.title)}
              </h3>
              <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                {t(feature.description)}
              </p>
            </article>
          )
        })}
      </div>
    </ComparisonSection>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Two columns: the other product's way, then Appwrite's
 * -----------------------------------------------------------------------------------------------*/

export type ContrastRow = { title: string; detail: string }

function ContrastColumn({
  heading,
  rows,
  appwrite,
  delayMs,
}: {
  heading: ReactNode
  rows: ContrastRow[]
  appwrite: boolean
  delayMs: number
}) {
  const t = useT()
  return (
    <div className="product-hero-rise min-w-0" style={riseStyle(delayMs)}>
      <p
        className={cn(
          'flex items-center gap-2 border-b pb-3 text-[13px] font-medium',
          appwrite
            ? 'border-[var(--tone-ink)] text-foreground'
            : 'border-border text-muted-foreground',
        )}
      >
        {heading}
      </p>
      <ul className="divide-y divide-border">
        {rows.map((row, index) => (
          <li
            key={row.title}
            className="product-hero-rise flex gap-3 py-3.5"
            style={riseStyle(delayMs + 120 + index * 90)}
          >
            <span
              className={cn(
                'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md',
                appwrite
                  ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                  : 'border border-border bg-muted/40 text-muted-foreground',
              )}
            >
              <CircleDot className="size-3" aria-hidden />
            </span>
            <span className="min-w-0">
              <span className="block text-[13.5px] font-medium text-foreground">
                {t(row.title)}
              </span>
              <span className="mt-0.5 block text-[12.5px] leading-5 text-muted-foreground">
                {t(row.detail)}
              </span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function ContrastSection({
  eyebrow,
  title,
  description,
  competitorName,
  competitorRows,
  appwriteLabel,
  appwriteRows,
  footnote,
}: {
  eyebrow: string
  title: string
  description: string
  competitorName: string
  competitorRows: ContrastRow[]
  appwriteLabel: string
  appwriteRows: ContrastRow[]
  footnote?: string
}) {
  const t = useT()
  return (
    <ComparisonSection
      backdrop={
        <div
          className="pointer-events-none absolute inset-0 z-0 overflow-hidden"
          aria-hidden
        >
          <div className="product-tone-glow absolute -start-[25%] top-1/2 h-[600px] w-[900px] -translate-y-1/2 opacity-70" />
        </div>
      }
    >
      <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.4fr)] lg:gap-16">
        <ComparisonHeading
          eyebrow={eyebrow}
          title={title}
          description={description}
        />
        <div className="min-w-0">
          <div className="grid gap-8 sm:grid-cols-2">
            <ContrastColumn
              heading={
                <>
                  <CompetitorMonogram name={competitorName} />
                  {competitorName}
                </>
              }
              rows={competitorRows}
              appwrite={false}
              delayMs={120}
            />
            <ContrastColumn
              heading={
                <>
                  <AppwriteMark className="size-4" />
                  {t(appwriteLabel)}
                </>
              }
              rows={appwriteRows}
              appwrite
              delayMs={300}
            />
          </div>
          {footnote ? (
            <p className="mt-6 text-[11.5px] leading-5 text-muted-foreground">
              {t(footnote)}
            </p>
          ) : null}
        </div>
      </div>
    </ComparisonSection>
  )
}

/** How Appwrite Reviews is billed, from the Cloud plans. */
export const APPWRITE_REVIEW_BILLING: ContrastRow[] = [
  {
    title: 'Credits from your Appwrite plan',
    detail: '10, 50, or 500 credits per review, by strength. No seats.',
  },
  {
    title: '100 credits a month on Free',
    detail: 'Shared by the organization, about two Normal reviews.',
  },
  {
    title: '2,000 credits a month on Pro',
    detail: 'For each project, then $1 per 100 credits up to your budget cap.',
  },
  {
    title: 'Answers are free',
    detail:
      'Chat replies cost nothing, and failed or skipped reviews are not billed.',
  },
]

/* -------------------------------------------------------------------------------------------------
 * The products around a review
 * -----------------------------------------------------------------------------------------------*/

export function ReviewsHubSection({
  title,
  description,
}: {
  title: string
  description: string
}) {
  return (
    <IntegrationHubSection
      focus="reviews"
      focusLabel="Appwrite Reviews"
      title={title}
      description={description}
      items={[
        {
          id: 'sites',
          title: 'Sites',
          description:
            'Review pull requests is on when you create a site from a repository.',
        },
        {
          id: 'functions',
          title: 'Functions',
          description:
            'The same Git connection deploys and reviews each function.',
        },
        {
          id: 'analytics',
          title: 'Analytics',
          description:
            'See how a release changed traffic on the site you merged.',
        },
        {
          id: 'monitors',
          title: 'Monitors',
          description:
            'Watch usage and endpoints in production after you merge.',
        },
        {
          id: 'beep',
          title: 'Beep',
          description:
            'Alerts reach your phone, with escalations until someone acknowledges.',
        },
        {
          id: 'firewall',
          title: 'Firewall',
          description: 'Rate limit or block the traffic a release attracts.',
        },
      ]}
    />
  )
}
