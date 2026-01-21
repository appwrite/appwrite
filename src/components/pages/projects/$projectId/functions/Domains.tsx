import { useState } from 'react'
import { useParams, useSearch } from '@tanstack/react-router'
import { MoreHorizontal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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

export function FunctionDomains() {
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
                  <TableRow>
                    <TableHead>Domain</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead className="w-[80px]">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rules.map((rule) => {
                    const statusBadge = getDomainStatusBadge(rule.status)

                    return (
                      <TableRow key={rule.$id}>
                        <TableCell>
                          <div className="flex items-center gap-2">
                            <a
                              href={`https://${rule.domain}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="font-mono text-[13px] text-foreground hover:underline"
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
                        <TableCell className="text-[13px] text-muted-foreground">
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
                        <TableCell>
                          <Badge variant={statusBadge.variant}>
                            {statusBadge.label}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <DateTooltip date={rule.$createdAt} />
                        </TableCell>
                        <TableCell>
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
            />
          </>
        ) : (
          <div className="flex h-full items-center justify-center py-16">
            <div className="text-center">
              <p className="mb-1 text-[14px] font-medium text-foreground">
                {searchValue
                  ? 'No domains found'
                  : 'Use a custom domain for your function'}
              </p>
              <p className="mb-4 text-[13px] text-muted-foreground">
                {searchValue
                  ? 'No domains match your search. Try a different query.'
                  : 'Connect a custom domain to your function for a branded experience.'}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
