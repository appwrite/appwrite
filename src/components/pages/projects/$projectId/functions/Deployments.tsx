import { useState, useEffect, useLayoutEffect, useMemo, useRef, useContext } from 'react'
import {
  useParams,
  Link,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import { useQueryClient, useMutation } from '@tanstack/react-query'
import {
  Info,
  Clock,
  Trash2,
  XCircle,
  GitBranch,
  GitCommit,
  Shield,
  CheckCircle2,
  HelpCircle,
  Lock,
  Download,
  RefreshCw,
  Play,
  FileCode,
  Package,
  ChevronDown,
  MoreHorizontal,
  ExternalLink,
} from 'lucide-react'
import {
  getDeploymentStatusBadge,
  isDeploymentCompleted,
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import {
  useProjectFunction,
  useFunctionDeployments,
  useFunctionDeployment,
  useFunctionDomains,
  useProjectRuntimes,
  useFunctionSpecifications,
  Dependencies,
  deleteFunctionDeployment,
  cancelFunctionDeployment,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getFirstEnabledSpecification,
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
} from '@/lib/specifications'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDownloadType } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Route } from '@/routes/_public/projects.$projectId.functions.$functionId.index'
import { CreateExecutionDrawer } from './CreateExecutionDrawer'
import { useCreateDeployment } from '../shared/CreateDeploymentContext'
import { CreateDeploymentDropdown } from '../shared/CreateDeploymentDropdown'
import { DeploymentsToolbarContext } from './Layout'
import { getQueryParam, queryParamToMap } from '@/lib/table-filters'

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1000
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

// GitHub Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

// GitLab Icon Component
function GitLabIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M23.955 13.587l-1.1-3.38-.02-.05-.02-.05-2.1-6.45a.74.74 0 00-.68-.47.74.74 0 00-.68.47l-2.1 6.45-1.1 3.38a.74.74 0 00.28.85l9.5 6.9a.74.74 0 00.85 0l9.5-6.9a.74.74 0 00.28-.85zm-2.1-3.38l1.1 3.38-8.5 6.18-8.5-6.18 1.1-3.38 1.1 3.38a.74.74 0 00.28.85l7.12 5.17 7.12-5.17a.74.74 0 00.28-.85l1.1-3.38z" />
    </svg>
  )
}

// Bitbucket Icon Component
function BitbucketIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M.778 1.213a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.891L.778 1.213zM14.52 15.53H9.522L8.17 8.466h7.561l.529 7.064h-1.74z" />
    </svg>
  )
}

