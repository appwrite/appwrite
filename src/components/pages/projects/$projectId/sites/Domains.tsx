import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  MoreHorizontal,
  AlertCircle,
  Loader2,
  CheckCircle2,
  Globe,
} from 'lucide-react'
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
import {
  getDomainStatusVariant,
  getStatusColor,
  type DomainStatus,
} from '@/lib/utils/status-badge'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

const DOMAINS_PER_PAGE = 25

function getStatusBadge(status: string) {
  const variant = getDomainStatusVariant(status as DomainStatus)

  if (variant === null) {
    return (
      <Badge
        variant="outline"
        className={cn('gap-1.5 border', getStatusColor('verified'))}
      >
        <CheckCircle2 className="h-3 w-3" />
        Verified
      </Badge>
    )
  }

  const statusConfig: Record<
    string,
    { icon: typeof AlertCircle; label: string; spin?: boolean }
  > = {
    created: { icon: AlertCircle, label: 'Verification failed' },
    verifying: { icon: Loader2, label: 'Generating certificate', spin: true },
    unverified: { icon: AlertCircle, label: 'Certificate generation failed' },
  }

  const config = statusConfig[status] || { icon: AlertCircle, label: status }
  const Icon = config.icon

  return (
    <Badge variant={variant} className="gap-1.5">
      <Icon className={cn('h-3 w-3', config.spin && 'animate-spin')} />
      {config.label}
    </Badge>
  )
}

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_PER_PAGE)
  const search = { search: '' } // TODO: Add search support if needed

  const searchValue = search?.search || ''

  const { rules, total, isLoading: domainsLoading } = useSiteDomains(
    projectId,
    siteId,
    currentPage,
    pageSize,
    searchValue,
  )

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
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]">
                      Actions
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rules.map((rule) => {
                    const ruleData = rule as Models.ProxyRule

                    return (
                      <TableRow key={ruleData.$id}>
                        <TableCell className="px-4 py-3">
                          <a
                            href={`https://${ruleData.domain}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-mono text-[13px] text-foreground hover:underline"
                          >
                            {ruleData.domain}
                          </a>
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                          {ruleData.redirectUrl ? (
                            <span>Redirect to {ruleData.redirectUrl}</span>
                          ) : ruleData.deploymentVcsProviderBranch ? (
                            <span>
                              Deployed from {ruleData.deploymentVcsProviderBranch}
                            </span>
                          ) : (
                            <span>Active deployment</span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          {getStatusBadge(ruleData.status)}
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <DateTooltip date={ruleData.$createdAt} />
                        </TableCell>
                        <TableCell className="px-4 py-3 text-right">
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
            icon={Globe}
            title={searchValue ? undefined : 'No domains yet'}
            description={
              searchValue
                ? undefined
                : 'Connect a custom domain to your site for a branded experience'
            }
            isEmpty={!searchValue}
            hasFilters={!!searchValue}
            variant="card"
          />
        )}
      </div>
    </div>
  )
}
