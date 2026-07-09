import { Database } from 'lucide-react'
import { cn } from '@/lib/utils'
import { getDatabaseServiceLucideIcon } from '@/lib/databases/database-service-icons'
import { MySQLDolphinIcon, MongoDbLeafIcon, PostgresElephantIcon } from './database-mascot-icons'

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
