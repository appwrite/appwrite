import { Link } from '@tanstack/react-router'
import type { CSSProperties, ReactNode } from 'react'
import { Fragment } from 'react'
import { CompetitorMonogram } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { SECRET_SUSPECTS } from '@/lib/campaigns/secret/content'
import type { SecretSuspect } from '@/lib/campaigns/secret/content'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** The page's conversion action. Same shape as the site's primary CTA, always naming Appwrite. */
export function TryAppwriteButton({
  size = 'lg',
  analytics = 'secret-start-building',
  className,
}: {
  size?: 'lg' | 'md'
  analytics?: 'secret-start-building' | 'secret-sticky-start-building'
  className?: string
}) {
  const t = useT()
  return (
    <Button
      variant="brandCta"
      size="lg"
      className={cn(size === 'lg' ? 'h-10 text-[14px]' : 'h-9 px-4 text-[13px]', className)}
      asChild
    >
      <Link to="/sign-up" search={{ redirect: '/' }} {...analyticsAttrs(analytics)}>
        {t('Try Appwrite for free')}
      </Link>
    </Button>
  )
}

/** Secondary action in the site's outline style. */
export const SECRET_OUTLINE_BUTTON_CLASS = 'h-10 bg-background/60 text-[14px]'

/** Neutral label for the other platform: monogram plus name. We never draw third-party logos. */
export function SuspectLabel({ suspect, className }: { suspect: SecretSuspect; className?: string }) {
  const name = SECRET_SUSPECTS[suspect].name
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <CompetitorMonogram name={name} />
      {name}
    </span>
  )
}

/** A sentence headline in the site's style: the closing period gives way to the brand underscore. */
export function BrandTitle({ children }: { children: string }) {
  return (
    <>
      {children.replace(/[.。]\s*$/, '')}
      <span className="text-[var(--brand-cta)]">_</span>
    </>
  )
}

/** "Supabase and Firebase" as plain text, for headlines. */
export function SuspectNames({ suspects }: { suspects: SecretSuspect[] }) {
  const t = useT()
  return (
    <>
      {suspects.map((suspect, index) => (
        <Fragment key={suspect}>
          {index > 0 ? ` ${t('and')} ` : null}
          {SECRET_SUSPECTS[suspect].name}
        </Fragment>
      ))}
    </>
  )
}

/** Brand-pink stamp that lands with a small bounce. Inside a `data-declassified` scope it waits for the reveal. */
export function Stamp({
  children,
  className,
  delayMs = 0,
  rotate = -6,
  size = 'md',
}: {
  children: ReactNode
  className?: string
  delayMs?: number
  rotate?: number
  size?: 'sm' | 'md' | 'lg'
}) {
  return (
    <span
      className={cn(
        'secret-stamp pointer-events-none inline-flex select-none items-center whitespace-nowrap rounded-md border-[var(--brand-cta)] bg-background/70 font-mono font-medium uppercase leading-none text-[var(--brand-cta)] backdrop-blur-sm',
        size === 'sm' && 'border px-2 py-1 text-[10px] tracking-[0.18em]',
        size === 'md' && 'border-[1.5px] px-2.5 py-1.5 text-[11px] tracking-[0.18em]',
        size === 'lg' && 'border-2 px-3 py-2 text-[clamp(11px,1.7cqw,16px)] tracking-[0.18em]',
        className,
      )}
      style={{ '--stamp-delay': `${delayMs}ms`, '--stamp-rotate': `${rotate}deg` } as CSSProperties}
    >
      {children}
    </span>
  )
}

/** Hand-drawn red marker stroke from the ad creative, drawn in under the hero headline. */
export function MarkerUnderline({ className, delayMs = 700 }: { className?: string; delayMs?: number }) {
  return (
    <svg
      viewBox="0 0 300 24"
      preserveAspectRatio="none"
      className={cn(
        'pointer-events-none absolute -start-[3%] -bottom-[0.16em] h-[0.24em] w-[106%] overflow-visible',
        className,
      )}
      aria-hidden
    >
      <path
        d="M4 13 C 52 7, 104 15, 158 10 S 252 6, 296 11"
        pathLength={1}
        fill="none"
        stroke="#ff4d55"
        strokeWidth={11}
        strokeLinecap="round"
        className="secret-brush"
        style={{ '--brush-delay': `${delayMs}ms` } as CSSProperties}
      />
      <path
        d="M10 17 C 70 13, 140 19, 210 14 S 268 13, 292 15"
        pathLength={1}
        fill="none"
        stroke="#ff4d55"
        strokeOpacity={0.7}
        strokeWidth={5}
        strokeLinecap="round"
        className="secret-brush"
        style={{ '--brush-delay': `${delayMs + 90}ms` } as CSSProperties}
      />
      <path
        d="M30 8 C 90 5, 170 9, 240 6"
        pathLength={1}
        fill="none"
        stroke="#ff4d55"
        strokeOpacity={0.45}
        strokeWidth={2.5}
        strokeLinecap="round"
        className="secret-brush"
        style={{ '--brush-delay': `${delayMs + 160}ms` } as CSSProperties}
      />
    </svg>
  )
}

/** Text under a redaction bar until its `data-declassified` scope flips to true. */
export function Redacted({ children, delayMs = 0 }: { children: ReactNode; delayMs?: number }) {
  return (
    <span className="secret-redacted" style={{ '--redact-delay': `${delayMs}ms` } as CSSProperties}>
      {children}
    </span>
  )
}
