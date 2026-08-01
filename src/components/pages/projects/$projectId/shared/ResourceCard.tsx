import { Fragment } from 'react'
import { cn } from '@/lib/utils'
import type { LucideIcon } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { getStatusColor, type StatusType } from '@/lib/utils/status-badge'

/** Full-bleed section divider for p-4 resource cards (chart blocks, metadata footers). */
export const RESOURCE_CARD_SECTION_DIVIDER_CLASSNAME =
  '-mx-4 mt-2 min-w-0 border-t border-border px-4 pt-2.5'

/** Full-bleed footer for p-4 resource cards. Pair with `pb-0` on the padded card body so bottom inset comes from the footer (avoids clipping with overflow-hidden shells). */
export const RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME = cn(
  RESOURCE_CARD_SECTION_DIVIDER_CLASSNAME,
  'pb-4',
)

/**
 * Base shell for resource grid cards (matches org project list cards).
 * Default: rounded-xl, bg-card/50, lifts to bg-card on hover when interactive.
 */
export const RESOURCE_CARD_BASE_CLASSNAME =
  'group min-w-0 overflow-hidden rounded-xl border border-border bg-card/50 transition-all'

/** Hover/focus shell for clickable resource cards in grids and lists. */
export const RESOURCE_CARD_INTERACTIVE_CLASSNAME =
  'cursor-pointer hover:border-border hover:bg-card'

/** Standard padded resource card (ResourceCard default layout). */
export const RESOURCE_CARD_PADDED_CLASSNAME = cn(
  RESOURCE_CARD_BASE_CLASSNAME,
  'p-4',
)

/** Apply to custom card shells inside resource grids when not using ResourceCard. */
export const RESOURCE_CARD_SHELL_CLASSNAME = 'min-w-0 overflow-hidden'

/** Custom card shell with preview/media above content (e.g. sites, storage files). */
export const RESOURCE_CARD_MEDIA_SHELL_CLASSNAME = cn(
  RESOURCE_CARD_BASE_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  'flex h-full flex-col',
  RESOURCE_CARD_SHELL_CLASSNAME,
)

/**
 * Resource list grids use container queries against the nearest `@container`
 * (typically `#main-content`), so columns respond to content width, not the
 * viewport. Do not rely on the layout-row `@container`, which includes sidebar
 * width. Breakpoints mirror former viewport tokens: sm=640, lg=1024, xl=1280.
 */

/** Three-column resource list grid; children shrink so long titles truncate instead of widening the page. */
export const RESOURCE_CARD_GRID_CLASSNAME =
  'grid min-w-0 gap-3 @[640px]:grid-cols-2 @[1024px]:grid-cols-3 [&>*]:min-w-0'

/** Four-column resource list grid (e.g. sites). */
export const RESOURCE_CARD_GRID_4_COL_CLASSNAME =
  'grid min-w-0 gap-3 @[640px]:grid-cols-2 @[1024px]:grid-cols-4 [&>*]:min-w-0'

/** Two-column resource list grid (e.g. analytics websites). */
export const RESOURCE_CARD_GRID_2_COL_CLASSNAME =
  'grid min-w-0 gap-4 @[640px]:grid-cols-2 [&>*]:min-w-0'

/** Responsive grid with xl third column (e.g. marketplace, function templates). */
export const RESOURCE_CARD_GRID_WIDE_CLASSNAME =
  'grid min-w-0 gap-4 grid-cols-1 @[640px]:grid-cols-2 @[1280px]:grid-cols-3 [&>*]:min-w-0'

type ResourceCardMetadataItem = {
  label: string
  value: string | number | React.ReactNode
  align?: 'right'
}

interface ResourceCardProps {
  title: string
  /** Renders inline after the title (e.g. status badges). */
  titleAccessory?: React.ReactNode
  subtitle?: string
  resourceId?: string
  icon?: LucideIcon
  customIcon?: React.ReactNode
  iconColor?: string
  status?: StatusType
  statusLabel?: string
  metadata?: ResourceCardMetadataItem[]
  onClick?: () => void
  onMenuClick?: (e: React.MouseEvent) => void
  avatar?: string
  /** When false, disables list hover treatment. Defaults to true. */
  interactive?: boolean
  className?: string
}

