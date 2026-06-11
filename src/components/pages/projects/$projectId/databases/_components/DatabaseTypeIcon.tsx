import { DatabaseType } from '@appwrite.io/console'
import { Braces, Database, Layers, Table as TableIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { MySQLDolphinIcon, PostgresElephantIcon } from './database-mascot-icons'

type DatabaseTypeIconProps = {
  /** Appwrite SDK `database.type` */
  apiType?: DatabaseType | string | null
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

  const normalizedType = String(apiType ?? '').toLowerCase()
  if (
    normalizedType === String(DatabaseType.Documentsdb).toLowerCase() ||
    normalizedType === 'documentsdb'
  ) {
    return <Braces className={iconClass} />
  }
  if (
    normalizedType === String(DatabaseType.Vectorsdb).toLowerCase() ||
    normalizedType === 'vectorsdb'
  ) {
    return <Layers className={iconClass} />
  }
  if (
    normalizedType === String(DatabaseType.Tablesdb).toLowerCase() ||
    normalizedType === 'tablesdb'
  ) {
    return <TableIcon className={iconClass} />
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
