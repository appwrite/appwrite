import { useState } from 'react'
import { Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  usePostgresSavedQueries,
  type PostgresSavedQueryLevel,
} from '@/lib/react-query/hooks/postgres-databases'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import type { SavedPostgresQuery } from '@/lib/user-prefs-keys'
import {
  postgresQuerySelectionKey,
  queryPreviewLabel,
  usePostgresSidebar,
} from './PostgresSidebarContext'

type PostgresQueriesSidebarPanelProps = {
  projectId: string
  databaseId: string
}

function SavedQueryButton({
  query,
  isSelected,
  onSelect,
  onDelete,
  isDeleting,
}: {
  query: SavedPostgresQuery
  isSelected: boolean
  onSelect: () => void
  onDelete?: () => void
  isDeleting: boolean
}) {
  return (
    <div
      className={cn(
        'group flex items-center gap-1 rounded-md transition-colors',
        isSelected
          ? 'bg-background text-foreground shadow-sm'
          : 'text-muted-foreground hover:bg-background/70 hover:text-foreground',
      )}
    >
      <button
        type="button"
        onClick={onSelect}
        className="flex min-w-0 flex-1 cursor-pointer flex-col px-3 py-2 text-left"
        title={query.sql}
      >
        <span className="truncate text-[13px] font-medium text-foreground">
          {query.name}
        </span>
        <span className="truncate font-mono text-[11px] text-muted-foreground">
          {queryPreviewLabel(query.sql)}
        </span>
      </button>
      {onDelete ? (
        <button
          type="button"
          onClick={(event) => {
            event.stopPropagation()
            void onDelete()
          }}
          disabled={isDeleting}
          className="mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-all hover:bg-muted hover:text-foreground group-hover:opacity-100 disabled:opacity-50"
          aria-label={`Delete ${query.name}`}
        >
          {isDeleting ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : (
            <Trash2 className="h-3 w-3" />
          )}
        </button>
      ) : null}
    </div>
  )
}

function SavedQueryScopeToggle({
  savedQueryLevel,
  userQueryCount,
  teamQueryCount,
  onChange,
}: {
  savedQueryLevel: PostgresSavedQueryLevel
  userQueryCount: number
  teamQueryCount: number
  onChange: (level: PostgresSavedQueryLevel) => void
}) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      value={savedQueryLevel}
      onValueChange={(value) => {
        if (value === 'user' || value === 'team') {
          onChange(value)
        }
      }}
      className="w-full"
      aria-label="Saved query scope"
    >
      <ToggleGroupItem
        value="user"
        className="h-9 flex-1 text-[12px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground"
      >
        For me
        {userQueryCount > 0 ? (
          <span className="ml-1 text-[10px] tabular-nums text-muted-foreground">
            {userQueryCount}
          </span>
        ) : null}
      </ToggleGroupItem>
      <ToggleGroupItem
        value="team"
        className="h-9 flex-1 text-[12px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground"
      >
        For team
        {teamQueryCount > 0 ? (
          <span className="ml-1 text-[10px] tabular-nums text-muted-foreground">
            {teamQueryCount}
          </span>
        ) : null}
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

export function PostgresQueriesSidebarPanel({
  projectId,
  databaseId,
}: PostgresQueriesSidebarPanelProps) {
  const { account } = useAuth()
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(teamId ?? undefined)
  const canSaveTeam = canSaveTeamFilters(access, features)

  const {
    selectedQueryKey,
    savedQueryLevel,
    setSavedQueryLevel,
    selectSavedQuery,
  } = usePostgresSidebar()

  const {
    userQueries,
    teamQueries,
    deleteSavedQuery,
    isDeleting,
    hasTeamLevel,
  } = usePostgresSavedQueries(databaseId, account, teamId)

  const [deletingKey, setDeletingKey] = useState<string | null>(null)

  const activeSavedQueries =
    savedQueryLevel === 'team' ? teamQueries : userQueries

  const handleDelete = async (
    id: string,
    level: PostgresSavedQueryLevel,
  ) => {
    const key = postgresQuerySelectionKey(level, id)
    setDeletingKey(key)
    try {
      await deleteSavedQuery(id, level)
      toast.success('Query deleted')
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setDeletingKey(null)
    }
  }

  const savedItems = activeSavedQueries.map((query) => (
    <SavedQueryButton
      key={query.id}
      query={query}
      isSelected={
        selectedQueryKey ===
        postgresQuerySelectionKey(savedQueryLevel, query.id)
      }
      onSelect={() => selectSavedQuery(savedQueryLevel, query)}
      onDelete={
        savedQueryLevel === 'user' || canSaveTeam
          ? () => handleDelete(query.id, savedQueryLevel)
          : undefined
      }
      isDeleting={
        isDeleting &&
        deletingKey === postgresQuerySelectionKey(savedQueryLevel, query.id)
      }
    />
  ))

  return (
    <div className="flex min-h-full flex-col pt-2">
      <div className="min-h-0 flex-1">
        {activeSavedQueries.length > 0 ? (
          <div className="space-y-0.5">{savedItems}</div>
        ) : (
          <p className="px-2 py-1 text-[12px] text-muted-foreground">
            {savedQueryLevel === 'team'
              ? 'No team queries yet.'
              : 'No saved queries yet.'}
          </p>
        )}
      </div>
      {hasTeamLevel ? (
        <div className="shrink-0 border-t border-border pt-2">
          <SavedQueryScopeToggle
            savedQueryLevel={savedQueryLevel}
            userQueryCount={userQueries.length}
            teamQueryCount={teamQueries.length}
            onChange={setSavedQueryLevel}
          />
        </div>
      ) : null}
    </div>
  )
}
