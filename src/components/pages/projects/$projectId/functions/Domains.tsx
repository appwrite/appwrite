import { useState } from 'react'
import { useParams, useSearch } from '@tanstack/react-router'
import { MoreHorizontal, Loader2 } from 'lucide-react'
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
import { useFunctionDomains } from '@/lib/react-query/hooks'
import { toast } from 'sonner'

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

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const search = useSearch({ strict: false }) as { search?: string }
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_PER_PAGE)

  const searchValue = search?.search || ''

  const { data: domainsData, isLoading: domainsLoading } = useFunctionDomains(
    projectId,
    functionId,
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
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      </div>
    )
  }

  if (rules.length === 0) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
        <div className="rounded-lg border border-border bg-card py-12">
          <EmptyState
            title={searchValue ? undefined : 'No domains yet'}
            description={
              searchValue
                ? undefined
                : 'Connect a custom domain to your function for a branded experience'
            }
            isEmpty={!searchValue}
            hasFilters={!!searchValue}
            iconSize="md"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
      <div className="space-y-0">
        <div className="rounded-lg border border-border bg-card overflow-hidden">
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
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                  Actions
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rules.map((rule) => {
                const statusBadge = getDomainStatusBadge(rule.status)

                return (
                  <TableRow key={rule.$id}>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <a
                          href={`https://${rule.domain}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="font-mono text-[13px] font-medium text-foreground hover:underline"
                        >
                          {rule.domain}
                        </a>
                        {rule.status === 'verified' && (
                          <Badge
                            variant="default"
                            className="h-5 text-[10px]"
                          >
                            Verified
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px]">
                      {rule.redirectUrl ? (
                        <span>Redirect to {rule.redirectUrl}</span>
                      ) : rule.deploymentVcsProviderBranch ? (
                        <span>
                          Deployed from {rule.deploymentVcsProviderBranch}
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
                      <DateTooltip
                        date={rule.$createdAt}
                        className="text-[12px] text-muted-foreground"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8 p-0"
                          >
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          {(rule.status === 'created' ||
                            rule.status === 'unverified') && (
                            <DropdownMenuItem
                              onClick={() => handleRetry(rule.$id)}
                            >
                              Retry
                            </DropdownMenuItem>
                          )}
                          {rule.logs && rule.logs.length > 0 && (
                            <DropdownMenuItem
                              onClick={() => handleViewLogs(rule.$id)}
                            >
                              View logs
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuItem
                            onClick={() => handleDelete(rule.$id)}
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
          className="mt-0"
        />
      </div>
    </div>
  )
}
