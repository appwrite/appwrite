import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import {
  Globe,
  List,
  LayoutGrid,
  MoreHorizontal,
  CheckCircle2,
  AlertCircle,
  Search,
  Plus,
} from 'lucide-react'
import { useOrganizationDomains } from '@/lib/react-query/hooks'
import { ResourceCard } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Link,
  useNavigate,
  useParams,
  useLocation,
} from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateDomainDialog } from './CreateDomain'
import { RetryVerification } from './RetryVerification'
import type { Models } from '@appwrite.io/console'
import {
  useCreateOrganizationDomain,
  useDeleteOrganizationDomain,
  useRetryDomainVerification,
} from '@/lib/react-query/hooks'

export function View() {
  const { orgId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [selectedDomains, setSelectedDomains] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [retryDialogOpen, setRetryDialogOpen] = useState(false)
  const [selectedDomain, setSelectedDomain] = useState<Models.Domain | null>(
    null,
  )

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total: domainsTotal,
    isLoading: domainsLoading,
    isFetching: domainsFetching,
  } = useOrganizationDomains(orgId, requestedPage - 1, pageSize, searchValue)

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    domains: apiDomains,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useOrganizationDomains(orgId, displayedPage - 1, pageSize, searchValue)

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !domainsFetching &&
      requestedPage !== displayedPage &&
      !domainsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [domainsFetching, domainsLoading, requestedPage, displayedPage])

  const showLoading = displayedLoading && apiDomains.length === 0
  const paginationTotal = displayedTotal ?? domainsTotal

  // Paginated data
  const paginatedDomains = apiDomains

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedDomains(new Set())
    setDeleteDialogOpen(false)
    setRetryDialogOpen(false)
  }, [location.pathname, orgId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedDomains(new Set())
  }

  // Delete domain mutation (for bulk delete)
  const deleteDomainMutation = useDeleteOrganizationDomain(orgId)

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (domainIds: string[]) => {
      if (!orgId) {
        throw new Error('Organization ID is required')
      }
      await Promise.all(
        domainIds.map((domainId) => deleteDomainMutation.mutateAsync(domainId)),
      )
    },
    onSuccess: async () => {
      // Refetch domains list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success(
        `Successfully deleted ${selectedDomains.size} domain${selectedDomains.size > 1 ? 's' : ''}`,
      )
      setSelectedDomains(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete domains')
    },
  })

  const handleBulkDelete = () => {
    if (selectedDomains.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedDomains.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedDomains))
  }

  const toggleDomain = (domainId: string) => {
    const newSelected = new Set(selectedDomains)
    if (newSelected.has(domainId)) {
      newSelected.delete(domainId)
    } else {
      newSelected.add(domainId)
    }
    setSelectedDomains(newSelected)
  }

  const toggleAllDomains = () => {
    if (selectedDomains.size === paginatedDomains.length) {
      setSelectedDomains(new Set())
    } else {
      setSelectedDomains(new Set(paginatedDomains.map((d) => d.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedDomains(new Set())
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedDomains(new Set())
  }

  // Create domain mutation
  const createDomainMutation = useCreateOrganizationDomain(orgId)

  const handleCreateDomain = (domain: string) => {
    createDomainMutation.mutate(domain, {
      onSuccess: (createdDomain) => {
        toast.success(`${createdDomain.domain} has been created`)
        setCreateDialogOpen(false)
        navigate({
          to: '/organizations/$orgId/domains/$domainId',
          params: { orgId: orgId!, domainId: createdDomain.$id },
        })
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Retry verification mutation
  const retryVerificationMutation = useRetryDomainVerification(orgId)

  const handleRetryVerification = (domainId: string) => {
    retryVerificationMutation.mutate(domainId, {
      onSuccess: (domain) => {
        const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
        if (isVerified) {
          toast.success('Domain verification successful')
        } else {
          toast.success('Nameservers updated. Please wait for DNS propagation.')
        }
        setRetryDialogOpen(false)
        setSelectedDomain(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Get verification status
  const getVerificationStatus = (domain: Models.Domain) => {
    const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
    if (isVerified) {
      return {
        status: 'verified' as const,
        icon: CheckCircle2,
        label: 'Verified',
        className: 'text-green-600 dark:text-green-500',
      }
    }
    return {
      status: 'unverified' as const,
      icon: AlertCircle,
      label: 'Unverified',
      className: 'text-yellow-600 dark:text-yellow-500',
    }
  }

  const ViewToggle = () => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('list')}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'grid' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  return (
    <div className="flex h-full flex-col">
      {/* Toolbar: Search + View Toggle + Create */}
      <div className="mb-4 flex items-center gap-3">
        <div className="relative w-64">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search domains..."
            value={searchValue}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="h-9 border-border bg-accent/50 pl-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>
        <div className="ml-auto flex items-center gap-2">
          <ViewToggle />
          <Button
            onClick={() => setCreateDialogOpen(true)}
            className="h-9 gap-2 text-[13px] font-medium text-white hover:opacity-90"
            style={{ backgroundColor: '#f02e65' }}
          >
            <Plus className="h-4 w-4" />
            Add domain
          </Button>
        </div>
      </div>

      <div className="flex-1">
        {viewMode === 'list' ? (
          showLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                Loading domains...
              </p>
            </div>
          ) : paginatedDomains.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedDomains.length > 0 &&
                            selectedDomains.size === paginatedDomains.length
                          }
                          onCheckedChange={toggleAllDomains}
                        />
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                        Domain
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                        Status
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                        Nameservers
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                        Created
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedDomains.map((domain) => {
                      const verification = getVerificationStatus(domain)
                      const VerificationIcon = verification.icon
                      return (
                        <TableRow
                          key={domain.$id}
                          className={cn(
                            'cursor-pointer transition-colors',
                            selectedDomains.has(domain.$id)
                              ? 'bg-muted'
                              : 'hover:bg-muted/50',
                          )}
                          onClick={() => {
                            navigate({
                              to: '/organizations/$orgId/domains/$domainId',
                              params: { orgId: orgId!, domainId: domain.$id },
                            })
                          }}
                        >
                          <TableCell onClick={(e) => e.stopPropagation()}>
                            <Checkbox
                              checked={selectedDomains.has(domain.$id)}
                              onCheckedChange={() => toggleDomain(domain.$id)}
                            />
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/organizations/$orgId/domains/$domainId"
                              params={{ orgId: orgId!, domainId: domain.$id }}
                              className="block"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <span className="text-[13px] font-medium text-foreground">
                                {domain.domain}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1.5">
                              <VerificationIcon
                                className={cn(
                                  'h-4 w-4',
                                  verification.className,
                                )}
                              />
                              <span
                                className={cn(
                                  'text-[12px] font-medium',
                                  verification.className,
                                )}
                              >
                                {verification.label}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>
                            <span className="text-[12px] text-muted-foreground">
                              {domain.nameservers || '—'}
                            </span>
                          </TableCell>
                          <TableCell>
                            <DateTooltip
                              date={domain.$createdAt}
                              className="text-[12px] font-medium text-muted-foreground"
                            />
                          </TableCell>
                          <TableCell onClick={(e) => e.stopPropagation()}>
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 w-8 p-0"
                                  onClick={(e) => e.stopPropagation()}
                                >
                                  <MoreHorizontal className="h-4 w-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                  onClick={() => {
                                    navigate({
                                      to: '/organizations/$orgId/domains/$domainId',
                                      params: {
                                        orgId: orgId!,
                                        domainId: domain.$id,
                                      },
                                    })
                                  }}
                                >
                                  View details
                                </DropdownMenuItem>
                                {verification.status === 'unverified' && (
                                  <DropdownMenuItem
                                    onClick={() => {
                                      setSelectedDomain(domain)
                                      setRetryDialogOpen(true)
                                    }}
                                  >
                                    Retry verification
                                  </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                  className="text-destructive"
                                  onClick={() => {
                                    setSelectedDomain(domain)
                                    setSelectedDomains(new Set([domain.$id]))
                                    setDeleteDialogOpen(true)
                                  }}
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
                currentPage={displayedPage}
                totalItems={paginationTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="domains"
              />
            </>
          ) : (
            <EmptyState
              icon={Globe}
              title="No domains yet"
              description="Create your first domain to get started"
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )
        ) : (
          <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  Loading domains...
                </p>
              </div>
            ) : paginatedDomains.length > 0 ? (
              <>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {paginatedDomains.map((domain) => {
                    const verification = getVerificationStatus(domain)
                    return (
                      <Link
                        key={domain.$id}
                        to="/organizations/$orgId/domains/$domainId"
                        params={{ orgId, domainId: domain.$id }}
                      >
                        <ResourceCard
                          title={domain.domain}
                          resourceId={domain.$id}
                          icon={Globe}
                          iconColor="bg-muted text-muted-foreground"
                          status={
                            verification.status === 'verified'
                              ? 'success'
                              : 'warning'
                          }
                          statusLabel={verification.label}
                          metadata={[
                            {
                              label: 'Nameservers',
                              value: (
                                <span className="text-[11px] font-medium text-muted-foreground">
                                  {domain.nameservers || '—'}
                                </span>
                              ),
                            },
                            {
                              label: 'Created',
                              value: (
                                <DateTooltip
                                  date={domain.$createdAt}
                                  className="text-[11px] font-medium text-muted-foreground"
                                />
                              ),
                            },
                          ]}
                        />
                      </Link>
                    )
                  })}
                </div>
                {!showLoading && paginatedDomains.length > 0 && (
                  <Pagination
                    currentPage={displayedPage}
                    totalItems={paginationTotal}
                    pageSize={pageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={handlePageChange}
                    onPageSizeChange={handlePageSizeChange}
                    itemLabel="domains"
                  />
                )}
              </>
            ) : (
              <EmptyState
                icon={Globe}
                title="No domains yet"
                description="Create your first domain to get started"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedDomains.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDomains.size} domain
                {selectedDomains.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedDomains(new Set())}
                  className="h-8 text-xs"
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  Delete
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>
                Delete Domain{selectedDomains.size > 1 ? 's' : ''}
              </DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedDomains.size} domain
                {selectedDomains.size > 1 ? 's' : ''}? This action cannot be
                undone.
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {/* Create Domain Dialog */}
      <CreateDomainDialog
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onCreate={handleCreateDomain}
        isLoading={createDomainMutation.isPending}
      />

      {/* Retry Verification Dialog */}
      {selectedDomain && (
        <RetryVerification
          open={retryDialogOpen}
          onOpenChange={setRetryDialogOpen}
          domain={selectedDomain}
          onRetry={handleRetryVerification}
          isLoading={retryVerificationMutation.isPending}
        />
      )}
    </div>
  )
}
