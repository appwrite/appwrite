import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * An Init 5 day picture drawn like the blog's covers: Appwrite's dark ground
 * with violet light from above and pink rising from below, a short headline
 * ending in the pink underscore, and a simplified product UI that
 * runs off the edge. Dark in both themes, like the covers themselves; sizes
 * follow the frame, so it reads the same on a phone and on a wide card.
 */
export function InitDayCover({
  headline,
  children,
  mockClassName,
}: {
  headline: string
  children: ReactNode
  /** Where the product UI sits; it should run off the right or bottom edge. */
  mockClassName?: string
}) {
  return (
    <div className="dark @container absolute inset-0 overflow-hidden bg-[#19191c] text-foreground">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-[45%] end-[-15%] h-[100%] w-[75%] rounded-full bg-[radial-gradient(closest-side,rgb(124_103_254/0.32),transparent)]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-[60%] start-[0%] h-[100%] w-[85%] rounded-full bg-[radial-gradient(closest-side,rgb(253_54_110/0.34),transparent)] transition-opacity duration-500 group-hover:opacity-100 [opacity:0.85]"
      />
      <p className="absolute start-[5cqw] top-1/2 w-[40cqw] -translate-y-1/2 font-aeonik-pro text-[6.6cqw] leading-[1.06] tracking-tight text-white">
        {headline}
        <span className="text-[var(--brand-cta)]">_</span>
      </p>
      <div
        className={cn(
          'absolute start-[50cqw] top-[16cqw] w-[58cqw] text-[2.9cqw] transition-transform duration-500 ease-out group-hover:-translate-y-[1.5cqw] motion-reduce:group-hover:translate-y-0',
          mockClassName,
        )}
      >
        {children}
      </div>
    </div>
  )
}

/** A cover's card: the blog covers' raised dark panel. */
export function CoverCard({
  className,
  children,
}: {
  className?: string
  children: ReactNode
}) {
  return (
    <div
      className={cn(
        'rounded-[1.6cqw] border border-white/10 bg-[#232326] shadow-[0_2cqw_6cqw_rgb(0_0_0/0.35)]',
        className,
      )}
    >
      {children}
    </div>
  )
}

/** Placeholder text, as the covers draw it: a soft grey bar. */
export function CoverLine({ className }: { className?: string }) {
  return (
    <span
      aria-hidden
      className={cn('block h-[1.2cqw] rounded-full bg-white/12', className)}
    />
  )
}
