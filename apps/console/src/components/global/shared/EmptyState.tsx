import { type ComponentType, type ReactNode } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export interface EmptyStateProps {
  /**
   * Icon to display in the empty state (Lucide or any `className` icon).
   */
  icon?: ComponentType<{ className?: string }>
  /**
   * Title text (defaults based on isEmpty/hasFilters)
   */
  title?: string
  /**
   * Description text (defaults based on isEmpty/hasFilters)
   */
  description?: string
  /**
   * Whether this is an empty state (no items at all) vs filtered results
   */
  isEmpty?: boolean
  /**
   * Whether there are active filters or search
   */
  hasFilters?: boolean
  /**
   * Custom content to render instead of default title/description
   * This allows full control over the empty state content
   */
  children?: ReactNode
  /** Renders below the description inside the same layout (e.g. clear filters button) */
  action?: ReactNode
  /**
   * Additional CSS classes
   */
  className?: string
  /**
   * Whether to show the container with border (card style)
   */
  variant?: 'default' | 'card' | 'centered'
  /**
   * Icon size variant (`xl` = larger hero-style empty state)
   */
  iconSize?: 'sm' | 'md' | 'lg' | 'xl'
  /** Extra classes applied to the icon (e.g. `fill-current` for Play). */
  iconClassName?: string
}

/**
 * Unified empty state component that differentiates between:
 * - No items at all (isEmpty = true)
 * - No results after search/filter (hasFilters = true)
 *
 * Supports content overwriting via children prop for custom empty states.
 */
export function EmptyState({
  icon: Icon,
  title,
  description,
  isEmpty = true,
  hasFilters = false,
  children,
  action,
  className,
  variant = 'default',
  iconSize = 'sm',
  iconClassName,
}: EmptyStateProps) {
  const t = useT()
  // Default icon size classes
  const iconSizeClasses = {
    sm: 'h-5 w-5',
    md: 'h-6 w-6',
    lg: 'h-8 w-8',
    xl: 'h-10 w-10',
  }

  // Default icon container size
  const iconContainerSize = {
    sm: 'h-12 w-12',
    md: 'h-12 w-12',
    lg: 'h-14 w-14',
    xl: 'h-20 w-20',
  }

  const isHeroEmpty = iconSize === 'xl'

  // If children are provided, render them with the container
  if (children) {
    if (variant === 'card') {
      return (
        <div
          className={cn(
            'rounded-xl border border-dashed border-border bg-card/50 p-8',
            className,
          )}
        >
          {children}
        </div>
      )
    }

    if (variant === 'centered') {
      return (
        <div
          className={cn(
            'flex h-full items-center justify-center',
            isHeroEmpty ? 'py-20' : 'py-16',
            className,
          )}
        >
          {children}
        </div>
      )
    }

    return (
      <div className={cn('flex flex-col items-center text-center', className)}>
        {children}
      </div>
    )
  }

  // Default empty state content
  const defaultTitle = t(
    title ||
      (hasFilters
        ? 'No results found'
        : isEmpty
          ? 'No items yet'
          : 'No items found'),
  )
  const defaultDescription = t(
    description ||
      (hasFilters
        ? 'Try adjusting your search or filters to see more results.'
        : isEmpty
          ? 'Get started by creating your first item.'
          : 'No items match your criteria.'),
  )

  const content = (
    <div className="flex flex-col items-center text-center">
      {Icon && (
        <div
          className={cn(
            'mx-auto flex items-center justify-center rounded-full bg-muted',
            isHeroEmpty ? 'mb-5' : 'mb-4',
            iconContainerSize[iconSize],
            variant === 'centered' && 'ring-1 ring-border',
          )}
        >
          <Icon
            className={cn(
              'text-muted-foreground',
              iconSizeClasses[iconSize],
              iconClassName,
            )}
          />
        </div>
      )}
      <p
        className={cn(
          'mb-1 text-foreground',
          isHeroEmpty
            ? 'text-[15px] font-semibold tracking-tight'
            : 'text-[14px] font-medium',
        )}
      >
        {defaultTitle}
      </p>
      <p
        className={cn(
          'text-muted-foreground',
          isHeroEmpty
            ? 'max-w-md text-[14px] leading-relaxed'
            : 'text-[13px]',
        )}
      >
        {defaultDescription}
      </p>
      {action ? (
        <div className="mt-6 flex w-full flex-wrap justify-center gap-2">
          {action}
        </div>
      ) : null}
    </div>
  )

  if (variant === 'card') {
    return (
      <div
        className={cn(
          'rounded-xl border border-dashed border-border bg-card/50 p-8',
          className,
        )}
      >
        {content}
      </div>
    )
  }

  if (variant === 'centered') {
    return (
      <div
        className={cn(
          'flex h-full items-center justify-center',
          isHeroEmpty ? 'py-20' : 'py-16',
          className,
        )}
      >
        {content}
      </div>
    )
  }

  return (
    <div className={cn('flex flex-col items-center text-center', className)}>
      {content}
    </div>
  )
}
