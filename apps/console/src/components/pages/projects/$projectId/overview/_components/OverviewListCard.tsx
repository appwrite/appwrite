import type { ComponentType, HTMLAttributes, ReactNode, Ref } from 'react'
import { Link, type LinkProps } from '@tanstack/react-router'
import { ArrowRight, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs, type AnalyticsActionId } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'
import { RESOURCE_CARD_BASE_CLASSNAME } from '../../shared/ResourceCard'

/**
 * Dashboard list cards share one row height, so rows in cards placed side by
 * side line up, and one body height per row of cards.
 */
export const OVERVIEW_LIST_ROW_CLASSNAME = 'h-14'
const OVERVIEW_LIST_ROW_HEIGHT = 56
const OVERVIEW_LIST_ROW_GAP = 4
const OVERVIEW_LIST_BODY_PADDING = 12

/** Body height that fits `rows` rows, for cards placed side by side. */
export function overviewListBodyHeight(rows: number): number {
  return (
    rows * OVERVIEW_LIST_ROW_HEIGHT +
    Math.max(0, rows - 1) * OVERVIEW_LIST_ROW_GAP +
    OVERVIEW_LIST_BODY_PADDING
  )
}

type CardAction = {
  label: string
  link?: LinkProps
  onClick?: () => void
  disabled?: boolean
  disabledTooltip?: string
  analytics?: AnalyticsActionId
}

/**
 * Card with a service header, a list body and a footer link. Every card in a
 * row gets the same body height, so footers align whatever each card holds.
 */
export function OverviewListCard({
  icon: Icon,
  title,
  count,
  action,
  bodyHeight,
  footer,
  className,
  children,
}: {
  icon: ComponentType<{ className?: string }>
  title: string
  count?: number
  /** Header quick action; rendered as an icon button with a tooltip. */
  action?: CardAction
  /** Minimum body height in px; see {@link overviewListBodyHeight}. */
  bodyHeight: number
  footer?: ReactNode
  className?: string
  children: ReactNode
}) {
  return (
    <section
      className={cn(
        RESOURCE_CARD_BASE_CLASSNAME,
        'flex h-full flex-col',
        className,
      )}
    >
      <header className="flex h-12 shrink-0 items-center gap-2.5 border-b border-border ps-4 pe-2.5">
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50 text-muted-foreground">
          <Icon className="h-3.5 w-3.5" />
        </span>
        <h3 className="min-w-0 truncate text-[14px] font-medium text-foreground">
          {title}
        </h3>
        {count != null && count > 0 ? (
          <span className="shrink-0 rounded-full bg-muted px-1.5 py-px font-mono text-[11px] tabular-nums text-muted-foreground">
            {count.toLocaleString()}
          </span>
        ) : null}
        {action ? <CardHeaderAction action={action} /> : null}
      </header>
      <div
        className="flex min-h-0 flex-1 flex-col gap-1 p-1.5"
        style={{ minHeight: bodyHeight }}
      >
        {children}
      </div>
      {footer}
    </section>
  )
}

function CardHeaderAction({ action }: { action: CardAction }) {
  const button = (
    <Button
      variant="ghost"
      size="icon"
      className="ms-auto h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
      aria-label={action.label}
      disabled={action.disabled}
      onClick={action.onClick}
      asChild={!!action.link && !action.disabled}
      {...(action.analytics ? analyticsAttrs(action.analytics) : {})}
    >
      {action.link && !action.disabled ? (
        <Link {...action.link}>
          <Plus className="h-4 w-4" />
        </Link>
      ) : (
        <Plus className="h-4 w-4" />
      )}
    </Button>
  )
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {action.disabled ? (
          <span tabIndex={0} className="ms-auto">
            {button}
          </span>
        ) : (
          button
        )}
      </TooltipTrigger>
      <TooltipContent side="bottom" className="max-w-xs text-[12px]">
        {action.disabled && action.disabledTooltip
          ? action.disabledTooltip
          : action.label}
      </TooltipContent>
    </Tooltip>
  )
}

/**
 * One list row: icon, two lines of text and an optional trailing slot.
 * Other props reach the root element, so a context menu trigger can wrap it.
 */
export function OverviewListRow({
  icon,
  title,
  meta,
  trailing,
  link,
  onClick,
  analytics,
  ref,
  className: triggerClassName,
  ...triggerProps
}: Omit<HTMLAttributes<HTMLElement>, 'title' | 'onClick'> & {
  ref?: Ref<HTMLElement>
  icon: ReactNode
  title: ReactNode
  meta?: ReactNode
  trailing?: ReactNode
  link?: LinkProps
  onClick?: () => void
  analytics?: AnalyticsActionId
}) {
  const className = cn(
    OVERVIEW_LIST_ROW_CLASSNAME,
    'group/row flex w-full min-w-0 shrink-0 items-center gap-3 rounded-lg px-2.5 text-start transition-colors',
    (link || onClick) &&
      'cursor-pointer hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
    'data-[state=open]:bg-muted/60',
    triggerClassName,
  )
  const content = (
    <>
      {icon}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] font-medium text-foreground">
          {title}
        </span>
        {meta ? (
          <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
            {meta}
          </span>
        ) : null}
      </span>
      {trailing ? (
        <span className="flex shrink-0 items-center gap-1.5 text-[12px] text-muted-foreground">
          {trailing}
        </span>
      ) : null}
    </>
  )
  const attrs = {
    ...triggerProps,
    ...(analytics ? analyticsAttrs(analytics) : {}),
  }
  if (link) {
    return (
      <Link
        {...link}
        {...(attrs as HTMLAttributes<HTMLAnchorElement>)}
        ref={ref as Ref<HTMLAnchorElement>}
        className={className}
      >
        {content}
      </Link>
    )
  }
  if (onClick) {
    return (
      <div
        role="button"
        tabIndex={0}
        {...(attrs as HTMLAttributes<HTMLDivElement>)}
        ref={ref as Ref<HTMLDivElement>}
        onClick={onClick}
        onKeyDown={(event) => {
          if (event.target !== event.currentTarget) return
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            onClick()
          }
        }}
        className={className}
      >
        {content}
      </div>
    )
  }
  return (
    <div
      {...(attrs as HTMLAttributes<HTMLDivElement>)}
      ref={ref as Ref<HTMLDivElement>}
      className={className}
    >
      {content}
    </div>
  )
}