// Detect VCS provider from deployment
function getVcsProvider(
  deployment: unknown,
): { name: string; icon: React.ReactNode } | null {
  // Check for providerRepositoryUrl which contains the provider domain
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) {
      return {
        name: 'GitHub',
        icon: <GitHubIcon className="h-4 w-4" />,
      }
    }
    if (url.includes('gitlab.com')) {
      return {
        name: 'GitLab',
        icon: <GitLabIcon className="h-4 w-4" />,
      }
    }
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com')) {
      return {
        name: 'Bitbucket',
        icon: <BitbucketIcon className="h-4 w-4" />,
      }
    }
  }

  // Check for vcsProvider field (if available)
  if (deployment.vcsProvider) {
    const provider = deployment.vcsProvider.toLowerCase()
    if (provider === 'github') {
      return {
        name: 'GitHub',
        icon: <GitHubIcon className="h-4 w-4" />,
      }
    }
    if (provider === 'gitlab') {
      return {
        name: 'GitLab',
        icon: <GitLabIcon className="h-4 w-4" />,
      }
    }
    if (provider === 'bitbucket') {
      return {
        name: 'Bitbucket',
        icon: <BitbucketIcon className="h-4 w-4" />,
      }
    }
  }

  // Check if type is 'git' or 'vcs' (generic VCS deployment)
  if (deployment.type === 'git' || deployment.type === 'vcs') {
    // If we have repository info but can't determine provider, show generic Git icon
    if (deployment.providerRepositoryUrl || deployment.providerRepositoryId) {
      return {
        name: 'Git',
        icon: <GitBranch className="h-4 w-4" />,
      }
    }
  }

  return null
}

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const search = Route.useSearch()
  const urlPage = search.page ?? 1
  // Derive filter from location so we use the same source as the Layout (handles TanStack Router parsed search object)
  const filterMap = useMemo(() => {
    const locSearch = location.search
    const queryParam =
      typeof locSearch === 'object' &&
      locSearch !== null &&
      'query' in locSearch
        ? (locSearch as { query?: string }).query ?? null
        : getQueryParam(
            new URL(
              location.pathname +
                (typeof locSearch === 'string' ? locSearch || '' : ''),
              typeof window !== 'undefined'
                ? window.location.origin
                : 'http://dummy',
            ),
          )
    return queryParamToMap(queryParam)
  }, [location.pathname, location.search])
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

  const [displayedPage, setDisplayedPage] = useState(urlPage - 1)
  const [requestedPage, setRequestedPage] = useState(urlPage - 1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [selectedDeployments, setSelectedDeployments] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deleteActiveDialogOpen, setDeleteActiveDialogOpen] = useState(false)
  const [runtimeLimitsDialogOpen, setRuntimeLimitsDialogOpen] = useState(false)
  const [selectedSpecification, setSelectedSpecification] = useState<string>('')
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)
  const [activateDialogOpen, setActivateDialogOpen] = useState(false)
  const [executeDrawerOpen, setExecuteDrawerOpen] = useState(false)
  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [cancelTargetDeploymentId, setCancelTargetDeploymentId] =
    useState<string | null>(null)
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const deploymentsToolbar = useContext(DeploymentsToolbarContext)

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  // Sync requested page with URL when it changes externally (e.g., browser back/forward)
  useEffect(() => {
    const newRequestedPage = urlPage - 1
    if (newRequestedPage !== requestedPage) {
      setRequestedPage(newRequestedPage)
    }
  }, [urlPage, requestedPage])

  const {
    total,
    isLoading: deploymentsLoading,
    isFetching: deploymentsFetching,
  } = useFunctionDeployments(
    projectId,
    functionId,
    requestedPage,
    pageSize,
    filterQueries,
  )

  const { deployments: displayedDeployments, total: displayedTotal } =
    useFunctionDeployments(
      projectId,
      functionId,
      displayedPage,
      pageSize,
      filterQueries,
    )

  // Update displayed page only when requested page data is ready (not fetching)
  // This keeps the current page visible until the next page data is fully loaded
  useEffect(() => {
    if (
      !deploymentsFetching &&
      requestedPage !== displayedPage &&
      !deploymentsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [deploymentsFetching, deploymentsLoading, requestedPage, displayedPage])

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastDeploymentsRef = useRef<typeof displayedDeployments>([])
  useEffect(() => {
    if (!deploymentsFetching && displayedDeployments.length > 0) {
      lastDeploymentsRef.current = displayedDeployments
    }
  }, [deploymentsFetching, displayedDeployments])
  const deployments =
    deploymentsFetching && lastDeploymentsRef.current.length > 0
      ? lastDeploymentsRef.current
      : displayedDeployments

  // Scroll to top when page changes and data is ready
  useLayoutEffect(() => {
    if (deployments.length > 0 && displayedPage !== undefined) {
      // Find the scrollable container by traversing up from our element
      const element = scrollContainerRef.current
      if (element) {
        let parent: HTMLElement | null = element.parentElement
        while (parent) {
          const style = window.getComputedStyle(parent)
          if (style.overflowY === 'auto' || style.overflowY === 'scroll') {
            parent.scrollTop = 0
            break
          }
          parent = parent.parentElement
        }
      }
    }
  }, [displayedPage, deployments.length])

  // Fetch active deployment if exists
  const { data: activeDeployment } = useFunctionDeployment(
    projectId,
    functionId,
    func?.deploymentId || undefined,
  )

  // Tick every second when any deployment is in progress (for live duration)
  const [, setTick] = useState(0)
  const hasInProgressDeployment = useMemo(
    () =>
      (deployments?.some((d) => isDeploymentInProgress(d.status)) ?? false) ||
      (activeDeployment != null &&
        isDeploymentInProgress(activeDeployment.status)),
    [deployments, activeDeployment],
  )
  useEffect(() => {
    if (!hasInProgressDeployment) return
    const interval = setInterval(() => setTick((t) => t + 1), 1000)
    return () => clearInterval(interval)
  }, [hasInProgressDeployment])

  // Fetch domains for the function (filter by active deployment)
  // Use same params as domains tab to share cache
  const { data: domainsData } = useFunctionDomains(
    projectId,
    functionId,
    0,
    DOMAINS_DEFAULT_PAGE_SIZE,
    '',
  )

  // Fetch runtimes to get runtime name
  const { data: runtimesData } = useProjectRuntimes(projectId)

  // Fetch specifications to get resource limits
  const { data: specificationsData } = useFunctionSpecifications(projectId)

  // Filter domains for active deployment and sort by length (shortest first), limit to 3
  const activeDomains = useMemo(() => {
    const filtered =
      domainsData?.rules?.filter(
        (rule) => rule.deploymentId === activeDeployment?.$id,
      ) || []
    return filtered
      .sort((a, b) => a.domain.length - b.domain.length)
      .slice(0, 3)
  }, [domainsData?.rules, activeDeployment?.$id])

  // Check if there are more domains than displayed
  const totalActiveDomains =
    domainsData?.rules?.filter(
      (rule) => rule.deploymentId === activeDeployment?.$id,
    ).length || 0
  const hasMoreDomains = totalActiveDomains > activeDomains.length

  // Get runtime name
  const runtimeName =
    runtimesData?.runtimes?.find((r) => r.$id === func?.runtime)?.name ||
    func?.runtime ||
    'N/A'

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const specifications = specificationsData?.specifications || []

  // Get specification info
  const specification = specifications.find(
    (spec) => spec.slug === func?.specification,
  )
  const specificationText = specification
    ? `${specification.cpus} CPU, ${specification.memory}MB RAM`
    : 'Not set'

  // Get VCS provider info
  const vcsProvider = activeDeployment ? getVcsProvider(activeDeployment) : null

  // Initialize selected specification when dialog opens
  useEffect(() => {
    if (runtimeLimitsDialogOpen && specifications.length > 0) {
      const currentSpec = func?.specification
      if (currentSpec) {
        const spec = specifications.find((s) => s.slug === currentSpec)
        if (spec && isSpecificationAllowedInPlan(spec)) {
          setSelectedSpecification(currentSpec)
        } else {
          const firstEnabled = getFirstEnabledSpecification(specifications)
          setSelectedSpecification(firstEnabled?.slug || '')
        }
      } else {
        const firstEnabled = getFirstEnabledSpecification(specifications)
        setSelectedSpecification(firstEnabled?.slug || '')
      }
    }
  }, [runtimeLimitsDialogOpen, func?.specification, specifications])

  // Update function specification mutation
  const updateSpecificationMutation = useMutation({
    mutationFn: async (specificationSlug: string) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      if (!specificationSlug)
        throw new Error('A specification must be selected')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId,
        name: func.name,
        specification: specificationSlug,
      })
    },
    onSuccess: () => {
      toast.success('Runtime limits updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      setRuntimeLimitsDialogOpen(false)
    },
    onError: (error: unknown) => {
      toast.error(error.message || 'Failed to update runtime limits')
    },
  })

  const handleSaveSpecification = () => {
    updateSpecificationMutation.mutate(selectedSpecification)
  }

  const createDeployment = useCreateDeployment()

  // Clear selection when navigating between pages
  useEffect(() => {
    setSelectedDeployments(new Set())
    setDeleteDialogOpen(false)
  }, [displayedPage])

  const isBuilding =
    activeDeployment?.status === 'building' ||
    activeDeployment?.status === 'processing'

  const handleDownloadSource = () => {
    if (!projectId || !functionId || !activeDeployment) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.functions.getDeploymentDownload({
        functionId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Source,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch {
      toast.error('Failed to download source code')
    }
  }

  const handleDownloadBuild = () => {
    if (!projectId || !functionId || !activeDeployment) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.functions.getDeploymentDownload({
        functionId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Output,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch {
      toast.error('Failed to download build output')
    }
  }

  // Redeploy mutation
  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !functionId || !activeDeployment) {
        throw new Error(
          'Project ID, Function ID, and Deployment ID are required',
        )
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.createDuplicateDeployment({
        functionId,
        deploymentId: activeDeployment.$id,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['deployments', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success('Deployment rebuild started')
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to redeploy')
    },
  })

  // Activate mutation (disabled for active deployment, but included for consistency)
  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !functionId || !activeDeployment) {
        throw new Error(
          'Project ID, Function ID, and Deployment ID are required',
        )
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.updateFunctionDeployment({
        functionId,
        deploymentId: activeDeployment.$id,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['deployments', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success('Deployment activated successfully')
      setActivateDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to activate deployment')
    },
  })

  // Cancel build mutation (stop the build, deployment remains with status canceled)
  const cancelBuildMutation = useMutation({
    mutationFn: async (deploymentIdToCancel: string) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }
      return await cancelFunctionDeployment(
        projectId,
        functionId,
        deploymentIdToCancel,
      )
    },
    onSuccess: async () => {
      setCancelBuildDialogOpen(false)
      setCancelTargetDeploymentId(null)
      await queryClient.refetchQueries({
        queryKey: ['deployments', 'project', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success('Build cancelled')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to cancel build')
    },
  })

  // Delete mutation for active deployment
  const deleteActiveMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !functionId || !activeDeployment) {
        throw new Error(
          'Project ID, Function ID, and Deployment ID are required',
        )
      }
      throw new Error(
        'Cannot delete the active deployment. Please activate another deployment first.',
      )
    },
    onSuccess: async () => {
      // Refetch deployments list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['deployments', 'project', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success('Deployment deleted successfully')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete deployment')
    },
  })

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (deploymentIds: string[]) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }

      // Prevent deleting active deployment
      const activeDeploymentId = activeDeployment?.$id
      if (activeDeploymentId && deploymentIds.includes(activeDeploymentId)) {
        throw new Error(
          'Cannot delete the active deployment. Please activate another deployment first.',
        )
      }

      // Delete all deployments in parallel
      await Promise.all(
        deploymentIds.map((deploymentId) =>
          deleteFunctionDeployment(projectId, functionId, deploymentId),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch deployments list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.DEPLOYMENTS,
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success(
        `Successfully deleted ${selectedDeployments.size} deployment${selectedDeployments.size > 1 ? 's' : ''}`,
      )
      setSelectedDeployments(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete deployments')
    },
  })

  const handleBulkDelete = () => {
    if (selectedDeployments.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedDeployments.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedDeployments))
  }

  const toggleDeployment = (deploymentId: string) => {
    const newSelected = new Set(selectedDeployments)
    if (newSelected.has(deploymentId)) {
      newSelected.delete(deploymentId)
    } else {
      newSelected.add(deploymentId)
    }
    setSelectedDeployments(newSelected)
  }

  const toggleAllDeployments = () => {
    const activeDeploymentId = activeDeployment?.$id
    const selectableDeployments = deployments.filter(
      (d) => d.$id !== activeDeploymentId,
    )

    if (selectedDeployments.size === selectableDeployments.length) {
      setSelectedDeployments(new Set())
    } else {
      setSelectedDeployments(new Set(selectableDeployments.map((d) => d.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    // Update URL with new page (1-indexed)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: page === 1 ? undefined : page, // Remove page param if it's page 1
      }),
      replace: true, // Replace history to avoid cluttering back button
    })
    setSelectedDeployments(new Set()) // Clear selection on page change
    // requestedPage will be updated via the useEffect that syncs with URL
  }

  const handlePageSizeChange = (size: number) => {
    setPageSize(size)
    // Reset to page 1 when changing page size
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: undefined, // Remove page param to go to page 1
      }),
      replace: true,
    })
    setRequestedPage(0)
    setDisplayedPage(0)
    setSelectedDeployments(new Set()) // Clear selection on page size change
  }

  const clearAllDeploymentsFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({ ...prev, query: undefined }),
      replace: true,
    })
    setSelectedDeployments(new Set())
  }

  // Only show full loading state on initial load when there's no data (not while refetching filters)
  if (
    (funcLoading || deploymentsLoading) &&
    deployments.length === 0 &&
    !deploymentsFetching
  ) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          Loading deployments...
        </p>
      </div>
    )
  }

  return (
    <div ref={scrollContainerRef} className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pt-6 pb-4 sm:px-6 sm:pt-6 sm:pb-6">
        <div className="space-y-6">
          {isBuilding && (
            <div className="border-b border-border bg-blue-500/5">
              <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-blue-500/30 bg-transparent"
                >
                  <Info className="h-4 w-4 text-blue-500 shrink-0" />
                  <AlertDescription className="flex flex-1 items-center justify-between gap-3 text-[12px] text-blue-600/80 dark:text-blue-400/80">
                    <span>Your function is currently being redeployed.</span>
                    <Button
                      variant="outline"
                      size="sm"
                      className="shrink-0 border-blue-500/40 text-blue-600 hover:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/10"
                      onClick={() => {
                        setCancelTargetDeploymentId(activeDeployment?.$id ?? null)
                        setCancelBuildDialogOpen(true)
                      }}
                      disabled={cancelBuildMutation.isPending}
                    >
                      Cancel build
                    </Button>
                  </AlertDescription>
                </Alert>
              </div>
            </div>
          )}

          {/* Active Deployment Card */}
          {activeDeployment && activeDeployment.status === 'ready' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Active deployment
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                  {/* Deployed */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Deployed
                    </div>
                    <div className="text-[13px] text-foreground">
                      <DateTooltip date={activeDeployment.$createdAt} />
                    </div>
                  </div>

                  {/* Build duration */}
                  {(activeDeployment.buildDuration ||
                    isDeploymentInProgress(activeDeployment.status)) &&
                    !isDeploymentTimeout(
                      activeDeployment.status,
                      activeDeployment.$createdAt,
                    ) && (
                      <div>
                        <div className="text-[12px] text-muted-foreground mb-1.5">
                          Build duration
                        </div>
                        <div className="text-[13px] text-foreground">
                          {isDeploymentInProgress(activeDeployment.status)
                            ? formatDuration(
                                Math.max(
                                  0,
                                  Math.floor(
                                    (Date.now() -
                                      new Date(
                                        activeDeployment.$createdAt,
                                      ).getTime()) /
                                      1000,
                                  ),
                                ),
                              )
                            : formatDuration(activeDeployment.buildDuration)}
                        </div>
                      </div>
                    )}

                  {/* Total size */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Total size
                    </div>
                    <div className="text-[13px] text-foreground">
                      {formatSize(
                        (activeDeployment.buildSize || 0) +
                          (activeDeployment.sourceSize || 0),
                      )}
                    </div>
                  </div>

                  {/* Source */}
                  {vcsProvider &&
                    activeDeployment.providerRepositoryOwner &&
                    activeDeployment.providerRepositoryName && (
                      <div>
                        <div className="text-[12px] text-muted-foreground mb-1.5">
                          Source
                        </div>
                        <div className="flex items-center gap-1.5 text-[13px] text-foreground">
                          {vcsProvider.icon}
                          <span>
                            {activeDeployment.providerRepositoryOwner}/
                            {activeDeployment.providerRepositoryName}
                          </span>
                        </div>
                      </div>
                    )}

                  {/* Runtime */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Runtime
                    </div>
                    <div className="flex items-center gap-1.5 text-[13px] text-foreground">
                      <RuntimeIcon runtime={func?.runtime || ''} size="sm" />
                      <span className="font-mono">{runtimeName}</span>
                    </div>
                  </div>

                  {/* Runtime Limits */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Runtime limits
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-[13px] text-foreground font-mono">
                        {specificationText}
                      </span>
                      <button
                        onClick={() => setRuntimeLimitsDialogOpen(true)}
                        className="text-[11px] text-muted-foreground hover:text-foreground underline cursor-pointer"
                      >
                        Update
                      </button>
                    </div>
                  </div>

                  {/* Global CDN */}
                  <div>
                    <div className="flex items-center gap-1.5 text-[12px] text-muted-foreground mb-1.5">
                      <span>Global CDN</span>
                      <TooltipProvider delayDuration={0}>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              className="inline-flex items-center justify-center"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="right" className="max-w-xs">
                            <p className="text-[12px] font-medium mb-1.5 text-background">
                              Content Delivery Network
                            </p>
                            <p className="text-[11px] text-background/90">
                              Appwrite's CDN provides global coverage with 120+
                              points of presence worldwide, reducing latency
                              through edge caching and content optimization. All
                              content is delivered over TLS for secure,
                              encrypted connections.
                            </p>
                            <a
                              href="https://appwrite.io/docs/products/network/cdn"
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-[11px] text-background hover:underline mt-1.5 inline-block font-medium"
                              onClick={(e) => e.stopPropagation()}
                            >
                              Learn more →
                            </a>
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <CheckCircle2 className="h-4 w-4 text-green-500" />
                      <span className="text-[13px] font-medium text-foreground">
                        Connected
                      </span>
                    </div>
                  </div>

                  {/* DDoS protection */}
                  <div>
                    <div className="flex items-center gap-1.5 text-[12px] text-muted-foreground mb-1.5">
                      <span>DDoS protection</span>
                      <TooltipProvider delayDuration={0}>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              className="inline-flex items-center justify-center"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="right" className="max-w-xs">
                            <p className="text-[12px] font-medium mb-1.5 text-background">
                              DDoS Mitigation
                            </p>
                            <p className="text-[11px] text-background/90">
                              Appwrite's network includes built-in DDoS
                              mitigation to protect against distributed
                              denial-of-service attacks, ensuring uninterrupted
                              access to your functions and maintaining high
                              availability even during high traffic loads.
                            </p>
                            <a
                              href="https://appwrite.io/docs/products/network"
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-[11px] text-background hover:underline mt-1.5 inline-block font-medium"
                              onClick={(e) => e.stopPropagation()}
                            >
                              Learn more →
                            </a>
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <Shield className="h-4 w-4 text-green-500" />
                      <span className="text-[13px] font-medium text-foreground">
                        Active
                      </span>
                    </div>
                  </div>
                </div>

                {/* Domains */}
                <div className="mt-4 pt-4 border-t border-border">
                  <div className="text-[12px] text-muted-foreground mb-1.5">
                    Domains
                  </div>
                  {activeDomains.length > 0 ? (
                    <>
                      <div className="flex flex-col gap-1">
                        {activeDomains.map((rule) => (
                          <a
                            key={rule.$id}
                            href={`https://${rule.domain}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 text-[13px] font-mono text-foreground hover:underline"
                          >
                            {rule.domain}
                            <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                          </a>
                        ))}
                      </div>
                      {hasMoreDomains && (
                        <p className="text-[11px] text-muted-foreground mt-1.5">
                          +{totalActiveDomains - activeDomains.length} more
                        </p>
                      )}
                      <div className="mt-3 pt-2 border-t border-border/60 flex flex-wrap items-center gap-2">
                        <Button
                          variant="link"
                          size="sm"
                          className="h-auto p-0 text-[13px] font-medium text-primary"
                          asChild
                        >
                          <Link
                            to="/projects/$projectId/functions/$functionId/domains"
                            params={{
                              projectId: projectId!,
                              functionId: functionId!,
                            }}
                          >
                            View all domains
                            {hasMoreDomains && (
                              <Badge
                                variant="secondary"
                                className="ml-1.5 h-4 min-w-4 px-1 text-[10px] font-semibold tabular-nums"
                              >
                                +{totalActiveDomains - activeDomains.length}
                              </Badge>
                            )}
                          </Link>
                        </Button>
                        <span className="text-muted-foreground/60">·</span>
                        <Button
                          variant="link"
                          size="sm"
                          className="h-auto p-0 text-[13px] font-medium text-primary"
                          asChild
                        >
                          <Link
                            to="/projects/$projectId/functions/$functionId/domains"
                            params={{
                              projectId: projectId!,
                              functionId: functionId!,
                            }}
                          >
                            Add domain
                          </Link>
                        </Button>
                      </div>
                    </>
                  ) : (
                    <div className="mt-2 pt-2 border-t border-border/60 flex flex-wrap items-center gap-2">
                      <Button
                        variant="link"
                        size="sm"
                        className="h-auto p-0 text-[13px] font-medium text-primary"
                        asChild
                      >
                        <Link
                          to="/projects/$projectId/functions/$functionId/domains"
                          params={{
                            projectId: projectId!,
                            functionId: functionId!,
                          }}
                        >
                          View all domains
                        </Link>
                      </Button>
                      <span className="text-muted-foreground/60">·</span>
                      <Button
                        variant="link"
                        size="sm"
                        className="h-auto p-0 text-[13px] font-medium text-primary"
                        asChild
                      >
                        <Link
                          to="/projects/$projectId/functions/$functionId/domains"
                          params={{
                            projectId: projectId!,
                            functionId: functionId!,
                          }}
                        >
                          Add domain
                        </Link>
                      </Button>
                    </div>
                  )}
                </div>
              </div>

              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                    >
                      <Download className="mr-1.5 h-4 w-4" />
                      Download
                      <ChevronDown className="ml-1.5 h-3.5 w-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="z-[200]">
                    <DropdownMenuItem onClick={handleDownloadSource}>
                      <FileCode className="mr-2 h-4 w-4" />
                      Source code
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={handleDownloadBuild}
                      disabled={!isDeploymentCompleted(activeDeployment?.status)}
                      title={
                        !isDeploymentCompleted(activeDeployment?.status)
                          ? 'Build output is available after the deployment has completed.'
                          : undefined
                      }
                    >
                      <Package className="mr-2 h-4 w-4" />
                      Build output
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setRedeployDialogOpen(true)}
                  disabled={redeployMutation.isPending}
                  className="h-9 text-[13px]"
                >
                  <RefreshCw className="mr-1.5 h-4 w-4" />
                  Redeploy
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    navigate({
                      to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                      params: {
                        projectId: projectId!,
                        functionId: functionId!,
                        deploymentId: activeDeployment.$id,
                      },
                    })
                  }}
                  className="h-9 text-[13px]"
                >
                  Build logs
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setExecuteDrawerOpen(true)}
                  className="h-9 text-[13px]"
                >
                  <Play className="mr-1.5 h-4 w-4" />
                  Execute
                </Button>
              </div>
            </div>
          )}

          {/* Building State */}
          {isBuilding && (
            <div className="flex h-full items-center justify-center py-16">
              <div className="text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                  <Clock className="h-5 w-5 text-muted-foreground animate-spin" />
                </div>
                <p className="mb-1 text-[14px] font-medium text-foreground">
                  Deployment is still building
                </p>
                <p className="mb-4 text-[13px] text-muted-foreground">
                  This may take a few minutes. We'll update automatically when
                  it's ready.
                </p>
                <Button
                  variant="outline"
                  onClick={() => {
                    if (activeDeployment) {
                      navigate({
                        to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                        params: {
                          projectId: projectId!,
                          functionId: functionId!,
                          deploymentId: activeDeployment.$id,
                        },
                      })
                    }
                  }}
                >
                  View logs
                </Button>
              </div>
            </div>
          )}

          {/* No Active Deployment */}
          {!activeDeployment && !isBuilding && (
            <div className="flex h-full items-center justify-center py-16">
              <div className="text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                  <Clock className="h-5 w-5 text-muted-foreground" />
                </div>
                <p className="mb-1 text-[14px] font-medium text-foreground">
                  There is no active deployment
                </p>
                <p className="mb-4 text-[13px] text-muted-foreground">
                  Create your first deployment to activate this function.
                </p>
                {createDeployment && (
                  <CreateDeploymentDropdown
                    onSelectGit={createDeployment.openGitModal}
                    onSelectCli={createDeployment.openCliModal}
                    onSelectManual={createDeployment.openManualModal}
                  />
                )}
              </div>
            </div>
          )}
        </div>

        {/* Deployments filter + create (below active deployment card) */}
        {deploymentsToolbar ? (
          <div className="flex flex-wrap items-center justify-between gap-4 mt-6">
            {deploymentsToolbar}
          </div>
        ) : null}

        {/* Deployments Table */}
        <div className="mt-6">
          {deployments.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={(() => {
                            const activeDeploymentId = activeDeployment?.$id
                            const selectableDeployments = deployments.filter(
                              (d) => d.$id !== activeDeploymentId,
                            )
                            return (
                              selectableDeployments.length > 0 &&
                              selectedDeployments.size ===
                                selectableDeployments.length
                            )
                          })()}
                          onCheckedChange={toggleAllDeployments}
                        />
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                        Deployment ID
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                        Status
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                        Type
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                        Source
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                        Total Size
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                        Duration
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                        Created
                      </TableHead>
                      <TableHead className="px-4 py-3 text-right w-[100px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {deployments.map((deployment) => {
                      const statusBadge = getDeploymentStatusBadge(
                        deployment.status,
                        deployment.$createdAt,
                      )
                      const isActive = deployment.$id === activeDeployment?.$id
                      return (
                        <TableRow
                          key={deployment.$id}
                          className={cn(
                            selectedDeployments.has(deployment.$id)
                              ? 'bg-muted'
                              : 'hover:bg-muted/50',
                            'cursor-pointer',
                          )}
                          onClick={() => {
                            navigate({
                              to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                              params: {
                                projectId: projectId!,
                                functionId: functionId!,
                                deploymentId: deployment.$id,
                              },
                            })
                          }}
                        >
                          <TableCell
                            className="px-4 py-3"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <Checkbox
                              checked={selectedDeployments.has(deployment.$id)}
                              onCheckedChange={() =>
                                toggleDeployment(deployment.$id)
                              }
                              disabled={isActive}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <CopyableId
                              id={deployment.$id}
                              size="sm"
                              maxWidth={180}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            {isActive ? (
                              <Badge
                                variant="active"
                                className="gap-1.5 text-[11px] font-medium"
                              >
                                <CheckCircle2 className="h-3 w-3" />
                                Active
                              </Badge>
                            ) : (
                              <Badge
                                variant={statusBadge.badgeVariant}
                                className="gap-1.5 text-[11px] font-medium"
                              >
                                {(() => {
                                  const StatusIcon = statusBadge.icon
                                  return <StatusIcon className="h-3 w-3" />
                                })()}
                                {statusBadge.label}
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            {(() => {
                              const vcsProvider = getVcsProvider(deployment)
                              if (vcsProvider) {
                                const repositoryOwner =
                                  deployment.providerRepositoryOwner
                                const repositoryName =
                                  deployment.providerRepositoryName
                                const hasRepository =
                                  repositoryOwner && repositoryName

                                if (hasRepository) {
                                  return (
                                    <Badge
                                      variant="outline"
                                      className="text-[11px] h-6 px-2.5 gap-1.5"
                                    >
                                      {vcsProvider.icon}
                                      <span>
                                        {repositoryOwner}/{repositoryName}
                                      </span>
                                    </Badge>
                                  )
                                }
                                // Fallback if no repository info
                                return (
                                  <div className="flex items-center gap-1.5 text-[12px] text-foreground">
                                    {vcsProvider.icon}
                                    <span>{vcsProvider.name}</span>
                                  </div>
                                )
                              }
                              // Show deployment type for non-VCS deployments
                              const typeLabel =
                                deployment.type === 'cli'
                                  ? 'CLI'
                                  : deployment.type === 'manual'
                                    ? 'Manual'
                                    : deployment.type || 'N/A'
                              return (
                                <div className="flex items-center gap-1.5 text-[12px] text-foreground">
                                  {deployment.type === 'cli' && (
                                    <GitBranch className="h-3.5 w-3.5" />
                                  )}
                                  <span>{typeLabel}</span>
                                </div>
                              )
                            })()}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            {(() => {
                              const vcsProvider = getVcsProvider(deployment)
                              if (!vcsProvider) {
                                return (
                                  <span className="text-[12px] text-muted-foreground">
                                    —
                                  </span>
                                )
                              }

                              const commitMessage =
                                deployment.providerCommitMessage
                              const commitHash = deployment.providerCommitHash
                              const commitUrl = deployment.providerCommitUrl
                              const branch = deployment.providerBranch

                              if (!commitMessage && !branch && !commitHash) {
                                return (
                                  <span className="text-[12px] text-muted-foreground">
                                    —
                                  </span>
                                )
                              }

                              return (
                                <div className="space-y-1.5 min-w-0">
                                  {commitMessage && (
                                    <div className="text-[12px] text-foreground line-clamp-1 font-mono">
                                      {commitUrl ? (
                                        <a
                                          href={commitUrl}
                                          target="_blank"
                                          rel="noopener noreferrer"
                                          className="hover:underline"
                                          onClick={(e) => e.stopPropagation()}
                                          title={
                                            commitMessage.length > 30
                                              ? commitMessage
                                              : undefined
                                          }
                                        >
                                          {commitMessage.length > 30
                                            ? `${commitMessage.slice(0, 30)}...`
                                            : commitMessage}
                                        </a>
                                      ) : (
                                        <span
                                          title={
                                            commitMessage.length > 30
                                              ? commitMessage
                                              : undefined
                                          }
                                        >
                                          {commitMessage.length > 30
                                            ? `${commitMessage.slice(0, 30)}...`
                                            : commitMessage}
                                        </span>
                                      )}
                                    </div>
                                  )}
                                  {(branch || commitHash) && (
                                    <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground flex-wrap">
                                      {branch && (
                                        <div className="flex items-center gap-1">
                                          <GitBranch className="h-3 w-3" />
                                          <span className="font-mono">
                                            {branch}
                                          </span>
                                        </div>
                                      )}
                                      {commitHash && (
                                        <>
                                          {branch && <span>•</span>}
                                          <div className="flex items-center gap-1">
                                            <GitCommit className="h-3 w-3" />
                                            <span className="font-mono">
                                              {commitHash.slice(0, 7)}
                                            </span>
                                          </div>
                                        </>
                                      )}
                                    </div>
                                  )}
                                </div>
                              )
                            })()}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <code className="text-[12px] font-mono text-muted-foreground">
                              {formatSize(
                                (deployment.buildSize || 0) +
                                  (deployment.sourceSize || 0),
                              )}
                            </code>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <code className="text-[12px] font-mono text-muted-foreground">
                              {isDeploymentInProgress(deployment.status) &&
                              !isDeploymentTimeout(
                                deployment.status,
                                deployment.$createdAt,
                              )
                                ? formatDuration(
                                    Math.max(
                                      0,
                                      Math.floor(
                                        (Date.now() -
                                          new Date(
                                            deployment.$createdAt,
                                          ).getTime()) /
                                          1000,
                                      ),
                                    ),
                                  )
                                : deployment.buildDuration &&
                                    !isDeploymentTimeout(
                                      deployment.status,
                                      deployment.$createdAt,
                                    )
                                  ? formatDuration(deployment.buildDuration)
                                  : '—'}
                            </code>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <DateTooltip
                              date={deployment.$createdAt}
                              className="text-[12px] font-medium text-muted-foreground"
                            />
                          </TableCell>
                          <TableCell
                            className="px-4 py-3 text-right"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 w-8 p-0"
                                  onClick={(e) => e.stopPropagation()}
                                  disabled={
                                    isActive &&
                                    !isDeploymentInProgress(deployment.status)
                                  }
                                >
                                  <MoreHorizontal className="h-4 w-4" />
                                  <span className="sr-only">Open menu</span>
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent
                                align="end"
                                className="z-[200]"
                              >
                                {!isActive && (
                                  <>
                                    <DropdownMenuItem
                                      disabled={deployment.status !== 'ready'}
                                      title={
                                        deployment.status !== 'ready'
                                          ? 'Build must be ready before activating'
                                          : undefined
                                      }
                                      onClick={async (e) => {
                                        e.stopPropagation()
                                        if (deployment.status !== 'ready')
                                          return
                                        try {
                                          const projectSdk = sdk.forProject(
                                            projectId!,
                                          )
                                          await projectSdk.functions.updateFunctionDeployment(
                                            {
                                              functionId: functionId!,
                                              deploymentId: deployment.$id,
                                            },
                                          )
                                          queryClient.invalidateQueries({
                                            queryKey: [
                                              'deployments',
                                              'project',
                                              projectId,
                                              functionId,
                                            ],
                                          })
                                          queryClient.invalidateQueries({
                                            queryKey: [
                                              'function',
                                              'project',
                                              projectId,
                                              functionId,
                                            ],
                                          })
                                          toast.success(
                                            'Deployment activated successfully',
                                          )
                                        } catch {
                                          toast.error(
                                            'Failed to activate deployment',
                                          )
                                        }
                                      }}
                                    >
                                      <Play className="mr-2 h-4 w-4" />
                                      Activate
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      onClick={async (e) => {
                                        e.stopPropagation()
                                        try {
                                          const projectSdk = sdk.forProject(
                                            projectId!,
                                          )
                                          await projectSdk.functions.createDuplicateDeployment(
                                            {
                                              functionId: functionId!,
                                              deploymentId: deployment.$id,
                                            },
                                          )
                                          queryClient.invalidateQueries({
                                            queryKey: [
                                              'deployments',
                                              'project',
                                              projectId,
                                              functionId,
                                            ],
                                          })
                                          toast.success(
                                            'Deployment rebuild started',
                                          )
                                        } catch {
                                          toast.error('Failed to redeploy')
                                        }
                                      }}
                                    >
                                      <RefreshCw className="mr-2 h-4 w-4" />
                                      Redeploy
                                    </DropdownMenuItem>
                                  </>
                                )}
                                {!isActive &&
                                  !isDeploymentInProgress(
                                    deployment.status,
                                  ) && (
                                  <DropdownMenuItem
                                    onClick={async (e) => {
                                      e.stopPropagation()
                                      try {
                                        await deleteFunctionDeployment(
                                          projectId!,
                                          functionId!,
                                          deployment.$id,
                                        )
                                        await queryClient.refetchQueries({
                                          queryKey: [
                                            'deployments',
                                            'project',
                                            projectId,
                                            functionId,
                                          ],
                                        })
                                        await queryClient.refetchQueries({
                                          queryKey: [
                                            'function',
                                            'project',
                                            projectId,
                                            functionId,
                                          ],
                                        })
                                        toast.success(
                                          'Deployment deleted successfully',
                                        )
                                      } catch (error) {
                                        toast.error(
                                          error instanceof Error
                                            ? error.message
                                            : 'Failed to delete deployment',
                                        )
                                      }
                                    }}
                                  >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete
                                  </DropdownMenuItem>
                                )}
                                {isDeploymentInProgress(deployment.status) && (
                                  <DropdownMenuItem
                                    onClick={(e) => {
                                      e.stopPropagation()
                                      setCancelTargetDeploymentId(deployment.$id)
                                      setCancelBuildDialogOpen(true)
                                    }}
                                  >
                                    <XCircle className="mr-2 h-4 w-4" />
                                    Cancel
                                  </DropdownMenuItem>
                                )}
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
                currentPage={displayedPage + 1}
                totalItems={displayedTotal ?? total}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="deployments"
                className="py-2"
              />
            </>
          ) : (
            <EmptyState
              icon={Clock}
              title={filterMap.size > 0 ? undefined : 'No deployments yet'}
              description={
                filterMap.size > 0
                  ? undefined
                  : 'Create your first deployment to get started'
              }
              isEmpty={filterMap.size === 0}
              hasFilters={filterMap.size > 0}
              variant="card"
              iconSize="md"
              children={
                filterMap.size === 0 && createDeployment ? (
                  <div className="flex flex-col items-center text-center mt-4">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                      <Clock className="h-6 w-6 text-muted-foreground" />
                    </div>
                    <p className="mb-1 text-[14px] font-medium text-foreground">
                      No deployments yet
                    </p>
                    <p className="mb-4 text-[13px] text-muted-foreground">
                      Create your first deployment to get started
                    </p>
                    <CreateDeploymentDropdown
                      onSelectGit={createDeployment.openGitModal}
                      onSelectCli={createDeployment.openCliModal}
                      onSelectManual={createDeployment.openManualModal}
                    />
                  </div>
                ) : undefined
              }
            />
          )}
        </div>
      </div>

      {/* Bulk Delete Action Bar */}
      {selectedDeployments.size > 0 && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedDeployments.size} deployment
              {selectedDeployments.size > 1 ? 's' : ''} selected
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedDeployments(new Set())}
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
                <Trash2 className="h-4 w-4" />
                Delete
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Runtime Limits Update Dialog */}
      {specifications.length > 0 && (
        <Dialog
          open={runtimeLimitsDialogOpen}
          onOpenChange={setRuntimeLimitsDialogOpen}
        >
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Update Runtime Limits</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Select the runtime specification for your function
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />

            <div className="px-6 pb-4 pt-0">
              <RadioGroup
                value={selectedSpecification}
                onValueChange={(value) => {
                  const spec = specifications.find((s) => s.slug === value)
                  if (spec && !isSpecificationAllowedInPlan(spec)) return
                  setSelectedSpecification(value)
                }}
                className="space-y-2"
              >
                <div className="rounded-lg border border-border bg-card/50 overflow-hidden divide-y divide-border max-h-[320px] overflow-y-auto">
                  {specifications.map((spec) => {
                    const isSelected = selectedSpecification === spec.slug
                    const isEnabled = isSpecificationAllowedInPlan(spec)
                    return (
                      <div
                        key={spec.slug}
                        className="first:rounded-t-lg last:rounded-b-lg [&:not(:first-child)]:border-t-0"
                      >
                        <RadioGroupItem
                          value={spec.slug}
                          id={`spec-${spec.slug}`}
                          className="peer sr-only"
                          disabled={!isEnabled}
                        />
                        <Label
                          htmlFor={`spec-${spec.slug}`}
                          className={cn(
                            'flex items-center gap-3 px-3 py-2.5 transition-colors',
                            isEnabled && 'cursor-pointer hover:bg-accent',
                            isSelected && isEnabled && 'bg-accent',
                            !isEnabled && 'cursor-not-allowed opacity-60',
                          )}
                          onClick={(e) => {
                            if (!isEnabled) {
                              e.preventDefault()
                              e.stopPropagation()
                            }
                          }}
                        >
                          <div className="shrink-0">
                            <div
                              className={cn(
                                'h-3.5 w-3.5 rounded-full border-2 flex items-center justify-center transition-colors',
                                isSelected && isEnabled
                                  ? 'border-foreground'
                                  : 'border-muted-foreground',
                                !isEnabled && 'border-muted-foreground/50',
                              )}
                            >
                              {isSelected && isEnabled && (
                                <div className="h-1.5 w-1.5 rounded-full bg-foreground" />
                              )}
                            </div>
                          </div>
                          <div className="flex flex-col min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                              <span className="text-[13px] font-medium text-foreground">
                                {spec.cpus} CPU, {spec.memory}MB RAM
                              </span>
                              {!isEnabled && (
                                <Lock className="h-3 w-3 text-muted-foreground shrink-0" />
                              )}
                            </div>
                            <span className="text-[11px] text-muted-foreground leading-tight mt-0.5">
                              {!isEnabled
                                ? 'Upgrade to unlock this specification'
                                : spec.slug}
                            </span>
                          </div>
                        </Label>
                      </div>
                    )
                  })}
                </div>
              </RadioGroup>
              {hasUnavailableSpecifications(specifications) && (
                <div className="mt-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                  <p className="text-[12px] text-muted-foreground">
                    Need more resources?{' '}
                    <a
                      href="#"
                      className="font-medium text-foreground underline hover:no-underline"
                      onClick={(e) => {
                        e.preventDefault()
                        // TODO: Navigate to upgrade or contact sales
                      }}
                    >
                      Upgrade your plan
                    </a>{' '}
                    or{' '}
                    <a
                      href="#"
                      className="font-medium text-foreground underline hover:no-underline"
                      onClick={(e) => {
                        e.preventDefault()
                        // TODO: Navigate to contact sales
                      }}
                    >
                      contact sales
                    </a>{' '}
                    to unlock additional specifications.
                  </p>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setRuntimeLimitsDialogOpen(false)}
                disabled={updateSpecificationMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                onClick={handleSaveSpecification}
                disabled={
                  !selectedSpecification ||
                  selectedSpecification === func?.specification ||
                  updateSpecificationMutation.isPending
                }
              >
                Update
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Bulk Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete Deployments</DialogTitle>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DialogDescription className="text-[13px] mb-4">
              Are you sure you want to delete {selectedDeployments.size}{' '}
              deployment{selectedDeployments.size > 1 ? 's' : ''}? This action
              cannot be undone.
            </DialogDescription>
            <div className="space-y-2 max-h-[300px] overflow-y-auto">
              {displayedDeployments
                ?.filter((d) => selectedDeployments.has(d.$id))
                .map((deployment) => (
                  <DeploymentInfo
                    key={deployment.$id}
                    deployment={deployment}
                    showStatus={true}
                    compact={true}
                  />
                ))}
            </div>
          </div>
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

      {/* Delete Confirmation Dialog for Active Deployment */}
      {activeDeployment && (
        <Dialog
          open={deleteActiveDialogOpen}
          onOpenChange={setDeleteActiveDialogOpen}
        >
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Delete deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                Are you sure you want to delete this deployment? This action
                cannot be undone.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteActiveDialogOpen(false)}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteActiveMutation.mutate()}
                disabled={deleteActiveMutation.isPending}
                className="h-9 text-[13px]"
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Cancel build confirmation */}
      <Dialog
        open={cancelBuildDialogOpen}
        onOpenChange={(open) => {
          setCancelBuildDialogOpen(open)
          if (!open) setCancelTargetDeploymentId(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Cancel build</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Stop the current deployment? You can deploy again later.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {(displayedDeployments?.find(
              (d) => d.$id === cancelTargetDeploymentId,
            ) ?? (cancelTargetDeploymentId === activeDeployment?.$id
              ? activeDeployment
              : null)) && (
              <DeploymentInfo
                deployment={
                  displayedDeployments?.find(
                    (d) => d.$id === cancelTargetDeploymentId,
                  ) ?? activeDeployment!
                }
                showStatus={true}
              />
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => {
                setCancelBuildDialogOpen(false)
                setCancelTargetDeploymentId(null)
              }}
              className="h-9 text-[13px]"
            >
              Keep building
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                cancelTargetDeploymentId &&
                cancelBuildMutation.mutate(cancelTargetDeploymentId)
              }
              disabled={cancelBuildMutation.isPending || !cancelTargetDeploymentId}
              className="h-9 text-[13px]"
            >
              Cancel build
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Redeploy Confirmation Dialog for Active Deployment */}
      {activeDeployment && (
        <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Redeploy deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will create a new build for this deployment using the
                current function configuration. The original deployment's code
                will be preserved and used for the new build.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setRedeployDialogOpen(false)}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="default"
                onClick={() => redeployMutation.mutate()}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                Redeploy
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Activate Confirmation Dialog for Active Deployment */}
      {activeDeployment && (
        <Dialog open={activateDialogOpen} onOpenChange={setActivateDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Activate deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will switch the active deployment to this one. All traffic
                will be routed to this deployment once activated.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setActivateDialogOpen(false)}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="default"
                onClick={() => activateMutation.mutate()}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                Activate
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      <CreateExecutionDrawer
        open={executeDrawerOpen}
        onOpenChange={setExecuteDrawerOpen}
        functionId={functionId!}
        func={func}
      />
    </div>
  )
}
