import { Database } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  getDatabaseTypeDisplayLabel,
  getDatabaseTypeDisplayLucideIcon,
  resolveDatabaseTypeDisplay,
  type DatabaseTypeDisplayHints,
} from '@/lib/databases/database-type-display'
import {
  MySQLDolphinIcon,
  MongoDbLeafIcon,
  PostgresElephantIcon,
} from './database-mascot-icons'

export { getDatabaseTypeDisplayLabel } from '@/lib/databases/database-type-display'

type DatabaseTypeIconProps = DatabaseTypeDisplayHints & {
  className?: string
}

function resolveDatabaseTypeIcon({
  apiType,
  engine,
  product,
  className,
}: DatabaseTypeIconProps) {
  const iconClass = cn('h-4 w-4 shrink-0 text-muted-foreground', className)
  const resolved = resolveDatabaseTypeDisplay({ apiType, engine, product })

  if (resolved.mode === 'engine') {
    const normalizedEngine = resolved.key
    if (
      normalizedEngine === 'postgres' ||
      normalizedEngine === 'postgresql'
    ) {
      return <PostgresElephantIcon className={iconClass} />
    }
    if (normalizedEngine === 'mysql' || normalizedEngine === 'mariadb') {
      return <MySQLDolphinIcon className={iconClass} />
    }
    if (normalizedEngine === 'mongo' || normalizedEngine === 'mongodb') {
      return <MongoDbLeafIcon className={iconClass} />
    }
  }

  const ProductIcon = getDatabaseTypeDisplayLucideIcon({
    apiType,
    engine,
    product,
  })
  if (ProductIcon) {
    return <ProductIcon className={iconClass} />
  }

  return <Database className={iconClass} />
}

export function DatabaseTypeIcon({
  apiType,
  engine,
  product,
  className,
}: DatabaseTypeIconProps) {
  return resolveDatabaseTypeIcon({ apiType, engine, product, className })
}

type DatabaseTypeBadgeProps = DatabaseTypeDisplayHints & {
  className?: string
  /** Hide the text label and show only the icon. */
  iconOnly?: boolean
}

/**
 * Service/engine icon with an optional product label.
 * Neutral muted chip styling for database type tags.
 */
export function DatabaseTypeBadge({
  apiType,
  engine,
  product,
  className,
  iconOnly = false,
}: DatabaseTypeBadgeProps) {
  const label = getDatabaseTypeDisplayLabel(apiType, engine, product)

  return (
    <span
      className={cn(
        'inline-flex max-w-full items-center gap-1.5',
        !iconOnly &&
          'rounded-md border border-border bg-muted/40 px-1.5 py-0.5',
        className,
      )}
      title={label}
    >
      <DatabaseTypeIcon
        apiType={apiType}
        engine={engine}
        product={product}
        className="h-3.5 w-3.5"
      />
      {!iconOnly ? (
        <span className="truncate text-[12px] font-medium text-foreground">
          {label}
        </span>
      ) : null}
    </span>
  )
}
