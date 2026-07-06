import { useParams } from '@tanstack/react-router'
import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useOrganizationScopes,
  usePostgresDatabase,
  useProject,
} from '@/lib/react-query/hooks'
import { usePostgresDatabaseHeaderSlot } from './_components/PostgresDatabaseHeaderSlotContext'
import { PostgresDatabaseGeneralSettings } from './_components/PostgresDatabaseGeneralSettings'
import { PostgresDatabaseConfigSettings } from './_components/PostgresDatabaseConfigSettings'
import { PostgresDatabaseDangerZoneCard } from './_components/PostgresDatabaseDangerZoneCard'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Settings } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

type ViewProps = {
  databaseId: string
}

export function View({ databaseId }: ViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { database, isLoading } = usePostgresDatabase(projectId, databaseId)

  usePostgresDatabaseHeaderSlot({})

  const canWrite = canCreateDatabase(access, features)

  if (isLoading && !database) {
    return null
  }

  if (!database) {
    return (
      <div className="flex min-h-0 flex-1 items-center justify-center px-4 py-8">
        <div className="w-full max-w-sm">
          <EmptyState
            variant="centered"
            icon={Settings}
            iconSize="md"
            title={t('Database not found')}
            description={t(
              'This database may have been deleted or you no longer have access.',
            )}
            isEmpty
            className="w-full"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto min-h-0 w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-6 pt-4 sm:px-6 sm:pb-8 sm:pt-6">
      <div className="space-y-6">
        <PostgresDatabaseGeneralSettings
          projectId={projectId}
          databaseId={databaseId}
          database={database}
          canWrite={canWrite}
        />
        <PostgresDatabaseConfigSettings
          projectId={projectId}
          databaseId={databaseId}
          database={database}
          canWrite={canWrite}
        />
        <PostgresDatabaseDangerZoneCard
          projectId={projectId}
          database={database}
          canWrite={canWrite}
        />
      </div>
    </div>
  )
}
