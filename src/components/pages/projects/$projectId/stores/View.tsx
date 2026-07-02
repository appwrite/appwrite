import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Package } from 'lucide-react'
import {
  RESOURCE_CARD_GRID_4_COL_CLASSNAME,
  RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
} from '../shared/ResourceCard'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useDistributionApps } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { CreateApp } from './_components/CreateApp'
import { PlatformIcons, frameworkLabel } from './_components/platform'

const MIN_SEARCH_LENGTH = 2

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { viewMode, setViewMode } = useServiceListViewMode('stores')

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState<string | undefined>(undefined)
  const [page, setPage] = useState(1)
  const [createOpen, setCreateOpen] = useState(false)
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      setSearch(trimmed || undefined)
      setPage(1)
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [searchInput])

  const { apps, total, isLoading } = useDistributionApps(
    projectId,
    page - 1,
    GRID_DEFAULT_PAGE_SIZE,
    search,
  )

  const showLoading = isLoading && apps.length === 0
  const hasFilters = !!(search && search.length > 0)

  const emptyState = useMemo(
    () => (
      <EmptyState
        icon={Package}
        title={t('No distribution apps yet')}
        description={t(
          'Create an app to build and submit to Google Play, the App Store, and the Microsoft Store.',
        )}
        isEmpty={!hasFilters}
        hasFilters={hasFilters}
        variant="card"
      />
    ),
    [hasFilters, t],
  )

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Distribution')}
        searchPlaceholder={t('Search apps...')}
        searchValue={searchInput}
        onSearchChange={(value) => {
          setSearchInput(value)
          setPage(1)
        }}
        createLabel={t('Create app')}
        onCreate={() => setCreateOpen(true)}
        fullWidthBorder
        rightContent={
          <ServiceListViewToggle
            viewMode={viewMode}
            onViewModeChange={setViewMode}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading apps...')}
            </p>
          </div>
        ) : apps.length === 0 ? (
          emptyState
        ) : viewMode === 'list' ? (
          <>
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('App')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Platforms')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Identifier')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Updated')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {apps.map((app) => (
                    <TableRow
                      key={app.$id}
                      className={cn(
                        'cursor-pointer transition-colors border-b border-border/50 hover:bg-muted/30',
                      )}
                      onClick={() =>
                        navigate({
                          to: '/projects/$projectId/stores/$appId',
                          params: { projectId: projectId!, appId: app.$id },
                        })
                      }
                    >
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Package className="h-4 w-4" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="truncate text-[13px] font-medium text-foreground">
                              {app.name}
                            </p>
                            <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                              {frameworkLabel(app.framework)}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <PlatformIcons platforms={app.platforms} />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="truncate text-[12px] text-muted-foreground font-mono">
                          {app.applicationId ||
                            app.bundleId ||
                            app.packageIdentity ||
                            '-'}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={app.$updatedAt}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={GRID_DEFAULT_PAGE_SIZE}
              pageSizeOptions={[12, 18, 36, 72]}
              onPageChange={setPage}
              onPageSizeChange={() => setPage(1)}
              showPageSizeSelector={false}
              itemLabel={t('apps')}
            />
          </>
        ) : (
          <>
            <div className={RESOURCE_CARD_GRID_4_COL_CLASSNAME}>
              {apps.map((app) => (
                <Link
                  key={app.$id}
                  to="/projects/$projectId/stores/$appId"
                  params={{ projectId: projectId!, appId: app.$id }}
                  className="block min-w-0 group"
                >
                  <div
                    className={cn(
                      RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
                      'h-auto',
                    )}
                  >
                    <div className="px-4 pt-4 pb-0">
                      <div className="flex items-start justify-between gap-3 min-w-0">
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Package className="h-4 w-4" />
                          </div>
                          <div className="min-w-0">
                            <h3 className="truncate text-[14px] font-medium text-foreground">
                              {app.name}
                            </h3>
                            <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                              {frameworkLabel(app.framework)}
                            </p>
                          </div>
                        </div>
                        <PlatformIcons
                          platforms={app.platforms}
                          className="pt-1"
                        />
                      </div>
                      <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
                        <div className="flex min-w-0 items-center justify-between gap-2">
                          <span className="truncate text-[12px] text-muted-foreground font-mono">
                            {app.applicationId ||
                              app.bundleId ||
                              app.packageIdentity ||
                              '-'}
                          </span>
                          <DateTooltip
                            date={app.$updatedAt}
                            className="shrink-0 text-[12px] text-muted-foreground"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </Link>
              ))}
            </div>
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={GRID_DEFAULT_PAGE_SIZE}
              pageSizeOptions={[12, 18, 36, 72]}
              onPageChange={setPage}
              onPageSizeChange={() => setPage(1)}
              showPageSizeSelector={false}
              itemLabel={t('apps')}
            />
          </>
        )}
      </div>

      <CreateApp open={createOpen} onOpenChange={setCreateOpen} />
    </div>
  )
}
