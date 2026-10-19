import type { LucideIcon } from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import { syntax } from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

/**
 * Building blocks for open product page art (heroes and feature visuals).
 * Compose floating pieces over the section aura instead of boxing everything in one card.
 * Entrance animations (`product-hero-rise`, `-fill`, `-type`) wait until the section is in view.
 */

export function riseStyle(delayMs: number, extra?: CSSProperties): CSSProperties {
  return { '--rise-delay': `${delayMs}ms`, ...extra } as CSSProperties
}

export function floatStyle(delayMs: number): CSSProperties {
  return { '--float-delay': `${delayMs}ms` } as CSSProperties
}

const floatingSurfaceClassName =
  'border border-border bg-background/95 shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] dark:border-white/10 dark:bg-[color-mix(in_srgb,var(--card)_92%,white_4%)]'

/** Mock app window with traffic-light dots. Use only when the visual is genuinely a window (editor, terminal, browser). */
export function ArtWindow({
  title,
  trailing,
  children,
  className,
  bodyClassName,
  style,
}: {
  title?: ReactNode
  trailing?: ReactNode
  children: ReactNode
  className?: string
  bodyClassName?: string
  style?: CSSProperties
}) {
  return (
    <div
      className={cn(
        'product-tone-shadow overflow-hidden rounded-xl border border-border bg-background dark:bg-card',
        className,
      )}
      style={style}
    >
      <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-3 py-2">
        <div className="flex shrink-0 gap-1.5" aria-hidden>
          <span className="size-2 rounded-full bg-foreground/15" />
          <span className="size-2 rounded-full bg-foreground/15" />
          <span className="size-2 rounded-full bg-foreground/15" />
        </div>
        {title ? (
          <div className="ms-1 min-w-0 truncate text-[11px] font-medium text-muted-foreground">
            {title}
          </div>
        ) : null}
        {trailing ? <div className="ms-auto shrink-0">{trailing}</div> : null}
      </div>
      <div className={cn('p-4', bodyClassName)}>{children}</div>
    </div>
  )
}

/** Floating surface in normal flow (or positioned via className). Rises in, optionally bobs. */
export function ArtPanel({
  children,
  className,
  innerClassName,
  delayMs = 0,
  float = false,
  floatDelayMs = 0,
}: {
  children: ReactNode
  className?: string
  innerClassName?: string
  delayMs?: number
  float?: boolean
  floatDelayMs?: number
}) {
  return (
    <div className={cn('product-hero-rise', className)} style={riseStyle(delayMs)}>
      <div
        className={cn(
          'rounded-xl p-3',
          floatingSurfaceClassName,
          float && 'product-hero-float',
          innerClassName,
        )}
        style={float ? floatStyle(floatDelayMs) : undefined}
      >
        {children}
      </div>
    </div>
  )
}

/** Small absolutely positioned floating chip around a centerpiece. */
export function ArtChip({
  children,
  className,
  delayMs = 0,
  floatDelayMs = 0,
}: {
  children: ReactNode
  className?: string
  delayMs?: number
  floatDelayMs?: number
}) {
  return (
    <div className={cn('product-hero-rise absolute z-[2]', className)} style={riseStyle(delayMs)}>
      <div
        className={cn('product-hero-float rounded-lg px-2.5 py-2', floatingSurfaceClassName)}
        style={floatStyle(floatDelayMs)}
      >
        {children}
      </div>
    </div>
  )
}

/** Tone-tinted square holding a small icon (not for product logos). */
export function ArtIconBadge({
  icon: Icon,
  tone = 'primary',
  className,
}: {
  icon: LucideIcon
  tone?: 'primary' | 'secondary' | 'neutral' | 'success'
  className?: string
}) {
  return (
    <span
      className={cn(
        'flex size-7 shrink-0 items-center justify-center rounded-md',
        tone === 'primary' && 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]',
        tone === 'secondary' && 'bg-[rgb(var(--tone2-rgb)/0.18)] text-foreground',
        tone === 'neutral' && 'border border-border bg-muted/40 text-muted-foreground',
        tone === 'success' && 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        className,
      )}
    >
      <Icon className="size-3.5" aria-hidden />
    </span>
  )
}

/** Dashed connector. Horizontal by default; travelling dot is optional. */
export function ArtConnector({
  orientation = 'horizontal',
  travel = false,
  travelDelayMs = 0,
  className,
}: {
  orientation?: 'horizontal' | 'vertical'
  travel?: boolean
  travelDelayMs?: number
  className?: string
}) {
  const horizontal = orientation === 'horizontal'
  if (!horizontal) {
    return (
      <div
        className={cn('relative h-full w-0 border-s border-dashed border-foreground/20', className)}
        aria-hidden
      />
    )
  }

  // The track is zero-height so absolutely positioned connectors stay on `top-*`.
  // The stroke and the travelling dot are both centered on that edge. A border on
  // the track itself sits on the padding edge, which drops the dot off the dashes.
  return (
    <div className={cn('relative h-0 w-full', className)} aria-hidden>
      <div className="absolute inset-x-0 top-0 -translate-y-1/2 border-t border-dashed border-foreground/20" />
      {travel ? (
        <span
          className="product-hero-travel absolute start-0 top-0 size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--tone-ink)] opacity-0 shadow-[0_0_10px_rgb(var(--tone-rgb))]"
          style={{ '--travel-delay': `${travelDelayMs}ms` } as CSSProperties}
        />
      ) : null}
    </div>
  )
}

/** Always-colored syntax token for code snippets (unlike the bento `Syn`, which waits for hover). */
export function ArtToken({ tone, children }: { tone: keyof typeof syntax; children: ReactNode }) {
  return <span className={syntax[tone]}>{children}</span>
}

export function ArtLiveDot({ className }: { className?: string }) {
  return (
    <span className={cn('relative flex size-2', className)} aria-hidden>
      <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/60 motion-reduce:animate-none" />
      <span className="relative inline-flex size-full rounded-full bg-emerald-500" />
    </span>
  )
}
