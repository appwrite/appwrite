import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Globe, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { sitesQueryOptions } from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useT } from '@/lib/i18n/translate'
import { FirewallResourceTable } from './_components/FirewallResourceTable'

interface SitesProps {
  projectId: string
  searchValue: string
}

/** Firewall page Sites tab: every site, linking to its Firewall tab. */
export function Sites({ projectId, searchValue }: SitesProps) {
  const t = useT()
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const search = useDebouncedValue(searchValue.trim(), 300)
  // Go back to the first page in the same render the search changes, so the old page is never requested.
  const [pageSearch, setPageSearch] = useState(search)
  if (pageSearch !== search) {
    setPageSearch(search)
    setCurrentPage(0)
  }

  const { data, isLoading } = useQuery({
    ...sitesQueryOptions(projectId, currentPage, pageSize, search || undefined),
    placeholderData: keepPreviousData,
  })
  const sites = data?.sites ?? []
  const total = data?.total ?? 0

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : sites.length === 0 && !search ? (
        <EmptyState
          icon={Globe}
          variant="card"
          title={t('No sites yet')}
          description={t('Create a site to protect it with Firewall rules.')}
          action={
            <Button size="sm" variant="outline" asChild>
              <Link to="/projects/$projectId/sites" params={{ projectId }}>
                {t('Create site')}
              </Link>
            </Button>
          }
        />
      ) : sites.length === 0 ? (
        <EmptyState icon={Globe} hasFilters variant="card" />
      ) : (
        <>
          <FirewallResourceTable
            projectId={projectId}
            resourceType="sites"
            label={t('Site')}
            rows={sites.map((site) => ({
              id: site.$id,
              name: site.name || t('Unnamed Site'),
              icon: <FrameworkIcon framework={site.framework} size="sm" />,
              updatedAt: site.$updatedAt,
            }))}
          />
          {total > 0 && (
            <Pagination
              currentPage={currentPage + 1}
              totalItems={total}
              pageSize={pageSize}
              onPageChange={(page) => setCurrentPage(page - 1)}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setCurrentPage(0)
              }}
              itemLabel={t('sites')}
            />
          )}
        </>
      )}
    </div>
  )
}
