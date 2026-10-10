import {
  useState,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useContext,
} from 'react'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import {
  useParams,
  useNavigate,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import { useQueryClient, useMutation } from '@tanstack/react-query'
import {
  Clock,
  Trash2,
  XCircle,
  GitBranch,
  GitCommit,
  CheckCircle2,
  Download,
  RefreshCw,
  Play,
  FileCode,
  Package,
} from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import {
  getDeploymentStatusBadge,
  canDownloadDeploymentBuildOutput,
  isDeploymentInProgress,
  isDeploymentTimeout,
  DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS,
} from '@/lib/utils/deployment-status'
import { getDeploymentRepositoryWebUrl } from '@/lib/utils/deployment-repository-url'
import { Button } from '@/components/ui/button'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { DeploymentListRowContextMenu } from '@/components/global/shared/DeploymentListRowContextMenu'
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
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
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
import { cn } from '@/lib/utils'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import {
  useProjectFunction,
  useFunctionDeployments,
  useFunctionDeployment,
  Dependencies,
  deleteFunctionDeployment,
  cancelFunctionDeployment,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { getVcsProvider } from '@/lib/vcs/providers'
import { DeploymentDownloadType, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useCreateDeployment } from '../shared/CreateDeploymentContext'
import { CreateDeploymentDropdown } from '../shared/CreateDeploymentDropdown'
import { DeploymentsToolbarContext } from './Layout'
import { SERVICE_HEADER_CONTAINER } from '../shared/service-header-container'
import { getQueryParam, queryParamToMap } from '@/lib/table-filters'
import { useT } from '@/lib/i18n/translate'

function formatSize(bytes: number | bigint): string {
  return formatDecimalBytes(bytes)
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

// Detect VCS provider from deployment
function detectVcsProvider(
  deployment: unknown,
): { name: string; icon: React.ReactNode } | null {
  // Check for providerRepositoryUrl which contains the provider domain
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) {
      const { label, Icon } = getVcsProvider('github')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('gitlab.com')) {
      const { label, Icon } = getVcsProvider('gitlab')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com')) {
      const { label, Icon } = getVcsProvider('bitbucket')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('cursor.com')) {
      const { label, Icon } = getVcsProvider('origin')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
  }

  // Check for vcsProvider field (if available)
  if (deployment.vcsProvider) {
    const provider = deployment.vcsProvider.toLowerCase()
    if (provider === 'github') {
      const { label, Icon } = getVcsProvider('github')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'gitlab') {
      const { label, Icon } = getVcsProvider('gitlab')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'bitbucket') {
      const { label, Icon } = getVcsProvider('bitbucket')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'origin') {
      const { label, Icon } = getVcsProvider('origin')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
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
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { page?: number }
  const urlPage = search.page ?? 1
  // Derive filter from location so we use the same source as the Layout (handles TanStack Router parsed search object)
  const filterMap = useMemo(() => {
    const locSearch = location.search
    const queryParam =
      typeof locSearch === 'object' &&
      locSearch !== null &&
      'query' in locSearch
        ? ((locSearch as { query?: string }).query ?? null)
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
  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [cancelTargetDeploymentId, setCancelTargetDeploymentId] = useState<
    string | null
  >(null)
  const [deleteRowDeploymentId, setDeleteRowDeploymentId] = useState<
    string | null
  >(null)
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

  // Refresh stale in-progress rows when returning from deployment detail
  useEffect(() => {
    if (!projectId || !functionId) return
    const queries = queryClient.getQueriesData<{
      deployments?: Models.Deployment[]
    }>({
      queryKey: ['deployments', 'function', projectId, functionId],
      exact: false,
    })
    const hasInProgressInCache = queries.some(([, data]) =>
      data?.deployments?.some((d) => isDeploymentInProgress(d.status)),
    )
    if (!hasInProgressInCache) return
    void queryClient.refetchQueries({
      queryKey: ['deployments', 'function', projectId, functionId],
      exact: false,
    })
  }, [projectId, functionId, queryClient])

  const createDeployment = useCreateDeployment()

  // Clear selection when navigating between pages
  useEffect(() => {
    setSelectedDeployments(new Set())
    setDeleteDialogOpen(false)
  }, [displayedPage])

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
        queryKey: ['deployments', 'function', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success(t('Build cancelled'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to cancel build'))
    },
  })

  const deleteRowMutation = useMutation({
    mutationFn: async (deploymentId: string) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }
      await deleteFunctionDeployment(projectId, functionId, deploymentId)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['deployments', 'function', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success(t('Deployment deleted successfully'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete deployment'))
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
          t(
            'Cannot delete the active deployment. Please activate another deployment first.',
          ),
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
        `${t('Successfully deleted')} ${selectedDeployments.size} ${selectedDeployments.size > 1 ? t('deployments') : t('deployment')}`,
      )
      setSelectedDeployments(new Set())
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete deployments'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedDeployments.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedDeployments.size === 0) return
    const ids = Array.from(selectedDeployments)
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
    })
    bulkDeleteMutation.mutate(ids)
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

  // Only show full loading state on initial load when there's no data (not while refetching filters)
  if (
    (funcLoading || deploymentsLoading) &&
    deployments.length === 0 &&
    !deploymentsFetching
  ) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading deployments...')}
        </p>
      </div>
    )
  }

  return (
    <div ref={scrollContainerRef} className="flex-1">
      {deploymentsToolbar ? (
        <div
          className={cn(
            SERVICE_HEADER_CONTAINER,
            'mx-auto flex w-full max-w-7xl min-w-0 flex-nowrap items-center justify-between gap-2 px-4 py-4 sm:px-6',
          )}
        >
          {deploymentsToolbar}
        </div>
      ) : null}
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
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
                        {t('Deployment ID')}
                      </TableHead>
                      <TableHead
                        className={cn(
                          'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider',
                          DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS,
                        )}
                      >
                        {t('Status')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                        {t('Type')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                        {t('Source')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                        {t('Total Size')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                        {t('Duration')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                        {t('Created')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-end w-[100px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {deployments.map((deployment) => {
                      const statusBadge = getDeploymentStatusBadge(
                        deployment.status,
                        deployment.$createdAt,
                      )
                      const isActive = deployment.$id === activeDeployment?.$id
                      const canDeleteFromMenu =
                        !isActive && !isDeploymentInProgress(deployment.status)
                      return (
                        <DeploymentListRowContextMenu
                          key={deployment.$id}
                          variant="function"
                          projectId={projectId!}
                          resourceId={functionId!}
                          deployment={deployment}
                          isActive={isActive}
                          onRequestCancelBuild={(id) => {
                            setCancelTargetDeploymentId(id)
                            setCancelBuildDialogOpen(true)
                          }}
                        >
                          <tr
                            className={cn(
                              'border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted',
                              selectedDeployments.has(deployment.$id)
                                ? 'bg-muted'
                                : isActive
                                  ? 'bg-muted/40 dark:bg-muted/35 hover:bg-muted/55 dark:hover:bg-muted/50'
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
                                checked={selectedDeployments.has(
                                  deployment.$id,
                                )}
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
                            <TableCell
                              className={cn(
                                'px-4 py-3',
                                DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS,
                              )}
                            >
                              {isActive ? (
                                <Badge
                                  variant="active"
                                  className="gap-1.5 text-[11px] font-medium"
                                >
                                  <CheckCircle2 className="h-3 w-3" />
                                  {t('Active')}
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
                                  {t(statusBadge.label)}
                                </Badge>
                              )}
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              {(() => {
                                const vcsProvider =
                                  detectVcsProvider(deployment)
                                if (vcsProvider) {
                                  const repositoryOwner =
                                    deployment.providerRepositoryOwner
                                  const repositoryName =
                                    deployment.providerRepositoryName
                                  const hasRepository =
                                    repositoryOwner && repositoryName

                                  if (hasRepository) {
                                    const repoUrl =
                                      getDeploymentRepositoryWebUrl(deployment)
                                    const label = `${repositoryOwner}/${repositoryName}`
                                    return (
                                      <Badge
                                        variant="outline"
                                        className="text-[11px] h-6 px-2.5 gap-1.5 max-w-full"
                                      >
                                        {vcsProvider.icon}
                                        {repoUrl ? (
                                          <a
                                            href={repoUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="truncate no-underline hover:text-foreground"
                                            onClick={(e) => e.stopPropagation()}
                                          >
                                            {label}
                                          </a>
                                        ) : (
                                          <span className="truncate">
                                            {label}
                                          </span>
                                        )}
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
                                      ? t('Manual')
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
                                const vcsProvider =
                                  detectVcsProvider(deployment)
                                if (!vcsProvider) {
                                  return (
                                    <span className="text-[12px] text-muted-foreground">
                                      -
                                    </span>
                                  )
                                }

                                const commitMessage =
                                  deployment.providerCommitMessage
                                const commitHash = deployment.providerCommitHash
                                const commitUrl = deployment.providerCommitUrl
                                const commitAuthor =
                                  deployment.providerCommitAuthor
                                const commitAuthorUrl =
                                  deployment.providerCommitAuthorUrl
                                const branch = deployment.providerBranch

                                if (!commitMessage && !branch && !commitHash) {
                                  return (
                                    <span className="text-[12px] text-muted-foreground">
                                      -
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
                                            className="link-neutral"
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
                                            <div className="flex min-w-0 items-center gap-1">
                                              <GitCommit className="h-3 w-3 shrink-0" />
                                              <span className="shrink-0 font-mono">
                                                {commitHash.slice(0, 7)}
                                              </span>
                                              {commitAuthor ? (
                                                <span className="min-w-0 truncate">
                                                  {` ${t('by')} `}
                                                  {commitAuthorUrl ? (
                                                    <a
                                                      href={commitAuthorUrl}
                                                      target="_blank"
                                                      rel="noopener noreferrer"
                                                      className="link-neutral"
                                                      onClick={(e) =>
                                                        e.stopPropagation()
                                                      }
                                                      title={commitAuthor}
                                                    >
                                                      {commitAuthor}
                                                    </a>
                                                  ) : (
                                                    commitAuthor
                                                  )}
                                                </span>
                                              ) : null}
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
                                    : '-'}
                              </code>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <DateTooltip
                                date={deployment.$createdAt}
                                className="text-[12px] font-medium text-muted-foreground"
                              />
                            </TableCell>
                            <TableCell
                              className="px-4 py-3 text-end"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <RowActionsMenuTrigger
                                    onClick={(e) => e.stopPropagation()}
                                    onPointerDown={(e) => e.stopPropagation()}
                                  />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                  align="end"
                                  className="z-[200]"
                                >
                                  {!isActive && (
                                    <DropdownMenuItem
                                      disabled={deployment.status !== 'ready'}
                                      title={
                                        deployment.status !== 'ready'
                                          ? t(
                                              'Build must be ready before activating',
                                            )
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
                                            t(
                                              'Deployment activated successfully',
                                            ),
                                          )
                                        } catch {
                                          toast.error(
                                            t('Failed to activate deployment'),
                                          )
                                        }
                                      }}
                                    >
                                      <MenuItemContent icon={Play}>
                                        {t('Activate')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  )}
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
                                          t('Deployment rebuild started'),
                                        )
                                      } catch {
                                        toast.error(t('Failed to redeploy'))
                                      }
                                    }}
                                  >
                                    <MenuItemContent icon={RefreshCw}>
                                      {t('Redeploy')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                  <DropdownMenuSub>
                                    <DropdownMenuSubTrigger
                                      onClick={(e) => e.stopPropagation()}
                                      onPointerDown={(e) => e.stopPropagation()}
                                    >
                                      <MenuItemIcon icon={Download} />
                                      {t('Download')}
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent className="z-[200]">
                                      <DropdownMenuItem
                                        onClick={(e) => {
                                          e.stopPropagation()
                                          if (!projectId || !functionId) return
                                          try {
                                            const projectSdk =
                                              sdk.forProject(projectId)
                                            const url =
                                              projectSdk.functions.getDeploymentDownload(
                                                {
                                                  functionId,
                                                  deploymentId: deployment.$id,
                                                  type: DeploymentDownloadType.Source,
                                                },
                                              )
                                            const urlWithMode =
                                              url +
                                              (url.includes('?') ? '&' : '?') +
                                              'mode=admin'
                                            window.open(urlWithMode, '_blank')
                                            toast.success(t('Download started'))
                                          } catch {
                                            toast.error(
                                              t(
                                                'Failed to download source code',
                                              ),
                                            )
                                          }
                                        }}
                                      >
                                        <MenuItemContent icon={FileCode}>
                                          {t('Source code')}
                                        </MenuItemContent>
                                      </DropdownMenuItem>
                                      <DropdownMenuItem
                                        disabled={
                                          !canDownloadDeploymentBuildOutput(
                                            deployment.status,
                                          )
                                        }
                                        title={
                                          !canDownloadDeploymentBuildOutput(
                                            deployment.status,
                                          )
                                            ? t(
                                                'Build output is only available for ready deployments.',
                                              )
                                            : undefined
                                        }
                                        onClick={(e) => {
                                          e.stopPropagation()
                                          if (
                                            !canDownloadDeploymentBuildOutput(
                                              deployment.status,
                                            )
                                          )
                                            return
                                          if (!projectId || !functionId) return
                                          try {
                                            const projectSdk =
                                              sdk.forProject(projectId)
                                            const url =
                                              projectSdk.functions.getDeploymentDownload(
                                                {
                                                  functionId,
                                                  deploymentId: deployment.$id,
                                                  type: DeploymentDownloadType.Output,
                                                },
                                              )
                                            const urlWithMode =
                                              url +
                                              (url.includes('?') ? '&' : '?') +
                                              'mode=admin'
                                            window.open(urlWithMode, '_blank')
                                            toast.success(t('Download started'))
                                          } catch {
                                            toast.error(
                                              t(
                                                'Failed to download build output',
                                              ),
                                            )
                                          }
                                        }}
                                      >
                                        <MenuItemContent icon={Package}>
                                          {t('Build output')}
                                        </MenuItemContent>
                                      </DropdownMenuItem>
                                    </DropdownMenuSubContent>
                                  </DropdownMenuSub>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    disabled={!canDeleteFromMenu}
                                    title={
                                      !canDeleteFromMenu
                                        ? isActive
                                          ? t(
                                              'The active deployment cannot be deleted from the list',
                                            )
                                          : isDeploymentInProgress(
                                                deployment.status,
                                              )
                                            ? t(
                                                'Wait for the build to finish or cancel it first',
                                              )
                                            : undefined
                                        : undefined
                                    }
                                    onSelect={() => {
                                      if (!canDeleteFromMenu) return
                                      const id = deployment.$id
                                      openDialogAfterOverlayCloses(() =>
                                        setDeleteRowDeploymentId(id),
                                      )
                                    }}
                                  >
                                    <MenuItemContent icon={Trash2}>
                                      {t('Delete')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                  {isDeploymentInProgress(
                                    deployment.status,
                                  ) && (
                                    <DropdownMenuItem
                                      onSelect={() => {
                                        const id = deployment.$id
                                        openDialogAfterOverlayCloses(() => {
                                          setCancelTargetDeploymentId(id)
                                          setCancelBuildDialogOpen(true)
                                        })
                                      }}
                                    >
                                      <MenuItemContent icon={XCircle}>
                                        {t('Cancel')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  )}
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </TableCell>
                          </tr>
                        </DeploymentListRowContextMenu>
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
              title={filterMap.size > 0 ? undefined : t('No deployments yet')}
              description={
                filterMap.size > 0
                  ? undefined
                  : t('Create your first deployment to get started')
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
                      {t('No deployments yet')}
                    </p>
                    <p className="mb-4 text-[13px] text-muted-foreground">
                      {t('Create your first deployment to get started')}
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

      {/* Bulk Delete Action Bar */}
      {selectedDeployments.size > 0 && (
        <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedDeployments.size}{' '}
              {selectedDeployments.size > 1
                ? t('deployments')
                : t('deployment')}{' '}
              {t('selected')}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedDeployments(new Set())}
                className="h-8 text-xs"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleBulkDelete}
                disabled={bulkDeleteMutation.isPending}
                className="h-8 gap-2"
              >
                <Trash2 className="h-4 w-4" />
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Bulk Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete Deployments')}</DialogTitle>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DialogDescription className="text-[13px] mb-4">
              {t('Are you sure you want to delete')} {selectedDeployments.size}{' '}
              {selectedDeployments.size > 1
                ? t('deployments')
                : t('deployment')}
              ? {t('This action cannot be undone.')}
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
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmBulkDelete}
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete confirmation for a list row */}
      <Dialog
        open={deleteRowDeploymentId !== null}
        onOpenChange={(open) => {
          if (!open) setDeleteRowDeploymentId(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete deployment')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this deployment? This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {displayedDeployments?.find(
              (d) => d.$id === deleteRowDeploymentId,
            ) && (
              <DeploymentInfo
                deployment={
                  displayedDeployments.find(
                    (d) => d.$id === deleteRowDeploymentId,
                  )!
                }
                showStatus={true}
              />
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteRowDeploymentId(null)}
              disabled={deleteRowMutation.isPending}
              className="h-9 text-[13px]"
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (!deleteRowDeploymentId) return
                const id = deleteRowDeploymentId
                closeDialogBeforeOverlayUnmount(() =>
                  setDeleteRowDeploymentId(null),
                )
                deleteRowMutation.mutate(id)
              }}
              disabled={deleteRowMutation.isPending || !deleteRowDeploymentId}
              className="h-9 text-[13px]"
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Cancel build confirmation */}
      <Dialog
        open={cancelBuildDialogOpen}
        onOpenChange={(open) => {
          setCancelBuildDialogOpen(open)
          if (!open) setCancelTargetDeploymentId(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Cancel build')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Stop the current deployment? You can deploy again later.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {(displayedDeployments?.find(
              (d) => d.$id === cancelTargetDeploymentId,
            ) ??
              (cancelTargetDeploymentId === activeDeployment?.$id
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
              {t('Keep building')}
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                cancelTargetDeploymentId &&
                cancelBuildMutation.mutate(cancelTargetDeploymentId)
              }
              disabled={
                cancelBuildMutation.isPending || !cancelTargetDeploymentId
              }
              className="h-9 text-[13px]"
            >
              {t('Cancel build')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
