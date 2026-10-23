import { useEffect, useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Loader2, RefreshCw, Trash2, Mail, Plus } from 'lucide-react'
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
  useFunctionSmtpDomains,
  useProject,
  useProjectFunction,
  useVerifyDomain,
} from '@/lib/react-query/hooks'
import { DeleteDomainDialog } from '@/components/pages/projects/$projectId/settings/domains/DeleteDomain'
import {
  VerifySmtpDomainContent,
  dnsPendingVerificationError,
} from './VerifySmtpDomainContent'
import type { DomainVerificationError } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import {
  isFunctionEmailEnabled,
  type FunctionWithEmail,
} from '@/lib/function-email'
import {
  needsSmtpDnsVerification,
  type SmtpProxyRule,
} from '@/lib/function-smtp-domain'
import { useQueryClient } from '@tanstack/react-query'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

function smtpStatusLabel(status: string, t: (s: string) => string): string {
  switch (status) {
    case 'verified':
      return t('Verified')
    case 'verifying':
      return t('Verifying')
    case 'created':
    case 'unverified':
      return t('Unverified')
    default:
      return status
  }
}

/**
 * Custom inbound email (SMTP) domains for a function, behind the
 * `functionEmailDomains` flag. Lives on the Domains tab, separate from HTTP
 * domains and the default address on the Executions Email card.
 */
export function SmtpDomainsSection() {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId, functionId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { data: func } = useProjectFunction(projectId, functionId)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<SmtpProxyRule | null>(null)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)
  const [currentPage, setCurrentPage] = useState(0)
  const [pageSize, setPageSize] = useState(DOMAINS_DEFAULT_PAGE_SIZE)

  const verifyMutation = useVerifyDomain(projectId, project?.region)

  const { data, isLoading, isFetching, refetch } = useFunctionSmtpDomains(
    projectId,
    functionId,
    currentPage,
    pageSize,
  )

  // Deleting the last domain on the last page leaves that page empty; step
  // back to the last page that still has rows so the list and its
  // pagination stay reachable.
  useEffect(() => {
    if (!data || currentPage === 0) return
    const lastPage = Math.max(0, Math.ceil(data.total / pageSize) - 1)
    if (currentPage > lastPage) setCurrentPage(lastPage)
  }, [data, currentPage, pageSize])

  const rules = (data?.rules ?? []) as SmtpProxyRule[]
  const total = data?.total ?? 0
  const emailEnabled = isFunctionEmailEnabled(
    func as FunctionWithEmail | undefined,
  )

  const goAdd = () => {
    navigate({
      to: '/projects/$projectId/functions/$functionId/domains/add',
      params: { projectId: projectId!, functionId: functionId! },
      search: { protocol: 'smtp' },
    })
  }

  const openVerify = (rule: SmtpProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setVerificationError(null)
      setSelectedRule(rule)
      setVerifyOpen(true)
    })
  }

  const openDelete = (rule: SmtpProxyRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedRule(rule)
      setDeleteOpen(true)
    })
  }

  const handleVerify = async () => {
    if (!selectedRule) return
    setVerificationError(null)
    try {
      const updated = (await verifyMutation.mutateAsync(
        selectedRule.$id,
      )) as SmtpProxyRule
      if (updated.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'smtp', 'function', projectId, functionId],
        })
        toast.success(t('Email domain verified'))
        setVerifyOpen(false)
        setSelectedRule(null)
      } else {
        setSelectedRule(updated)
        setVerificationError(dnsPendingVerificationError(t))
      }
    } catch {
      setVerificationError(dnsPendingVerificationError(t))
    }
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pt-4 sm:px-6 sm:pt-6 space-y-3">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h2 className="text-[15px] font-semibold text-foreground">
            {t('Email domains')}
          </h2>
          <p className="text-[13px] text-muted-foreground mt-1">
            {t(
              'Receive mail for this function on domains you own. The default address is on the Email card under Settings → Executions.',
            )}
          </p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => refetch()}
            disabled={isFetching}
          >
            {isFetching ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
            ) : (
              <RefreshCw className="h-3.5 w-3.5" />
            )}
            <span className="ms-1.5">{t('Refresh')}</span>
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            onClick={goAdd}
            disabled={!emailEnabled}
          >
            <Plus className="h-3.5 w-3.5" />
            <span className="ms-1.5">{t('Add email domain')}</span>
          </Button>
        </div>
      </div>

      {!emailEnabled ? (
        <div className="rounded-lg border border-border bg-muted/30 px-4 py-3 text-[13px] text-muted-foreground">
          {t(
            'Enable the email trigger under Settings → Executions to add email domains.',
          )}
        </div>
      ) : null}

      {isLoading && rules.length === 0 ? (
        <div className="flex items-center justify-center py-10">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      ) : rules.length === 0 ? (
        <EmptyState
          icon={Mail}
          title={t('No email domains yet')}
          description={t(
            'Add a domain you own, publish MX and TXT records, then verify to accept inbound mail.',
          )}
          isEmpty
          variant="card"
          iconSize="md"
          action={
            emailEnabled ? (
              <Button
                type="button"
                size="sm"
                className="mt-4 h-9 text-[13px]"
                onClick={goAdd}
              >
                {t('Add email domain')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="space-y-0">
          <div className="rounded-lg border border-border bg-card overflow-hidden">
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
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rules.map((rule) => {
                  const statusConfig = getDomainStatusBadgeConfig(rule.status)
                  return (
                    <TableRow key={rule.$id}>
                      <TableCell className="px-4 py-3 font-mono text-[13px] font-medium">
                        {rule.domain}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <Badge
                            variant={statusConfig.variant}
                            className="text-[10px] shrink-0"
                          >
                            {smtpStatusLabel(String(rule.status), t)}
                          </Badge>
                          {needsSmtpDnsVerification(rule) ? (
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px]"
                              onClick={() => openVerify(rule)}
                            >
                              {t('Verify')}
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={rule.$createdAt}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            {needsSmtpDnsVerification(rule) ? (
                              <DropdownMenuItem
                                onClick={() => openVerify(rule)}
                              >
                                <MenuItemContent icon={RefreshCw}>
                                  {t('Verify')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            ) : null}
                            <DropdownMenuItem onClick={() => openDelete(rule)}>
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
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
      )}

      {selectedRule ? (
        <>
          <Dialog
            open={verifyOpen}
            onOpenChange={(open) => {
              setVerifyOpen(open)
              if (!open) {
                setSelectedRule(null)
                setVerificationError(null)
              }
            }}
          >
            <DialogContent className="sm:max-w-3xl p-0 gap-0 overflow-hidden">
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Verify email domain')}</DialogTitle>
              </DialogHeader>
              <div className="px-6 py-4 max-h-[70dvh] overflow-y-auto">
                <VerifySmtpDomainContent
                  rule={selectedRule}
                  region={project?.region}
                  verificationError={verificationError}
                />
              </div>
              <DialogFooter className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setVerifyOpen(false)}
                  disabled={verifyMutation.isPending}
                >
                  {t('Close')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleVerify}
                  disabled={verifyMutation.isPending}
                >
                  {verifyMutation.isPending ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin me-1.5" />
                  ) : null}
                  {t('Verify')}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
          <DeleteDomainDialog
            open={deleteOpen}
            onOpenChange={setDeleteOpen}
            projectId={projectId ?? ''}
            region={project?.region}
            rule={selectedRule}
            onDeleteSuccess={() => {
              toast.success(t('Email domain has been deleted'))
              setDeleteOpen(false)
              setSelectedRule(null)
            }}
          />
        </>
      ) : null}
    </div>
  )
}