export function ResourceCard({
  title,
  titleAccessory,
  subtitle,
  resourceId,
  icon: Icon,
  customIcon,
  iconColor = 'bg-muted text-muted-foreground',
  status,
  statusLabel,
  metadata,
  onClick,
  onMenuClick,
  avatar,
  interactive = true,
  className,
}: ResourceCardProps) {
  const statusDotColors: Record<StatusType, string> = {
    success: 'bg-emerald-500 dark:bg-emerald-400',
    warning: 'bg-amber-500 dark:bg-amber-400',
    error: 'bg-red-500 dark:bg-red-400',
    info: 'bg-slate-500 dark:bg-slate-400',
    pending: 'bg-amber-500 dark:bg-amber-400',
    processing: 'bg-blue-500 dark:bg-blue-400',
    active: 'bg-emerald-500 dark:bg-emerald-400',
    inactive: 'bg-muted-foreground/50',
    completed: 'bg-emerald-500 dark:bg-emerald-400',
    failed: 'bg-red-500 dark:bg-red-400',
    verified: 'bg-emerald-500 dark:bg-emerald-400',
    unverified: 'bg-red-500 dark:bg-red-400',
  }

  return (
    <div
      onClick={onClick}
      className={cn(
        RESOURCE_CARD_PADDED_CLASSNAME,
        interactive && RESOURCE_CARD_INTERACTIVE_CLASSNAME,
        metadata && metadata.length > 0 && 'pb-0',
        className,
      )}
    >
      <div className="flex items-start justify-between gap-3">
        {/* Icon or Avatar */}
        <div className="flex items-start gap-3">
          {avatar ? (
            <InitialsAvatar name={avatar} size="lg" />
          ) : customIcon ? (
            <div
              className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                iconColor,
              )}
            >
              {customIcon}
            </div>
          ) : Icon ? (
            <div
              className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                iconColor,
              )}
            >
              <Icon className="h-5 w-5" />
            </div>
          ) : null}

          <div className="min-w-0 flex-1">
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
              <h3 className="truncate text-[14px] font-medium text-foreground">
                {title}
              </h3>
              {titleAccessory ? (
                <div className="flex shrink-0 items-center gap-1">
                  {titleAccessory}
                </div>
              ) : null}
              {status && !statusLabel && (
                <span
                  className={cn(
                    'h-1.5 w-1.5 shrink-0 rounded-full',
                    statusDotColors[status] || 'bg-muted-foreground/50',
                  )}
                />
              )}
              {statusLabel && (
                <span
                  className={cn(
                    'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium',
                    status
                      ? getStatusColor(status)
                      : 'bg-muted text-muted-foreground',
                  )}
                >
                  {statusLabel}
                </span>
              )}
            </div>
            {subtitle && (
              <p className="mt-0.5 truncate text-[12px] text-muted-foreground whitespace-nowrap">
                {subtitle}
              </p>
            )}
            {/* Resource ID Tag */}
            {resourceId && (
              <div className="mt-1.5">
                <CopyableId id={resourceId} size="xs" maxWidth={120} />
              </div>
            )}
          </div>
        </div>

        {/* Menu Button */}
        {onMenuClick && (
          <RowActionsMenuTrigger
            compact
            revealOnGroupHover
            onClick={(e) => {
              e.stopPropagation()
              onMenuClick(e)
            }}
          />
        )}
      </div>

      {/* Metadata - single row, compact; scroll horizontally only if needed */}
      {metadata && metadata.length > 0 && (
        <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
          <div className="flex min-w-0 flex-nowrap items-center gap-x-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            {metadata.map((item, index) => (
              <Fragment key={index}>
                {index > 0 && item.align !== 'right' ? (
                  <span
                    className="shrink-0 text-[10px] text-muted-foreground/40"
                    aria-hidden
                  >
                    ·
                  </span>
                ) : null}
                <div
                  className={cn(
                    'flex shrink-0 items-center gap-0.5',
                    item.align === 'right' && 'ms-auto',
                  )}
                >
                  {item.label ? (
                    <span className="text-[12px] text-muted-foreground/70">
                      {item.label}
                    </span>
                  ) : null}
                  <span className="text-[12px] font-medium text-muted-foreground">
                    {item.value}
                  </span>
                </div>
              </Fragment>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
