import { useState, useMemo, useEffect } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  useProjectDomains,
  useCreateDomain,
  useVerifyDomain,
  useDeleteDomain,
  useProject,
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
import { Badge } from '@/components/ui/badge'
import {
  getDomainStatusVariant,
  type DomainStatus,
} from '@/lib/utils/status-badge'
import { cn } from '@/lib/utils'
import {
  MoreHorizontal,
  ExternalLink,
  AlertCircle,
  Loader2,
  FileText,
  RefreshCw,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import type { Models } from '@appwrite.io/console'
import { AddDomainDialog } from './domains/AddDomain'
import { VerifyDomainDialog } from './domains/VerifyDomain'
import { DeleteDomainDialog } from './domains/DeleteDomain'
import { ViewLogsDialog } from './domains/ViewLogs'
import { RetryDomainDialog } from './domains/RetryDomain'

interface DomainsProps {
  projectId: string
  searchValue?: string
}

export function Domains({
  projectId,
  searchValue: searchValueProp = '',
}: DomainsProps) {
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const region = project?.region

  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [addDomainOpen, setAddDomainOpen] = useState(false)
  const [verifyDomainOpen, setVerifyDomainOpen] = useState(false)
  const [deleteDomainOpen, setDeleteDomainOpen] = useState(false)
  const [viewLogsOpen, setViewLogsOpen] = useState(false)
  const [retryDomainOpen, setRetryDomainOpen] = useState(false)
  const [selectedRule, setSelectedRule] = useState<Models.ProxyRule | null>(
    null,
  )

  const { rules, total, isLoading } = useProjectDomains(
    projectId,
    region,
    searchValueProp,
  )

  // Listen for create event from ServiceHeader
  useEffect(() => {
    const handleCreate = () => {
      setAddDomainOpen(true)
    }
    window.addEventListener('settings-create-domain', handleCreate)
    return () => {
      window.removeEventListener('settings-create-domain', handleCreate)
    }
  }, [])
  const createDomainMutation = useCreateDomain(projectId, region)
  const verifyDomainMutation = useVerifyDomain(projectId, region)
  const deleteDomainMutation = useDeleteDomain(projectId, region)

  const getStatusBadge = (status: string) => {
    const variant = getDomainStatusVariant(status as DomainStatus)

    if (variant === null) {
      return null // No badge for verified domains
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

  const handleVerify = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setVerifyDomainOpen(true)
  }

  const handleRetry = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setRetryDomainOpen(true)
  }

  const handleViewLogs = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setViewLogsOpen(true)
  }

  const handleDelete = (rule: Models.ProxyRule) => {
    setSelectedRule(rule)
    setDeleteDomainOpen(true)
  }

  const canRetry = (status: string) => {
    return status === 'created' || status === 'unverified'
  }

  const canViewLogs = (rule: Models.ProxyRule) => {
    return (
      rule.logs && (rule.status === 'verifying' || rule.status === 'unverified')
    )
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
          title={searchValueProp ? undefined : 'No domains yet'}
          description={
            searchValueProp
              ? undefined
              : 'Add a custom domain to serve your Appwrite API on your own domain'
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
                    Domain
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Status
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Created
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedRules.map((rule) => (
                  <TableRow key={rule.$id}>
                    <TableCell className="px-4 py-3">
                      <a
                        href={`https://${rule.domain}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-1.5 font-medium text-foreground hover:underline"
                      >
                        {rule.domain}
                        <ExternalLink className="h-3.5 w-3.5 text-muted-foreground" />
                      </a>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        {getStatusBadge(rule.status)}
                        {canRetry(rule.status) && (
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
                      <DateTooltip date={rule.$createdAt} />
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
                          {canViewLogs(rule) && (
                            <DropdownMenuItem
                              onClick={() => handleViewLogs(rule)}
                            >
                              <FileText className="mr-2 h-4 w-4" />
                              View logs
                            </DropdownMenuItem>
                          )}
                          {canRetry(rule.status) && (
                            <DropdownMenuItem onClick={() => handleRetry(rule)}>
                              <RefreshCw className="mr-2 h-4 w-4" />
                              Retry
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuItem
                            onClick={() =>
                              navigate({
                                to: '/projects/$projectId/settings/domains/$ruleId',
                                params: { projectId, ruleId: rule.$id },
                              })
                            }
                          >
                            <FileText className="mr-2 h-4 w-4" />
                            DNS Records
                          </DropdownMenuItem>
                          <DropdownMenuItem onClick={() => handleDelete(rule)}>
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
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
              itemLabel="domains"
            />
          )}
        </>
      )}

      {/* Dialogs */}
      <AddDomainDialog
        open={addDomainOpen}
        onOpenChange={setAddDomainOpen}
        projectId={projectId}
        region={region}
        onCreateSuccess={(rule) => {
          if (rule.status === 'verified') {
            toast.success('Domain added successfully')
            setAddDomainOpen(false)
          } else {
            setAddDomainOpen(false)
            setSelectedRule(rule)
            setVerifyDomainOpen(true)
          }
        }}
      />

      {selectedRule && (
        <>
          <VerifyDomainDialog
            open={verifyDomainOpen}
            onOpenChange={setVerifyDomainOpen}
            projectId={projectId}
            region={region}
            rule={selectedRule}
            onVerifySuccess={() => {
              toast.success('Domain verified successfully')
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
            onRetrySuccess={() => {
              toast.success('Verification in progress')
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
              toast.success('Domain has been deleted')
              setDeleteDomainOpen(false)
              setSelectedRule(null)
            }}
          />
        </>
      )}
    </div>
  )
}
