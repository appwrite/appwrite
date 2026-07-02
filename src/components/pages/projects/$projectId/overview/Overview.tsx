import { useState, useMemo, useEffect } from 'react'
import {
  Plus,
  Plug2,
  Check,
  Copy,
  Key,
} from 'lucide-react'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '../shared/ResourceCard'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { HorizontalScrollFade } from '@/components/global/shared/HorizontalScrollFade'
import { useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  OVERVIEW_CHART_TAB_ORDER,
  OVERVIEW_CHART_TAB_LABELS,
  type OverviewChartTabId,
  isOverviewChartTabEnabled,
} from '@/lib/overview-chart-tabs'
import {
  COMPUTE_EXECUTIONS_BREAKDOWN_TITLE,
  COMPUTE_EXECUTIONS_CHART_TITLE,
} from '@/lib/usage/compute-usage'
import { RequestsChart } from './RequestsChart'
import { OverviewStorageChart } from './OverviewStorageChart'
import { OverviewStorageBreakdownToggle } from './OverviewStorageBreakdownToggle'
import { TopRequests } from './TopRequests'
import {
  useProject,
  usePlatforms,
  useApiKeys,
  useCreateApiKey,
  useUpdateApiKey,
  useDeleteApiKey,
  fetchApiKeys,
  useProjectBandwidthOverview,
  useProjectRequestsOverview,
  useProjectExecutionsOverview,
  useProjectGbHoursOverview,
  useProjectOverviewStorageOverview,
  useComputeBreakdownResources,
  useStorageBreakdownResources,
} from '@/lib/react-query/hooks'
import {
  formatBandwidthTotal,
  formatBandwidthValue,
  sumUsageChartPoints,
} from '@/lib/usage/bandwidth-events'
import {
  formatExecutionsTotal,
  formatExecutionsValue,
} from '@/lib/usage/executions-events'
import {
  formatGbHoursTotal,
  formatGbHoursValue,
} from '@/lib/usage/gb-hours-events'
import {
  formatRequestsTotal,
  formatRequestsValue,
} from '@/lib/usage/requests-events'
import {
  formatStorageTotal,
  formatStorageValue,
} from '@/lib/usage/storage-gauges'
import {
  type OverviewStorageBreakdownType,
} from '@/lib/usage/storage-usage'
import { formatApiEndpointDisplay, getApiEndpoint } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import {
  getPlatformDisplayName,
  getPlatformIdentifier,
  type ProjectPlatform,
} from '@/lib/utils/platform'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiKeysList, type ApiKey } from '../shared/ApiKeysList'
import { UsageHistoricDataNote } from '../shared/UsageHistoricDataNote'
import { ApiKeyDrawer } from '../api-keys/ApiKeyDrawer'
import { PlatformDrawer } from '../apps/_components/PlatformDrawer'
import { PlatformContextMenu } from '../apps/_components/PlatformContextMenu'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { Models } from '@appwrite.io/console'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateRangePicker } from '../analytics/DateRangePicker'
import { UsageChartIntervalToggle } from './UsageChartIntervalToggle'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import {
  shouldShowUsageChartSkeleton,
  shouldShowUsageTabMetricSkeleton,
} from '@/lib/usage/usage-chart-loading'
import {
  OVERVIEW_METRIC_NOT_AVAILABLE,
  OVERVIEW_REQUESTS_ERROR,
  OVERVIEW_EXECUTIONS_ERROR,
  OVERVIEW_GB_HOURS_ERROR,
  OVERVIEW_STORAGE_ERROR,
  overviewChartColumnClass,
  overviewChartContentRowClassName,
  overviewChartTabPanelsContainerClass,
  overviewChartTabPanelVisibilityClass,
  overviewBreakdownColumnClass,
  OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT,
} from './chart-panel'
import { OverviewTabMetricContent } from './OverviewTabMetricContent'

interface OverviewTab {
  id: string
  value: string
  label: string
  /** `null` renders N/A instead of a trend (e.g. when usage data failed to load). */
  change: number | null
  /** When true, the tab KPI shows a skeleton instead of value/change. */
  isLoading?: boolean
}

interface Integration {
  id: string
  name: string
  type: 'web' | 'app'
  identifier: string // hostname for web, app ID for apps
  icon: React.ReactNode
  docsUrl: string
  platform: ProjectPlatform
}

const supportedPlatforms = [
  { id: 'web', platform: 'web' },
  { id: 'react-native', platform: 'react-native' },
  { id: 'flutter', platform: 'flutter' },
  { id: 'apple', platform: 'apple' },
  { id: 'android', platform: 'android' },
  { id: 'windows', platform: 'windows' },
  { id: 'linux', platform: 'linux' },
] as const

const supportedLanguages = [
  { id: 'node', name: 'Node.js' },
  { id: 'python', name: 'Python' },
  { id: 'php', name: 'PHP' },
  { id: 'ruby', name: 'Ruby' },
  { id: 'go', name: 'Go' },
  { id: 'deno', name: 'Deno' },
  { id: 'bun', name: 'Bun' },
  { id: 'dart', name: 'Dart' },
  { id: 'swift', name: 'Swift' },
  { id: 'kotlin', name: 'Kotlin' },
  { id: 'java', name: 'Java' },
  { id: 'dotnet', name: '.NET' },
] as const

export interface OverviewInitialData {
  apiKeys: ApiKey[]
  /** Raw listKeys response from loader; passed to useApiKeys to avoid duplicate fetch */
  apiKeysRaw?: { keys?: unknown[] } | null
  /** Prefetched platforms from listPlatforms; avoids empty-state flash in Apps section */
  platforms?: ProjectPlatform[]
}

