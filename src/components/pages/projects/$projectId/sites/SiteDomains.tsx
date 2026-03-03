import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import {
  MoreHorizontal,
  FileText,
  RefreshCw,
  Trash2,
  ExternalLink,
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
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { Loader2 } from 'lucide-react'
import {
  useSiteDomains,
  useProject,
  useOrganizationDomains,
} from '@/lib/react-query/hooks'
import { ViewLogsDialog } from '@/components/pages/projects/$projectId/settings/domains/ViewLogs'
import { DeleteDomainDialog } from '@/components/pages/projects/$projectId/settings/domains/DeleteDomain'
import { VerifyDomain } from './_components/VerifyDomain'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

const DOMAINS_PER_PAGE = 25

export function SiteDomainsView() {
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_PER_PAGE)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [deleteDomainOpen, setDeleteDomainOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(null)
  const [viewLogsRule, setViewLogsRule] = useState<Models.ProxyRule | null>(null)
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

  const handleDelete = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setDeleteDomainOpen(true)
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
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rules.map((rule) => {
                    const ruleData = rule as Models.ProxyRule
                    const statusConfig = getDomainStatusBadgeConfig(ruleData.status)

                    return (
                      <TableRow key={ruleData.$id}>
                        <TableCell className="px-4 py-3">
                          <a
                            href={`https://${ruleData.domain}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 font-mono text-[13px] text-foreground hover:underline"
                          >
                            {ruleData.domain}
                            <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                          </a>
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
                          <div className="flex items-center gap-2">
                            <Badge
                              variant={statusConfig.variant}
                              className="text-[10px] shrink-0 gap-1.5"
                              title={
                                ruleData.status === 'verifying'
                                  ? 'SSL certificate is being issued. This usually takes a couple of minutes.'
                                  : undefined
                              }
                            >
                              {ruleData.status === 'verifying' && (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              )}
                              {statusConfig.label}
                            </Badge>
                            {ruleData.status !== 'verified' && (
                              <Button
                                variant="link"
                                size="sm"
                                className="h-auto p-0 text-[13px]"
                                onClick={() => handleViewLogs(ruleData)}
                              >
                                View logs
                              </Button>
                            )}
                            {(ruleData.status === 'created' ||
                              ruleData.status === 'unverified') && (
                              <Button
                                variant="link"
                                size="sm"
                                className="h-auto p-0 text-[13px]"
                                onClick={() => handleRetry(ruleData)}
                              >
                                Retry
                              </Button>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <DateTooltip date={ruleData.$createdAt} />
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
                              {ruleData.status !== 'verified' && (
                                <DropdownMenuItem
                                  onClick={() => handleViewLogs(ruleData)}
                                >
                                  <FileText className="mr-2 h-4 w-4" />
                                  View logs
                                </DropdownMenuItem>
                              )}
                              {(ruleData.status === 'created' ||
                                ruleData.status === 'unverified') && (
                                <DropdownMenuItem
                                  onClick={() => handleRetry(ruleData)}
                                >
                                  <RefreshCw className="mr-2 h-4 w-4" />
                                  Retry
                                </DropdownMenuItem>
                              )}
                              <DropdownMenuItem
                                onClick={() => {
                                  const apex = getApexDomain(ruleData.domain)
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
                                  !getApexDomain(ruleData.domain) ||
                                  !apexToOrgDomainId.has(
                                    getApexDomain(ruleData.domain)?.toLowerCase() ?? '',
                                  )
                                }
                              >
                                <FileText className="mr-2 h-4 w-4" />
                                DNS Records
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() => handleDelete(ruleData)}
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

      {selectedRule && (
        <>
          <VerifyDomain
            open={verifyOpen}
            onOpenChange={setVerifyOpen}
            projectId={projectId ?? ''}
            rule={selectedRule}
            region={project?.region}
            onVerifySuccess={() => {
              setVerifyOpen(false)
              setSelectedRule(null)
            }}
            onReconfigure={() => {
              setVerifyOpen(false)
              setSelectedRule(null)
            }}
          />
          <DeleteDomainDialog
            open={deleteDomainOpen}
            onOpenChange={setDeleteDomainOpen}
            projectId={projectId ?? ''}
            region={project?.region}
            rule={selectedRule}
            onDeleteSuccess={() => {
              toast.success('Domain has been deleted')
              setDeleteDomainOpen(false)
              setSelectedRule(null)
            }}
          />
        </>
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
    </div>
  )
}
