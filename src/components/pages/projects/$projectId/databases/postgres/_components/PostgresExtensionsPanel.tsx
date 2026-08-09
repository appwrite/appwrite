import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Loader2, Puzzle, Search, Tags, X } from 'lucide-react'
import { toast } from 'sonner'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import {
  RESOURCE_CARD_BASE_CLASSNAME,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { useDatabaseAdminOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import {
  useInstallPostgresDatabaseExtension,
  useOrganizationPlan,
  usePostgresDatabaseExtensions,
  useProject,
  useUninstallPostgresDatabaseExtension,
} from '@/lib/react-query/hooks'
import {
  buildPostgresExtensionRows,
  getPostgresExtensionCategories,
  matchesExtensionCategoryFilter,
  matchesExtensionStatusFilter,
  sortPostgresExtensionRows,
  type ExtensionStatusFilter,
  type PostgresExtensionRow,
  type PostgresExtensionRowStatus,
} from '@/lib/postgres-extension-catalog'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { matchesPostgresLocalSearch } from './postgres-spreadsheet-chrome'
import { PostgresExtensionsHeaderLimit } from './PostgresExtensionsHeaderLimit'
import { PostgresSegmentedToggle } from './PostgresSegmentedToggle'
import { useT } from '@/lib/i18n/translate'

type PostgresExtensionsPanelProps = {
  databaseId: string
}

function ExtensionStatusBadge({ status }: { status: PostgresExtensionRowStatus }) {
  const t = useT()

  if (status === 'installing' || status === 'uninstalling') {
    return (
      <Badge variant="info" className="gap-1 text-[10px] shrink-0">
        <Loader2 className="h-3 w-3 animate-spin" />
        {status === 'installing' ? t('Installing') : t('Uninstalling')}
      </Badge>
    )
  }

  if (status === 'installed') {
    return (
      <Badge variant="success" className="text-[10px] shrink-0">
        {t('Installed')}
      </Badge>
    )
  }

  return (
    <Badge variant="info" className="text-[10px] shrink-0">
      {t('Available')}
    </Badge>
  )
}

function ExtensionCardSkeleton() {
  return (
    <div className={cn(RESOURCE_CARD_BASE_CLASSNAME, 'flex flex-col p-4')}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1 space-y-1.5">
          <Skeleton className="h-3.5 w-24" />
          <Skeleton className="h-3 w-16" />
        </div>
        <Skeleton className="h-5 w-16 shrink-0 rounded-full" />
      </div>
      <div className="mt-3 border-t border-border pt-3">
        <Skeleton className="h-8 w-full rounded-md" />
      </div>
    </div>
  )
}

type ExtensionCardProps = {
  row: PostgresExtensionRow
  canManage: boolean
  atExtensionLimit: boolean
  actionPending: boolean
  onInstall: (row: PostgresExtensionRow) => void
  onUninstall: (row: PostgresExtensionRow) => void
  getInstallDisabledReason: (row: PostgresExtensionRow) => string | undefined
}

function ExtensionCard({
  row,
  canManage,
  atExtensionLimit,
  actionPending,
  onInstall,
  onUninstall,
  getInstallDisabledReason,
}: ExtensionCardProps) {
  const t = useT()
  const canInstall =
    row.status === 'available' && canManage && !atExtensionLimit
  const canUninstall = row.status === 'installed' && canManage
  const installDisabledReason = getInstallDisabledReason(row)
  const isRowBusy =
    row.status === 'installing' || row.status === 'uninstalling'
  const showActions = canInstall || canUninstall

  const installButton = (
    <Button
      type="button"
      variant="secondary"
      size="sm"
      className="h-8 w-full text-[13px]"
      disabled={!!installDisabledReason || isRowBusy || actionPending}
      onClick={() => onInstall(row)}
    >
      {t('Install')}
    </Button>
  )

  const uninstallButton = (
    <Button
      type="button"
      variant="secondary"
      size="sm"
      className="h-8 w-full text-[13px]"
      disabled={isRowBusy || actionPending}
      onClick={() => onUninstall(row)}
    >
      {t('Uninstall')}
    </Button>
  )

  return (
    <div className={cn(RESOURCE_CARD_BASE_CLASSNAME, 'flex h-full flex-col p-4')}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-mono text-[13px] font-medium text-foreground">
            {row.key}
          </p>
          {row.category ? (
            <p className="truncate text-[11px] text-muted-foreground">
              {row.category}
            </p>
          ) : null}
        </div>
        <ExtensionStatusBadge status={row.status} />
      </div>

      {showActions ? (
        <div className="mt-3 border-t border-border pt-3">
          {canInstall ? (
            installDisabledReason ? (
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex w-full">{installButton}</span>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="max-w-xs text-[12px]">
                    {installDisabledReason}
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            ) : (
              installButton
            )
          ) : (
            uninstallButton
          )}
        </div>
      ) : null}
    </div>
  )
}

export function PostgresExtensionsPanel({
  databaseId,
}: PostgresExtensionsPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const { canWrite: canManage, writeTooltip: manageWriteTooltip } =
    useDatabaseAdminOperationsAccess()

  const [searchValue, setSearchValue] = useState('')
  const [filter, setFilter] = useState<ExtensionStatusFilter>('all')
  const [selectedCategories, setSelectedCategories] = useState<Set<string>>(
    () => new Set(),
  )
  const [categoryPopoverOpen, setCategoryPopoverOpen] = useState(false)
  const [pendingInstalls, setPendingInstalls] = useState<Set<string>>(
    () => new Set(),
  )
  const [pendingUninstalls, setPendingUninstalls] = useState<Set<string>>(
    () => new Set(),
  )
  const [confirmInstall, setConfirmInstall] = useState<PostgresExtensionRow | null>(
    null,
  )
  const [confirmUninstall, setConfirmUninstall] =
    useState<PostgresExtensionRow | null>(null)

  const hasPendingOperations =
    pendingInstalls.size > 0 || pendingUninstalls.size > 0

  const {
    installed,
    available,
    metadata,
    isLoading,
    isFetching,
    error,
    refetch,
  } = usePostgresDatabaseExtensions(projectId, databaseId, {
    pollWhilePending: hasPendingOperations,
  })

  const installMutation = useInstallPostgresDatabaseExtension(
    projectId,
    databaseId,
  )
  const uninstallMutation = useUninstallPostgresDatabaseExtension(
    projectId,
    databaseId,
  )

  const maxExtensions = plan?.dedicatedDatabases?.maxExtensions ?? 0
  const atExtensionLimit =
    maxExtensions > 0 && installed.length >= maxExtensions

  const rows = useMemo(
    () =>
      buildPostgresExtensionRows({
        installed,
        available,
        pendingInstalls,
        pendingUninstalls,
        metadata,
      }),
    [installed, available, pendingInstalls, pendingUninstalls, metadata],
  )

  const filterCounts = useMemo(() => {
    const counts: Record<ExtensionStatusFilter, number> = {
      all: rows.length,
      installed: 0,
      in_progress: 0,
    }
    for (const row of rows) {
      if (row.status === 'installed' || row.status === 'uninstalling') {
        counts.installed += 1
      }
      if (row.status === 'installing' || row.status === 'uninstalling') {
        counts.in_progress += 1
      }
    }
    return counts
  }, [rows])

  const categories = useMemo(() => getPostgresExtensionCategories(rows), [rows])

  const filteredRows = useMemo(() => {
    const normalizedSearch = searchValue.trim()
    return rows.filter((row) => {
      if (!matchesExtensionStatusFilter(row.status, filter)) return false
      if (
        !matchesExtensionCategoryFilter(row.category, selectedCategories)
      ) {
        return false
      }
      if (!normalizedSearch) return true

      return matchesPostgresLocalSearch(
        normalizedSearch,
        row.name,
        row.key,
        row.category,
        row.description,
      )
    })
  }, [filter, rows, searchValue, selectedCategories])

  const displayRows = useMemo(
    () => sortPostgresExtensionRows(filteredRows, 'status', 'asc'),
    [filteredRows],
  )

  const hasPanelFilters =
    filter !== 'all' || selectedCategories.size > 0
  const hasActiveFilters = hasPanelFilters || !!searchValue.trim()

  const toggleCategory = useCallback((category: string, checked: boolean) => {
    setSelectedCategories((current) => {
      const next = new Set(current)
      if (checked) {
        next.add(category)
      } else {
        next.delete(category)
      }
      return next
    })
  }, [])

  const clearFilters = useCallback(() => {
    setFilter('all')
    setSelectedCategories(new Set())
  }, [])

  const clearResolvedPending = useCallback(() => {
    setPendingInstalls((current) => {
      const next = new Set(current)
      for (const key of current) {
        if (installed.includes(key)) next.delete(key)
      }
      return next.size === current.size ? current : next
    })

    setPendingUninstalls((current) => {
      const next = new Set(current)
      for (const key of current) {
        if (!installed.includes(key)) next.delete(key)
      }
      return next.size === current.size ? current : next
    })
  }, [installed])

  useEffect(() => {
    clearResolvedPending()
  }, [clearResolvedPending, installed, available])

  const handleInstall = async () => {
    if (!confirmInstall) return

    const extensionKey = confirmInstall.key
    setPendingInstalls((current) => new Set(current).add(extensionKey))

    try {
      await installMutation.mutateAsync(extensionKey)
      toast.success(t('Extension install started'))
      setConfirmInstall(null)
      await refetch()
    } catch (installError) {
      setPendingInstalls((current) => {
        const next = new Set(current)
        next.delete(extensionKey)
        return next
      })
      toast.error(
        getErrorMessage(installError) ?? t('Failed to install extension'),
      )
    }
  }

  const handleUninstall = async () => {
    if (!confirmUninstall) return

    const extensionKey = confirmUninstall.key
    setPendingUninstalls((current) => new Set(current).add(extensionKey))

    try {
      await uninstallMutation.mutateAsync(extensionKey)
      toast.success(t('Extension uninstall started'))
      setConfirmUninstall(null)
      await refetch()
    } catch (uninstallError) {
      setPendingUninstalls((current) => {
        const next = new Set(current)
        next.delete(extensionKey)
        return next
      })
      toast.error(
        getErrorMessage(uninstallError) ?? t('Failed to uninstall extension'),
      )
    }
  }

  const actionPending = installMutation.isPending || uninstallMutation.isPending
  const errorMessage = error ? getErrorMessage(error) : null

  const filterOptions = [
    { value: 'all' as const, label: `${t('All')} (${filterCounts.all})` },
    {
      value: 'installed' as const,
      label: `${t('Installed')} (${filterCounts.installed})`,
    },
    {
      value: 'in_progress' as const,
      label: `${t('In progress')} (${filterCounts.in_progress})`,
    },
  ]

  const emptyTitle =
    filter === 'installed'
      ? t('No extensions installed')
      : filter === 'in_progress'
        ? t('No extensions in progress')
        : searchValue.trim() && !hasPanelFilters
          ? t('No extensions match your search')
          : hasActiveFilters
            ? t('No extensions match your filters')
            : t('No extensions')

  const emptyDescription =
    filter === 'installed'
      ? t('Install an extension from the available list to extend PostgreSQL capabilities.')
      : filter === 'in_progress'
        ? t(
            'Extensions being installed or uninstalled will appear here.',
          )
        : hasActiveFilters
          ? t('Try adjusting or clearing filters.')
          : t('Extensions for this database will appear here when available.')

  const getInstallDisabledReason = (row: PostgresExtensionRow): string | undefined => {
    if (!canManage) {
      return (
        manageWriteTooltip ??
        t("You don't have permission to manage extensions.")
      )
    }
    if (atExtensionLimit) {
      return t('Upgrade your plan or uninstall an extension to install more.')
    }
    if (row.status === 'installing' || row.status === 'uninstalling') {
      return t('An extension operation is already in progress.')
    }
    return undefined
  }

  const showLoadingCards = isLoading && rows.length === 0

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <div className="flex shrink-0 items-center gap-2">
          <div className="relative w-48">
            <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t('Search extensions...')}
              value={searchValue}
              onChange={(event) => setSearchValue(event.target.value)}
              className={cn(
                'h-8 w-full ps-9 text-[13px]',
                searchValue && 'pe-9',
              )}
            />
            {searchValue ? (
              <button
                type="button"
                onClick={() => setSearchValue('')}
                className="absolute end-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                aria-label={t('Clear search')}
              >
                <X className="h-3.5 w-3.5" />
              </button>
            ) : null}
          </div>
          <PostgresExtensionsHeaderLimit
            projectId={projectId}
            databaseId={databaseId}
          />
        </div>
        <div className="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto py-1">
          <div
            className="hidden h-4 w-px shrink-0 bg-border sm:block"
            aria-hidden
          />
          <PostgresSegmentedToggle
            value={filter}
            onValueChange={setFilter}
            options={filterOptions}
            ariaLabel="Extension filters"
            variant="inline"
          />
          {categories.length > 0 ? (
            <>
              <div
                className="hidden h-4 w-px shrink-0 bg-border sm:block"
                aria-hidden
              />
              <Popover
                open={categoryPopoverOpen}
                onOpenChange={setCategoryPopoverOpen}
              >
                <PopoverTrigger asChild>
                  <Button
                    type="button"
                    variant={
                      selectedCategories.size > 0 ? 'secondary' : 'outline'
                    }
                    size="sm"
                    className="h-8 shrink-0 text-[12px]"
                    aria-label={t('Filter by category')}
                  >
                    <Tags className="me-1.5 h-3.5 w-3.5" />
                    {t('Category')}
                    {selectedCategories.size > 0 ? (
                      <span className="ms-1.5 text-muted-foreground">
                        {selectedCategories.size}
                      </span>
                    ) : null}
                  </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-56 p-2">
                  <div className="max-h-[min(40dvh,280px)] overflow-y-auto">
                    {categories.map((category) => {
                      const checked = selectedCategories.has(category)
                      return (
                        <label
                          key={category}
                          className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-[13px] hover:bg-muted/60"
                        >
                          <Checkbox
                            checked={checked}
                            onCheckedChange={(value) =>
                              toggleCategory(category, value === true)
                            }
                          />
                          <span className="min-w-0 truncate">{category}</span>
                        </label>
                      )
                    })}
                  </div>
                  {selectedCategories.size > 0 ? (
                    <div className="mt-2 border-t border-border pt-2">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 w-full text-[12px]"
                        onClick={() => setSelectedCategories(new Set())}
                      >
                        {t('All categories')}
                      </Button>
                    </div>
                  ) : null}
                </PopoverContent>
              </Popover>
            </>
          ) : null}
          {hasPanelFilters ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 shrink-0 text-[12px]"
              onClick={clearFilters}
            >
              {t('Clear filters')}
            </Button>
          ) : null}
        </div>
        <RefreshButton
          className="shrink-0"
          onClick={() => void refetch()}
          isRefreshing={isFetching}
        />
      </div>

      <div>
        {errorMessage ? (
          <EmptyState
            variant="centered"
            icon={Puzzle}
            title={t('Failed to load extensions')}
            description={errorMessage}
            isEmpty
          />
        ) : showLoadingCards ? (
          <div
            role="status"
            aria-live="polite"
            aria-busy="true"
            aria-label={t('Loading extensions...')}
          >
            <div className={RESOURCE_CARD_GRID_CLASSNAME}>
              {Array.from({ length: 6 }, (_, index) => (
                <ExtensionCardSkeleton key={index} />
              ))}
            </div>
          </div>
        ) : displayRows.length === 0 ? (
          <div className="flex min-h-[12rem] items-center justify-center">
            <div className="w-full max-w-sm">
              <EmptyState
                variant="centered"
                icon={Puzzle}
                title={emptyTitle}
                description={emptyDescription}
                isEmpty
              />
            </div>
          </div>
        ) : (
          <div className={RESOURCE_CARD_GRID_CLASSNAME}>
            {displayRows.map((row) => (
              <ExtensionCard
                key={row.key}
                row={row}
                canManage={canManage}
                atExtensionLimit={atExtensionLimit}
                actionPending={actionPending}
                onInstall={setConfirmInstall}
                onUninstall={setConfirmUninstall}
                getInstallDisabledReason={getInstallDisabledReason}
              />
            ))}
          </div>
        )}
      </div>

      {isFetching && !showLoadingCards ? (
        <p className="text-[12px] text-muted-foreground">
          {hasPendingOperations
            ? t('Waiting for extension operation to complete...')
            : t('Refreshing extensions...')}
        </p>
      ) : null}

      <AlertDialog
        open={!!confirmInstall}
        onOpenChange={(open) => {
          if (!open) setConfirmInstall(null)
        }}
      >
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-left">
            <AlertDialogTitle>{t('Install extension')}</AlertDialogTitle>
            <AlertDialogDescription className="text-[13px] mt-2">
              {t('Install')} <span className="font-medium font-mono">{confirmInstall?.key}</span>?{' '}
              {t('Installation runs in the background and may take a few minutes.')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel disabled={actionPending}>
              {t('Cancel')}
            </AlertDialogCancel>
            <Button disabled={actionPending} onClick={() => void handleInstall()}>
              {t('Install')}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog
        open={!!confirmUninstall}
        onOpenChange={(open) => {
          if (!open) setConfirmUninstall(null)
        }}
      >
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-left">
            <AlertDialogTitle>{t('Uninstall extension')}</AlertDialogTitle>
            <AlertDialogDescription className="text-[13px] mt-2">
              {t('Uninstall')} <span className="font-medium font-mono">{confirmUninstall?.key}</span>?{' '}
              {t('This action cannot be undone.')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel disabled={actionPending}>
              {t('Cancel')}
            </AlertDialogCancel>
            <Button
              variant="destructive"
              disabled={actionPending}
              onClick={() => void handleUninstall()}
            >
              {t('Uninstall')}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
