import { Database } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  formatDatabaseServiceLabel,
  getDatabaseServiceLucideIcon,
} from '@/lib/databases/database-service-icons'
import {
  MySQLDolphinIcon,
  MongoDbLeafIcon,
  PostgresElephantIcon,
} from './database-mascot-icons'

type DatabaseTypeIconProps = {
  /** Appwrite SDK `database.type` */
  apiType?: string | null
  /** Dedicated database `engine` (e.g. postgres, mysql) */
  engine?: string | null
  className?: string
}

function resolveDatabaseTypeIcon({
  apiType,
  engine,
  className,
}: DatabaseTypeIconProps) {
  const iconClass = cn('h-4 w-4 shrink-0 text-muted-foreground', className)

  const normalizedEngine = engine?.toLowerCase() ?? ''
  if (normalizedEngine === 'postgres' || normalizedEngine === 'postgresql') {
    return <PostgresElephantIcon className={iconClass} />
  }
  if (normalizedEngine === 'mysql' || normalizedEngine === 'mariadb') {
    return <MySQLDolphinIcon className={iconClass} />
  }
  if (normalizedEngine === 'mongo' || normalizedEngine === 'mongodb') {
    return <MongoDbLeafIcon className={iconClass} />
  }

  const normalizedType = String(apiType ?? '').toLowerCase()
  const databaseServiceIcon = getDatabaseServiceLucideIcon(normalizedType)
  if (databaseServiceIcon) {
    const Icon = databaseServiceIcon
    return <Icon className={iconClass} />
  }

  return <Database className={iconClass} />
}

export function DatabaseTypeIcon({
  apiType,
  engine,
  className,
}: DatabaseTypeIconProps) {
  return resolveDatabaseTypeIcon({ apiType, engine, className })
}

function databaseTypeDisplayLabel(
  apiType?: string | null,
  engine?: string | null,
): string {
  const normalizedEngine = engine?.toLowerCase() ?? ''
  if (normalizedEngine === 'postgres' || normalizedEngine === 'postgresql') {
    return 'PostgreSQL'
  }
  if (normalizedEngine === 'mysql') return 'MySQL'
  if (normalizedEngine === 'mariadb') return 'MariaDB'
  if (normalizedEngine === 'mongo' || normalizedEngine === 'mongodb') {
    return 'MongoDB'
  }

  const normalizedType = String(apiType ?? 'tablesdb').toLowerCase()
  return formatDatabaseServiceLabel(normalizedType) ?? 'TablesDB'
}

type DatabaseTypeBadgeProps = {
  apiType?: string | null
  engine?: string | null
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
  className,
  iconOnly = false,
}: DatabaseTypeBadgeProps) {
  const label = databaseTypeDisplayLabel(apiType, engine)

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
