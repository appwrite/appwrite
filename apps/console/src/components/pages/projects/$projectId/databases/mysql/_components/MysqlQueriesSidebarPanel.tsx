import { useEffect, useMemo, useState } from 'react'
import { ArrowUpDown, Loader2, Search, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  useMysqlSavedQueries,
  useMysqlSavedQueriesSort,
  type MysqlSavedQueryLevel,
} from '@/lib/react-query/hooks/mysql-databases'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  MYSQL_SAVED_QUERIES_SORT_OPTIONS,
  sortSavedMysqlQueries,
  type SavedMysqlQuery,
} from '@/lib/user-prefs-keys'
import { matchesMysqlLocalSearch } from './mysql-spreadsheet-chrome'
import {
  mysqlQuerySelectionKey,
  queryPreviewLabel,
  useMysqlSidebar,
} from './MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

type MysqlQueriesSidebarPanelProps = {
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
  query: SavedMysqlQuery
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
        className="flex min-w-0 flex-1 cursor-pointer flex-col px-3 py-2 text-start"
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
          className="me-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-all hover:bg-muted hover:text-foreground group-hover:opacity-100 disabled:opacity-50"
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
  onChange,
}: {
  savedQueryLevel: MysqlSavedQueryLevel
  onChange: (level: MysqlSavedQueryLevel) => void
}) {
  const t = useT()
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
      aria-label={t('Saved query scope')}
    >
      <ToggleGroupItem
        value="user"
        className="h-9 flex-1 text-[12px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground"
      >
        {t('For me')}
      </ToggleGroupItem>
      <ToggleGroupItem
        value="team"
        className="h-9 flex-1 text-[12px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground"
      >
        {t('For team')}
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

export function MysqlQueriesSidebarPanel({
  projectId,
  databaseId,
}: MysqlQueriesSidebarPanelProps) {
  const t = useT()
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
  } = useMysqlSidebar()

  const {
    userQueries,
    teamQueries,
    deleteSavedQuery,
    isDeleting,
    hasTeamLevel,
  } = useMysqlSavedQueries(databaseId, account, teamId)

  const { sort, setSort } = useMysqlSavedQueriesSort(databaseId, account)

  const [deletingKey, setDeletingKey] = useState<string | null>(null)
  const [querySearch, setQuerySearch] = useState('')

  useEffect(() => {
    setQuerySearch('')
  }, [databaseId])

  const activeSavedQueries =
    savedQueryLevel === 'team' ? teamQueries : userQueries

  const filteredSavedQueries = activeSavedQueries.filter((query) =>
    matchesMysqlLocalSearch(querySearch, query.name, query.sql),
  )

  const displayedSavedQueries = useMemo(
    () => sortSavedMysqlQueries(filteredSavedQueries, sort),
    [filteredSavedQueries, sort],
  )

  const hasActiveSearch = querySearch.trim().length > 0

  const handleDelete = async (
    id: string,
    level: MysqlSavedQueryLevel,
  ) => {
    const key = mysqlQuerySelectionKey(level, id)
    setDeletingKey(key)
    try {
      await deleteSavedQuery(id, level)
      toast.success(t('Query deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setDeletingKey(null)
    }
  }

  const savedItems = displayedSavedQueries.map((query) => (
    <SavedQueryButton
      key={query.id}
      query={query}
      isSelected={
        selectedQueryKey ===
        mysqlQuerySelectionKey(savedQueryLevel, query.id)
      }
      onSelect={() => selectSavedQuery(savedQueryLevel, query)}
      onDelete={
        savedQueryLevel === 'user' || canSaveTeam
          ? () => handleDelete(query.id, savedQueryLevel)
          : undefined
      }
      isDeleting={
        isDeleting &&
        deletingKey === mysqlQuerySelectionKey(savedQueryLevel, query.id)
      }
    />
  ))

  return (
    <div className="flex min-h-0 flex-1 flex-col pt-2">
      <div className="shrink-0 pb-2">
        <div className="flex min-w-0 items-center gap-2">
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="text"
              value={querySearch}
              onChange={(event) => setQuerySearch(event.target.value)}
              placeholder={t('Search queries...')}
              className="h-8 ps-8 pe-8 text-[13px]"
              aria-label={t('Search queries...')}
            />
            {querySearch ? (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute end-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
                aria-label={t('Clear query search')}
                onClick={() => setQuerySearch('')}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            ) : null}
          </div>
          <DropdownMenu>
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8 shrink-0"
                      aria-label={t('Sort queries')}
                    >
                      <ArrowUpDown className="h-3.5 w-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent side="top" className="text-xs">
                  {t('Sort queries')}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Sort queries')}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuRadioGroup
                value={sort}
                onValueChange={(value) => {
                  const option = MYSQL_SAVED_QUERIES_SORT_OPTIONS.find(
                    (item) => item.value === value,
                  )
                  if (option) setSort(option.value)
                }}
              >
                {MYSQL_SAVED_QUERIES_SORT_OPTIONS.map((option) => (
                  <DropdownMenuRadioItem key={option.value} value={option.value}>
                    {t(option.label)}
                  </DropdownMenuRadioItem>
                ))}
              </DropdownMenuRadioGroup>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        {displayedSavedQueries.length > 0 ? (
          <div className="space-y-0.5">{savedItems}</div>
        ) : (
          <p className="px-2 py-1 text-[12px] text-muted-foreground">
            {hasActiveSearch
              ? t('No queries match your search.')
              : savedQueryLevel === 'team'
                ? t('No team queries yet.')
                : t('No saved queries yet.')}
          </p>
        )}
      </div>
      {hasTeamLevel ? (
        <div className="shrink-0 border-t border-border pt-2">
          <SavedQueryScopeToggle
            savedQueryLevel={savedQueryLevel}
            onChange={(level) => {
              setQuerySearch('')
              setSavedQueryLevel(level)
            }}
          />
        </div>
      ) : null}
    </div>
  )
}
