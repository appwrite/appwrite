import type { ProductIcon } from '@/lib/products/types'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * Ambient glows and dot grid tinted by the nearest `[data-product-tone]` ancestor.
 * Radial gradients only (no CSS blur) to keep scrolling cheap.
 */
export function ProductToneBackdrop({
  variant,
  side = 'end',
  className,
}: {
  variant: 'hero' | 'section' | 'cta'
  side?: 'start' | 'end'
  className?: string
}) {
  return (
    <div
      className={cn('pointer-events-none absolute inset-0 z-0 overflow-hidden', className)}
      aria-hidden
    >
      {variant === 'hero' ? (
        <>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-80" />
          <div className="product-tone-glow absolute -start-[30%] -top-[40%] h-[720px] w-[1100px] sm:-start-[20%]" />
          <div className="product-tone2-glow absolute -end-[35%] bottom-[-45%] h-[720px] w-[1100px] sm:-end-[25%]" />
        </>
      ) : null}
      {variant === 'section' ? (
        <div
          className={cn(
            'product-tone-glow absolute top-1/2 h-[560px] w-[900px] -translate-y-1/2 opacity-70',
            side === 'end' ? '-end-[30%]' : '-start-[30%]',
          )}
        />
      ) : null}
      {variant === 'cta' ? (
        <>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0" />
          <div className="product-tone-glow absolute -top-[60%] left-1/2 h-[560px] w-[min(1100px,160%)] -translate-x-1/2" />
          <div className="product-tone2-glow absolute -bottom-[70%] left-1/2 h-[560px] w-[min(1100px,160%)] -translate-x-1/2" />
        </>
      ) : null}
    </div>
  )
}

/** Open, borderless light behind feature visuals: a tone glow and fading dots, no frame. */
export function ProductVisualAura({
  children,
  side = 'end',
  className,
}: {
  children: ReactNode
  side?: 'start' | 'end'
  className?: string
}) {
  return (
    <div className={cn('relative isolate', className)}>
      <div
        className="pointer-events-none absolute -inset-x-4 -inset-y-10 -z-10 sm:-inset-x-10"
        aria-hidden
      >
        <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
        <div
          className={cn(
            'product-tone-glow absolute top-1/2 h-[110%] w-[110%] -translate-y-1/2',
            side === 'end' ? '-end-[15%]' : '-start-[15%]',
          )}
        />
        <div className="product-tone2-glow absolute bottom-[-20%] left-1/2 h-[70%] w-[80%] -translate-x-1/2 opacity-70" />
      </div>
      {children}
    </div>
  )
}

/** Product logos stay neutral on every page; only surrounding surfaces take the product tone. */
export function ProductIconTile({
  icon: Icon,
  size = 'md',
  className,
}: {
  icon: ProductIcon
  size?: 'sm' | 'md' | 'lg'
  className?: string
}) {
  return (
    <span
      className={cn(
        'relative flex shrink-0 items-center justify-center border border-border bg-gradient-to-b from-card to-muted/40 text-muted-foreground shadow-sm dark:from-muted/30 dark:to-background',
        size === 'sm' && 'size-8 rounded-lg',
        size === 'md' && 'size-10 rounded-xl',
        size === 'lg' && 'size-14 rounded-2xl',
        className,
      )}
    >
      <Icon
        className={cn(size === 'sm' ? 'size-4' : size === 'md' ? 'size-[18px]' : 'size-6')}
        strokeWidth={1.75}
        aria-hidden
      />
    </span>
  )
}
