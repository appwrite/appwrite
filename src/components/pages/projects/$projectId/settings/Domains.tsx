import { useState, useMemo } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  useProjectDomains,
  useProject,
  useOrganizationDomains} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import {
  ExternalLink,
  Loader2,
  FileText,
  RefreshCw,
  Trash2,
  Globe} from 'lucide-react'
import { toast } from 'sonner'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import type { Models } from '@appwrite.io/console'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { VerifyDomainDialog } from './domains/VerifyDomain'
import { DeleteDomainDialog } from './domains/DeleteDomain'
import { ViewLogsDialog } from './domains/ViewLogs'
import { RetryDomainDialog } from './domains/RetryDomain'
import { ProxyRuleContextMenu } from './domains/ProxyRuleContextMenu'
import { domainUrl } from '@/lib/domains/url'

interface DomainsProps {
  projectId: string
  searchValue?: string
}

export function Domains({
  projectId,
  searchValue: searchValueProp = ''}: DomainsProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const region = project?.region

  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [verifyDomainOpen, setVerifyDomainOpen] = useState(false)
  const [deleteDomainOpen, setDeleteDomainOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [retryDomainOpen, setRetryDomainOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(
    null,
  )

  const { rules, isLoading } = useProjectDomains(
    projectId,
    region,
    searchValueProp,
  )
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

  const getStatusBadge = (status: string) => {
    const config = getDomainStatusBadgeConfig(status)
    return (
      <Badge
        variant={config.variant}
        className="text-[10px] shrink-0 gap-1.5"
        title={
          status === 'verifying'
            ? t('SSL certificate is being issued. This usually takes a couple of minutes.')
            : undefined
        }
      >
        {status === 'verifying' && <Loader2 className="h-3 w-3 animate-spin" />}
        {t(config.label)}
      </Badge>
    )
  }

  const handleRetry = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setRetryDomainOpen(true)
    })
  }

  const handleViewLogs = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setViewLogsOpen(true)
    })
  }

  const handleDelete = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setDeleteDomainOpen(true)
    })
  }

  const canRetry = (status: string) => {
    return status === 'created' || status === 'unverified'
  }

  const getOrganizationDomainId = (rule: Models.ProxyRule) => {
    const apex = getApexDomain(rule.domain)
    return apex ? apexToOrgDomainId.get(apex.toLowerCase()) : undefined
  }

  // Filter rules by search
  const filteredRules = useMemo(() => {
    if (!searchValueProp.trim()) return rules
    const search = searchValueProp.toLowerCase()
    return rules.filter(
      (rule) =>
        rule.domain.toLowerCase().includes(search) ||
        rule.$id.toLowerCase().includes(search),
    )
  }, [rules, searchValueProp])

  // Convert 1-indexed page to 0-indexed for pagination
  const pageIndexed = currentPage - 1
  const paginatedRules = useMemo(() => {
    const start = pageIndexed * pageSize
    const end = start + pageSize
    return filteredRules.slice(start, end)
  }, [filteredRules, pageIndexed, pageSize])

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : paginatedRules.length === 0 ? (
        <EmptyState
          icon={ExternalLink}
          title={searchValueProp ? undefined : t('No domains yet')}
          description={
            searchValueProp
              ? undefined
              : t('Add a custom domain to serve your Appwrite API on your own domain') // pragma: allowlist secret
          }
          isEmpty={!searchValueProp}
          hasFilters={!!searchValueProp}
          variant="card"
        />
      ) : (
        <>
          <div className="rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Domain')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedRules.map((rule) => (
                  <ProxyRuleContextMenu
                    key={rule.$id}
                    projectId={projectId}
                    rule={rule}
                    projectTeamId={project?.teamId}
                    apexToOrgDomainId={apexToOrgDomainId}
                    onViewLogs={handleViewLogs}
                    onRetry={handleRetry}
                    onDelete={handleDelete}
                  >
                    <TableRow>
                      <TableCell className="px-4 py-3">
                        <a
                          href={domainUrl(rule.domain)}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1.5 font-mono text-[13px] font-medium link-neutral"
                        >
                          {rule.domain}
                          <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                        </a>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          {getStatusBadge(rule.status)}
                          {rule.status !== 'verified' && (
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px]"
                              onClick={() => handleViewLogs(rule)}
                            >
                              {t('View logs')}
                            </Button>
                          )}
                          {canRetry(rule.status) && (
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px]"
                              onClick={() => handleRetry(rule)}
                            >
                              {t('Retry')}
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
                      <TableCell className="px-4 py-3 text-end">
                        <div className="flex justify-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              {rule.status !== 'verified' && (
                                <DropdownMenuItem
                                  onClick={() => handleViewLogs(rule)}
                                >
                                  <MenuItemContent icon={FileText}>{t('Logs')}</MenuItemContent>
                                </DropdownMenuItem>
                              )}
                              {canRetry(rule.status) && (
                                <DropdownMenuItem
                                  onClick={() => handleRetry(rule)}
                                >
                                  <MenuItemContent icon={RefreshCw}>{t('Retry')}</MenuItemContent>
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
                                        domainId: orgDomainId}})
                                  }
                                }}
                                disabled={
                                  !project?.teamId ||
                                  !getApexDomain(rule.domain) ||
                                  !apexToOrgDomainId.has(
                                    getApexDomain(rule.domain)?.toLowerCase() ??
                                      '',
                                  )
                                }
                              >
                                <MenuItemContent icon={Globe}>{t('Records')}</MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() => handleDelete(rule)}
                              >
                                <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </TableCell>
                    </TableRow>
                  </ProxyRuleContextMenu>
                ))}
              </TableBody>
            </Table>
          </div>

          {filteredRules.length > 0 && (
            <Pagination
              currentPage={currentPage}
              totalItems={filteredRules.length}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setCurrentPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setCurrentPage(1)
              }}
              itemLabel={t('domains')}
            />
          )}
        </>
      )}

      {selectedRule && (
        <>
          <VerifyDomainDialog
            open={verifyDomainOpen}
            onOpenChange={setVerifyDomainOpen}
            projectId={projectId}
            region={region}
            rule={selectedRule}
            organizationDomainId={getOrganizationDomainId(selectedRule)}
            onReconfigure={(reconfigureDomain) => {
              setVerifyDomainOpen(false)
              setSelectedRule(null)
              navigate({
                to: '/projects/$projectId/settings/domains/add',
                params: { projectId },
                search: reconfigureDomain
                  ? { domain: reconfigureDomain }
                  : undefined})
            }}
            onVerifySuccess={() => {
              toast.success(t('Domain verified successfully'))
              setVerifyDomainOpen(false)
              setSelectedRule(null)
            }}
          />

          <RetryDomainDialog
            open={retryDomainOpen}
            onOpenChange={setRetryDomainOpen}
            projectId={projectId}
            region={region}
            rule={selectedRule}
            organizationDomainId={getOrganizationDomainId(selectedRule)}
            onRetrySuccess={() => {
              toast.success(t('Verification in progress'))
              setRetryDomainOpen(false)
              setSelectedRule(null)
            }}
          />

          <ViewLogsDialog
            open={viewLogsOpen}
            onOpenChange={setViewLogsOpen}
            rule={selectedRule}
          />

          <DeleteDomainDialog
            open={deleteDomainOpen}
            onOpenChange={setDeleteDomainOpen}
            projectId={projectId}
            region={region}
            rule={selectedRule}
            onDeleteSuccess={() => {
              toast.success(t('Domain has been deleted'))
              setDeleteDomainOpen(false)
              setSelectedRule(null)
            }}
          />
        </>
      )}
    </div>
  )
}
