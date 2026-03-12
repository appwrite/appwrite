import { useState, useMemo } from 'react'
import { useMutation } from '@tanstack/react-query'
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
  Copy,
  Check,
} from 'lucide-react'
import {
  useDomain,
  useDomainRecords,
  useDomainZone,
  DNS_RECORDS_DEFAULT_SORT_BY,
  DNS_RECORDS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import {
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateRecordDialog } from './CreateRecord'
import { UpdateRecordDialog } from './UpdateRecord'
import { DeleteRecordDialog } from './DeleteRecord'
import { ImportZoneDialog } from './ImportZone'
import { RetryVerification } from '../RetryVerification'
import type { Models } from '@appwrite.io/console'
import {
  useCreateDnsRecord,
  useUpdateDnsRecord,
  useDeleteDnsRecord,
  deleteDnsRecord,
  useUpdateDomainZone,
  useRetryDomainVerification,
  usePresetRecords,
  useUpdateDomainTeam,
  useDeleteOrganizationDomain,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import {
  getQueryParam,
  getSort,
  parseSort,
  encodeSort,
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  dnsRecordsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'

export type DomainDetailInitialData = {
  domain: Models.Domain
  records: { dnsRecords: Models.DnsRecord[]; total: number }
}

type ViewProps = {
  initialData?: DomainDetailInitialData
}

export function View({ initialData }: ViewProps = {}) {
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
  const [retryDialogOpen, setRetryDialogOpen] = useState(false)
  const [selectedRecord, setSelectedRecord] = useState<Models.DnsRecord | null>(
    null,
  )
  const [selectedPreset, setSelectedPreset] = useState<
    | 'zoho'
    | 'mailgun'
    | 'outlook'
    | 'protonmail'
    | 'icloud'
    | 'google-workspace'
    | null
  >(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [selectedOrgId, setSelectedOrgId] = useState('')
  const [transferDialogOpen, setTransferDialogOpen] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedRecords, setSelectedRecords] = useState<Set<string>>(new Set())
  const [bulkDeleteRecordsDialogOpen, setBulkDeleteRecordsDialogOpen] =
    useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)

  const search = useSearch({ strict: false }) as
    | Record<string, unknown>
    | undefined
  const isRecordsIndex = useMemo(
    () =>
      location.pathname.replace(/\/$/, '') ===
      `/organizations/${orgId}/domains/${domainId}`,
    [location.pathname, orgId, domainId],
  )
  const recordsFilterMap = useMemo(() => {
    if (!isRecordsIndex || typeof search !== 'object' || !search)
      return new Map()
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    return queryParamToMap(
      getQueryParam(url) ?? (search.query as string | undefined) ?? null,
    )
  }, [isRecordsIndex, location.pathname, location.search, search?.query])
  const recordsSortParams = useMemo(() => {
    if (!isRecordsIndex || typeof search !== 'object' || !search) return null
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    const parsed = parseSort(search.sort as string | undefined) ?? getSort(url)
    return (
      parsed ?? {
        sortBy: DNS_RECORDS_DEFAULT_SORT_BY,
        sortOrder: DNS_RECORDS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
      }
    )
  }, [isRecordsIndex, search?.sort, location.pathname, location.search])
  const recordsSortBy = recordsSortParams?.sortBy ?? DNS_RECORDS_DEFAULT_SORT_BY
  const recordsSortOrder =
    recordsSortParams?.sortOrder ?? DNS_RECORDS_DEFAULT_SORT_ORDER
  const filterQueries =
    recordsFilterMap.size > 0
      ? Array.from(recordsFilterMap.values())
      : undefined

  // Convert 1-indexed page to 0-indexed for API
  const pageIndexed = currentPage - 1

  // Fetch domain data (use initialData on first paint so no "Domain not found" / "Loading DNS records" flash)
  const { data: domainFromHook, isLoading: domainLoading } = useDomain(domainId)
  const domain = domainFromHook ?? initialData?.domain

  // Fetch DNS records (use initialData only when no filters so we don't show unfiltered data when filtered)
  const hasRecordFilters = (filterQueries?.length ?? 0) > 0
  const { dnsRecords: recordsFromHook, total: recordsTotalFromHook } =
    useDomainRecords(
      domainId,
      pageIndexed,
      pageSize,
      filterQueries,
      recordsSortBy,
      recordsSortOrder,
    )
  const isFirstPage = currentPage === 1
  const canUseInitialRecords =
    isFirstPage &&
    initialData?.records &&
    !hasRecordFilters &&
    !recordsFromHook?.length
  const rawRecords = canUseInitialRecords
    ? initialData!.records.dnsRecords
    : (recordsFromHook ?? [])
  const dnsRecords = rawRecords
  const recordsTotal =
    isFirstPage && initialData?.records && !hasRecordFilters
      ? (recordsTotalFromHook ?? initialData.records.total)
      : (recordsTotalFromHook ?? 0)

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
    [orgId, domainId],
  )

  // Create record mutation
  const createRecordMutation = useCreateDnsRecord(domainId)

  // Preset records
  usePresetRecords(domainId, selectedPreset)

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

  const handleUpdateRecord = (
    recordId: string,
    data: {
      type: string
      name: string
      value: string
      ttl: number
      priority?: number
      weight?: number
      port?: number
      comment?: string
    },
  ) => {
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

  // Deletable records (non-locked) on current page for bulk actions
  const deletableRecords = useMemo(
    () => dnsRecords.filter((r) => !r.lock),
    [dnsRecords],
  )

  const bulkDeleteRecordsMutation = useMutation({
    mutationFn: async (recordIds: string[]) => {
      if (!domainId) throw new Error('Domain ID is required')
      await Promise.all(
        recordIds.map((recordId) => deleteDnsRecord(domainId, recordId)),
      )
    },
    onSuccess: async (_, recordIds) => {
      await queryClient.refetchQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      await queryClient.refetchQueries({
        queryKey: ['domain', domainId],
      })
      toast.success(
        `Deleted ${recordIds.length} DNS record${recordIds.length > 1 ? 's' : ''}`,
      )
      setSelectedRecords(new Set())
      setBulkDeleteRecordsDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleBulkDeleteRecords = () => {
    if (selectedRecords.size === 0) return
    setBulkDeleteRecordsDialogOpen(true)
  }

  const confirmBulkDeleteRecords = () => {
    if (selectedRecords.size === 0) return
    bulkDeleteRecordsMutation.mutate(Array.from(selectedRecords))
  }

  const toggleRecord = (recordId: string, locked: boolean) => {
    if (locked) return
    setSelectedRecords((prev) => {
      const next = new Set(prev)
      if (next.has(recordId)) next.delete(recordId)
      else next.add(recordId)
      return next
    })
  }

  const toggleAllRecords = () => {
    if (selectedRecords.size === deletableRecords.length) {
      setSelectedRecords(new Set())
    } else {
      setSelectedRecords(new Set(deletableRecords.map((r) => r.$id)))
    }
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
  const handlePresetSelect = async (
    preset:
      | 'zoho'
      | 'mailgun'
      | 'outlook'
      | 'protonmail'
      | 'icloud'
      | 'google-workspace',
  ) => {
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
    preset:
      | 'zoho'
      | 'mailgun'
      | 'outlook'
      | 'protonmail'
      | 'icloud'
      | 'google-workspace',
  ) => {
    const presetLabels: Record<string, string> = {
      'google-workspace': 'Google Workspace',
      outlook: 'Outlook',
      mailgun: 'Mailgun',
      zoho: 'Zoho',
      protonmail: 'ProtonMail',
      icloud: 'iCloud',
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

      toast.success(
        `Successfully added ${records.length} DNS records from ${presetLabels[preset]}`,
      )
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
        const content =
          typeof result.data === 'string'
            ? result.data
            : (result.data as { message?: string })?.message || ''
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
    if (!domainId || !domain) return
    retryVerificationMutation.mutate(domainId, {
      onSuccess: (updatedDomain) => {
        // Check if verified
        const isVerified =
          updatedDomain.nameservers?.toLowerCase() === 'appwrite'
        if (isVerified) {
          // Invalidate domain data to refresh the page
          queryClient.invalidateQueries({
            queryKey: ['domain', domainId],
          })
          toast.success(`${domain.domain} has been verified`)
          setRetryDialogOpen(false)
        } else {
          // Still not verified - show error
          toast.error(
            'Domain verification failed. Please check your domain settings or try again later.',
          )
        }
      },
      onError: (error) => {
        toast.error(
          getErrorMessage(error) ||
            'Domain verification failed. Please check your domain settings or try again later.',
        )
      },
    })
  }

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
    setSelectedRecords(new Set())
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
    setSelectedRecords(new Set())
  }

  const recordsRouteTo = '/organizations/$orgId/domains/$domainId' as const
  const recordsSearchWithSort =
    (queryParam: string | undefined, sortParam: string | undefined) =>
    (prev: Record<string, unknown>) => ({
      ...(typeof prev === 'object' && prev !== null ? prev : {}),
      ...buildListSearchParams({
        query: queryParam ?? undefined,
        sort: sortParam ?? undefined,
      }),
    })
  const handleDnsSortChange = (sortBy: string, sortOrder: 'asc' | 'desc') => {
    setCurrentPage(1)
    setSelectedRecords(new Set())
    const sortParam =
      sortBy !== DNS_RECORDS_DEFAULT_SORT_BY ||
      sortOrder !== DNS_RECORDS_DEFAULT_SORT_ORDER
        ? encodeSort(sortBy, sortOrder)
        : undefined
    navigate({
      to: recordsRouteTo,
      params: { orgId: orgId!, domainId: domainId! },
      search: recordsSearchWithSort(
        recordsFilterMap.size > 0
          ? mapToQueryParam(recordsFilterMap)
          : undefined,
        sortParam,
      ),
      replace: true,
    })
  }
  const applyFilter = (compactKey: CompactFilterKey, queryStr: string) => {
    const next = new Map(recordsFilterMap)
    next.set(compactKey, queryStr)
    setCurrentPage(1)
    setSelectedRecords(new Set())
    const sortParam =
      recordsSortBy !== DNS_RECORDS_DEFAULT_SORT_BY ||
      recordsSortOrder !== DNS_RECORDS_DEFAULT_SORT_ORDER
        ? encodeSort(recordsSortBy, recordsSortOrder)
        : undefined
    navigate({
      to: recordsRouteTo,
      params: { orgId: orgId!, domainId: domainId! },
      search: recordsSearchWithSort(
        mapToQueryParam(next) || undefined,
        sortParam,
      ),
      replace: true,
    })
  }
  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(recordsFilterMap)
    next.delete(compactKey)
    setCurrentPage(1)
    setSelectedRecords(new Set())
    setFiltersOpen(false)
    const sortParam =
      recordsSortBy !== DNS_RECORDS_DEFAULT_SORT_BY ||
      recordsSortOrder !== DNS_RECORDS_DEFAULT_SORT_ORDER
        ? encodeSort(recordsSortBy, recordsSortOrder)
        : undefined
    navigate({
      to: recordsRouteTo,
      params: { orgId: orgId!, domainId: domainId! },
      search: recordsSearchWithSort(
        next.size > 0 ? mapToQueryParam(next) : undefined,
        sortParam,
      ),
      replace: true,
    })
  }
  const clearAllFilters = () => {
    setCurrentPage(1)
    setSelectedRecords(new Set())
    setFiltersOpen(false)
    const sortParam =
      recordsSortBy !== DNS_RECORDS_DEFAULT_SORT_BY ||
      recordsSortOrder !== DNS_RECORDS_DEFAULT_SORT_ORDER
        ? encodeSort(recordsSortBy, recordsSortOrder)
        : undefined
    navigate({
      to: recordsRouteTo,
      params: { orgId: orgId!, domainId: domainId! },
      search: recordsSearchWithSort(undefined, sortParam),
      replace: true,
    })
  }

  const handleBack = () => {
    navigate({
      to: '/organizations/$orgId/domains',
      params: { orgId: orgId! },
    })
  }

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success('Copied to clipboard')
    setTimeout(() => setCopiedField(null), 2000)
  }

  // Get organizations for transfer (excluding current)
  const { organizations: allOrganizations, isLoading: organizationsLoading } =
    useOrganizations()
  const organizations = useMemo(() => {
    if (!allOrganizations || !domain) return []

    return allOrganizations
      .filter((org) => org.$id !== domain.teamId)
      .map((org) => ({
        value: org.$id,
        label: org.name,
      }))
  }, [allOrganizations, domain])

  // Transfer domain mutation
  const transferDomainMutation = useUpdateDomainTeam(orgId)

  const handleTransferDomain = () => {
    if (!domainId || !selectedOrgId) return
    transferDomainMutation.mutate(
      { domainId, teamId: selectedOrgId },
      {
        onSuccess: () => {
          const selectedOrg = organizations.find(
            (org) => org.value === selectedOrgId,
          )
          toast.success(
            `${domain?.domain || 'Domain'} has been transferred to ${selectedOrg?.label || 'the selected organization'}`,
          )

          // Invalidate domain query to refresh data
          queryClient.invalidateQueries({
            queryKey: ['domain', domainId],
          })

          setTransferDialogOpen(false)
          setSelectedOrgId('')

          // Navigate to the new organization's domains page
          navigate({
            to: '/organizations/$orgId/domains',
            params: { orgId: selectedOrgId },
          })
        },
        onError: (error) => {
          toast.error(getErrorMessage(error) || 'Failed to transfer domain')
        },
      },
    )
  }

  // Delete domain mutation
  const deleteDomainMutation = useDeleteOrganizationDomain(orgId)

  const handleDeleteDomain = () => {
    if (!domainId || !domain) return
    if (deleteConfirmation !== domain.domain) {
      toast.error('Domain name does not match')
      return
    }
    deleteDomainMutation.mutate(domainId, {
      onSuccess: () => {
        toast.success(`${domain.domain} has been deleted`)
        setDeleteDialogOpen(false)
        setDeleteConfirmation('')
        // Navigate back to domains list
        navigate({
          to: '/organizations/$orgId/domains',
          params: { orgId: orgId! },
        })
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete domain')
      },
    })
  }

  const getRecordTypeColor = (type: string) => {
    const colors: Record<string, string> = {
      A: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
      AAAA: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
      CNAME:
        'bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20',
      MX: 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
      TXT: 'bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20',
      NS: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
      SRV: 'bg-pink-500/10 text-pink-600 dark:text-pink-400 border-pink-500/20',
      CAA: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-500/20',
      HTTPS:
        'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20',
      ALIAS:
        'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    }
    return colors[type] || 'bg-muted text-muted-foreground border-border'
  }

  // Domain not found only when we have no domain from hook and no initialData (never show while loading / first paint)
  if (!domain && !initialData?.domain && !domainLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[13px] font-medium text-foreground">
            Domain not found
          </p>
          <Button
            variant="link"
            onClick={() =>
              navigate({
                to: '/organizations/$orgId/domains',
                params: { orgId: orgId! },
              })
            }
          >
            Back to domains
          </Button>
        </div>
      </div>
    )
  }

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => {},
          onCreateOrganization: () => {},
        }}
        showFooter
        containerClassName="domain-detail-layout-container"
      >
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
              <span>{domain?.domain}</span>
            </div>
          }
          tabs={tabs}
          activeTab={activeTab}
          fullWidthBorder
          contentAfterBorder={
            activeTab === 'records' &&
            verificationStatus &&
            !verificationStatus.isVerified ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                  <Alert
                    variant="default"
                    className="border-amber-500/30 bg-transparent"
                  >
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <div className="flex flex-1 items-start justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                          Domain not verified
                        </AlertTitle>
                        <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                          <span className="inline">
                            Update your domain's nameservers to point to
                            Appwrite
                          </span>
                        </AlertDescription>
                      </div>
                      <Button
                        size="sm"
                        onClick={() => setRetryDialogOpen(true)}
                        disabled={retryVerificationMutation.isPending}
                        className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400 gap-1.5 cursor-pointer"
                      >
                        <RefreshCw
                          className={cn(
                            'h-4 w-4',
                            retryVerificationMutation.isPending &&
                              'animate-spin',
                          )}
                        />
                        Retry Verification
                      </Button>
                    </div>
                  </Alert>
                </div>
              </div>
            ) : undefined
          }
        />

        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pt-4 pb-4 sm:px-6 sm:pb-6">
          {activeTab === 'records' ? (
            <>
              {/* Domain Metadata Card */}
              {domain && (
                <div className="mb-4 rounded-lg border border-border bg-card/50">
                  <div className="grid grid-cols-2 gap-x-6 gap-y-3 px-4 py-3 sm:grid-cols-3 lg:grid-cols-6">
                    {/* Status */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Status
                      </p>
                      <div className="flex items-center gap-1.5">
                        {verificationStatus && (
                          <>
                            <code
                              className={cn(
                                'text-[12px] font-mono font-medium',
                                verificationStatus.isVerified
                                  ? 'text-green-600 dark:text-green-500'
                                  : 'text-yellow-600 dark:text-yellow-500',
                              )}
                            >
                              {verificationStatus.label}
                            </code>
                            {!verificationStatus.isVerified && (
                              <button
                                onClick={() => setRetryDialogOpen(true)}
                                className="text-[11px] text-primary hover:text-primary/80 font-medium"
                              >
                                Retry
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </div>

                    {/* Registrar */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Registrar
                      </p>
                      <code className="text-[12px] font-mono text-foreground">
                        —
                      </code>
                    </div>

                    {/* Nameservers */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Nameservers
                      </p>
                      <code className="text-[12px] font-mono text-foreground truncate block">
                        {domain.nameservers || '—'}
                      </code>
                    </div>

                    {/* Expiry date */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Expiry date
                      </p>
                      <code className="text-[12px] font-mono text-foreground">
                        —
                      </code>
                    </div>

                    {/* Auto renewal */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Auto renewal
                      </p>
                      <code className="text-[12px] font-mono text-foreground">
                        —
                      </code>
                    </div>

                    {/* Renewal price */}
                    <div>
                      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-0.5">
                        Renewal price
                      </p>
                      <code className="text-[12px] font-mono text-foreground">
                        —
                      </code>
                    </div>
                  </div>
                </div>
              )}

              <div className="mb-4 flex flex-wrap items-center gap-2 sm:gap-3">
                <FiltersPopover
                  open={filtersOpen}
                  onOpenChange={setFiltersOpen}
                  columns={dnsRecordsFilterColumns}
                  filterMap={recordsFilterMap}
                  onRemoveFilter={removeFilter}
                  onClearAll={clearAllFilters}
                  onApplyFilter={applyFilter}
                  resourceLabel="DNS records"
                  filterScope="organizations.domains.records"
                  onApplyQuery={(queryParam, sortParam) => {
                    navigate({
                      to: recordsRouteTo,
                      params: { orgId: orgId!, domainId: domainId! },
                      search: recordsSearchWithSort(
                        queryParam ?? undefined,
                        sortParam ?? undefined,
                      ),
                      replace: true,
                    })
                  }}
                  sortBy={recordsSortBy}
                  sortOrder={recordsSortOrder}
                  onSortChange={handleDnsSortChange}
                  defaultSortParam={encodeSort(
                    DNS_RECORDS_DEFAULT_SORT_BY,
                    DNS_RECORDS_DEFAULT_SORT_ORDER,
                  )}
                  onReset={() => {
                    navigate({
                      to: recordsRouteTo,
                      params: { orgId: orgId!, domainId: domainId! },
                      search: {},
                      replace: true,
                    })
                  }}
                  teamId={orgId}
                />
                {/* Desktop: individual buttons */}
                <div className="hidden sm:flex sm:items-center sm:gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setImportZoneDialogOpen(true)}
                    className="h-9 gap-1.5 text-[13px] cursor-pointer"
                  >
                    <Upload className="h-4 w-4" />
                    Import zone file
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleExportZone}
                    className="h-9 gap-1.5 text-[13px] cursor-pointer"
                  >
                    <Download className="h-4 w-4" />
                    Export
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-1.5 text-[13px] cursor-pointer"
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
                {/* Mobile: More dropdown */}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 gap-1.5 text-[13px] cursor-pointer sm:hidden"
                    >
                      <MoreHorizontal className="h-4 w-4" />
                      More
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" className="w-48">
                    <DropdownMenuItem
                      onClick={() => setImportZoneDialogOpen(true)}
                    >
                      <Upload className="h-4 w-4 mr-1.5" />
                      Import zone file
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleExportZone}>
                      <Download className="h-4 w-4 mr-1.5" />
                      Export
                    </DropdownMenuItem>
                    <DropdownMenuSub>
                      <DropdownMenuSubTrigger className="text-[13px]">
                        <List className="h-4 w-4 mr-1.5" />
                        Add preset
                      </DropdownMenuSubTrigger>
                      <DropdownMenuSubContent className="w-52">
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
                      </DropdownMenuSubContent>
                    </DropdownMenuSub>
                  </DropdownMenuContent>
                </DropdownMenu>
                <div className="ml-auto">
                  <Button
                    onClick={() => setCreateRecordDialogOpen(true)}
                    className="h-9 gap-1.5 text-[13px] font-medium text-white hover:opacity-90 cursor-pointer"
                    style={{ backgroundColor: '#f02e65' }}
                  >
                    <Plus className="h-4 w-4" />
                    Create Record
                  </Button>
                </div>
              </div>

              {/* No loading state: keep previous results until new data is ready (see AGENTS.md → Filters) */}
              {dnsRecords.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card overflow-x-auto overflow-y-visible">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="w-[40px] px-4 py-3">
                            {deletableRecords.length > 0 ? (
                              <Checkbox
                                checked={
                                  deletableRecords.length > 0 &&
                                  selectedRecords.size ===
                                    deletableRecords.length
                                }
                                onCheckedChange={toggleAllRecords}
                              />
                            ) : null}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                            Name
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[90px]">
                            Type
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Value
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[70px]">
                            TTL
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]">
                            Priority
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[70px]">
                            Weight
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[70px]">
                            Port
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                            Comment
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px] pr-4"></TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {dnsRecords.map((record) => {
                          const nameValue = record.name || '@'
                          const value = record.value
                          const isAppwriteManaged =
                            value === 'a.a.a.a' ||
                            value === 'b:b::b:b:b' ||
                            (value?.startsWith('0 issue "') &&
                              value?.includes('"'))
                          const showPriority =
                            record.type === 'MX' || record.type === 'SRV'
                          const showSRVFields = record.type === 'SRV'

                          return (
                            <TableRow key={record.$id}>
                              <TableCell className="w-[40px] px-4 py-3">
                                {record.lock ? null : (
                                  <Checkbox
                                    checked={selectedRecords.has(record.$id)}
                                    onCheckedChange={() =>
                                      toggleRecord(record.$id, !!record.lock)
                                    }
                                  />
                                )}
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2 group/name">
                                  <code className="text-[12px] font-mono text-foreground bg-muted/50 px-1.5 py-0.5 rounded">
                                    {nameValue}
                                  </code>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 w-6 p-0 opacity-0 group-hover/name:opacity-100 transition-opacity cursor-pointer"
                                    onClick={() =>
                                      handleCopy(
                                        nameValue,
                                        `name-${record.$id}`,
                                      )
                                    }
                                  >
                                    {copiedField === `name-${record.$id}` ? (
                                      <Check className="h-3 w-3 text-emerald-500" />
                                    ) : (
                                      <Copy className="h-3 w-3" />
                                    )}
                                  </Button>
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Badge
                                  variant="outline"
                                  className={cn(
                                    'text-[11px] font-medium border',
                                    getRecordTypeColor(record.type),
                                  )}
                                >
                                  {record.type}
                                </Badge>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2 max-w-[280px] group/value">
                                  {isAppwriteManaged ? (
                                    <Badge
                                      variant="outline"
                                      className={cn(
                                        'text-[11px] font-medium border bg-emerald-500/5 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
                                        'inline-flex items-center gap-1.5 px-2 py-0.5',
                                      )}
                                    >
                                      {value === 'a.a.a.a' ||
                                      value === 'b:b::b:b:b'
                                        ? 'Served by Appwrite'
                                        : 'Generated by Appwrite'}
                                    </Badge>
                                  ) : (
                                    <>
                                      {value && value.length > 28 ? (
                                        <TooltipProvider delayDuration={0}>
                                          <Tooltip>
                                            <TooltipTrigger asChild>
                                              <code className="text-[12px] font-mono text-foreground cursor-pointer truncate max-w-[220px] block">
                                                {value}
                                              </code>
                                            </TooltipTrigger>
                                            <TooltipContent
                                              side="top"
                                              className="max-w-md"
                                            >
                                              <p className="text-[12px] whitespace-pre-wrap break-words font-mono">
                                                {value}
                                              </p>
                                            </TooltipContent>
                                          </Tooltip>
                                        </TooltipProvider>
                                      ) : (
                                        <code className="text-[12px] font-mono text-foreground truncate max-w-[220px] block">
                                          {value}
                                        </code>
                                      )}
                                      <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 w-6 p-0 shrink-0 opacity-0 group-hover/value:opacity-100 transition-opacity cursor-pointer"
                                        onClick={() =>
                                          handleCopy(
                                            value,
                                            `value-${record.$id}`,
                                          )
                                        }
                                      >
                                        {copiedField ===
                                        `value-${record.$id}` ? (
                                          <Check className="h-3 w-3 text-emerald-500" />
                                        ) : (
                                          <Copy className="h-3 w-3" />
                                        )}
                                      </Button>
                                    </>
                                  )}
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <code className="text-[12px] font-mono text-muted-foreground">
                                  {record.ttl}
                                </code>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <code className="text-[12px] font-mono text-muted-foreground">
                                  {showPriority && record.priority !== undefined
                                    ? record.priority
                                    : '—'}
                                </code>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <code className="text-[12px] font-mono text-muted-foreground">
                                  {showSRVFields && record.weight !== undefined
                                    ? record.weight
                                    : '—'}
                                </code>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <code className="text-[12px] font-mono text-muted-foreground">
                                  {showSRVFields && record.port !== undefined
                                    ? record.port
                                    : '—'}
                                </code>
                              </TableCell>
                              <TableCell className="px-4 py-3 w-[150px]">
                                {record.comment ? (
                                  <TooltipProvider delayDuration={0}>
                                    <Tooltip>
                                      <TooltipTrigger asChild>
                                        <p className="text-[12px] text-muted-foreground line-clamp-2 cursor-pointer min-w-0 break-words">
                                          {record.comment}
                                        </p>
                                      </TooltipTrigger>
                                      <TooltipContent
                                        side="top"
                                        className="max-w-xs"
                                      >
                                        <p className="text-[12px] whitespace-pre-wrap break-words">
                                          {record.comment}
                                        </p>
                                      </TooltipContent>
                                    </Tooltip>
                                  </TooltipProvider>
                                ) : (
                                  <span className="text-[12px] text-muted-foreground">
                                    —
                                  </span>
                                )}
                              </TableCell>
                              <TableCell className="px-4 py-3 text-right pr-4">
                                {record.lock ? (
                                  <div className="flex justify-end">
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      className="h-8 w-8 p-0"
                                      disabled
                                    >
                                      <Lock className="h-4 w-4 text-muted-foreground" />
                                    </Button>
                                  </div>
                                ) : (
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
                          )
                        })}
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

                  {/* Bulk Delete DNS Records Action Bar */}
                  {selectedRecords.size > 0 && (
                    <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                      <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                        <Badge variant="secondary" className="h-6 px-2.5">
                          {selectedRecords.size} record
                          {selectedRecords.size > 1 ? 's' : ''} selected
                        </Badge>
                        <div className="flex items-center gap-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelectedRecords(new Set())}
                            className="h-8 text-xs"
                          >
                            Cancel
                          </Button>
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={handleBulkDeleteRecords}
                            disabled={bulkDeleteRecordsMutation.isPending}
                            className="h-8 gap-2"
                          >
                            Delete
                          </Button>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Bulk Delete DNS Records Confirmation Dialog */}
                  <Dialog
                    open={bulkDeleteRecordsDialogOpen}
                    onOpenChange={setBulkDeleteRecordsDialogOpen}
                  >
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-left">
                        <DialogTitle>Delete DNS records</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Are you sure you want to delete {selectedRecords.size}{' '}
                          DNS record
                          {selectedRecords.size > 1 ? 's' : ''}? This action
                          cannot be undone.
                        </DialogDescription>
                      </DialogHeader>

                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          onClick={() => setBulkDeleteRecordsDialogOpen(false)}
                          disabled={bulkDeleteRecordsMutation.isPending}
                        >
                          Cancel
                        </Button>
                        <Button
                          variant="destructive"
                          onClick={confirmBulkDeleteRecords}
                          disabled={bulkDeleteRecordsMutation.isPending}
                        >
                          Delete
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </>
              ) : hasRecordFilters ? (
                <EmptyState
                  icon={Globe}
                  title="No records match your filters"
                  description="Try adjusting or clearing filters to see more records"
                  variant="card"
                />
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
            <div className="space-y-6">
              {/* Transfer Domain Section */}
              {domain && (
                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Change organization
                    </h3>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4">
                    <p className="text-[13px] text-muted-foreground mb-4">
                      Select an organization you own to move this domain.
                    </p>
                    <Label
                      htmlFor="organization"
                      className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block"
                    >
                      Move to
                    </Label>
                    <Select
                      value={selectedOrgId}
                      onValueChange={setSelectedOrgId}
                      disabled={organizationsLoading}
                    >
                      <SelectTrigger
                        id="organization"
                        className="mt-2 h-9 max-w-sm"
                      >
                        <SelectValue
                          placeholder={
                            organizationsLoading && organizations.length === 0
                              ? 'Loading organizations...'
                              : 'Select destination'
                          }
                        />
                      </SelectTrigger>
                      <SelectContent>
                        {organizations.length === 0 ? (
                          <div className="px-2 py-1.5 text-[13px] text-muted-foreground">
                            {organizationsLoading
                              ? 'Loading...'
                              : 'No other organizations available'}
                          </div>
                        ) : (
                          organizations.map((org) => (
                            <SelectItem key={org.value} value={org.value}>
                              {org.label}
                            </SelectItem>
                          ))
                        )}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="px-6 py-4 border-t border-border bg-muted/30">
                    <Button
                      size="sm"
                      className="h-9 text-[13px]"
                      disabled={
                        !selectedOrgId ||
                        selectedOrgId === domain.teamId ||
                        transferDomainMutation.isPending
                      }
                      onClick={() => setTransferDialogOpen(true)}
                    >
                      Move
                    </Button>
                  </div>
                </div>
              )}

              {/* Transfer Confirmation Dialog */}
              {domain && (
                <Dialog
                  open={transferDialogOpen}
                  onOpenChange={setTransferDialogOpen}
                >
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-left">
                      <DialogTitle>Change organization</DialogTitle>
                      <DialogDescription className="text-[13px] mt-2">
                        Are you sure you want to move{' '}
                        <strong>{domain.domain}</strong> to{' '}
                        <strong>
                          {organizations.find(
                            (org) => org.value === selectedOrgId,
                          )?.label || 'the selected organization'}
                        </strong>
                        ?
                        <br />
                        <br />
                        Members who are not part of the destination organization
                        must be invited to gain access to this domain.
                      </DialogDescription>
                    </DialogHeader>

                    <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => setTransferDialogOpen(false)}
                      >
                        Cancel
                      </Button>
                      <Button
                        size="sm"
                        className="h-9 text-[13px]"
                        disabled={transferDomainMutation.isPending}
                        onClick={handleTransferDomain}
                      >
                        Move
                      </Button>
                    </div>
                  </DialogContent>
                </Dialog>
              )}

              {/* Delete Domain Section */}
              {domain && (
                <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Delete domain
                    </h3>
                  </div>
                  <div className="border-t border-destructive/20" />
                  <div className="px-6 py-4">
                    <p className="text-[13px] text-muted-foreground">
                      Permanently delete this domain and all associated DNS
                      records. This action cannot be undone.
                    </p>

                    {/* Domain Info Summary */}
                    <div className="flex items-center gap-3 mt-4">
                      <InitialsAvatar name={domain.domain} size="md" />
                      <div className="flex-1 min-w-0">
                        <p className="text-[14px] font-medium text-foreground truncate">
                          {domain.domain}
                        </p>
                        <p className="text-[12px] text-muted-foreground">
                          {domain.nameservers?.toLowerCase() === 'appwrite'
                            ? 'Verified'
                            : 'Unverified'}
                        </p>
                      </div>
                    </div>
                  </div>
                  <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                    <Dialog
                      open={deleteDialogOpen}
                      onOpenChange={setDeleteDialogOpen}
                    >
                      <DialogTrigger asChild>
                        <Button
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                        >
                          Delete domain
                        </Button>
                      </DialogTrigger>
                      <DialogContent className="sm:max-w-md p-0">
                        <DialogHeader className="px-6 pt-6 text-left">
                          <DialogTitle>Delete Domain</DialogTitle>
                          <DialogDescription className="text-[13px] mt-2">
                            Are you sure you want to delete{' '}
                            {domain && (
                              <span className="font-medium text-foreground">
                                {domain.domain}
                              </span>
                            )}{' '}
                            and all its DNS records? This action cannot be
                            undone.
                          </DialogDescription>
                        </DialogHeader>
                        <div className="border-t border-border" />
                        <div className="px-6 pb-4 pt-0">
                          <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                            {domain && (
                              <div className="flex items-center gap-3">
                                <InitialsAvatar
                                  name={domain.domain}
                                  size="sm"
                                />
                                <div>
                                  <p className="text-[13px] font-medium text-foreground">
                                    {domain.domain}
                                  </p>
                                  <p className="text-[11px] text-muted-foreground">
                                    {domain.nameservers?.toLowerCase() ===
                                    'appwrite'
                                      ? 'Verified'
                                      : 'Unverified'}
                                  </p>
                                </div>
                              </div>
                            )}
                          </div>
                          <label className="text-[13px] text-muted-foreground">
                            Type{' '}
                            {domain && (
                              <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                                {domain.domain}
                              </span>
                            )}{' '}
                            to confirm
                          </label>
                          <Input
                            value={deleteConfirmation}
                            onChange={(e) =>
                              setDeleteConfirmation(e.target.value)
                            }
                            placeholder="Enter domain name"
                            className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
                            autoFocus
                          />
                        </div>
                        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-9 text-[13px]"
                            onClick={() => {
                              setDeleteDialogOpen(false)
                              setDeleteConfirmation('')
                            }}
                          >
                            Cancel
                          </Button>
                          <Button
                            variant="destructive"
                            size="sm"
                            className="h-9 text-[13px]"
                            disabled={
                              deleteConfirmation !== domain?.domain ||
                              deleteDomainMutation.isPending
                            }
                            onClick={handleDeleteDomain}
                          >
                            Delete
                          </Button>
                        </div>
                      </DialogContent>
                    </Dialog>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </ConsoleLayout>

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

      {/* Retry Verification Dialog */}
      {domain && (
        <RetryVerification
          open={retryDialogOpen}
          onOpenChange={setRetryDialogOpen}
          domain={domain}
          onRetry={handleRetryVerification}
          isLoading={retryVerificationMutation.isPending}
        />
      )}
    </>
  )
}
