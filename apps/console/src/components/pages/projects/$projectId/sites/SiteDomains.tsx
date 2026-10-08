import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import {
  FileText,
  RefreshCw,
  Trash2,
  ExternalLink,
  Globe} from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
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
  TableRow} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { Loader2 } from 'lucide-react'
import {
  useSiteDomains,
  useProject,
  useOrganizationDomains} from '@/lib/react-query/hooks'
import { DeleteDomainDialog } from '@/components/pages/projects/$projectId/settings/domains/DeleteDomain'
import { ProxyRuleContextMenu } from '@/components/pages/projects/$projectId/settings/domains/ProxyRuleContextMenu'
import { ViewLogsDialog } from '@/components/pages/projects/$projectId/settings/domains/ViewLogs'
import { VerifyDomain } from './_components/VerifyDomain'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { domainUrl } from '@/lib/domains/url'

export function SiteDomainsView() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_DEFAULT_PAGE_SIZE)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [deleteDomainOpen, setDeleteDomainOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(
    null,
  )
  const [viewLogsRule, setViewLogsRule] = useState<Models.ProxyRule | null>(
    null,
  )
  const search = { search: '' } // TODO: Add search support if needed

  const searchValue = search?.search || ''

  const { rules, total, isLoading: domainsLoading } = useSiteDomains(
    projectId,
    siteId,
    currentPage,
    pageSize,
    searchValue,
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

  const handleRetry = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setVerifyOpen(true)
    })
  }

  const handleViewLogs = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setViewLogsRule(rule)
      setViewLogsOpen(true)
    })
  }

  const handleDelete = (rule: Models.ProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setDeleteDomainOpen(true)
    })
  }

  const getOrganizationDomainId = (rule: Models.ProxyRule) => {
    const apex = getApexDomain(rule.domain)
    return apex ? apexToOrgDomainId.get(apex.toLowerCase()) : undefined
  }

  if (domainsLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading domains...')}
        </p>
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
                      {t('Domain')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Type')}
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
                  {rules.map((rule) => {
                    const ruleData = rule as Models.ProxyRule
                    const statusConfig = getDomainStatusBadgeConfig(
                      ruleData.status,
                    )

                    return (
                      <ProxyRuleContextMenu
                        key={ruleData.$id}
                        projectId={projectId!}
                        rule={ruleData}
                        projectTeamId={project?.teamId}
                        apexToOrgDomainId={apexToOrgDomainId}
                        onViewLogs={handleViewLogs}
                        onRetry={handleRetry}
                        onDelete={handleDelete}
                      >
                      <TableRow>
                        <TableCell className="px-4 py-3">
                          <a
                            href={domainUrl(ruleData.domain)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 font-mono text-[13px] link-neutral"
                          >
                            {ruleData.domain}
                            <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                          </a>
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                          {ruleData.redirectUrl ? (
                            <span>
                              {t('Redirect to')} {ruleData.redirectUrl}
                            </span>
                          ) : ruleData.deploymentVcsProviderBranch ? (
                            <span>
                              {t('Deployed from')}{' '}
                              {ruleData.deploymentVcsProviderBranch}
                            </span>
                          ) : (
                            <span>{t('Active deployment')}</span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <Badge
                              variant={statusConfig.variant}
                              className="text-[10px] shrink-0 gap-1.5"
                              title={
                                ruleData.status === 'verifying'
                                  ? t(
                                      'SSL certificate is being issued. This usually takes a couple of minutes.',
                                    )
                                  : undefined
                              }
                            >
                              {ruleData.status === 'verifying' && (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              )}
                              {t(statusConfig.label)}
                            </Badge>
                            {ruleData.status !== 'verified' && (
                              <Button
                                variant="link"
                                size="sm"
                                className="h-auto p-0 text-[13px]"
                                onClick={() => handleViewLogs(ruleData)}
                              >
                                {t('View logs')}
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
                                {t('Retry')}
                              </Button>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <DateTooltip date={ruleData.$createdAt} />
                        </TableCell>
                        <TableCell className="px-4 py-3 text-end">
                          <div className="flex justify-end">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <RowActionsMenuTrigger />
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                {ruleData.status !== 'verified' && (
                                  <DropdownMenuItem
                                    onClick={() => handleViewLogs(ruleData)}
                                  >
                                    <MenuItemContent icon={FileText}>
                                      {t('Logs')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                )}
                                {(ruleData.status === 'created' ||
                                  ruleData.status === 'unverified') && (
                                  <DropdownMenuItem
                                    onClick={() => handleRetry(ruleData)}
                                  >
                                    <MenuItemContent icon={RefreshCw}>
                                      {t('Retry')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                  onClick={() => {
                                    const apex = getApexDomain(ruleData.domain)
                                    const orgDomainId = apex
                                      ? apexToOrgDomainId.get(
                                          apex.toLowerCase(),
                                        )
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
                                    !getApexDomain(ruleData.domain) ||
                                    !apexToOrgDomainId.has(
                                      getApexDomain(
                                        ruleData.domain,
                                      )?.toLowerCase() ?? '',
                                    )
                                  }
                                >
                                  <MenuItemContent icon={Globe}>
                                    {t('Records')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() => handleDelete(ruleData)}
                                >
                                  <MenuItemContent icon={Trash2}>
                                    {t('Delete')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </TableCell>
                      </TableRow>
                      </ProxyRuleContextMenu>
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
              itemLabel={t('domains')}
            />
          </>
        ) : (
          <EmptyState
            title={searchValue ? undefined : t('No domains yet')}
            description={
              searchValue
                ? undefined
                : t(
                    'Connect a custom domain to your site for a branded experience',
                  )
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
            organizationDomainId={getOrganizationDomainId(selectedRule)}
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
              toast.success(t('Domain has been deleted'))
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
