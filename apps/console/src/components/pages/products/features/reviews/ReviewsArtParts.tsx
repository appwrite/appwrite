import { GitPullRequest, GitPullRequestArrow } from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import { ArtAppwriteMark } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * Drawn pieces of a pull request for the Reviews page: the PR header, diff
 * lines, and the reviewer's inline finding. Severities, verdicts, and grades
 * use the Console's own names (see the project Reviews pages).
 */

export const REVIEWS_GIT_PROVIDERS = [
  { id: 'github', name: 'GitHub', icon: '/icons/github.svg' },
  { id: 'gitlab', name: 'GitLab', icon: '/icons/gitlab.svg' },
  { id: 'bitbucket', name: 'Bitbucket', icon: '/icons/bitbucket.svg' },
] as const

export function ReviewsProviderMark({
  src,
  className,
}: {
  src: string
  className?: string
}) {
  return (
    <ProductFeaturePublicIcon src={src} className={cn('size-3.5', className)} />
  )
}

export type ReviewsSeverity = 'critical' | 'major' | 'minor' | 'info'

export const REVIEWS_SEVERITY_LABEL: Record<ReviewsSeverity, string> = {
  critical: 'Critical',
  major: 'Major',
  minor: 'Minor',
  info: 'Info',
}

export const REVIEWS_SEVERITY_CLASS: Record<
  ReviewsSeverity,
  { pill: string; dot: string }
> = {
  critical: {
    pill: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    dot: 'bg-rose-500',
  },
  major: {
    pill: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
    dot: 'bg-orange-500',
  },
  minor: {
    pill: 'bg-amber-400/20 text-amber-700 dark:text-amber-300',
    dot: 'bg-amber-400',
  },
  info: {
    pill: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    dot: 'bg-sky-500',
  },
}

export function ReviewsSeverityPill({
  severity,
  className,
}: {
  severity: ReviewsSeverity
  className?: string
}) {
  const t = useT()
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-[5px] px-1.5 py-px text-[10px] font-semibold leading-[15px]',
        REVIEWS_SEVERITY_CLASS[severity].pill,
        className,
      )}
    >
      {t(REVIEWS_SEVERITY_LABEL[severity])}
    </span>
  )
}

/** Merge confidence from S (best) to F, colored by grade like the Console badge. */
export function ReviewsGrade({
  grade,
  className,
}: {
  grade: string
  className?: string
}) {
  const good = grade === 'S' || grade === 'A'
  const middling = grade === 'B' || grade === 'C'
  return (
    <span
      className={cn(
        'inline-flex size-5 shrink-0 items-center justify-center rounded-md font-mono text-[11px] font-semibold',
        good && 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        middling && 'bg-amber-400/20 text-amber-700 dark:text-amber-300',
        !good && !middling && 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        className,
      )}
    >
      {grade}
    </span>
  )
}

/** Title row of a pull request: state icon, title, number, and branches. */
export function ReviewsPrHeader({
  title,
  number,
  head,
  base = 'main',
  merged = false,
  trailing,
  className,
}: {
  title: string
  number: number
  head: string
  base?: string
  merged?: boolean
  trailing?: ReactNode
  className?: string
}) {
  const t = useT()
  const Icon = merged ? GitPullRequestArrow : GitPullRequest
  return (
    <div className={cn('flex items-start gap-2.5', className)}>
      <span
        className={cn(
          'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full',
          merged
            ? 'bg-violet-500/15 text-violet-600 dark:text-violet-400'
            : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        )}
      >
        <Icon className="size-3.5" aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="flex items-baseline gap-1.5 text-[13px] font-semibold text-foreground">
          <span className="min-w-0 truncate">{t(title)}</span>
          <span
            dir="ltr"
            className="shrink-0 font-mono text-[11px] font-normal text-muted-foreground"
          >
            #{number}
          </span>
        </p>
        <p
          dir="ltr"
          className="mt-0.5 truncate font-mono text-[10.5px] text-muted-foreground"
        >
          {head} → {base}
        </p>
      </div>
      {trailing ? <div className="shrink-0">{trailing}</div> : null}
    </div>
  )
}

export type ReviewsDiffLine = {
  kind: 'add' | 'remove' | 'context'
  /** Line number in the new file (or the old one for removals). */
  number: number
  code: ReactNode
  /** Draws the line as the one a finding points at. */
  marked?: boolean
}

