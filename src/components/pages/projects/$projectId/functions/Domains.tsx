import { useState, useMemo } from 'react'
import { useParams, useSearch, useNavigate } from '@tanstack/react-router'
import {
  MoreHorizontal,
  Loader2,
  FileText,
  RefreshCw,
  Trash2,
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
import {
  useFunctionDomains,
  useProject,
  useOrganizationDomains,
} from '@/lib/react-query/hooks'
import { VerifyDomain } from './_components/VerifyDomain'
import { ViewLogsDialog } from '@/components/pages/projects/$projectId/settings/domains/ViewLogs'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

const DOMAINS_PER_PAGE = 25

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const search = useSearch({ strict: false }) as { search?: string }
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_PER_PAGE)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(null)
  const [viewLogsRule, setViewLogsRule] = useState<Models.ProxyRule | null>(
    null,
  )

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

  const { domains: orgDomains } = useOrganizationDomains(
    project?.teamId,
    0,
    500,
  )
  const apexToOrgDomainId = useMemo(() => {
    const map = new Map<string, string>()
    for (const d of orgDomains) {
      if (d.domain) map.set(d.domain.toLowerCase(), d.$id)
    }
    return map
  }, [orgDomains])

  const handleRetry = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setVerifyOpen(true)
  }

  const handleViewLogs = (rule: Models.ProxyRule) => {
    setViewLogsRule(rule)
    setViewLogsOpen(true)
  }

  const handleDelete = (_ruleId: string) => {
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

  const domainsContent = rules.length === 0 ? (
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
  ) : (
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
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rules.map((rule) => {
                const statusConfig = getDomainStatusBadgeConfig(rule.status)

                return (
                  <TableRow key={rule.$id}>
                    <TableCell className="px-4 py-3">
                      <a
                        href={`https://${rule.domain}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-mono text-[13px] font-medium text-foreground hover:underline"
                      >
                        {rule.domain}
                      </a>
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
                      <div className="flex items-center gap-2">
                        <Badge
                          variant={statusConfig.variant}
                          className="text-[10px] shrink-0 gap-1.5"
                          title={
                            rule.status === 'verifying'
                              ? 'SSL certificate is being issued. This usually takes a couple of minutes.'
                              : undefined
                          }
                        >
                          {rule.status === 'verifying' && (
                            <Loader2 className="h-3 w-3 animate-spin" />
                          )}
                          {statusConfig.label}
                        </Badge>
                        {rule.status !== 'verified' && (
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px]"
                            onClick={() => handleViewLogs(rule)}
                          >
                            View logs
                          </Button>
                        )}
                        {(rule.status === 'created' ||
                          rule.status === 'unverified') && (
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px]"
                            onClick={() => handleRetry(rule)}
                          >
                            Retry
                          </Button>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip
                        date={rule.$createdAt}
                        className="text-[12px] text-muted-foreground"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <div className="flex justify-end">
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
                            {rule.status !== 'verified' && (
                              <DropdownMenuItem
                              onClick={() => handleViewLogs(rule)}
                            >
                              <FileText className="mr-2 h-4 w-4" />
                              View logs
                            </DropdownMenuItem>
                            )}
                            {(rule.status === 'created' ||
                              rule.status === 'unverified') && (
                              <DropdownMenuItem
                              onClick={() => handleRetry(rule)}
                            >
                              <RefreshCw className="mr-2 h-4 w-4" />
                              Retry
                            </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                              onClick={() => {
                              const apex = getApexDomain(rule.domain)
                              const orgDomainId = apex
                                ? apexToOrgDomainId.get(apex.toLowerCase())
                                : undefined
                              if (project?.teamId && orgDomainId) {
                                navigate({
                                  to: '/organizations/$orgId/domains/$domainId',
                                  params: {
                                    orgId: project.teamId,
                                    domainId: orgDomainId,
                                  },
                                })
                              }
                            }}
                            disabled={
                              !project?.teamId ||
                              !getApexDomain(rule.domain) ||
                              !apexToOrgDomainId.has(
                                getApexDomain(rule.domain)?.toLowerCase() ?? '',
                              )
                            }
                          >
                            <FileText className="mr-2 h-4 w-4" />
                            DNS Records
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() => handleDelete(rule.$id)}
                            >
                              <Trash2 className="mr-2 h-4 w-4" />
                              Delete
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
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

  return (
    <>
      {domainsContent}
      {selectedRule && (
        <VerifyDomain
          open={verifyOpen}
          onOpenChange={setVerifyOpen}
          projectId={projectId ?? ''}
          rule={selectedRule}
          region={project?.region}
          onVerifySuccess={() => setSelectedRule(null)}
          onReconfigure={() => {
            setVerifyOpen(false)
            setSelectedRule(null)
            navigate({
              to: '/projects/$projectId/functions/$functionId/domains/add',
              params: { projectId: projectId!, functionId: functionId! },
            })
          }}
        />
      )}
      {viewLogsRule && (
        <ViewLogsDialog
          open={viewLogsOpen}
          onOpenChange={(open) => {
            setViewLogsOpen(open)
            if (!open) setViewLogsRule(null)
          }}
          rule={viewLogsRule}
        />
      )}
    </>
  )
}
