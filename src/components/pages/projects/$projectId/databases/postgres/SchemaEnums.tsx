import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { usePostgresSchemaEnums } from '@/lib/react-query/hooks'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { ListOrdered } from 'lucide-react'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'
import { usePostgresDatabaseHeaderSlot } from './_components/PostgresDatabaseHeaderSlotContext'
import { PostgresSchemaEnumsPanel } from './_components/PostgresSchemaEnumsPanel'
import { useT } from '@/lib/i18n/translate'

type PostgresSchemaEnumsProps = {
  databaseId: string
}

export function PostgresSchemaEnums({ databaseId }: PostgresSchemaEnumsProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { selectedSchema } = usePostgresSidebar()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  const [searchValue, setSearchValue] = useState('')
  const [createOpen, setCreateOpen] = useState(false)

  const { refetch, isFetching } = usePostgresSchemaEnums(
    projectId,
    databaseId,
    selectedSchema,
  )

  useEffect(() => {
    setSearchValue('')
  }, [selectedSchema])

  const headerSlot = useMemo(() => {
    if (!selectedSchema) return {}

    return {
      searchPlaceholder: t('Search enums...'),
      searchValue,
      onSearchChange: setSearchValue,
      createLabel: canWrite ? t('Create enum') : undefined,
      onCreate: canWrite ? () => setCreateOpen(true) : undefined,
      createDisabled: !canWrite,
      createDisabledTooltip: canWrite
        ? undefined
        : t("You don't have permission to modify table structure."),
      showRefresh: true,
      onRefresh: () => void refetch(),
      isRefreshing: isFetching,
    }
  }, [canWrite, isFetching, refetch, searchValue, selectedSchema, t])

  usePostgresDatabaseHeaderSlot(headerSlot)

  if (!selectedSchema) {
    return (
      <div className="flex min-h-0 flex-1 w-full items-center justify-center px-4 py-8">
        <div className="w-full max-w-sm">
          <EmptyState
            variant="centered"
            icon={ListOrdered}
            iconSize="md"
            title={t('No schema selected')}
            description={t('Select a schema in the sidebar to manage enum types.')}
            isEmpty
            className="w-full"
          />
        </div>
      </div>
    )
  }

  return (
    <PostgresSchemaEnumsPanel
      databaseId={databaseId}
      schema={selectedSchema}
      search={searchValue}
      createDialogOpen={createOpen}
      onCreateDialogOpenChange={setCreateOpen}
    />
  )
}
