import { useState, useMemo, useRef, useEffect } from 'react'
import { useParams, useNavigate, useSearch } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import {
  Loader2,
  Globe,
  FileText,
  RefreshCw,
  Trash2,
  ExternalLink} from 'lucide-react'
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
import {
  siteDomainsQueryOptions,
  useProject,
  useOrganizationDomains} from '@/lib/react-query/hooks'
import { VerifyDomain } from './_components/VerifyDomain'
import { ViewLogsDialog } from '@/components/pages/projects/$projectId/settings/domains/ViewLogs'
import { DeleteDomainDialog } from '@/components/pages/projects/$projectId/settings/domains/DeleteDomain'
import { ProxyRuleContextMenu } from '@/components/pages/projects/$projectId/settings/domains/ProxyRuleContextMenu'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { queryParamToMap } from '@/lib/table-filters'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { domainUrl } from '@/lib/domains/url'

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as
    | { search?: string; query?: string }
    | undefined
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

  const searchValue = search?.search ?? ''
  const filterMap = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

  const {
    data: domainsData,
    isLoading: domainsLoading,
    isFetching: domainsFetching} = useQuery(
    siteDomainsQueryOptions(
      projectId,
      siteId,
      currentPage,
      pageSize,
      searchValue,
      filterQueries,
    ),
  )

  const rulesFromApi = domainsData?.rules || []
  const total = domainsData?.total || 0

  const lastRulesRef = useRef<typeof rulesFromApi>([])
  useEffect(() => {
    if (!domainsFetching && rulesFromApi.length > 0) {
      lastRulesRef.current = rulesFromApi
    }
  }, [domainsFetching, rulesFromApi])
  const rules =
    domainsFetching && lastRulesRef.current.length > 0
      ? lastRulesRef.current
      : rulesFromApi

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

  if (domainsLoading && rules.length === 0 && !domainsFetching) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      </div>
    )
  }

  const hasFilters = filterMap.size > 0 || !!searchValue
  const domainsContent =
    rules.length === 0 ? (
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
        <EmptyState
          icon={Globe}
          title={hasFilters ? undefined : t('No domains yet')}
          description={
            hasFilters
              ? undefined
              : t(
                  'Connect a custom domain to your site for a branded experience',
                )
          }
          isEmpty={!hasFilters}
          hasFilters={hasFilters}
          variant="card"
          iconSize="md"
        />
      </div>
    ) : (
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
        <div className="space-y-0">
          <div className="rounded-lg border border-border bg-card overflow-hidden">
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
                  const statusConfig = getDomainStatusBadgeConfig(rule.status)

                  return (
                    <ProxyRuleContextMenu
                      key={rule.$id}
                      projectId={projectId!}
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
                        <TableCell className="px-4 py-3 text-[13px]">
                          {rule.redirectUrl ? (
                            <span>
                              {t('Redirect to')} {rule.redirectUrl}
                            </span>
                          ) : rule.deploymentVcsProviderBranch ? (
                            <span>
                              {t('Deployed from')}{' '}
                              {rule.deploymentVcsProviderBranch}
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
                                rule.status === 'verifying'
                                  ? t(
                                      'SSL certificate is being issued. This usually takes a couple of minutes.',
                                    )
                                  : undefined
                              }
                            >
                              {rule.status === 'verifying' && (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              )}
                              {t(statusConfig.label)}
                            </Badge>
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
                            {(rule.status === 'created' ||
                              rule.status === 'unverified') && (
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
                                    <MenuItemContent icon={FileText}>
                                      {t('Logs')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                )}
                                {(rule.status === 'created' ||
                                  rule.status === 'unverified') && (
                                  <DropdownMenuItem
                                    onClick={() => handleRetry(rule)}
                                  >
                                    <MenuItemContent icon={RefreshCw}>
                                      {t('Retry')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                  onClick={() => {
                                    const apex = getApexDomain(rule.domain)
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
                                    !getApexDomain(rule.domain) ||
                                    !apexToOrgDomainId.has(
                                      getApexDomain(
                                        rule.domain,
                                      )?.toLowerCase() ?? '',
                                    )
                                  }
                                >
                                  <MenuItemContent icon={Globe}>
                                    {t('Records')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() => handleDelete(rule)}
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
            className="mt-0"
          />
        </div>
      </div>
    )

  return (
    <>
      {domainsContent}
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
              setSelectedRule(null)
            }}
            onReconfigure={() => {
              setVerifyOpen(false)
              setSelectedRule(null)
              navigate({
                to: '/projects/$projectId/sites/$siteId/domains/add',
                params: { projectId: projectId!, siteId: siteId! }})
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
    </>
  )
}