/** Square icon slot at the start of a row. */
export function OverviewListRowIcon({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/40 text-muted-foreground',
        className,
      )}
    >
      {children}
    </span>
  )
}

/**
 * Dashed create tile that fills the rows a card does not use, so a card with
 * one item is as tall as one with three.
 */
export function OverviewListFiller({
  label,
  description,
  link,
  onClick,
  disabled = false,
  analytics,
}: {
  label: string
  /** Pass only when the filler spans more than one row. */
  description?: string
  link?: LinkProps
  onClick?: () => void
  disabled?: boolean
  analytics?: AnalyticsActionId
}) {
  const className = cn(
    'group/filler flex min-h-14 flex-1 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-muted-foreground/25 px-4 text-center transition-colors',
    disabled
      ? 'opacity-60'
      : 'cursor-pointer hover:border-muted-foreground/50 hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
  )
  const content = (
    <>
      <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground transition-colors group-hover/filler:text-foreground">
        <Plus className="h-3.5 w-3.5" />
        {label}
      </span>
      {description ? (
        <span className="line-clamp-2 max-w-64 text-[12px] leading-5 text-muted-foreground/80">
          {description}
        </span>
      ) : null}
    </>
  )
  const attrs = analytics ? analyticsAttrs(analytics) : {}
  if (disabled) return <div className={className}>{content}</div>
  if (link) {
    return (
      <Link {...link} className={className} {...attrs}>
        {content}
      </Link>
    )
  }
  return (
    <button type="button" onClick={onClick} className={className} {...attrs}>
      {content}
    </button>
  )
}

/** Footer link to the full list, or the primary action of an empty card. */
export function OverviewListFooter({
  label,
  link,
  onClick,
  variant = 'link',
  disabled = false,
  disabledTooltip,
  analytics,
}: {
  label: string
  link?: LinkProps
  onClick?: () => void
  /** `action` reads as the card's primary call to action. */
  variant?: 'link' | 'action'
  disabled?: boolean
  disabledTooltip?: string
  analytics?: AnalyticsActionId
}) {
  const className = cn(
    'group/footer flex h-10 w-full shrink-0 items-center justify-between gap-2 border-t border-border px-4 text-[12px] transition-colors',
    variant === 'action'
      ? 'font-medium text-foreground'
      : 'text-muted-foreground',
    disabled
      ? 'cursor-not-allowed opacity-60'
      : 'hover:bg-muted/40 hover:text-foreground focus-visible:bg-muted/40 focus-visible:outline-none',
  )
  const content = (
    <>
      <span className="flex min-w-0 items-center gap-1.5 truncate">
        {variant === 'action' ? (
          <Plus className="h-3.5 w-3.5 shrink-0" />
        ) : null}
        {label}
      </span>
      <ArrowRight className="h-3.5 w-3.5 shrink-0 transition-transform group-hover/footer:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover/footer:-translate-x-0.5" />
    </>
  )
  const attrs = analytics ? analyticsAttrs(analytics) : {}
  if (disabled) {
    const footer = <div className={className}>{content}</div>
    if (!disabledTooltip) return footer
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <div tabIndex={0} className={className}>
            {content}
          </div>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-[12px]">
          {disabledTooltip}
        </TooltipContent>
      </Tooltip>
    )
  }
  if (link) {
    return (
      <Link {...link} className={className} {...attrs}>
        {content}
      </Link>
    )
  }
  return (
    <button type="button" onClick={onClick} className={className} {...attrs}>
      {content}
    </button>
  )
}

/** Placeholder card with the same frame, for the first paint. */
export function OverviewListCardSkeleton({
  bodyHeight,
  rows = 3,
}: {
  bodyHeight: number
  rows?: number
}) {
  return (
    <div
      aria-hidden
      className={cn(RESOURCE_CARD_BASE_CLASSNAME, 'flex h-full flex-col')}
    >
      <div className="flex h-12 items-center gap-2.5 border-b border-border px-4">
        <Skeleton className="h-7 w-7 rounded-md" />
        <Skeleton className="h-3.5 w-24" />
      </div>
      <div
        className="flex flex-1 flex-col gap-1 p-1.5"
        style={{ minHeight: bodyHeight }}
      >
        {Array.from({ length: rows }).map((_, index) => (
          <div
            key={index}
            className={cn(
              OVERVIEW_LIST_ROW_CLASSNAME,
              'flex items-center gap-3 px-2.5',
            )}
          >
            <Skeleton className="h-8 w-8 rounded-md" />
            <div className="flex-1 space-y-1.5">
              <Skeleton className="h-3 w-2/5" />
              <Skeleton className="h-2.5 w-1/4" />
            </div>
          </div>
        ))}
      </div>
      <div className="flex h-10 items-center border-t border-border px-4">
        <Skeleton className="h-3 w-28" />
      </div>
    </div>
  )
}
