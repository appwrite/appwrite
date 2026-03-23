import { useState, useMemo, useEffect } from 'react'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  TrendingUp,
  TrendingDown,
  Plus,
  Plug2,
  Check,
  Copy,
  Key,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { RequestsChart } from './RequestsChart'
import { TopRequests } from './TopRequests'
import { dashboardStats, formatNumber } from '@/lib/utils/mock-data'
import {
  useProject,
  useApiKeys,
  useCreateApiKey,
  useUpdateApiKey,
  useDeleteApiKey,
  fetchApiKeys,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiKeysList, type ApiKey } from '../shared/ApiKeysList'
import { ApiKeyDrawer } from '../api-keys/ApiKeyDrawer'
import { PlatformDrawer } from '../apps/_components/PlatformDrawer'
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
import { ConnectProject } from '../shared/ConnectProject'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { DateRangePicker } from '../analytics/DateRangePicker'

interface OverviewTab {
  id: string
  value: string
  label: string
  change: number
}

const overviewTabs: OverviewTab[] = [
  {
    id: 'bandwidth',
    value: `${dashboardStats.totalBandwidth}GB`,
    label: 'Bandwidth',
    change: dashboardStats.bandwidthChange,
  },
  {
    id: 'requests',
    value: formatNumber(dashboardStats.totalRequests),
    label: 'Requests',
    change: dashboardStats.requestsChange,
  },
  {
    id: 'storage',
    value: `${dashboardStats.totalStorage}GB`,
    label: 'Storage',
    change: dashboardStats.storageChange,
  },
  {
    id: 'executions',
    value: formatNumber(dashboardStats.totalExecutions),
    label: 'Executions',
    change: dashboardStats.executionsChange,
  },
  {
    id: 'gbhours',
    value: `${dashboardStats.totalGbHours}`,
    label: 'GB-hours',
    change: dashboardStats.gbHoursChange,
  },
]

interface Integration {
  id: string
  name: string
  type: 'web' | 'app'
  identifier: string // hostname for web, app ID for apps
  icon: React.ReactNode
  docsUrl: string
  platform: Models.Platform
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
  /** Prefetched platforms from project; avoids empty-state flash in Apps section */
  platforms?: unknown[]
}

interface ViewProps {
  projectId: string
  /** Prefetched data from route loader; avoids loading spinner for API keys on first paint */
  initialData?: OverviewInitialData
}

function getDefaultDashboardChartRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