interface ViewProps {
  projectId: string
  /** Prefetched data from route loader; avoids loading spinner for API keys on first paint */
  initialData?: OverviewInitialData
}

export function View({ projectId, initialData }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState('bandwidth')
  const [storageBreakdownType, setStorageBreakdownType] =
    useState<OverviewStorageBreakdownType>('files')
  const {
    dateRange: dashboardChartDateRange,
    chartInterval,
    setDateRange: setDashboardChartDateRange,
    setChartInterval,
  } = useUsageChartFilters()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [updateDrawerOpen, setUpdateDrawerOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<string | null>(null)
  const [platformDrawerOpen, setPlatformDrawerOpen] = useState(false)
  const [selectedPlatform, setSelectedPlatform] =
    useState<ProjectPlatform | null>(null)
  const { features, isCloud } = useConsoleProfile()

  const handleOverviewTabChange = (tabId: string) => {
    setActiveTab(tabId)
  }

  const usageStatsEnabled = features.usageStats
  const debugOverrides = useDebugOverrides()
  const { disableUsageBreakdownQueries } = debugOverrides
  const showUsageBreakdownPanels = !disableUsageBreakdownQueries
  const isOverviewChartTabVisible = (tabId: OverviewChartTabId) =>
    usageStatsEnabled && isOverviewChartTabEnabled(tabId, debugOverrides)
  const visibleOverviewChartTabs = useMemo(
    () =>
      OVERVIEW_CHART_TAB_ORDER.filter((tabId) =>
        isOverviewChartTabVisible(tabId),
      ),
    [usageStatsEnabled, debugOverrides],
  )
  const overviewMainChartColumnClass = cn(
    overviewChartColumnClass,
    !showUsageBreakdownPanels && '@[700px]:border-e-0',
  )
  const overviewChartRowClassName = useMemo(
    () => overviewChartContentRowClassName(showUsageBreakdownPanels),
    [showUsageBreakdownPanels],
  )

  useEffect(() => {
    if (
      visibleOverviewChartTabs.length > 0 &&
      !visibleOverviewChartTabs.includes(activeTab as OverviewChartTabId)
    ) {
      setActiveTab(visibleOverviewChartTabs[0])
    }
  }, [visibleOverviewChartTabs, activeTab])

  const {
    data: bandwidthUsage,
    isLoading: isBandwidthLoading,
    isFetching: isBandwidthFetching,
    isPlaceholderData: isBandwidthPlaceholderData,
    isError: isBandwidthError,
    refetch: refetchBandwidth,
  } = useProjectBandwidthOverview(
    projectId,
    dashboardChartDateRange,
    isOverviewChartTabVisible('bandwidth'),
    chartInterval,
    activeTab === 'bandwidth',
  )

  const {
    data: requestsUsage,
    isLoading: isRequestsLoading,
    isFetching: isRequestsFetching,
    isPlaceholderData: isRequestsPlaceholderData,
    isError: isRequestsError,
    refetch: refetchRequests,
  } = useProjectRequestsOverview(
    projectId,
    dashboardChartDateRange,
    isOverviewChartTabVisible('requests'),
    chartInterval,
    activeTab === 'requests',
  )

  const {
    data: executionsUsage,
    isLoading: isExecutionsLoading,
    isFetching: isExecutionsFetching,
    isPlaceholderData: isExecutionsPlaceholderData,
    isError: isExecutionsError,
    refetch: refetchExecutions,
  } = useProjectExecutionsOverview(
    projectId,
    dashboardChartDateRange,
    isOverviewChartTabVisible('executions'),
    chartInterval,
    activeTab === 'executions',
  )

  const {
    data: gbHoursUsage,
    isLoading: isGbHoursLoading,
    isFetching: isGbHoursFetching,
    isPlaceholderData: isGbHoursPlaceholderData,
    isError: isGbHoursError,
    refetch: refetchGbHours,
  } = useProjectGbHoursOverview(
    projectId,
    dashboardChartDateRange,
    isOverviewChartTabVisible('gbhours'),
    chartInterval,
    activeTab === 'gbhours',
  )

  const {
    data: storageUsage,
    isLoading: isStorageLoading,
    isFetching: isStorageFetching,
    isPlaceholderData: isStoragePlaceholderData,
    isError: isStorageError,
    refetch: refetchStorage,
  } = useProjectOverviewStorageOverview(
    projectId,
    dashboardChartDateRange,
    isOverviewChartTabVisible('storage'),
    chartInterval,
    activeTab === 'storage',
  )

  const showBandwidthChartLoading = shouldShowUsageChartSkeleton(
    isBandwidthError,
    isBandwidthLoading,
    isBandwidthPlaceholderData,
  )

  const showRequestsChartLoading = shouldShowUsageChartSkeleton(
    isRequestsError,
    isRequestsLoading,
    isRequestsPlaceholderData,
  )

  const showExecutionsChartLoading = shouldShowUsageChartSkeleton(
    isExecutionsError,
    isExecutionsLoading,
    isExecutionsPlaceholderData,
  )

  const showGbHoursChartLoading = shouldShowUsageChartSkeleton(
    isGbHoursError,
    isGbHoursLoading,
    isGbHoursPlaceholderData,
  )

  const showStorageChartLoading = shouldShowUsageChartSkeleton(
    isStorageError,
    isStorageLoading,
    isStoragePlaceholderData,
  )

  const showBandwidthTabMetricLoading = shouldShowUsageTabMetricSkeleton(
    isBandwidthError,
    bandwidthUsage,
    isBandwidthFetching,
  )

  const showRequestsTabMetricLoading = shouldShowUsageTabMetricSkeleton(
    isRequestsError,
    requestsUsage,
    isRequestsFetching,
  )

  const showExecutionsTabMetricLoading = shouldShowUsageTabMetricSkeleton(
    isExecutionsError,
    executionsUsage,
    isExecutionsFetching,
  )

  const showGbHoursTabMetricLoading = shouldShowUsageTabMetricSkeleton(
    isGbHoursError,
    gbHoursUsage,
    isGbHoursFetching,
  )

  const showStorageTabMetricLoading = shouldShowUsageTabMetricSkeleton(
    isStorageError,
    storageUsage,
    isStorageFetching,
  )

  const executionBreakdownIds = useMemo(
    () => executionsUsage?.topConsumers.map((item) => item.id) ?? [],
    [executionsUsage?.topConsumers],
  )

  const storageBreakdownIds = useMemo(() => {
    const ids = new Set<string>()
    for (const item of storageUsage?.storageBreakdown?.files ?? []) {
      const id = item.id?.trim() || item.path?.trim()
      if (id) ids.add(id)
    }
    return Array.from(ids)
  }, [storageUsage?.storageBreakdown?.files])

  const storageComputeBreakdownIds = useMemo(() => {
    const ids = new Set<string>()
    for (const item of [
      ...(storageUsage?.storageBreakdown?.deployments ?? []),
      ...(storageUsage?.storageBreakdown?.builds ?? []),
    ]) {
      const id = item.id?.trim() || item.path?.trim()
      if (id) ids.add(id)
    }
    return Array.from(ids)
  }, [
    storageUsage?.storageBreakdown?.deployments,
    storageUsage?.storageBreakdown?.builds,
  ])

  const activeStorageBreakdownTitle = 'Top consumers'

  const activeStorageBreakdownItems = useMemo(() => {
    if (isStorageError || !storageUsage?.storageBreakdown) return []
    return storageUsage.storageBreakdown[storageBreakdownType] ?? []
  }, [isStorageError, storageUsage?.storageBreakdown, storageBreakdownType])

  const gbHoursBreakdownIds = useMemo(
    () => gbHoursUsage?.topConsumers.map((item) => item.id) ?? [],
    [gbHoursUsage?.topConsumers],
  )

  const { data: executionBreakdownResources } = useComputeBreakdownResources(
    projectId,
    executionBreakdownIds,
    isOverviewChartTabVisible('executions') &&
      showUsageBreakdownPanels &&
      executionBreakdownIds.length > 0,
  )

  const { data: storageBreakdownResources } = useStorageBreakdownResources(
    projectId,
    storageBreakdownIds,
    isOverviewChartTabVisible('storage') &&
      showUsageBreakdownPanels &&
      activeTab === 'storage' &&
      storageBreakdownIds.length > 0,
  )

  const { data: storageComputeBreakdownResources } =
    useComputeBreakdownResources(
      projectId,
      storageComputeBreakdownIds,
      isOverviewChartTabVisible('storage') &&
        showUsageBreakdownPanels &&
        activeTab === 'storage' &&
        storageComputeBreakdownIds.length > 0,
    )

  const { data: gbHoursBreakdownResources } = useComputeBreakdownResources(
    projectId,
    gbHoursBreakdownIds,
    isOverviewChartTabVisible('gbhours') &&
      showUsageBreakdownPanels &&
      gbHoursBreakdownIds.length > 0,
  )

  const overviewTabs = useMemo(() => {
    const bandwidthTab: OverviewTab = isBandwidthError
      ? {
          id: 'bandwidth',
          value: OVERVIEW_METRIC_NOT_AVAILABLE,
          label: 'Bandwidth',
          change: null,
        }
      : showBandwidthTabMetricLoading
        ? {
            id: 'bandwidth',
            value: '',
            label: 'Bandwidth',
            change: null,
            isLoading: true,
          }
        : {
            id: 'bandwidth',
            value: formatBandwidthTotal(
              sumUsageChartPoints(bandwidthUsage?.chartPoints ?? []),
            ),
            label: 'Bandwidth',
            change: bandwidthUsage?.changePercent ?? 0,
          }

    const requestsTab: OverviewTab = isRequestsError
      ? {
          id: 'requests',
          value: OVERVIEW_METRIC_NOT_AVAILABLE,
          label: 'Requests',
          change: null,
        }
      : showRequestsTabMetricLoading
        ? {
            id: 'requests',
            value: '',
            label: 'Requests',
            change: null,
            isLoading: true,
          }
        : {
            id: 'requests',
            value: formatRequestsTotal(
              sumUsageChartPoints(requestsUsage?.chartPoints ?? []),
            ),
            label: 'Requests',
            change: requestsUsage?.changePercent ?? 0,
          }

    const storageTab: OverviewTab = isStorageError
      ? {
          id: 'storage',
          value: OVERVIEW_METRIC_NOT_AVAILABLE,
          label: 'Storage',
          change: null,
        }
      : showStorageTabMetricLoading
        ? {
            id: 'storage',
            value: '',
            label: 'Storage',
            change: null,
            isLoading: true,
          }
        : {
            id: 'storage',
            value: formatStorageTotal(storageUsage?.latestValue ?? 0),
            label: 'Storage',
            change: storageUsage?.changePercent ?? 0,
          }

    const executionsTab: OverviewTab = isExecutionsError
      ? {
          id: 'executions',
          value: OVERVIEW_METRIC_NOT_AVAILABLE,
          label: OVERVIEW_CHART_TAB_LABELS.executions,
          change: null,
        }
      : showExecutionsTabMetricLoading
        ? {
            id: 'executions',
            value: '',
            label: OVERVIEW_CHART_TAB_LABELS.executions,
            change: null,
            isLoading: true,
          }
        : {
            id: 'executions',
            value: formatExecutionsTotal(
              sumUsageChartPoints(executionsUsage?.chartPoints ?? []),
            ),
            label: OVERVIEW_CHART_TAB_LABELS.executions,
            change: executionsUsage?.changePercent ?? 0,
          }

    const gbHoursTab: OverviewTab = isGbHoursError
      ? {
          id: 'gbhours',
          value: OVERVIEW_METRIC_NOT_AVAILABLE,
          label: 'Compute',
          change: null,
        }
      : showGbHoursTabMetricLoading
        ? {
            id: 'gbhours',
            value: '',
            label: 'Compute',
            change: null,
            isLoading: true,
          }
        : {
            id: 'gbhours',
            value: formatGbHoursTotal(
              sumUsageChartPoints(gbHoursUsage?.chartPoints ?? []),
            ),
            label: 'Compute',
            change: gbHoursUsage?.changePercent ?? 0,
          }

    const tabsById = {
      bandwidth: bandwidthTab,
      requests: requestsTab,
      storage: storageTab,
      executions: executionsTab,
      gbhours: gbHoursTab,
    } satisfies Record<OverviewChartTabId, OverviewTab>

    return visibleOverviewChartTabs.map((tabId) => tabsById[tabId])
  }, [
    bandwidthUsage,
    isBandwidthError,
    requestsUsage,
    isRequestsError,
    storageUsage,
    isStorageError,
    executionsUsage,
    isExecutionsError,
    gbHoursUsage,
    isGbHoursError,
    showBandwidthTabMetricLoading,
    showRequestsTabMetricLoading,
    showStorageTabMetricLoading,
    showExecutionsTabMetricLoading,
    showGbHoursTabMetricLoading,
    visibleOverviewChartTabs,
  ])

  const goToAddAppWizard = (kind?: AddAppKind) => {
    navigate({
      to: '/projects/$projectId/apps/add',
      params: { projectId },
      search: kind
        ? { kind, configureStep: 'details' as const }
        : {},
    })
  }

  const handleCreateApiKey = () => {
    setCreateDrawerOpen(true)
  }

  const handleCreateApiKeyForLanguage = () => {
    setCreateDrawerOpen(true)
  }

  const handleCreate = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    createMutation.mutate(data, {
      onSuccess: () => {
        toast.success(t('API key created successfully'))
        setCreateDrawerOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to create API key'))
      },
    })
  }

  // Fetch real project data from console SDK
  const { project: currentProject } = useProject(projectId)

  // Fetch API keys using the hook; pass loader prefetch as initialData to avoid duplicate fetch
  const { apiKeys, isLoading: isLoadingKeys } = useApiKeys(projectId, {
    initialData: initialData?.apiKeysRaw,
  })
  const effectiveApiKeys =
    apiKeys.length > 0 || initialData?.apiKeysRaw
      ? apiKeys
      : (initialData?.apiKeys ?? [])
  const showLoadingKeys = isLoadingKeys && !initialData

  // Create mutation
  const createMutation = useCreateApiKey(projectId)

  // Update mutation
  const updateMutation = useUpdateApiKey(projectId)

  // Delete mutation
  const deleteMutation = useDeleteApiKey(projectId)

  const { platforms: platformsFromHook, isLoading: isLoadingPlatforms } =
    usePlatforms(projectId)
  const platformsForIntegrations =
    platformsFromHook.length > 0
      ? platformsFromHook
      : isLoadingPlatforms
        ? (initialData?.platforms ?? [])
        : platformsFromHook
  const integrations = useMemo(() => {
    if (platformsForIntegrations.length === 0) return []

    return platformsForIntegrations.map((platform) => {
      const platformType = (platform.type ?? 'web') as string
      const platformId = platform.$id
      // Use platform name if available, otherwise fall back to display name from type
      const platformName = (platform.name ||
        getPlatformDisplayName(platformType)) as string
      const identifier = getPlatformIdentifier(platform)

      // Determine if it's web or app based on platform type
      const type: 'web' | 'app' = platformType === 'web' ? 'web' : 'app'

      // Randomly decide which icon shows first for web platforms
      const initialIcon =
        platformType === 'web' ? (Math.random() < 0.5 ? 'ts' : 'js') : undefined

      return {
        id: platformId,
        name: platformName,
        type,
        identifier,
        icon: (
          <PlatformIcon
            platform={platformType}
            size="md"
            initialIcon={initialIcon}
          />
        ),
        docsUrl: '#',
        platform,
      } as Integration
    })
  }, [platformsForIntegrations])

  // Get endpoint from project region (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(currentProject?.region),
    [currentProject?.region],
  )

  const endpointDisplay = useMemo(
    () => formatApiEndpointDisplay(projectEndpoint),
    [projectEndpoint],
  )

  const copyToClipboard = (text: string, field?: string) => {
    navigator.clipboard.writeText(text)
    if (field) {
      setCopiedField(field)
      setTimeout(() => setCopiedField(null), 2000)
    }
  }

  const handleUpdate = (keyId: string) => {
    setSelectedKeyId(keyId)
    setUpdateDrawerOpen(true)
  }

  const handleUpdateSubmit = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    if (!selectedKeyId) return

    updateMutation.mutate(
      {
        keyId: selectedKeyId,
        ...data,
      },
      {
        onSuccess: () => {
          toast.success(t('API key updated successfully'))
          setUpdateDrawerOpen(false)
          setSelectedKeyId(null)
        },
        onError: (error: Error) => {
          toast.error(getErrorMessage(error) || t('Failed to update API key'))
        },
      },
    )
  }

  const handleDelete = (keyId: string) => {
    setSelectedKeyId(keyId)
    setDeleteDialogOpen(true)
  }

  const confirmDelete = () => {
    if (!selectedKeyId) return

    deleteMutation.mutate(selectedKeyId, {
      onSuccess: () => {
        toast.success(t('API key deleted successfully'))
        setDeleteDialogOpen(false)
        setSelectedKeyId(null)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete API key'))
      },
    })
  }

  const selectedKey = selectedKeyId
    ? effectiveApiKeys.find((key) => key.id === selectedKeyId)
    : null

  // Get the full key data for update (we need to fetch it from the API)
  const [updateKeyData, setUpdateKeyData] = useState<Models.Key | null>(null)

  useEffect(() => {
    if (updateDrawerOpen && selectedKeyId) {
      // Fetch the full key data for update
      const fetchKeyData = async () => {
        try {
          const response = await fetchApiKeys(projectId)
          const key = response.keys.find(
            (k: Models.Key) => k.$id === selectedKeyId,
          )
          setUpdateKeyData(key || null)
        } catch {
          setUpdateKeyData(null)
        }
      }
      fetchKeyData()
    } else {
      setUpdateKeyData(null)
    }
  }, [updateDrawerOpen, selectedKeyId, projectId])

  return (
    <div>
      {/* Custom Header with Project Info */}
      <div className="legacy-theme-header">
        {/* Title Row */}
        <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            {/* Left: Title */}
            <div className="flex min-w-0 items-center gap-3">
              <h1
                className={cn(
                  'min-w-0 truncate text-[17px] font-medium text-foreground',
                  isCloud ? 'font-aeonik-pro' : 'font-inter-overview',
                )}
                title={currentProject?.name || undefined}
              >
                {currentProject?.name || ''}
              </h1>
            </div>

            {/* Right: Project ID and Region/Endpoint labels */}
            <TooltipProvider delayDuration={0}>
              <div className="flex items-center gap-2 overflow-hidden">
                {/* Project ID Label */}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={() => copyToClipboard(projectId, 'projectId')}
                      className="flex min-w-0 cursor-pointer items-center gap-1.5 rounded-md bg-muted/50 px-2.5 py-1.5 font-mono text-[12px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                      <span className="truncate max-w-[120px] sm:max-w-[180px]">
                        {projectId}
                      </span>
                      {copiedField === 'projectId' ? (
                        <Check className="h-3 w-3 shrink-0 text-emerald-500" />
                      ) : (
                        <Copy className="h-3 w-3 shrink-0" />
                      )}
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    <p>{t('Copy project ID')}</p>
                  </TooltipContent>
                </Tooltip>

                {/* Endpoint Label (copies endpoint) */}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={() =>
                        copyToClipboard(projectEndpoint, 'endpoint')
                      }
                      className="flex min-w-0 cursor-pointer items-center gap-1.5 rounded-md bg-muted/50 px-2.5 py-1.5 text-[12px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                      <span className="truncate max-w-[100px] sm:max-w-[160px] font-mono">
                        {endpointDisplay}
                      </span>
                      {copiedField === 'endpoint' ? (
                        <Check className="h-3 w-3 shrink-0 text-emerald-500" />
                      ) : (
                        <Copy className="h-3 w-3 shrink-0" />
                      )}
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    <p>{t('Copy API endpoint')}</p>
                  </TooltipContent>
                </Tooltip>
              </div>
            </TooltipProvider>
          </div>
        </div>

        {/* Border separator */}
        <div className="border-b border-border" />
      </div>

      {/* Content area */}
      <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
        {/* Charts card - usage stats (cloud only) */}
        {visibleOverviewChartTabs.length > 0 && (
          <div className="@container overflow-hidden rounded-xl border border-border bg-card/50">
            {/* Metric tabs + date range - same row */}
            <div className="border-b border-border px-5">
              <div className="flex min-w-0 items-center gap-3">
                <HorizontalScrollFade
                  className="min-w-0 flex-1"
                  fadeFromClassName="from-card/50"
                >
                  <TooltipProvider delayDuration={0}>
                  <div className="flex min-w-max" role="tablist">
                    {overviewTabs.map((tab, index) => {
                      const isActive = activeTab === tab.id

                      return (
                        <div key={tab.id} className="flex">
                          {/* Separator */}
                          {index > 0 && (
                            <div className="my-2.5 w-px bg-border" />
                          )}

                          {/* Tab Button */}
                          <button
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => handleOverviewTabChange(tab.id)}
                            className={cn(
                              'relative flex min-w-[168px] flex-col gap-0.5 px-4 py-3 text-start cursor-pointer focus:cursor-pointer focus-visible:cursor-pointer transition-colors first:ps-0 rounded-sm',
                              'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                              isActive
                                ? 'text-foreground'
                                : 'text-muted-foreground hover:text-foreground/80',
                            )}
                          >
                            <OverviewTabMetricContent
                              tabId={tab.id}
                              isLoading={tab.isLoading}
                              value={tab.value}
                              change={tab.change}
                              isActive={isActive}
                            />
                            <span
                              className={cn(
                                'text-[12px]',
                                isActive
                                  ? 'text-muted-foreground'
                                  : 'text-muted-foreground/70',
                              )}
                            >
                              {t(tab.label)}
                            </span>

                            {/* Active indicator */}
                            {isActive && (
                              <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
                            )}
                          </button>
                        </div>
                      )
                    })}
                  </div>
                  </TooltipProvider>
                </HorizontalScrollFade>
                <div className="flex shrink-0 items-center gap-2 py-3">
                  <UsageChartIntervalToggle
                    value={chartInterval}
                    onValueChange={setChartInterval}
                    dateRange={dashboardChartDateRange}
                    className="h-9"
                  />
                  <DateRangePicker
                    dateRange={dashboardChartDateRange}
                    onDateRangeChange={setDashboardChartDateRange}
                    className="h-9"
                  />
                </div>
              </div>
            </div>

            {/* Chart content — stacked in one grid cell for stable height across tabs */}
            <div className={overviewChartTabPanelsContainerClass}>
            {isOverviewChartTabVisible('bandwidth') ? (
            <div
              className={cn(
                overviewChartRowClassName,
                overviewChartTabPanelVisibilityClass(activeTab === 'bandwidth'),
              )}
              aria-hidden={activeTab !== 'bandwidth'}
            >
                <div className={overviewMainChartColumnClass}>
                  <RequestsChart
                    className="@[700px]:min-h-0 @[700px]:flex-1"
                    isPanelVisible={activeTab === 'bandwidth'}
                    title="Bandwidth over time"
                    metric="bandwidth"
                    dateRange={dashboardChartDateRange}
                    chartInterval={chartInterval}
                    chartData={
                      isBandwidthError ? [] : (bandwidthUsage?.dualChartPoints ?? [])
                    }
                    isLoading={showBandwidthChartLoading}
                    isError={isBandwidthError}
                    onRetry={() => void refetchBandwidth()}
                    formatValue={formatBandwidthValue}
                  />
                </div>
                {showUsageBreakdownPanels ? (
                  <div className={overviewBreakdownColumnClass}>
                    <TopRequests
                      className="min-h-0 flex-1"
                      title="Top bandwidth consumers"
                      metric="bandwidth"
                      items={
                        isBandwidthError ? [] : bandwidthUsage?.topConsumers
                      }
                      formatCount={formatBandwidthValue}
                      isLoading={showBandwidthChartLoading}
                      isError={isBandwidthError}
                      onRetry={() => void refetchBandwidth()}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}

            {isOverviewChartTabVisible('requests') ? (
            <div
              className={cn(
                overviewChartRowClassName,
                overviewChartTabPanelVisibilityClass(activeTab === 'requests'),
              )}
              aria-hidden={activeTab !== 'requests'}
            >
                <div className={overviewMainChartColumnClass}>
                  <RequestsChart
                    className="@[700px]:min-h-0 @[700px]:flex-1"
                    isPanelVisible={activeTab === 'requests'}
                    title="Requests over time"
                    metric="requests"
                    dateRange={dashboardChartDateRange}
                    chartInterval={chartInterval}
                    chartData={
                      isRequestsError ? [] : (requestsUsage?.chartPoints ?? [])
                    }
                    isLoading={showRequestsChartLoading}
                    isError={isRequestsError}
                    onRetry={() => void refetchRequests()}
                    formatValue={formatRequestsValue}
                    errorTitle={OVERVIEW_REQUESTS_ERROR.title}
                    errorMessage={OVERVIEW_REQUESTS_ERROR.message}
                  />
                </div>
                {showUsageBreakdownPanels ? (
                  <div className={overviewBreakdownColumnClass}>
                    <TopRequests
                      className="min-h-0 flex-1"
                      title="Top requested endpoints"
                      metric="requests"
                      items={
                        isRequestsError ? [] : requestsUsage?.topEndpoints
                      }
                      formatCount={formatRequestsValue}
                      isLoading={showRequestsChartLoading}
                      isError={isRequestsError}
                      onRetry={() => void refetchRequests()}
                      errorTitle={OVERVIEW_REQUESTS_ERROR.title}
                      errorMessage={OVERVIEW_REQUESTS_ERROR.message}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}

            {isOverviewChartTabVisible('storage') ? (
            <div
              className={cn(
                overviewChartRowClassName,
                overviewChartTabPanelVisibilityClass(activeTab === 'storage'),
              )}
              aria-hidden={activeTab !== 'storage'}
            >
                <div className={overviewMainChartColumnClass}>
                  <OverviewStorageChart
                    className="@[700px]:min-h-0 @[700px]:flex-1"
                    isPanelVisible={activeTab === 'storage'}
                    dateRange={dashboardChartDateRange}
                    chartInterval={chartInterval}
                    chartData={isStorageError ? [] : (storageUsage?.chartPoints ?? [])}
                    isLoading={showStorageChartLoading}
                    isError={isStorageError}
                    onRetry={() => void refetchStorage()}
                  />
                </div>
                {showUsageBreakdownPanels ? (
                  <div className={overviewBreakdownColumnClass}>
                    <TopRequests
                      className="min-h-0 flex-1"
                      title={activeStorageBreakdownTitle}
                      metric="storage"
                      breakdownVariant="resource"
                      storageBreakdownKind={storageBreakdownType}
                      projectId={projectId}
                      storageLookup={storageBreakdownResources?.resources}
                      resourceLookup={storageComputeBreakdownResources?.resources}
                      headerAddon={
                        <OverviewStorageBreakdownToggle
                          value={storageBreakdownType}
                          onValueChange={setStorageBreakdownType}
                        />
                      }
                      items={activeStorageBreakdownItems}
                      formatCount={formatStorageValue}
                      isLoading={showStorageChartLoading}
                      isError={isStorageError}
                      onRetry={() => void refetchStorage()}
                      errorTitle={OVERVIEW_STORAGE_ERROR.title}
                      errorMessage={OVERVIEW_STORAGE_ERROR.message}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}

            {isOverviewChartTabVisible('executions') ? (
            <div
              className={cn(
                overviewChartRowClassName,
                overviewChartTabPanelVisibilityClass(activeTab === 'executions'),
              )}
              aria-hidden={activeTab !== 'executions'}
            >
                <div className={overviewMainChartColumnClass}>
                  <RequestsChart
                    className="@[700px]:min-h-0 @[700px]:flex-1"
                    isPanelVisible={activeTab === 'executions'}
                    title={COMPUTE_EXECUTIONS_CHART_TITLE}
                    metric="executions"
                    dateRange={dashboardChartDateRange}
                    chartInterval={chartInterval}
                    chartData={
                      isExecutionsError ? [] : (executionsUsage?.chartPoints ?? [])
                    }
                    isLoading={showExecutionsChartLoading}
                    isError={isExecutionsError}
                    onRetry={() => void refetchExecutions()}
                    formatValue={formatExecutionsValue}
                    errorTitle={OVERVIEW_EXECUTIONS_ERROR.title}
                    errorMessage={OVERVIEW_EXECUTIONS_ERROR.message}
                  />
                </div>
                {showUsageBreakdownPanels ? (
                  <div className={overviewBreakdownColumnClass}>
                    <TopRequests
                      className="min-h-0 flex-1"
                      title={COMPUTE_EXECUTIONS_BREAKDOWN_TITLE}
                      metric="executions"
                      breakdownVariant="resource"
                      projectId={projectId}
                      resourceLookup={executionBreakdownResources?.resources}
                      itemCount={OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT}
                      items={
                        isExecutionsError ? [] : executionsUsage?.topConsumers
                      }
                      formatCount={formatExecutionsValue}
                      isLoading={showExecutionsChartLoading}
                      isError={isExecutionsError}
                      onRetry={() => void refetchExecutions()}
                      errorTitle={OVERVIEW_EXECUTIONS_ERROR.title}
                      errorMessage={OVERVIEW_EXECUTIONS_ERROR.message}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}

            {isOverviewChartTabVisible('gbhours') ? (
            <div
              className={cn(
                overviewChartRowClassName,
                overviewChartTabPanelVisibilityClass(activeTab === 'gbhours'),
              )}
              aria-hidden={activeTab !== 'gbhours'}
            >
                <div className={overviewMainChartColumnClass}>
                  <RequestsChart
                    className="@[700px]:min-h-0 @[700px]:flex-1"
                    isPanelVisible={activeTab === 'gbhours'}
                    title="Compute over time"
                    metric="gbhours"
                    dateRange={dashboardChartDateRange}
                    chartInterval={chartInterval}
                    chartData={
                      isGbHoursError ? [] : (gbHoursUsage?.chartPoints ?? [])
                    }
                    isLoading={showGbHoursChartLoading}
                    isError={isGbHoursError}
                    onRetry={() => void refetchGbHours()}
                    formatValue={formatGbHoursValue}
                    errorTitle={OVERVIEW_GB_HOURS_ERROR.title}
                    errorMessage={OVERVIEW_GB_HOURS_ERROR.message}
                  />
                </div>
                {showUsageBreakdownPanels ? (
                  <div className={overviewBreakdownColumnClass}>
                    <TopRequests
                      className="min-h-0 flex-1"
                      title="Top compute consumers"
                      metric="gbhours"
                      showUnitInfo
                      breakdownVariant="resource"
                      projectId={projectId}
                      resourceLookup={gbHoursBreakdownResources?.resources}
                      itemCount={OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT}
                      items={
                        isGbHoursError ? [] : gbHoursUsage?.topConsumers
                      }
                      formatCount={formatGbHoursValue}
                      isLoading={showGbHoursChartLoading}
                      isError={isGbHoursError}
                      onRetry={() => void refetchGbHours()}
                      errorTitle={OVERVIEW_GB_HOURS_ERROR.title}
                      errorMessage={OVERVIEW_GB_HOURS_ERROR.message}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}
            </div>
            <UsageHistoricDataNote variant="footer" />
          </div>
        )}

        {/* Integrations Section */}
        <div className={visibleOverviewChartTabs.length > 0 ? 'mt-6' : 'mt-0'}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-[15px] font-semibold text-foreground">{t('Apps')}</h2>
            <Button
              variant="brandCta"
              onClick={() => goToAddAppWizard()}
              size="sm"
              className="h-8 gap-1.5 text-[13px] font-medium"
            >
              <Plus className="h-3.5 w-3.5" />
              {t('Add app')}
            </Button>
          </div>
          {integrations.length === 0 ? (
            <EmptyState icon={Plug2} variant="card" isEmpty={true}>
              <div className="flex flex-col items-center text-center">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Plug2 className="h-6 w-6 text-muted-foreground" />
                </div>
                <h3 className="mb-2 text-[15px] font-medium text-foreground">
                  {t('No apps connected')}
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  {t('Connect your first app to start building with Appwrite. Add web apps, mobile apps, or server SDKs to get started.')} {/* pragma: allowlist secret */}
                </p>
                <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                  <div className="h-px flex-1 bg-border" />
                  <span className="font-medium text-foreground/80">
                    {t('Connect with your stack')}
                  </span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <div className="flex w-full flex-wrap justify-center gap-2">
                  {supportedPlatforms.map(({ id, platform }) => (
                    <Button
                      key={id}
                      onClick={() => goToAddAppWizard(id as AddAppKind)}
                      variant="outline"
                      size="lg"
                    >
                      <PlatformIcon platform={platform} size="sm" />
                      <span>{getPlatformDisplayName(platform)}</span>
                    </Button>
                  ))}
                </div>
              </div>
            </EmptyState>
          ) : (
            <div className={RESOURCE_CARD_GRID_CLASSNAME}>
              {integrations.map((integration) => (
                <PlatformContextMenu
                  key={integration.id}
                  projectId={projectId}
                  platform={integration.platform}
                  onUpdate={(p) => {
                    setSelectedPlatform(p)
                    setPlatformDrawerOpen(true)
                  }}
                >
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedPlatform(integration.platform)
                      setPlatformDrawerOpen(true)
                    }}
                    className={cn(
                      RESOURCE_CARD_PADDED_CLASSNAME,
                      RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                      'flex items-center gap-4 text-start',
                      RESOURCE_CARD_SHELL_CLASSNAME,
                    )}
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-foreground">
                      {integration.icon}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p
                        className="truncate text-[14px] font-medium text-foreground"
                        title={integration.name}
                      >
                        {integration.name}
                      </p>
                      {integration.identifier && (
                        <p
                          className="truncate text-[12px] text-muted-foreground"
                          title={integration.identifier}
                        >
                          {integration.identifier}
                        </p>
                      )}
                    </div>
                  </button>
                </PlatformContextMenu>
              ))}
            </div>
          )}
        </div>

        {/* API Keys Section */}
        <div className="mt-6">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t('API keys')}
            </h2>
            <Button
              variant="brandCta"
              onClick={handleCreateApiKey}
              size="sm"
              className="h-8 gap-1.5 text-[13px] font-medium"
            >
              <Plus className="h-3.5 w-3.5" />
              {t('Add API key')}
            </Button>
          </div>
          {showLoadingKeys ? (
            <div className="rounded-xl border border-border bg-card/50">
              <div className="divide-y divide-border">
                {Array.from({ length: 2 }).map((_, i) => (
                  <div
                    key={i}
                    className="flex items-center justify-between gap-3 p-4"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <Skeleton className="h-4 w-4 shrink-0 rounded" />
                        <Skeleton className="h-4 w-24" />
                        <Skeleton className="h-4 w-16 rounded-full" />
                      </div>
                      <div className="mt-1.5 flex items-center gap-2">
                        <Skeleton className="h-5 w-32 rounded" />
                        <Skeleton className="h-3.5 w-3.5 rounded" />
                        <Skeleton className="h-3.5 w-3.5 rounded" />
                        <Skeleton className="ms-auto h-3 w-24" />
                      </div>
                    </div>
                    <Skeleton className="h-6 w-6 shrink-0 rounded" />
                  </div>
                ))}
              </div>
            </div>
          ) : effectiveApiKeys.length === 0 ? (
            <EmptyState icon={Key} variant="card" isEmpty={true}>
              <div className="flex flex-col items-center text-center">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Key className="h-6 w-6 text-muted-foreground" />
                </div>
                <h3 className="mb-2 text-[15px] font-medium text-foreground">
                  {t('No API keys created')}
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  {t('Create an API key to authenticate your applications and access Appwrite services. API keys provide secure access to your project resources.')} {/* pragma: allowlist secret */}
                </p>
                <div className="w-full">
                  <div className="mb-4 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                    <div className="h-px flex-1 bg-border" />
                    <span className="font-medium text-foreground/80">
                      {t('Create API key for your language')}
                    </span>
                    <div className="h-px flex-1 bg-border" />
                  </div>
                  <div className="flex w-full flex-wrap justify-center gap-2">
                    {supportedLanguages.map(({ id, name }) => (
                      <Button
                        key={id}
                        onClick={handleCreateApiKeyForLanguage}
                        variant="outline"
                        size="lg"
                      >
                        <LanguageIcon language={id} size="sm" />
                        <span>{name}</span>
                      </Button>
                    ))}
                  </div>
                </div>
              </div>
            </EmptyState>
          ) : (
            <ApiKeysList
              apiKeys={effectiveApiKeys}
              isLoading={false}
              onUpdate={handleUpdate}
              onDelete={handleDelete}
              onCopy={copyToClipboard}
              copiedField={copiedField}
              showActions={true}
              projectId={projectId}
            />
          )}
        </div>
      </div>

      {/* Create Drawer */}
      <ApiKeyDrawer
        open={createDrawerOpen}
        onOpenChange={setCreateDrawerOpen}
        onSubmit={handleCreate}
        isLoading={createMutation.isPending}
      />

      {/* Update Drawer */}
      <ApiKeyDrawer
        open={updateDrawerOpen}
        onOpenChange={(open) => {
          setUpdateDrawerOpen(open)
          if (!open) {
            setSelectedKeyId(null)
            setUpdateKeyData(null)
          }
        }}
        onSubmit={handleUpdateSubmit}
        isLoading={updateMutation.isPending}
        apiKey={updateKeyData}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete API key')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} "{selectedKey?.name}"?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <PlatformDrawer
        open={platformDrawerOpen}
        onOpenChange={(open) => {
          setPlatformDrawerOpen(open)
          if (!open) setSelectedPlatform(null)
        }}
        projectId={projectId}
        platform={selectedPlatform}
      />
    </div>
  )
}
