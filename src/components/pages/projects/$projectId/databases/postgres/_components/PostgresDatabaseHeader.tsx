import { cn } from '@/lib/utils'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { POSTGRES_TOP_HEADER_BAR_CLASS } from './postgres-chrome'

type PostgresDatabaseHeaderProps = {
  databaseTab: PostgresDatabaseTab
}

export function PostgresDatabaseHeader({
  databaseTab,
}: PostgresDatabaseHeaderProps) {
  return (
    <div
      className={cn('flex gap-2 px-4 sm:px-6', POSTGRES_TOP_HEADER_BAR_CLASS)}
    >
      <h1 className="flex min-w-0 flex-1 items-center text-[13px] font-semibold text-foreground">
        <span className="min-w-0 truncate">
          {POSTGRES_DATABASE_TAB_LABELS[databaseTab]}
        </span>
      </h1>
    </div>
  )
}
