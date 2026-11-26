import { useState, useMemo, useEffect } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  useProjectDomains,
  useProject,
  useOrganizationDomains,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
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
import {
  isWaitingForDns,
} from '@/lib/utils/status-badge'
import {
  ExternalLink,
  Loader2,
  FileText,
  Trash2,
  Globe,
} from 'lucide-react'
import { toast } from 'sonner'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DomainsEmptyState } from './_components/DomainsEmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import type { Models } from '@appwrite.io/console'
import {
  canRetryRule,
  canViewRuleLogs,
  getApexDomain,
} from '@/lib/utils/proxy-domains'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { DeleteDomainDialog } from './domains/DeleteDomain'
import { ViewLogsDialog } from './domains/ViewLogs'
import { RetryDomainDialog } from './domains/RetryDomain'
import { DomainRecordsDialog } from './domains/DomainRecords'
import { ProxyRuleContextMenu } from './domains/ProxyRuleContextMenu'
import { RuleStatusBadge } from './domains/RuleStatusBadge'
import { CheckDomain } from './domains/CheckDomain'
import { useVisitUrl } from '@/hooks/use-visit-url'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'

interface DomainsProps {
  projectId: string
  searchValue?: string
  onFirstRunEmptyChange?: (empty: boolean) => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}

export function Domains({
  projectId,
  searchValue: searchValueProp = '',
  onFirstRunEmptyChange,
  createDisabled = false,
  createDisabledTooltip,
}: DomainsProps) {
  const t = useT()
  const visitUrl = useVisitUrl()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const region = project?.region

  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_DEFAULT_PAGE_SIZE)
  const [deleteDomainOpen, setDeleteDomainOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [retryDomainOpen, setRetryDomainOpen] = useState(false)
  const [recordsOpen, setRecordsOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(
    null,
  )

  const search = useDebouncedValue(searchValueProp.trim(), 300)
  // Go back to the first page in the same render the search changes, so the old page is never requested.
  const [pageSearch, setPageSearch] = useState(search)
  if (pageSearch !== search) {
    setPageSearch(search)
    setCurrentPage(0)
  }

  const { rules, total, isLoading, isFetching, isPlaceholderData } =
    useProjectDomains(projectId, region, currentPage, pageSize, search)

  // Deleting the last rule on the last page leaves an empty page; step back to the new last page.
  useEffect(() => {
    if (!isFetching && rules.length === 0 && total > 0 && currentPage > 0) {
      setCurrentPage(Math.ceil(total / pageSize) - 1)
    }
  }, [isFetching, rules.length, total, currentPage, pageSize])
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
      setRetryDomainOpen(true)
    })
  }

  const handleRecords = (rule: Models.ProxyRule) => {
    // While DNS is pending, the records dialog is where the domain is verified
    if (isWaitingForDns(rule)) return handleRetry(rule)
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setRecordsOpen(true)
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

  const getOrganizationDomainId = (rule: Models.ProxyRule) => {
    const apex = getApexDomain(rule.domain)
    return apex ? apexToOrgDomainId.get(apex.toLowerCase()) : undefined
  }

  const showsFirstRunEmptyState =
    !isLoading && !isPlaceholderData && !search && total === 0
  useEffect(() => {
    onFirstRunEmptyChange?.(showsFirstRunEmptyState)
    return () => onFirstRunEmptyChange?.(false)
  }, [onFirstRunEmptyChange, showsFirstRunEmptyState])

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : showsFirstRunEmptyState ? (
        <DomainsEmptyState
          onAdd={() =>
            navigate({
              to: '/projects/$projectId/settings/domains/add',
              params: { projectId },
            })
          }
          addDisabled={createDisabled}
          addDisabledTooltip={createDisabledTooltip}
        />
      ) : rules.length === 0 ? (
        <EmptyState icon={ExternalLink} hasFilters variant="card" />
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
                {rules.map((rule) => (
                  <ProxyRuleContextMenu
                    key={rule.$id}
                    projectId={projectId}
                    rule={rule}
                    onViewLogs={handleViewLogs}
                    onRecords={handleRecords}
                    onDelete={handleDelete}
                  >
                    <TableRow
                      className="cursor-pointer"
                      // The row opens its DNS records; its links and buttons keep their own action.
                      onClick={(event) => {
                        if ((event.target as HTMLElement).closest('a, button'))
                          return
                        handleRecords(rule)
                      }}
                    >
                      <TableCell className="px-4 py-3">
                        <a
                          href={visitUrl(rule.domain)}
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
                          <RuleStatusBadge rule={rule} />
                          <CheckDomain
                            projectId={projectId}
                            region={region}
                            rule={rule}
                            organizationDomainId={getOrganizationDomainId(rule)}
                          />
                          {canRetryRule(rule) && !isWaitingForDns(rule) && (
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
                          live
                        />
                      </TableCell>
                      <TableCell
                        className="px-4 py-3 text-end"
                        // Its menu renders in a portal, whose clicks still bubble to the row.
                        onClick={(event) => event.stopPropagation()}
                      >
                        <div className="flex justify-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => handleRecords(rule)}
                              >
                                <MenuItemContent icon={Globe}>
                                  {t('Records')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              {canViewRuleLogs(rule) && (
                                <DropdownMenuItem
                                  onClick={() => handleViewLogs(rule)}
                                >
                                  <MenuItemContent icon={FileText}>
                                    {t('Logs')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              )}
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
                ))}
              </TableBody>
            </Table>
          </div>

          {total > 0 && (
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
          )}
        </>
      )}

      {selectedRule && (
        <>
          <RetryDomainDialog
            open={retryDomainOpen}
            onOpenChange={setRetryDomainOpen}
            projectId={projectId}
            region={region}
            rule={rules.find((r) => r.$id === selectedRule.$id) ?? selectedRule}
            organizationDomainId={getOrganizationDomainId(selectedRule)}
            onRetrySuccess={() => {
              setRetryDomainOpen(false)
              setSelectedRule(null)
            }}
          />
          <DomainRecordsDialog
            open={recordsOpen}
            onOpenChange={setRecordsOpen}
            rule={rules.find((r) => r.$id === selectedRule.$id) ?? selectedRule}
            region={region}
          />

          <ViewLogsDialog
            open={viewLogsOpen}
            onOpenChange={setViewLogsOpen}
            rule={rules.find((r) => r.$id === selectedRule.$id) ?? selectedRule}
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
