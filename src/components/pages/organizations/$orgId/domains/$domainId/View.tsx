import { useState, useEffect, useMemo } from 'react'
import { cn } from '@/lib/utils'
import {
  Globe,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  Download,
  Upload,
  Plus,
  MoreHorizontal,
  Lock,
  Trash2,
  Pencil,
  ArrowLeft,
  List,
} from 'lucide-react'
import { useDomain, useDomainRecords, useDomainZone } from '@/lib/react-query/hooks'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
  Alert,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Link, useNavigate, useParams, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateRecordDialog } from './CreateRecordDialog'
import { UpdateRecordDialog } from './UpdateRecordDialog'
import { DeleteRecordDialog } from './DeleteRecordDialog'
import { ImportZoneDialog } from './ImportZoneDialog'
import type { Models } from '@appwrite.io/console'
import {
  useCreateDnsRecord,
  useUpdateDnsRecord,
  useDeleteDnsRecord,
  useUpdateDomainZone,
  useRetryDomainVerification,
  usePresetRecords,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { ConsoleHeader } from '@/components/global/layout/Header'
import { ConsoleFooter } from '@/components/global/layout/Footer'
import { PaymentAlert } from '@/components/pages/projects/$projectId/shared/PaymentAlert'

export function DomainDetailView() {
  const { orgId, domainId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [createRecordDialogOpen, setCreateRecordDialogOpen] = useState(false)
  const [updateRecordDialogOpen, setUpdateRecordDialogOpen] = useState(false)
  const [deleteRecordDialogOpen, setDeleteRecordDialogOpen] = useState(false)
  const [importZoneDialogOpen, setImportZoneDialogOpen] = useState(false)
  const [selectedRecord, setSelectedRecord] = useState<Models.DnsRecord | null>(null)
  const [selectedPreset, setSelectedPreset] = useState<'zoho' | 'mailgun' | 'outlook' | 'protonmail' | 'icloud' | 'google-workspace' | null>(null)

  // Convert 1-indexed page to 0-indexed for API
  const pageIndexed = currentPage - 1

  // Fetch domain data
  const { data: domain, isLoading: domainLoading } = useDomain(domainId)

  // Fetch DNS records
  const {
    dnsRecords,
    total: recordsTotal,
    isLoading: recordsLoading,
  } = useDomainRecords(domainId, pageIndexed, pageSize)

  // Get verification status
  const verificationStatus = useMemo(() => {
    if (!domain) return null
    const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
    return {
      isVerified,
      icon: isVerified ? CheckCircle2 : AlertCircle,
      label: isVerified ? 'Verified' : 'Unverified',
      className: isVerified
        ? 'text-green-600 dark:text-green-500'
        : 'text-yellow-600 dark:text-yellow-500',
    }
  }, [domain])

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const domainIndex = pathParts.findIndex(
      (part, idx) => part === 'domains' && pathParts[idx + 1] === domainId,
    )

    if (domainIndex >= 0 && pathParts[domainIndex + 2]) {
      const tabFromPath = pathParts[domainIndex + 2]
      if (tabFromPath === 'settings') {
        return 'settings'
      }
    }

    // Default to records for index route
    return 'records'
  }, [location.pathname, domainId])

  const tabs = useMemo(
    () => [
      {
        id: 'records',
        label: 'DNS Records',
        count: recordsTotal,
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId: orgId as string, domainId: domainId as string },
      },
      {
        id: 'settings',
        label: 'Settings',
        to: '/organizations/$orgId/domains/$domainId/settings',
        params: { orgId: orgId as string, domainId: domainId as string },
      },
    ],
    [orgId, domainId, recordsTotal],
  )

  // Create record mutation
  const createRecordMutation = useCreateDnsRecord(domainId)

  // Preset records
  const { data: presetRecords } = usePresetRecords(domainId, selectedPreset)

  const handleCreateRecord = (data: {
    type: string
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  }) => {
    createRecordMutation.mutate(
      {
        type: data.type,
        data: {
          name: data.name,
          value: data.value,
          ttl: data.ttl,
          priority: data.priority,
          weight: data.weight,
          port: data.port,
          comment: data.comment,
        },
      },
      {
        onSuccess: () => {
          toast.success('DNS record created successfully')
          setCreateRecordDialogOpen(false)
        },
        onError: (error) => {
          toast.error(getErrorMessage(error))
        },
      },
    )
  }

  // Update record mutation
  const updateRecordMutation = useUpdateDnsRecord(domainId)

  const handleUpdateRecord = (recordId: string, data: {
    type: string
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  }) => {
    updateRecordMutation.mutate(
      {
        recordId,
        type: data.type,
        data: {
          name: data.name,
          value: data.value,
          ttl: data.ttl,
          priority: data.priority,
          weight: data.weight,
          port: data.port,
          comment: data.comment,
        },
      },
      {
        onSuccess: () => {
          toast.success('DNS record updated successfully')
          setUpdateRecordDialogOpen(false)
          setSelectedRecord(null)
        },
        onError: (error) => {
          toast.error(getErrorMessage(error))
        },
      },
    )
  }

  // Delete record mutation
  const deleteRecordMutation = useDeleteDnsRecord(domainId)

  const handleDeleteRecord = (recordId: string) => {
    deleteRecordMutation.mutate(recordId, {
      onSuccess: () => {
        toast.success('DNS record deleted successfully')
        setDeleteRecordDialogOpen(false)
        setSelectedRecord(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Import zone mutation
  const importZoneMutation = useUpdateDomainZone(domainId)

  const handleImportZone = (content: string) => {
    importZoneMutation.mutate(content, {
      onSuccess: () => {
        toast.success('Zone file imported successfully')
        setImportZoneDialogOpen(false)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Handle preset selection
  const handlePresetSelect = async (preset: 'zoho' | 'mailgun' | 'outlook' | 'protonmail' | 'icloud' | 'google-workspace') => {
    setSelectedPreset(preset)
    
    // Fetch preset records and create them
    try {
      const { fetchPresetRecords } = await import('@/lib/react-query/hooks')
      const data = await fetchPresetRecords(domainId!, preset)

      if (data?.dnsRecords) {
        await handleCreatePresetRecords(data.dnsRecords, preset)
      }
    } catch (error) {
      toast.error(getErrorMessage(error))
      setSelectedPreset(null)
    }
  }

  const handleCreatePresetRecords = async (
    records: Models.DnsRecord[],
    preset: 'zoho' | 'mailgun' | 'outlook' | 'protonmail' | 'icloud' | 'google-workspace',
  ) => {
    const presetLabels: Record<string, string> = {
      'google-workspace': 'Google Workspace',
      'outlook': 'Outlook',
      'mailgun': 'Mailgun',
      'zoho': 'Zoho',
      'protonmail': 'ProtonMail',
      'icloud': 'iCloud',
    }

    try {
      // Create all records from the preset
      await Promise.all(
        records.map((record) =>
          createRecordMutation.mutateAsync({
            type: record.type,
            data: {
              name: record.name || '@',
              value: record.value,
              ttl: record.ttl,
              priority: record.priority,
              weight: record.weight,
              port: record.port,
              comment: record.comment,
            },
          }),
        ),
      )

      toast.success(`Successfully added ${records.length} DNS records from ${presetLabels[preset]}`)
      queryClient.invalidateQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      setSelectedPreset(null)
    } catch (error) {
      toast.error(getErrorMessage(error))
      setSelectedPreset(null)
    }
  }

  // Export zone file
  const { refetch: refetchZone } = useDomainZone(domainId)

  const handleExportZone = async () => {
    try {
      const result = await refetchZone()
      if (result.data) {
        const content = typeof result.data === 'string' 
          ? result.data 
          : (result.data as any).message || ''
        const blob = new Blob([content], { type: 'text/plain' })
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `${domain?.domain || 'zone'}.txt`
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        URL.revokeObjectURL(url)
        toast.success('Zone file downloaded')
      }
    } catch (error) {
      toast.error(getErrorMessage(error as Error))
    }
  }

  // Retry verification
  const retryVerificationMutation = useRetryDomainVerification(orgId)

  const handleRetryVerification = () => {
    if (!domainId) return
    retryVerificationMutation.mutate(domainId, {
      onSuccess: (updatedDomain) => {
        const isVerified = updatedDomain.nameservers?.toLowerCase() === 'appwrite'
        if (isVerified) {
          toast.success('Domain verification successful')
        } else {
          toast.success('Nameservers updated. Please wait for DNS propagation.')
        }
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
  }

  const handleBack = () => {
    navigate({
      to: '/organizations/$orgId/domains',
      params: { orgId: orgId! },
    })
  }

  if (domainLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[13px] text-muted-foreground">Loading domain...</p>
        </div>
      </div>
    )
  }

  if (!domain) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[13px] font-medium text-foreground">Domain not found</p>
          <Button
            variant="link"
            onClick={() => navigate({ to: '/organizations/$orgId/domains', params: { orgId: orgId! } })}
          >
            Back to domains
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-screen flex-col bg-background">
      {/* Sticky Header Section */}
      <div className="sticky top-0 z-30 flex-shrink-0">
        {/* Payment Alert */}
        <PaymentAlert />

        {/* Console Header */}
        <ConsoleHeader 
          onCommandCenterOpen={() => {}}
          onCreateOrganization={() => {}}
        />
      </div>

      {/* Scrollable Content */}
      <div className="flex h-full min-h-0 flex-col overflow-y-auto">
        <ServiceHeader
          title={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0 cursor-pointer"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
              <span>{domain.domain}</span>
            </div>
          }
          tabs={tabs}
          activeTab={activeTab}
          fullWidthBorder
          contentAfterBorder={
            activeTab === 'records' && verificationStatus && !verificationStatus.isVerified ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                  <Alert variant="default" className="border-amber-500/30 bg-transparent">
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <div className="flex flex-1 items-start justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                          Domain not verified
                        </AlertTitle>
                        <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                          <span className="inline">
                            Update your domain's nameservers to point to Appwrite
                          </span>
                        </AlertDescription>
                      </div>
                      <Button
                        size="sm"
                        onClick={handleRetryVerification}
                        disabled={retryVerificationMutation.isPending}
                        className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400 gap-1.5 cursor-pointer"
                      >
                        <RefreshCw className={cn('h-4 w-4', retryVerificationMutation.isPending && 'animate-spin')} />
                        Retry Verification
                      </Button>
                    </div>
                  </Alert>
                </div>
              </div>
            ) : undefined
          }
        />

        <div className="flex-1 flex flex-col">
          <div className="mx-auto w-full max-w-7xl flex-1 px-4 pt-4 pb-4 sm:px-6 sm:pb-6">
        {activeTab === 'records' ? (
          <>
            {/* Domain Metadata Card */}
            {domain && (
              <div className="mb-4 rounded-lg border border-border bg-card p-4">
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                  {/* Status */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Status</p>
                    <div className="flex items-center gap-1.5">
                      {verificationStatus && (() => {
                        const StatusIcon = verificationStatus.icon
                        return (
                          <>
                            <StatusIcon
                              className={cn('h-3.5 w-3.5', verificationStatus.className)}
                            />
                            <span
                              className={cn(
                                'text-[13px] font-medium',
                                verificationStatus.isVerified
                                  ? 'text-green-600 dark:text-green-500'
                                  : 'text-yellow-600 dark:text-yellow-500',
                              )}
                            >
                              {verificationStatus.label}
                            </span>
                          </>
                        )
                      })()}
                    </div>
                  </div>

                  {/* Registrar */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Registrar</p>
                    <p className="text-[13px] text-foreground">—</p>
                  </div>

                  {/* Nameservers */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Nameservers</p>
                    <p className="text-[13px] text-foreground">
                      {domain.nameservers || '—'}
                    </p>
                  </div>

                  {/* Expiry date */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Expiry date</p>
                    <p className="text-[13px] text-foreground">—</p>
                  </div>

                  {/* Auto renewal */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Auto renewal</p>
                    <p className="text-[13px] text-foreground">—</p>
                  </div>

                  {/* Renewal price */}
                  <div className="space-y-1">
                    <p className="text-[11px] font-medium text-muted-foreground">Renewal price</p>
                    <p className="text-[13px] text-foreground">—</p>
                  </div>
                </div>
              </div>
            )}

            <div className="mb-4 flex items-center gap-3">
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setImportZoneDialogOpen(true)}
                  className="h-9 gap-2 text-[13px] cursor-pointer"
                >
                  <Upload className="h-4 w-4" />
                  Import zone file
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleExportZone}
                  className="h-9 gap-2 text-[13px] cursor-pointer"
                >
                  <Download className="h-4 w-4" />
                  Export
                </Button>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 gap-2 text-[13px] cursor-pointer"
                    >
                      <List className="h-4 w-4" />
                      Add preset
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start">
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('google-workspace')}
                      disabled={createRecordMutation.isPending}
                    >
                      Google Workspace
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('outlook')}
                      disabled={createRecordMutation.isPending}
                    >
                      Outlook
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('mailgun')}
                      disabled={createRecordMutation.isPending}
                    >
                      Mailgun
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('zoho')}
                      disabled={createRecordMutation.isPending}
                    >
                      Zoho
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('protonmail')}
                      disabled={createRecordMutation.isPending}
                    >
                      ProtonMail
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => handlePresetSelect('icloud')}
                      disabled={createRecordMutation.isPending}
                    >
                      iCloud
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
              <div className="ml-auto">
                <Button
                  onClick={() => setCreateRecordDialogOpen(true)}
                  className="h-9 gap-2 text-[13px] font-medium text-white hover:opacity-90 cursor-pointer"
                  style={{ backgroundColor: '#f02e65' }}
                >
                  <Plus className="h-4 w-4" />
                  Add Record
                </Button>
              </div>
            </div>

            {recordsLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">Loading DNS records...</p>
              </div>
            ) : dnsRecords.length > 0 ? (
              <>
                <div className="rounded-lg border border-border bg-card">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent">
                        <TableHead className="w-[200px]">Name</TableHead>
                        <TableHead className="w-[100px]">Type</TableHead>
                        <TableHead>Value</TableHead>
                        <TableHead className="w-[80px]">TTL</TableHead>
                        <TableHead className="w-[100px]">Priority</TableHead>
                        <TableHead className="w-[120px]">Created</TableHead>
                        <TableHead className="w-[80px] text-right pr-4"></TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {dnsRecords.map((record) => (
                        <TableRow key={record.$id}>
                          <TableCell>
                            <span className="text-[13px] font-medium text-foreground">
                              {record.name || '@'}
                            </span>
                          </TableCell>
                          <TableCell>
                            <Badge variant="secondary" className="text-[11px]">
                              {record.type}
                            </Badge>
                          </TableCell>
                          <TableCell>
                            {(() => {
                              // Replace Appwrite placeholder values with standard tags
                              const value = record.value
                              if (value === 'a.a.a.a' || value === 'b:b::b:b:b') {
                                return (
                                  <Badge variant="secondary" className="text-[11px]">
                                    Served by Appwrite
                                  </Badge>
                                )
                              }
                              if (value?.startsWith('0 issue "') && value?.includes('"')) {
                                return (
                                  <Badge variant="secondary" className="text-[11px]">
                                    Generate by Appwrite
                                  </Badge>
                                )
                              }
                              return (
                                <span className="text-[13px] text-muted-foreground">
                                  {value}
                                </span>
                              )
                            })()}
                          </TableCell>
                          <TableCell>
                            <span className="text-[12px] text-muted-foreground">
                              {record.ttl}
                            </span>
                          </TableCell>
                          <TableCell>
                            <span className="text-[12px] text-muted-foreground">
                              {record.priority !== undefined ? record.priority : '—'}
                            </span>
                          </TableCell>
                          <TableCell>
                            <DateTooltip
                              date={record.$createdAt}
                              className="text-[12px] font-medium text-muted-foreground"
                            />
                          </TableCell>
                          <TableCell className="text-right pr-4">
                            {record.lock ? (
                              <div className="flex justify-end">
                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0 cursor-pointer" disabled>
                                  <Lock className="h-4 w-4 text-muted-foreground" />
                                </Button>
                              </div>
                            ) : (
                              <div className="flex justify-end">
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0 cursor-pointer">
                                      <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                  </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem
                                    onClick={() => {
                                      setSelectedRecord(record)
                                      setUpdateRecordDialogOpen(true)
                                    }}
                                  >
                                    <Pencil className="mr-1.5 h-4 w-4" />
                                    Update
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    className="text-destructive"
                                    onClick={() => {
                                      setSelectedRecord(record)
                                      setDeleteRecordDialogOpen(true)
                                    }}
                                  >
                                    <Trash2 className="mr-1.5 h-4 w-4" />
                                    Delete
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                              </div>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
                <Pagination
                  currentPage={currentPage}
                  totalItems={recordsTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handlePageChange}
                  onPageSizeChange={handlePageSizeChange}
                  itemLabel="records"
                />
              </>
            ) : (
              <EmptyState
                icon={Globe}
                title="No DNS records"
                description="Add your first DNS record to get started"
                variant="card"
              />
            )}
          </>
        ) : (
          <div className="py-8">
            <p className="text-[13px] text-muted-foreground">Settings coming soon</p>
          </div>
        )}
          </div>
          <ConsoleFooter />
        </div>
      </div>

      {/* Create Record Dialog */}
      <CreateRecordDialog
        open={createRecordDialogOpen}
        onOpenChange={setCreateRecordDialogOpen}
        onCreate={handleCreateRecord}
        isLoading={createRecordMutation.isPending}
      />

      {/* Update Record Dialog */}
      {selectedRecord && (
        <UpdateRecordDialog
          open={updateRecordDialogOpen}
          onOpenChange={setUpdateRecordDialogOpen}
          record={selectedRecord}
          onUpdate={(data) => handleUpdateRecord(selectedRecord.$id, data)}
          isLoading={updateRecordMutation.isPending}
        />
      )}

      {/* Delete Record Dialog */}
      {selectedRecord && (
        <DeleteRecordDialog
          open={deleteRecordDialogOpen}
          onOpenChange={setDeleteRecordDialogOpen}
          record={selectedRecord}
          onDelete={() => handleDeleteRecord(selectedRecord.$id)}
          isLoading={deleteRecordMutation.isPending}
        />
      )}

      {/* Import Zone Dialog */}
      <ImportZoneDialog
        open={importZoneDialogOpen}
        onOpenChange={setImportZoneDialogOpen}
        onImport={handleImportZone}
        isLoading={importZoneMutation.isPending}
      />
    </div>
  )
}