/** A few lines of a diff with gutters, monospaced and always left to right. */
export function ReviewsDiff({
  file,
  lines,
  className,
  children,
}: {
  file?: string
  lines: ReviewsDiffLine[]
  className?: string
  /** Rendered under the lines, inside the frame (an inline finding). */
  children?: ReactNode
}) {
  return (
    <div
      dir="ltr"
      className={cn(
        'overflow-hidden rounded-lg border border-border bg-background text-start dark:bg-card',
        className,
      )}
    >
      {file ? (
        <p className="border-b border-border bg-muted/30 px-3 py-1.5 font-mono text-[10.5px] text-muted-foreground">
          {file}
        </p>
      ) : null}
      <div className="py-1 font-mono text-[11px] leading-[19px]">
        {lines.map((line, index) => (
          <div
            key={index}
            className={cn(
              'grid grid-cols-[2.25rem_1rem_minmax(0,1fr)] pe-3',
              line.kind === 'add' && 'bg-emerald-500/[0.09]',
              line.kind === 'remove' && 'bg-rose-500/[0.08]',
              line.marked && 'shadow-[inset_2px_0_0_rgb(var(--tone-rgb))]',
            )}
          >
            <span className="select-none pe-2 text-end tabular-nums text-muted-foreground/60">
              {line.number}
            </span>
            <span
              className={cn(
                'select-none',
                line.kind === 'add' && 'text-emerald-600 dark:text-emerald-400',
                line.kind === 'remove' && 'text-rose-600 dark:text-rose-400',
              )}
            >
              {line.kind === 'add' ? '+' : line.kind === 'remove' ? '-' : ' '}
            </span>
            <span className="min-w-0 truncate whitespace-pre text-foreground/90">
              {line.code}
            </span>
          </div>
        ))}
      </div>
      {children}
    </div>
  )
}

/** The reviewer as it appears on the pull request: the Appwrite Git app that also posts deployments. */
export function ReviewsBotName({
  className,
  meta,
}: {
  className?: string
  meta?: ReactNode
}) {
  const t = useT()
  return (
    <p className={cn('flex items-center gap-2 text-[11.5px]', className)}>
      <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border bg-background dark:bg-card">
        <ArtAppwriteMark className="size-4" />
      </span>
      <span dir="ltr" className="font-semibold text-foreground">
        appwrite
      </span>
      <span className="rounded-full border border-border px-1.5 text-[9.5px] leading-4 text-muted-foreground">
        {t('bot')}
      </span>
      {meta ? (
        <span className="ms-auto text-[10.5px] text-muted-foreground">
          {meta}
        </span>
      ) : null}
    </p>
  )
}

/** A comment on the pull request timeline: author row, then the body. */
export function ReviewsComment({
  children,
  meta,
  author,
  className,
  style,
}: {
  children: ReactNode
  meta?: ReactNode
  /** Defaults to the Appwrite bot. */
  author?: ReactNode
  className?: string
  style?: CSSProperties
}) {
  return (
    <div
      className={cn(
        'rounded-xl border border-border bg-background p-3 text-start dark:bg-card',
        className,
      )}
      style={style}
    >
      {author ?? <ReviewsBotName meta={meta} />}
      <div className="mt-2.5">{children}</div>
    </div>
  )
}

/** A person on the pull request: initials avatar and login. */
export function ReviewsPerson({
  login,
  meta,
  className,
}: {
  login: string
  meta?: ReactNode
  className?: string
}) {
  return (
    <p className={cn('flex items-center gap-2 text-[11.5px]', className)}>
      <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone2-rgb)/0.3)] text-[9px] font-semibold uppercase text-foreground">
        {login.charAt(0)}
      </span>
      <span dir="ltr" className="font-semibold text-foreground">
        {login}
      </span>
      {meta ? (
        <span className="ms-auto text-[10.5px] text-muted-foreground">
          {meta}
        </span>
      ) : null}
    </p>
  )
}

/** Merge confidence tiers, from the review service. */
export const REVIEWS_TIERS = [
  { grade: 'S', meaning: 'Ready to merge' },
  { grade: 'A', meaning: 'Mergeable after minor fixes' },
  { grade: 'B', meaning: 'Needs changes before merging' },
  { grade: 'C', meaning: 'Significant problems' },
  { grade: 'D', meaning: 'Serious problems' },
  { grade: 'F', meaning: 'Do not merge' },
] as const

/** An inline finding: severity, the problem in one sentence, and optionally a suggested change. */
export function ReviewsFinding({
  severity,
  category,
  title,
  body,
  suggestion,
  footer,
  className,
  style,
}: {
  severity: ReviewsSeverity
  category?: string
  title: string
  body?: string
  suggestion?: ReactNode
  footer?: ReactNode
  className?: string
  style?: CSSProperties
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'rounded-xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background p-3 text-start shadow-[0_16px_40px_-24px_rgb(0_0_0/0.4)] dark:bg-card',
        className,
      )}
      style={style}
    >
      <ReviewsBotName />
      <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
        <ReviewsSeverityPill severity={severity} />
        {category ? (
          <span className="rounded-[5px] border border-border px-1.5 text-[10px] leading-[15px] text-muted-foreground">
            {t(category)}
          </span>
        ) : null}
      </div>
      <p className="mt-2 text-[12.5px] font-medium leading-5 text-foreground">
        {t(title)}
      </p>
      {body ? (
        <p className="mt-1 text-[11.5px] leading-[18px] text-muted-foreground">
          {t(body)}
        </p>
      ) : null}
      {suggestion ? <div className="mt-2.5">{suggestion}</div> : null}
      {footer ? (
        <div className="mt-2.5 border-t border-border/70 pt-2.5">{footer}</div>
      ) : null}
    </div>
  )
}
