import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { MoreHorizontal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import { useSiteDomains } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

const DOMAINS_PER_PAGE = 25

function getDomainStatusBadge(status: string) {
  const statusMap: Record<
    string,
    {
      label: string
      variant: 'default' | 'secondary' | 'destructive' | 'outline'
    }
  > = {
    verified: { label: 'Verified', variant: 'default' },
    verifying: { label: 'Generating certificate', variant: 'secondary' },
    created: { label: 'Verification failed', variant: 'destructive' },
    unverified: {
      label: 'Certificate generation failed',
      variant: 'destructive',
    },
  }
  return statusMap[status] || { label: status, variant: 'outline' }
}

export function SiteDomainsView() {
  const { projectId, siteId } = useParams({ strict: false })
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_PER_PAGE)
  const search = { search: '' } // TODO: Add search support if needed

  const searchValue = search?.search || ''

  const { data: domainsData, isLoading: domainsLoading } = useSiteDomains(
    projectId,
    siteId,
    currentPage,
    pageSize,
    searchValue,
  )

  const rules = domainsData?.rules || []
  const total = domainsData?.total || 0

  const handleRetry = (ruleId: string) => {
    // TODO: Implement retry verification
    toast.info('Retry verification coming soon')
  }

  const handleViewLogs = (ruleId: string) => {
    // TODO: Open logs modal
    toast.info('View logs coming soon')
  }

  const handleDelete = (ruleId: string) => {
    // TODO: Implement delete with confirmation
    toast.info('Delete domain coming soon')
  }

  if (domainsLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading domains...</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        {/* Domains Table */}
        {rules.length > 0 ? (
          <>
            <div className="rounded-lg border border-border">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Domain
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Type
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Status
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Created
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]">
                      Actions
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rules.map((rule) => {
                    const ruleData = rule as Models.ProxyRule
                    const statusBadge = getDomainStatusBadge(ruleData.status)

                    return (
                      <TableRow key={ruleData.$id}>
                        <TableCell>
                          <div className="flex items-center gap-2">
                            <a
                              href={`https://${ruleData.domain}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="font-mono text-[13px] text-foreground hover:underline"
                            >
                              {ruleData.domain}
                            </a>
                            {ruleData.status === 'verified' && (
                              <Badge
                                variant="default"
                                className="h-5 text-[10px]"
                              >
                                Verified
                              </Badge>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                          {ruleData.redirectUrl ? (
                            <span>Redirect to {ruleData.redirectUrl}</span>
                          ) : ruleData.deploymentVcsProviderBranch ? (
                            <span>
                              Deployed from{' '}
                              {ruleData.deploymentVcsProviderBranch}
                            </span>
                          ) : (
                            <span>Active deployment</span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Badge variant={statusBadge.variant}>
                            {statusBadge.label}
                          </Badge>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <DateTooltip date={ruleData.$createdAt} />
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                variant="outline"
                                size="sm"
                                className="h-8 w-8 p-0"
                              >
                                <MoreHorizontal className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              {(ruleData.status === 'created' ||
                                ruleData.status === 'unverified') && (
                                <DropdownMenuItem
                                  onClick={() => handleRetry(ruleData.$id)}
                                >
                                  Retry
                                </DropdownMenuItem>
                              )}
                              {ruleData.logs && ruleData.logs.length > 0 && (
                                <DropdownMenuItem
                                  onClick={() => handleViewLogs(ruleData.$id)}
                                >
                                  View logs
                                </DropdownMenuItem>
                              )}
                              <DropdownMenuItem
                                onClick={() => handleDelete(ruleData.$id)}
                              >
                                Delete
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>

            <Pagination
              currentPage={currentPage + 1}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={(page) => setCurrentPage(page - 1)}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setCurrentPage(0)
              }}
              itemLabel="domains"
            />
          </>
        ) : (
          <EmptyState
            title={searchValue ? undefined : 'No domains yet'}
            description={
              searchValue
                ? undefined
                : 'Connect a custom domain to your site for a branded experience'
            }
            isEmpty={!searchValue}
            hasFilters={!!searchValue}
            variant="centered"
          />
        )}
      </div>
    </div>
  )
}