export function View({ projectId, initialData }: ViewProps) {
  const [activeTab, setActiveTab] = useState('bandwidth')
  const [dashboardChartDateRange, setDashboardChartDateRange] = useState<
    DateRange | undefined
  >(() => getDefaultDashboardChartRange())
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [updateDrawerOpen, setUpdateDrawerOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<string | null>(null)
  const [connectDialogOpen, setConnectDialogOpen] = useState(false)
  const [connectInitialSdk, setConnectInitialSdk] = useState<string>('web')
  const [platformDrawerOpen, setPlatformDrawerOpen] = useState(false)
  const [selectedPlatform, setSelectedPlatform] =
    useState<Models.Platform | null>(null)
  const { features } = useConsoleProfile()
  const handleConnectPlatform = (platform?: string) => {
    const sdkMap: Record<string, string> = {
      web: 'web',
      'react-native': 'web',
      flutter: 'flutter',
      apple: 'apple',
      android: 'android',
      windows: 'web',
      linux: 'web',
    }
    setConnectInitialSdk(sdkMap[platform ?? 'web'] ?? 'web')
    setConnectDialogOpen(true)
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
        toast.success('API key created successfully')
        setCreateDrawerOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to create API key')
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
    apiKeys.length > 0 ? apiKeys : (initialData?.apiKeys ?? [])
  const showLoadingKeys = isLoadingKeys && !initialData

  // Create mutation
  const createMutation = useCreateApiKey(projectId)

  // Update mutation
  const updateMutation = useUpdateApiKey(projectId)

  // Delete mutation
  const deleteMutation = useDeleteApiKey(projectId)

  // Build integrations list from project platforms; use initialData for first paint to avoid empty-state flash
  const platformsForIntegrations =
    currentProject?.platforms ?? initialData?.platforms ?? []
  const integrations = useMemo(() => {
    if (platformsForIntegrations.length === 0) return []

    return platformsForIntegrations.map((platform: Record<string, unknown>) => {
      const platformType = (platform.type ??
        platform.platform ??
        'web') as string
      const platformId = (platform.$id ?? platform.id ?? platformType) as string
      // Use platform name if available, otherwise fall back to display name from type
      const platformName = (platform.name ??
        getPlatformDisplayName(platformType)) as string
      const identifier = (platform.hostname ??
        platform.identifier ??
        platform.key ??
        '') as string

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
        platform: platform as Models.Platform,
      } as Integration
    })
  }, [platformsForIntegrations])

  // Get endpoint from project region (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(currentProject?.region),
    [currentProject?.region],
  )

  // Extract hostname and path from endpoint URL
  const endpointDisplay = useMemo(() => {
    if (!projectEndpoint) return ''

    try {
      const url = new URL(projectEndpoint)
      // Ensure we include the full hostname (with region code if present) and path
      const hostname = url.hostname
      const pathname = url.pathname || '/v1'
      return `${hostname}${pathname}`
    } catch {
      // If URL parsing fails, try to extract hostname and path manually
      const match = projectEndpoint.match(/https?:\/\/([^\/]+)(\/.*)?/)
      if (match) {
        const hostname = match[1]
        const path = match[2] || '/v1'
        return `${hostname}${path}`
      }
      return projectEndpoint.replace(/^https?:\/\//, '')
    }
  }, [projectEndpoint])

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
          toast.success('API key updated successfully')
          setUpdateDrawerOpen(false)
          setSelectedKeyId(null)
        },
        onError: (error: Error) => {
          toast.error(getErrorMessage(error) || 'Failed to update API key')
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
        toast.success('API key deleted successfully')
        setDeleteDialogOpen(false)
        setSelectedKeyId(null)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete API key')
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
      <div>
        {/* Title Row */}
        <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            {/* Left: Title */}
            <div className="flex items-center gap-3">
              <h1 className="text-[17px] font-semibold text-foreground">
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
                    <p>Copy project ID</p>
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
                    <p>Copy API endpoint</p>
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
        {features.usageStats && (
          <div className="@container rounded-xl border border-border bg-card/50">
            {/* Metric tabs + date range — same row */}
            <div className="border-b border-border px-5">
              <div className="flex min-w-0 items-center gap-3">
                <div
                  className="min-w-0 flex-1 overflow-x-auto"
                  style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
                >
                  <div className="flex min-w-max" role="tablist">
                    {overviewTabs.map((tab, index) => {
                      const isActive = activeTab === tab.id
                      const isPositive = tab.change > 0
                      const isNegative = tab.change < 0

                      return (
                        <div key={tab.id} className="flex">
                          {/* Separator */}
                          {index > 0 && <div className="my-2.5 w-px bg-border" />}

                          {/* Tab Button */}
                          <button
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => setActiveTab(tab.id)}
                            className={cn(
                              'relative flex min-w-[150px] flex-col gap-0.5 px-4 py-3 text-left cursor-pointer focus:cursor-pointer focus-visible:cursor-pointer transition-colors first:pl-0 rounded-sm',
                              'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                              isActive
                                ? 'text-foreground'
                                : 'text-muted-foreground hover:text-foreground/80',
                            )}
                          >
                            <div className="flex items-baseline gap-2">
                              <span
                                className={cn(
                                  'text-[18px] font-semibold tracking-tight sm:text-[20px]',
                                  isActive
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                                )}
                              >
                                {tab.value}
                              </span>
                              <div className="flex items-center gap-1">
                                {isPositive && (
                                  <TrendingUp
                                    className={cn(
                                      'h-3 w-3',
                                      isActive
                                        ? 'text-emerald-500'
                                        : 'text-emerald-500/60',
                                    )}
                                  />
                                )}
                                {isNegative && (
                                  <TrendingDown
                                    className={cn(
                                      'h-3 w-3',
                                      isActive
                                        ? 'text-red-500'
                                        : 'text-red-500/60',
                                    )}
                                  />
                                )}
                                <span
                                  className={cn(
                                    'text-[11px] font-medium',
                                    isPositive &&
                                      (isActive
                                        ? 'text-emerald-500'
                                        : 'text-emerald-500/60'),
                                    isNegative &&
                                      (isActive
                                        ? 'text-red-500'
                                        : 'text-red-500/60'),
                                    !isPositive &&
                                      !isNegative &&
                                      'text-muted-foreground',
                                  )}
                                >
                                  {isPositive && '+'}
                                  {tab.change}%
                                </span>
                              </div>
                            </div>
                            <span
                              className={cn(
                                'text-[12px]',
                                isActive
                                  ? 'text-muted-foreground'
                                  : 'text-muted-foreground/70',
                              )}
                            >
                              {tab.label}
                            </span>

                            {/* Active indicator */}
                            {isActive && (
                              <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                            )}
                          </button>
                        </div>
                      )
                    })}
                  </div>
                </div>
                <div className="shrink-0 py-3">
                  <DateRangePicker
                    dateRange={dashboardChartDateRange}
                    onDateRangeChange={setDashboardChartDateRange}
                    className="h-9"
                  />
                </div>
              </div>
            </div>

            {/* Chart content */}
            {activeTab === 'bandwidth' && (
              <div className="flex flex-col @[700px]:flex-row">
                <div className="flex-1 border-b border-border p-5 @[700px]:border-b-0 @[700px]:border-r">
                  <RequestsChart
                    title="Bandwidth over time"
                    metric="bandwidth"
                    dateRange={dashboardChartDateRange}
                  />
                </div>
                <div className="w-full p-5 @[700px]:w-[320px]">
                  <TopRequests
                    title="Top bandwidth consumers"
                    metric="bandwidth"
                  />
                </div>
              </div>
            )}

            {activeTab === 'requests' && (
              <div className="flex flex-col @[700px]:flex-row">
                <div className="flex-1 border-b border-border p-5 @[700px]:border-b-0 @[700px]:border-r">
                  <RequestsChart
                    title="Requests over time"
                    metric="requests"
                    dateRange={dashboardChartDateRange}
                  />
                </div>
                <div className="w-full p-5 @[700px]:w-[320px]">
                  <TopRequests
                    title="Top requested endpoints"
                    metric="requests"
                  />
                </div>
              </div>
            )}

            {activeTab === 'storage' && (
              <div className="flex flex-col @[700px]:flex-row">
                <div className="flex-1 border-b border-border p-5 @[700px]:border-b-0 @[700px]:border-r">
                  <RequestsChart
                    title="Storage usage over time"
                    metric="storage"
                    dateRange={dashboardChartDateRange}
                  />
                </div>
                <div className="w-full p-5 @[700px]:w-[320px]">
                  <TopRequests title="Top storage buckets" metric="storage" />
                </div>
              </div>
            )}

            {activeTab === 'executions' && (
              <div className="flex flex-col @[700px]:flex-row">
                <div className="flex-1 border-b border-border p-5 @[700px]:border-b-0 @[700px]:border-r">
                  <RequestsChart
                    title="Executions over time"
                    metric="executions"
                    dateRange={dashboardChartDateRange}
                  />
                </div>
                <div className="w-full p-5 @[700px]:w-[320px]">
                  <TopRequests
                    title="Top executed functions"
                    metric="executions"
                  />
                </div>
              </div>
            )}

            {activeTab === 'gbhours' && (
              <div className="flex flex-col @[700px]:flex-row">
                <div className="flex-1 border-b border-border p-5 @[700px]:border-b-0 @[700px]:border-r">
                  <RequestsChart
                    title="GB-hours over time"
                    metric="gbhours"
                    dateRange={dashboardChartDateRange}
                  />
                </div>
                <div className="w-full p-5 @[700px]:w-[320px]">
                  <TopRequests
                    title="Top GB-hours consumers"
                    metric="gbhours"
                  />
                </div>
              </div>
            )}
          </div>
        )}

        {/* Integrations Section */}
        <div className={features.usageStats ? 'mt-6' : 'mt-0'}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-[15px] font-semibold text-foreground">Apps</h2>
            <Button
              onClick={() => handleConnectPlatform()}
              size="sm"
              className="h-8 gap-1.5 text-[13px] font-medium text-white hover:opacity-90"
              style={{ backgroundColor: '#f02e65' }}
            >
              <Plus className="h-3.5 w-3.5" />
              Add app
            </Button>
          </div>
          {integrations.length === 0 ? (
            <EmptyState icon={Plug2} variant="card" isEmpty={true}>
              <div className="flex flex-col items-center text-center">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Plug2 className="h-6 w-6 text-muted-foreground" />
                </div>
                <h3 className="mb-2 text-[15px] font-medium text-foreground">
                  No apps connected
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  Connect your first app to start building with Appwrite. Add
                  web apps, mobile apps, or server SDKs to get started.
                </p>
                <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                  <div className="h-px flex-1 bg-border" />
                  <span className="font-medium text-foreground/80">
                    Connect with your stack
                  </span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <div className="flex w-full flex-wrap justify-center gap-2">
                  {supportedPlatforms.map(({ id, platform }) => (
                    <Button
                      key={id}
                      onClick={() => handleConnectPlatform(id)}
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
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {integrations.map((integration) => (
                <button
                  key={integration.id}
                  type="button"
                  onClick={() => {
                    setSelectedPlatform(integration.platform)
                    setPlatformDrawerOpen(true)
                  }}
                  className="group flex cursor-pointer items-center gap-4 rounded-xl border border-border bg-card/50 p-4 text-left transition-colors hover:border-border hover:bg-card"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-foreground">
                    {integration.icon}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-[14px] font-medium text-foreground">
                      {integration.name}
                    </p>
                    {integration.identifier && (
                      <p className="text-[12px] text-muted-foreground">
                        {integration.identifier}
                      </p>
                    )}
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>

        {/* API Keys Section */}
        <div className="mt-6">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-[15px] font-semibold text-foreground">
              API Keys
            </h2>
            <Button
              onClick={handleCreateApiKey}
              size="sm"
              className="h-8 gap-1.5 text-[13px] font-medium text-white hover:opacity-90"
              style={{ backgroundColor: '#f02e65' }}
            >
              <Plus className="h-3.5 w-3.5" />
              Add API key
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
                        <Skeleton className="ml-auto h-3 w-24" />
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
                  No API keys created
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  Create an API key to authenticate your applications and access
                  Appwrite services. API keys provide secure access to your
                  project resources.
                </p>
                <div className="w-full">
                  <div className="mb-4 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                    <div className="h-px flex-1 bg-border" />
                    <span className="font-medium text-foreground/80">
                      Create API key for your language
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
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete API key</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete "{selectedKey?.name}"? This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <ConnectProject
        open={connectDialogOpen}
        onOpenChange={setConnectDialogOpen}
        projectId={projectId}
        initialSdk={connectInitialSdk}
      />

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
