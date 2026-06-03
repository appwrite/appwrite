import { Fragment } from 'react'
import { cn } from '@/lib/utils'
import type { LucideIcon } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { getStatusColor, type StatusType } from '@/lib/utils/status-badge'

/** Divider + spacing for compact grid card metadata/footer rows (use with ResourceCard or matching cards). */
export const RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME =
  'mt-2 min-w-0 border-t border-border pt-2'

/** Three-column resource list grid; children shrink so long titles truncate instead of widening the page. */
export const RESOURCE_CARD_GRID_CLASSNAME =
  'grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0'

/** Four-column resource list grid (e.g. sites). */
export const RESOURCE_CARD_GRID_4_COL_CLASSNAME =
  'grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0'

/** Two-column resource list grid (e.g. analytics websites). */
export const RESOURCE_CARD_GRID_2_COL_CLASSNAME =
  'grid min-w-0 gap-4 sm:grid-cols-2 [&>*]:min-w-0'

/** Responsive grid with xl third column (e.g. marketplace, function templates). */
export const RESOURCE_CARD_GRID_WIDE_CLASSNAME =
  'grid min-w-0 gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0'

/** Apply to custom card shells inside resource grids when not using ResourceCard. */
export const RESOURCE_CARD_SHELL_CLASSNAME =
  'min-w-0 overflow-hidden'

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
  metadata?: Array<{ label: string; value: string | number | React.ReactNode }>
  onClick?: () => void
  onMenuClick?: (e: React.MouseEvent) => void
  avatar?: string
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
      data-analytics-id={onClick ? 'resource_card_open' : undefined}
      data-analytics-surface="resource_card"
      className={cn(
        'group min-w-0 overflow-hidden rounded-lg border border-border bg-card p-4 transition-all',
        onClick && 'cursor-pointer hover:border-border hover:bg-accent/50',
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
                    'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium border',
                    status
                      ? getStatusColor(status)
                      : 'bg-muted text-muted-foreground border-border',
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
                <CopyableId
                  id={resourceId}
                  size="xs"
                  maxWidth={120}
                />
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
                {index > 0 ? (
                  <span
                    className="shrink-0 text-[10px] text-muted-foreground/40"
                    aria-hidden
                  >
                    ·
                  </span>
                ) : null}
                <div className="flex shrink-0 items-center gap-0.5">
                  {item.label ? (
                    <span className="text-[10px] text-muted-foreground/70">
                      {item.label}
                    </span>
                  ) : null}
                  <span className="text-[10px] font-medium text-muted-foreground">
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
