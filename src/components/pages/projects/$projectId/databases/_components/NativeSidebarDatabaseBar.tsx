import { Link } from '@tanstack/react-router'
import { ChevronLeft } from 'lucide-react'
import { useSwitchResourceInPlace } from '@/components/global/shared/ResourceTitleSwitcher'
import type { NativeDatabaseEngine } from '@/lib/databases/native-database-engines'
import { DatabaseSelector } from './DatabaseSelector'
import { useT } from '@/lib/i18n/translate'

type NativeSidebarDatabaseBarProps = {
  projectId: string
  databaseId: string
  databaseName: string
  databaseSpecification?: string | null
  nativeEngine: NativeDatabaseEngine
}

export function NativeSidebarDatabaseBar({
  projectId,
  databaseId,
  databaseName,
  databaseSpecification,
  nativeEngine,
}: NativeSidebarDatabaseBarProps) {
  const t = useT()
  const switchResource = useSwitchResourceInPlace()

  return (
    <div className="flex shrink-0 flex-col border-b border-border bg-background">
      <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
        <Link
          to="/projects/$projectId/databases"
          params={{ projectId }}
          className="flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label={t('Back to databases')}
        >
          <ChevronLeft className="h-4 w-4" />
        </Link>
        <span className="text-[13px] font-medium text-foreground">
          {t('Databases')}
        </span>
      </div>
      <div className="flex min-w-0 flex-col gap-2 px-2 py-2">
        <DatabaseSelector
          projectId={projectId}
          mode="native"
          nativeEngine={nativeEngine}
          value={databaseId}
          selectedName={databaseName}
          selectedSpecification={databaseSpecification}
          onSelect={(newDatabaseId) => {
            if (newDatabaseId === databaseId) return
            switchResource(databaseId, newDatabaseId)
          }}
        />
      </div>
    </div>
  )
}
