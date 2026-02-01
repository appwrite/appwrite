import { useState, useMemo, useRef, useEffect, useCallback } from 'react'
import * as React from 'react'
import { Link } from '@tanstack/react-router'
import {
  GitBranch,
  GitCommit,
  Search,
  Copy,
  Download,
  Trash2,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  Play,
  ArrowUp,
  ArrowDown,
  HelpCircle,
  FileCode,
  Package,
  BrainCircuit,
  ExternalLink,
  RefreshCw,
  Globe,
} from 'lucide-react'
import {
  getDeploymentStatusBadge,
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import {
  getAIChatIDEs,
  generateAIChatDeeplink,
  type IDEConfig,
} from '@/lib/config/ide'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useLocation, useSearch } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  useDeploymentProxyRules,
  useFunctionDeploymentProxyRules,
} from '@/lib/react-query/hooks'

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
  deployment: any,
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

/**
 * Get VCS provider type from deployment
 */
function getVcsProviderType(deployment: any): 'github' | 'gitlab' | 'bitbucket' | null {
  // Check provider from URL or vcsProvider field
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) return 'github'
    if (url.includes('gitlab.com')) return 'gitlab'
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com')) return 'bitbucket'
  }

  // Fallback to vcsProvider field
  if (deployment.vcsProvider) {
    const provider = deployment.vcsProvider.toLowerCase()
    if (provider === 'github') return 'github'
    if (provider === 'gitlab') return 'gitlab'
    if (provider === 'bitbucket') return 'bitbucket'
  }

  return null
}

/**
 * Build repository URL from deployment VCS provider info
 */
function getRepositoryUrl(deployment: any): string | null {
  if (!deployment.providerRepositoryOwner || !deployment.providerRepositoryName) {
    return null
  }

  const owner = deployment.providerRepositoryOwner
  const repo = deployment.providerRepositoryName
  const provider = getVcsProviderType(deployment)

  if (!provider) return null

  if (provider === 'github') {
    return `https://github.com/${owner}/${repo}`
  }
  if (provider === 'gitlab') {
    return `https://gitlab.com/${owner}/${repo}`
  }
  if (provider === 'bitbucket') {
    return `https://bitbucket.org/${owner}/${repo}`
  }

  return null
}

/**
 * Build commit URL from deployment VCS provider info
 */
function getCommitUrl(deployment: any): string | null {
  if (!deployment.providerCommitHash || !deployment.providerRepositoryOwner || !deployment.providerRepositoryName) {
    return null
  }

  const owner = deployment.providerRepositoryOwner
  const repo = deployment.providerRepositoryName
  const commitHash = deployment.providerCommitHash
  const provider = getVcsProviderType(deployment)

  if (!provider) return null

  if (provider === 'github') {
    return `https://github.com/${owner}/${repo}/commit/${commitHash}`
  }
  if (provider === 'gitlab') {
    return `https://gitlab.com/${owner}/${repo}/-/commit/${commitHash}`
  }
  if (provider === 'bitbucket') {
    return `https://bitbucket.org/${owner}/${repo}/commits/${commitHash}`
  }

  return null
}

/**
 * Build branch URL from deployment VCS provider info
 */
function getBranchUrl(deployment: any): string | null {
  if (!deployment.providerBranch || !deployment.providerRepositoryOwner || !deployment.providerRepositoryName) {
    return null
  }

  const owner = deployment.providerRepositoryOwner
  const repo = deployment.providerRepositoryName
  const branch = deployment.providerBranch
  const provider = getVcsProviderType(deployment)

  if (!provider) return null

  if (provider === 'github') {
    return `https://github.com/${owner}/${repo}/tree/${branch}`
  }
  if (provider === 'gitlab') {
    return `https://gitlab.com/${owner}/${repo}/-/tree/${branch}`
  }
  if (provider === 'bitbucket') {
    return `https://bitbucket.org/${owner}/${repo}/src/${branch}`
  }

  return null
}

/**
 * Generate the AI fix prompt for a failed deployment
 */
function generateAIFixPrompt(
  deployment: Models.Deployment,
  runtime?: string,
  resourceName?: string,
  isSite?: boolean,
): string {
  const resourceType = isSite ? 'Site' : 'Function'
  const buildLogs = deployment.buildLogs || ''
  
  // Get the last 100 lines of logs to avoid overly long prompts
  const logLines = buildLogs.split('\n')
  const lastLogs = logLines.slice(-100).join('\n')
  
  // Strip ANSI codes from logs for clean markdown
  const cleanLogs = lastLogs.replace(/\x1b\[(\d+(?:;\d+)*)?m/g, '')
  
  let prompt = `# Fix Appwrite ${resourceType} Deployment Failure

## Context
`

  if (resourceName) {
    prompt += `- **${resourceType} Name**: ${resourceName}\n`
  }
  
  prompt += `- **Deployment ID**: ${deployment.$id}\n`
  
  if (runtime) {
    prompt += `- **Runtime**: ${runtime}\n`
  }
  
  prompt += `- **Status**: Failed\n`
  prompt += `- **Created**: ${new Date(deployment.$createdAt).toISOString()}\n`
  
  if (deployment.providerBranch) {
    prompt += `- **Branch**: ${deployment.providerBranch}\n`
  }
  
  if (deployment.providerCommitHash) {
    prompt += `- **Commit**: ${deployment.providerCommitHash.slice(0, 7)}\n`
  }
  
  if (deployment.providerCommitMessage) {
    prompt += `- **Commit Message**: ${deployment.providerCommitMessage}\n`
  }

  prompt += `
## Build Logs (Last 100 lines)

\`\`\`
${cleanLogs || 'No build logs available'}
\`\`\`

## Task

Please analyze the build logs above and help me fix the deployment failure. Identify:
1. The root cause of the failure
2. Specific code changes or configuration updates needed
3. Any missing dependencies or incorrect settings

Provide clear, actionable steps to resolve this issue.`

  return prompt
}

export interface DeploymentDetailViewConfig {
  // Data
  projectId: string
  resourceId: string // siteId or functionId
  deploymentId: string
  deployment: Models.Deployment | undefined
  isLoading: boolean
  parentResource: { name?: string; deploymentId?: string; runtime?: string } | undefined // site or function
  deployments: Models.Deployment[]
  relatedData?: { total?: number } // logs or executions count
  
  // Navigation
  deploymentDetailRoute: string // Route pattern for deployment detail
  listRoute: string // Route pattern for parent resource list
  relatedRoute?: string // Route pattern for related data (logs/executions)
  
  // Actions
  onDelete: (deploymentId: string) => Promise<void>
  onDownloadSource: (projectId: string, resourceId: string, deploymentId: string) => void
  onDownloadBuild: (projectId: string, resourceId: string, deploymentId: string) => void
  onRedeploy?: (projectId: string, resourceId: string, deploymentId: string) => Promise<void>
  onActivate?: (projectId: string, resourceId: string, deploymentId: string) => Promise<void>
  onNavigateToRelated?: (deploymentId: string) => void
  
  // UI
  relatedDataLabel?: string // "Logs" or "Executions"
  showRuntime?: boolean // Show runtime metadata (for functions)
  RuntimeIcon?: React.ComponentType<{ runtime: string; size?: string; className?: string }>
  
  // Query invalidation
  invalidateQueries: Array<string | string[]>
  
  // Fallback path
  fallbackPath: string
}

export function DeploymentDetailView({
  projectId,
  resourceId,
  deploymentId,
  deployment,
  isLoading,
  parentResource,
  deployments,
  deploymentDetailRoute,
  listRoute,
  onDelete,
  onDownloadSource,
  onDownloadBuild,
  onRedeploy,
  onActivate,
  showRuntime = false,
  RuntimeIcon,
  invalidateQueries,
  fallbackPath,
}: DeploymentDetailViewConfig) {
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false })
  const queryClient = useQueryClient()
  const [logsSearch, setLogsSearch] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)
  const [activateDialogOpen, setActivateDialogOpen] = useState(false)
  const logsContainerRef = useRef<HTMLDivElement>(null)
  const lineRefs = useRef<Map<number, HTMLDivElement>>(new Map())
  /** Set on first scroll; until then we auto-scroll so initial load follows tail. */
  const hasUserScrolledRef = useRef(false)
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)

  // Live elapsed seconds when deployment is processing/building (updates every second)
  const [elapsedSeconds, setElapsedSeconds] = useState(0)
  useEffect(() => {
    if (!deployment?.$createdAt || !isDeploymentInProgress(deployment.status)) return
    const tick = () => {
      const created = new Date(deployment.$createdAt).getTime()
      setElapsedSeconds(Math.floor((Date.now() - created) / 1000))
    }
    tick()
    const interval = setInterval(tick, 1000)
    return () => clearInterval(interval)
  }, [deployment?.$createdAt, deployment?.status])

  // Get selected line from URL query params
  const selectedLine = useMemo(() => {
    // Handle both object and string search params
    if (typeof search === 'object' && search !== null && 'line' in search) {
      const lineParam = search.line
      if (typeof lineParam === 'number') {
        return lineParam
      }
      if (typeof lineParam === 'string') {
        const parsed = parseInt(lineParam, 10)
        return isNaN(parsed) ? null : parsed
      }
      return null
    }
    
    // Fallback to string parsing
    const searchParams = new URLSearchParams(
      typeof location.search === 'string' ? location.search : '',
    )
    const lineParam = searchParams.get('line')
    return lineParam ? parseInt(lineParam, 10) : null
  }, [search, location.search])

  // Determine if this is a site or function deployment (needed for navigation)
  const isSiteDeployment = resourceId.includes('site') || deploymentDetailRoute.includes('sites')
  const parentResourceParam = isSiteDeployment ? 'siteId' : 'functionId'

  // Get current deployment index and find previous/next
  const deploymentIndex = useMemo(() => {
    if (!deployments || !deploymentId) return -1
    return deployments.findIndex((d) => d.$id === deploymentId)
  }, [deployments, deploymentId])

  const previousDeployment =
    deploymentIndex > 0 ? deployments[deploymentIndex - 1] : null
  const nextDeployment =
    deploymentIndex >= 0 && deploymentIndex < (deployments.length || 0) - 1
      ? deployments[deploymentIndex + 1]
      : null

  // Check if this is the active deployment
  const isActiveDeployment = parentResource?.deploymentId === deploymentId

  // Fetch proxy rules for this deployment
  const siteProxyRules = useDeploymentProxyRules(
    isSiteDeployment ? projectId : null,
    isSiteDeployment ? resourceId : null,
    isSiteDeployment ? deploymentId : null,
  )

  const functionProxyRules = useFunctionDeploymentProxyRules(
    !isSiteDeployment ? projectId : null,
    !isSiteDeployment ? resourceId : null,
    !isSiteDeployment ? deploymentId : null,
  )

  const proxyRules = isSiteDeployment ? siteProxyRules : functionProxyRules

  // Check if deployment failed (including timeout)
  const isDeploymentFailed = deployment
    ? deployment.status === 'failed' || isDeploymentTimeout(deployment.status, deployment.$createdAt)
    : false

  // Generate AI fix prompt
  const aiFixPrompt = useMemo(() => {
    if (!deployment || !isDeploymentFailed) return ''
    return generateAIFixPrompt(
      deployment,
      parentResource?.runtime,
      parentResource?.name,
      isSiteDeployment,
    )
  }, [deployment, isDeploymentFailed, parentResource?.runtime, parentResource?.name, isSiteDeployment])

  // IDE configurations (only those that support AI chat)
  const aiChatIDEs = useMemo(() => getAIChatIDEs(), [])

  // Handle opening IDE with prompt
  const handleOpenInIDE = (ide: IDEConfig) => {
    const deeplink = generateAIChatDeeplink(ide, aiFixPrompt)
    if (deeplink) {
      window.open(deeplink, '_blank')
      toast.success(`Opening ${ide.name}...`)
    }
  }

  // Handle copy prompt as markdown
  const handleCopyPrompt = async () => {
    try {
      await navigator.clipboard.writeText(aiFixPrompt)
      toast.success('Prompt copied to clipboard')
    } catch (error) {
      toast.error('Failed to copy prompt')
    }
  }

  // Get VCS provider info
  const vcsProvider = deployment ? getVcsProvider(deployment) : null

  // Get repository URLs
  const repositoryUrl = deployment ? getRepositoryUrl(deployment) : null
  const commitUrl = deployment ? getCommitUrl(deployment) : null
  const branchUrl = deployment ? getBranchUrl(deployment) : null

  // Get status badge
  const statusBadge = deployment
    ? getDeploymentStatusBadge(deployment.status, deployment.$createdAt)
    : null

  const buildLogs = deployment?.buildLogs || ''

  // Delete deployment mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (isActiveDeployment) {
        throw new Error(
          'Cannot delete the active deployment. Please activate another deployment first.',
        )
      }
      return await onDelete(deploymentId)
    },
    onSuccess: async () => {
      // Refetch lists so the UI updates (lists use refetchOnMount: false)
      for (const queryKey of invalidateQueries) {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey) ? queryKey : [queryKey]
        await queryClient.refetchQueries({ queryKey: normalizedKey })
      }
      toast.success('Deployment deleted successfully')
      setDeleteDialogOpen(false)
      // Navigate back to list
      navigate({
        to: listRoute as any,
        params: { projectId, [parentResourceParam]: resourceId } as any,
      })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete deployment')
    },
  })

  // Redeploy mutation
  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!onRedeploy) {
        throw new Error('Redeploy is not available for this deployment type')
      }
      return await onRedeploy(projectId, resourceId, deploymentId)
    },
    onSuccess: () => {
      invalidateQueries.forEach((queryKey) => {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey) ? queryKey : [queryKey]
        queryClient.invalidateQueries({ queryKey: normalizedKey })
      })
      toast.success('Deployment rebuild started')
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to redeploy')
    },
  })

  // Activate mutation
  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!onActivate) {
        throw new Error('Activate is not available for this deployment type')
      }
      return await onActivate(projectId, resourceId, deploymentId)
    },
    onSuccess: () => {
      invalidateQueries.forEach((queryKey) => {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey) ? queryKey : [queryKey]
        queryClient.invalidateQueries({ queryKey: normalizedKey })
      })
      toast.success('Deployment activated successfully')
      setActivateDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to activate deployment')
    },
  })

  // Handle download source code
  const handleDownloadSource = () => {
    onDownloadSource(projectId, resourceId, deploymentId)
  }

  // Handle download build output
  const handleDownloadBuild = () => {
    onDownloadBuild(projectId, resourceId, deploymentId)
  }

  // Handle copy logs
  const handleCopyLogs = async () => {
    if (!buildLogs) {
      toast.error('No logs to copy')
      return
    }

    try {
      await navigator.clipboard.writeText(buildLogs)
      toast.success('Logs copied to clipboard')
    } catch (error) {
      toast.error('Failed to copy logs')
    }
  }

  // Handle download logs
  const handleDownloadLogs = () => {
    if (!buildLogs) {
      toast.error('No logs to download')
      return
    }

    const blob = new Blob([buildLogs], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `deployment-${deploymentId}-logs.txt`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast.success('Logs downloaded')
  }

  // Find the scrollable parent element (WizardLayout's content wrapper)
  const getScrollContainer = useCallback((): HTMLElement | null => {
    if (!logsContainerRef.current) return null
    // Walk up the DOM to find the scrollable parent
    let element: HTMLElement | null = logsContainerRef.current.parentElement
    while (element) {
      const style = window.getComputedStyle(element)
      const overflowY = style.overflowY
      if (overflowY === 'auto' || overflowY === 'scroll') {
        return element
      }
      element = element.parentElement
    }
    return null
  }, [])

  // Update scroll position state for scroll-to-top/bottom buttons (controls auto-scroll: follow when at bottom)
  const updateScrollPosition = useCallback(() => {
    const scrollContainer = getScrollContainer()
    if (!scrollContainer) return

    hasUserScrolledRef.current = true
    const { scrollTop, scrollHeight, clientHeight } = scrollContainer
    const threshold = 10 // Small threshold to account for rounding

    setIsAtTop(scrollTop <= threshold)
    setIsAtBottom(scrollTop + clientHeight >= scrollHeight - threshold)
  }, [getScrollContainer])

  // Track scroll position
  useEffect(() => {
    const scrollContainer = getScrollContainer()
    if (!scrollContainer) return

    // Initial check
    updateScrollPosition()

    // Listen for scroll events
    scrollContainer.addEventListener('scroll', updateScrollPosition)
    
    // Also listen for resize to recalculate
    window.addEventListener('resize', updateScrollPosition)

    return () => {
      scrollContainer.removeEventListener('scroll', updateScrollPosition)
      window.removeEventListener('resize', updateScrollPosition)
    }
  }, [getScrollContainer, updateScrollPosition, buildLogs])

  // Auto-scroll when new logs arrive if user hasn't scrolled or is at bottom (same as scroll-to-bottom control)
  useEffect(() => {
    const shouldFollow = !hasUserScrolledRef.current || isAtBottom
    if (!shouldFollow || !buildLogs) return
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTop = scrollContainer.scrollHeight
    }
  }, [buildLogs, isAtBottom, getScrollContainer])

  // Scroll to selected line when it changes
  useEffect(() => {
    if (selectedLine === null) return
    if (!buildLogs) return // Wait for logs to be available

    // Retry mechanism to ensure DOM has updated with refs
    let retryCount = 0
    const maxRetries = 10
    
    const tryScroll = () => {
      const lineElement = lineRefs.current.get(selectedLine)
      if (!lineElement) {
        // Retry if element not found yet
        if (retryCount < maxRetries) {
          retryCount++
          setTimeout(tryScroll, 100)
        }
        return
      }

      const scrollContainer = getScrollContainer()
      if (!scrollContainer) return

      // Calculate position relative to scroll container
      const containerRect = scrollContainer.getBoundingClientRect()
      const elementRect = lineElement.getBoundingClientRect()
      const relativeTop = elementRect.top - containerRect.top + scrollContainer.scrollTop

      // Scroll to line with some padding from top
      scrollContainer.scrollTo({
        top: relativeTop - 20, // 20px padding from top
        behavior: 'smooth',
      })
    }

    // Start trying after a short delay
    const timeoutId = setTimeout(tryScroll, 100)

    return () => clearTimeout(timeoutId)
  }, [selectedLine, getScrollContainer, buildLogs])

  // Scroll handlers for logs
  const handleScrollToTop = () => {
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  const handleScrollToBottom = () => {
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTo({
        top: scrollContainer.scrollHeight,
        behavior: 'smooth',
      })
    }
  }

  // Line click: toggle URL line param and copy "Line N" to clipboard
  const handleLineClick = useCallback(
    async (lineNumber: number) => {
      const isSelected = selectedLine === lineNumber
      if (isSelected) {
        navigate({
          to: location.pathname,
          search: (prev: any) => {
            const newSearch = { ...(prev || {}) }
            delete newSearch.line
            return Object.keys(newSearch).length === 0 ? {} : newSearch
          },
          replace: true,
        })
      } else {
        navigate({
          to: location.pathname,
          search: (prev: any) => ({ ...(prev || {}), line: lineNumber }),
          replace: true,
        })
        const lineRef = `Line ${lineNumber}`
        try {
          await navigator.clipboard.writeText(lineRef)
          toast.success(`Copied "${lineRef}" to clipboard`)
        } catch (error) {
          // Ignore clipboard errors
        }
      }
    },
    [selectedLine, navigate, location.pathname],
  )

  // Extract route params for navigation
  const routeParams = useMemo(() => {
    return {
      projectId,
      [parentResourceParam]: resourceId,
      deploymentId,
    }
  }, [projectId, resourceId, deploymentId, parentResourceParam])

  if (isLoading) {
    return (
      <WizardLayout
        title="Deployment Details"
        fullscreen
        useSidebar={false}
        fallbackPath={fallbackPath}
      >
        <div className="flex h-full items-center justify-center">
          <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
            <p className="text-[13px] text-muted-foreground">
              Loading deployment...
            </p>
          </div>
        </div>
      </WizardLayout>
    )
  }

  if (!deployment) {
    return (
      <WizardLayout
        title="Deployment Details"
        fullscreen
        useSidebar={false}
        fallbackPath={fallbackPath}
      >
        <div className="flex h-full items-center justify-center">
          <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
            <p className="mb-4 text-[13px] text-muted-foreground">
              Deployment not found
            </p>
          </div>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={
        <>
          <span className="hidden sm:inline">Deployment</span>
          <span className="sm:hidden">Deploy</span>
          {parentResource?.name && (
            <>
              {' '}
              <span className="text-muted-foreground hidden sm:inline">for</span>{' '}
              <Link
                to={listRoute as any}
                params={{ projectId, [parentResourceParam]: resourceId } as any}
                className="text-primary hover:underline font-medium"
              >
                {parentResource.name}
              </Link>
            </>
          )}
          <CopyableId id={deployment.$id} size="sm" maxWidth={300} className="hidden sm:inline-flex" />
          <CopyableId id={deployment.$id} size="sm" maxWidth={200} className="sm:hidden" />
        </>
      }
      headerActions={
        <>
          {/* Previous/Next Navigation */}
          <div className="flex items-center gap-1">
            <Button
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0"
              disabled={!previousDeployment}
              onClick={() => {
                if (previousDeployment) {
                  navigate({
                    to: deploymentDetailRoute as any,
                    params: { ...routeParams, deploymentId: previousDeployment.$id } as any,
                  })
                }
              }}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0"
              disabled={!nextDeployment}
              onClick={() => {
                if (nextDeployment) {
                  navigate({
                    to: deploymentDetailRoute as any,
                    params: { ...routeParams, deploymentId: nextDeployment.$id } as any,
                  })
                }
              }}
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </>
      }
      headerBottom={
        <>
          {/* Metadata - Part of Header */}
          <div className="border-t border-border bg-muted/20">
            <div className="px-4 sm:px-6 py-3 sm:py-4">
              <div className="flex items-center gap-3 sm:gap-6 flex-wrap">
                {/* Deployed */}
                <div className="flex items-center gap-2">
                  <span className="text-[12px] sm:text-[13px] text-muted-foreground">Deployed</span>
                  <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                    <DateTooltip date={deployment.$createdAt} />
                  </span>
                </div>

                {/* Total size */}
                <div className="flex items-center gap-2">
                  <span className="text-[12px] sm:text-[13px] text-muted-foreground">Size</span>
                  <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                    {formatSize(
                      (deployment.buildSize || 0) + (deployment.sourceSize || 0),
                    )}
                  </span>
                </div>

                {/* Source */}
                {vcsProvider &&
                  deployment.providerRepositoryOwner &&
                  deployment.providerRepositoryName && (
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">Source</span>
                      <div className="flex items-center gap-1.5 min-w-0">
                        <span className="text-muted-foreground shrink-0">{vcsProvider.icon}</span>
                        {repositoryUrl ? (
                          <a
                            href={repositoryUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[12px] sm:text-[13px] font-medium text-foreground hover:text-primary transition-colors truncate"
                          >
                            {deployment.providerRepositoryOwner}/
                            {deployment.providerRepositoryName}
                          </a>
                        ) : (
                          <span className="text-[12px] sm:text-[13px] font-medium text-foreground truncate">
                            {deployment.providerRepositoryOwner}/
                            {deployment.providerRepositoryName}
                          </span>
                        )}
                      </div>
                    </div>
                  )}

                {/* Branch */}
                {deployment.providerBranch && (
                  <div className="flex items-center gap-2 min-w-0">
                    <GitBranch className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                    {branchUrl ? (
                      <a
                        href={branchUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[12px] sm:text-[13px] font-medium text-foreground hover:text-primary transition-colors truncate"
                      >
                        {deployment.providerBranch}
                      </a>
                    ) : (
                      <span className="text-[12px] sm:text-[13px] font-medium text-foreground truncate">
                        {deployment.providerBranch}
                      </span>
                    )}
                  </div>
                )}

                {/* Commit Hash */}
                {deployment.providerCommitHash && (
                  <div className="flex items-center gap-2 min-w-0">
                    <GitCommit className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                    {deployment.providerCommitMessage ? (
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="text-[12px] sm:text-[13px] font-mono font-medium text-foreground hover:text-primary transition-colors cursor-pointer text-left"
                          >
                            {deployment.providerCommitHash.slice(0, 7)}
                          </button>
                        </PopoverTrigger>
                        <PopoverContent 
                          side="bottom" 
                          align="start"
                          className="max-w-sm z-[200]"
                          sideOffset={4}
                        >
                          <div className="space-y-2">
                            <p className="text-[13px] whitespace-pre-wrap break-words text-foreground">
                              {deployment.providerCommitMessage}
                            </p>
                            {commitUrl && (
                              <a
                                href={commitUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-[12px] text-primary hover:underline inline-block"
                                onClick={(e) => e.stopPropagation()}
                              >
                                View commit →
                              </a>
                            )}
                          </div>
                        </PopoverContent>
                      </Popover>
                    ) : (
                      <>
                        {commitUrl ? (
                          <a
                            href={commitUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[12px] sm:text-[13px] font-mono font-medium text-foreground hover:text-primary transition-colors"
                          >
                            {deployment.providerCommitHash.slice(0, 7)}
                          </a>
                        ) : (
                          <span className="text-[12px] sm:text-[13px] font-mono font-medium text-foreground">
                            {deployment.providerCommitHash.slice(0, 7)}
                          </span>
                        )}
                      </>
                    )}
                  </div>
                )}

                {/* Runtime - Only for functions */}
                {showRuntime && parentResource?.runtime && RuntimeIcon && (
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">Runtime</span>
                    <div className="flex items-center gap-1.5 min-w-0">
                      <RuntimeIcon runtime={parentResource.runtime} size="sm" className="h-3.5 w-3.5 shrink-0" />
                      <span className="text-[12px] sm:text-[13px] font-mono font-medium text-foreground truncate">
                        {parentResource.runtime}
                      </span>
                    </div>
                  </div>
                )}

                {/* Type */}
                <div className="flex items-center gap-2">
                  <span className="text-[13px] text-muted-foreground">Type</span>
                  <div className="flex items-center gap-1.5">
                    <span className="text-[13px] font-medium text-foreground">
                      {deployment.type === 'cli'
                        ? 'CLI'
                        : deployment.type === 'manual'
                          ? 'Manual'
                          : deployment.type === 'vcs'
                            ? 'VCS'
                            : deployment.type || 'N/A'}
                    </span>
                    <TooltipProvider delayDuration={0}>
                      <TooltipPrimitive.Root>
                        <TooltipTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex items-center justify-center focus:outline-none"
                          >
                            <HelpCircle className="h-3.5 w-3.5 text-muted-foreground cursor-help hover:text-foreground transition-colors" />
                          </button>
                        </TooltipTrigger>
                        <TooltipContent side="top" sideOffset={4} className="max-w-xs z-[200]">
                          <p className="text-[12px]">
                            {deployment.type === 'vcs'
                              ? 'VCS (Version Control System) deployments are triggered from a connected Git repository and enable automatic deployments on code pushes.'
                              : deployment.type === 'cli'
                                ? 'CLI deployments are created using the Appwrite command line tool, useful for developer workflows and scripted automation.'
                                : deployment.type === 'manual'
                                  ? 'Manual deployments are created by uploading code through the Console or API, or by redeploying an existing deployment. Useful for quick testing and re-running builds.'
                                  : 'The deployment type indicates how this deployment was created.'}
                          </p>
                        </TooltipContent>
                      </TooltipPrimitive.Root>
                    </TooltipProvider>
                  </div>
                </div>

                {/* Build duration and Status - at end */}
                {(deployment.buildDuration != null ||
                  isDeploymentInProgress(deployment.status) ||
                  statusBadge) && (
                  <div className="flex items-center gap-2 sm:gap-3 ml-auto">
                    {(isDeploymentInProgress(deployment.status)
                      ? !isDeploymentTimeout(
                          deployment.status,
                          deployment.$createdAt,
                        )
                      : deployment.buildDuration != null &&
                        deployment.buildDuration > 0 &&
                        !isDeploymentTimeout(
                          deployment.status,
                          deployment.$createdAt,
                        )) && (
                        <div className="flex items-center gap-2">
                          <span className="text-[12px] sm:text-[13px] text-muted-foreground">Duration</span>
                          <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                            {isDeploymentInProgress(deployment.status)
                              ? formatDuration(Math.max(0, elapsedSeconds))
                              : formatDuration(deployment.buildDuration)}
                          </span>
                        </div>
                      )}
                    {statusBadge && (
                      <Badge
                        variant={statusBadge.badgeVariant}
                        className="gap-1.5 text-[12px] font-medium shrink-0 h-6 px-2.5"
                      >
                        {(() => {
                          const StatusIcon = statusBadge.icon
                          return <StatusIcon className="h-3.5 w-3.5" />
                        })()}
                        {statusBadge.label}
                      </Badge>
                    )}
                    {/* Fix with AI button - only shown for failed deployments */}
                    {isDeploymentFailed && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-6 text-[12px] px-2.5 gap-1"
                          >
                            <BrainCircuit className="h-3.5 w-3.5" />
                            <span className="hidden sm:inline">Fix with AI</span>
                            <ChevronDown className="h-3 w-3" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="z-[200] min-w-[180px]">
                          {aiChatIDEs.map((ide) => (
                            <DropdownMenuItem key={ide.id} onClick={() => handleOpenInIDE(ide)}>
                              <img src={ide.iconPath} alt={ide.name} className="h-4 w-4" />
                              <span className="ml-2">Prompt {ide.name}</span>
                              <ExternalLink className="ml-auto h-3.5 w-3.5 text-muted-foreground" />
                            </DropdownMenuItem>
                          ))}
                          <div className="h-px bg-border my-1" />
                          <DropdownMenuItem onClick={handleCopyPrompt}>
                            <Copy className="h-4 w-4" />
                            <span className="ml-2">Copy prompt</span>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
          {/* Search - Part of Header */}
          <div className="border-t border-border px-4 sm:px-6 py-3">
            <TooltipProvider>
              <div className="flex items-center gap-2">
                <div className="relative flex-1 min-w-0">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search logs..."
                    value={logsSearch}
                    onChange={(e) => setLogsSearch(e.target.value)}
                    className="pl-9 h-9 text-[13px]"
                  />
                </div>
                <TooltipPrimitive.Root>
                  <TooltipTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={handleDownloadLogs}
                      disabled={!buildLogs}
                      className="h-9 w-9 p-0 shrink-0"
                    >
                      <Download className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>Download logs</p>
                  </TooltipContent>
                </TooltipPrimitive.Root>
                <TooltipPrimitive.Root>
                  <TooltipTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={handleCopyLogs}
                      disabled={!buildLogs}
                      className="h-9 w-9 p-0 shrink-0"
                    >
                      <Copy className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>Copy logs</p>
                  </TooltipContent>
                </TooltipPrimitive.Root>
              </div>
            </TooltipProvider>
          </div>
        </>
      }
      fullscreen
      useSidebar={false}
      constrainWidth={false}
      constrainFooterWidth={false}
      showBackButton={true}
      backButtonLabel="Deployments"
      contentPadding={false}
      onClose={() => {
        navigate({
          to: listRoute as any,
          params: { projectId, [parentResourceParam]: resourceId } as any,
        })
      }}
      contentClassName="flex flex-col h-full min-h-0 overflow-hidden -mx-6"
      footer={
        <div className="hidden sm:flex flex-row items-center justify-between gap-2 w-full">
          {/* Left side - Delete button */}
          <div className="flex items-center">
            <TooltipProvider delayDuration={0}>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <span>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={() => setDeleteDialogOpen(true)}
                      disabled={isActiveDeployment}
                      className="h-9 text-[13px]"
                    >
                      <Trash2 className="mr-1.5 h-4 w-4" />
                      Delete
                    </Button>
                  </span>
                </TooltipTrigger>
                {isActiveDeployment && (
                  <TooltipContent sideOffset={4} className="z-[200]">
                    <p>Cannot delete the active deployment. Please activate another deployment first.</p>
                  </TooltipContent>
                )}
              </TooltipPrimitive.Root>
            </TooltipProvider>
          </div>
          
          {/* Right side - Individual buttons */}
          <div className="flex items-center gap-2 ml-auto">
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
                <DropdownMenuItem onClick={handleDownloadBuild}>
                  <Package className="mr-2 h-4 w-4" />
                  Build output
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            {onRedeploy && (
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
            )}
            <TooltipProvider delayDuration={0}>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <span>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        if (onActivate) {
                          setActivateDialogOpen(true)
                        } else {
                          toast.info('Activate deployment functionality coming soon')
                        }
                      }}
                      disabled={
                        isActiveDeployment ||
                        activateMutation.isPending ||
                        deployment?.status !== 'ready'
                      }
                      className="h-9 text-[13px]"
                    >
                      <Play className="mr-1.5 h-4 w-4" />
                      Activate
                    </Button>
                  </span>
                </TooltipTrigger>
                {(isActiveDeployment || deployment?.status !== 'ready') && (
                  <TooltipContent sideOffset={4} className="z-[200]">
                    <p>
                      {isActiveDeployment
                        ? 'This deployment is already active.'
                        : 'Build must be ready before activating.'}
                    </p>
                  </TooltipContent>
                )}
              </TooltipPrimitive.Root>
            </TooltipProvider>
            {proxyRules.rules.length > 0 && (
              <Popover>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                  >
                    <Globe className="mr-1.5 h-4 w-4" />
                    Visit
                  </Button>
                </PopoverTrigger>
                <PopoverContent align="end" className="z-[200] w-80">
                  <div className="space-y-3">
                    <div>
                      <h4 className="text-[13px] font-semibold text-foreground mb-2">
                        Domains
                      </h4>
                      <div className="space-y-1.5">
                        {proxyRules.rules.map((rule) => (
                          <a
                            key={rule.$id}
                            href={`https://${rule.domain}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center gap-2 p-2 rounded-md hover:bg-muted/50 transition-colors group"
                          >
                            <Globe className="h-3.5 w-3.5 text-muted-foreground group-hover:text-foreground shrink-0" />
                            <span className="text-[12px] font-mono text-foreground group-hover:text-primary flex-1 truncate">
                              {rule.domain}
                            </span>
                            <ExternalLink className="h-3 w-3 text-muted-foreground group-hover:text-foreground shrink-0" />
                          </a>
                        ))}
                      </div>
                    </div>
                  </div>
                </PopoverContent>
              </Popover>
            )}
          </div>
        </div>
      }
    >
      <div ref={logsContainerRef} className="flex flex-col flex-1 min-h-0 overflow-hidden">
        {/* Build Logs */}
        <BuildLogsView
          buildLogs={buildLogs}
          searchTerm={logsSearch}
          selectedLine={selectedLine}
          onLineClick={handleLineClick}
          lineRefs={lineRefs}
          emptyMessage="No build logs available."
        />
        
        {/* Scroll Control Buttons - Fixed position in viewport */}
        {buildLogs && (
          <div className="fixed bottom-24 right-8 flex flex-col gap-2 z-[101]">
            <TooltipProvider>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleScrollToTop}
                    disabled={isAtTop}
                    className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                  >
                    <ArrowUp className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="left">
                  <p>Scroll to top</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleScrollToBottom}
                    disabled={isAtBottom}
                    className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                  >
                    <ArrowDown className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="left">
                  <p>Scroll to bottom</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
            </TooltipProvider>
          </div>
        )}
      </div>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete deployment</DialogTitle>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DialogDescription className="text-[13px] mb-4">
              Are you sure you want to delete this deployment? This action cannot be undone.
            </DialogDescription>
            <DeploymentInfo deployment={deployment} showStatus={true} />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              className="h-9 text-[13px]"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteMutation.mutate()}
              disabled={deleteMutation.isPending}
              className="h-9 text-[13px]"
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Redeploy Confirmation Dialog */}
      {onRedeploy && (
        <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Redeploy deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.
              </DialogDescription>
              <DeploymentInfo deployment={deployment} showStatus={true} />
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

      {/* Activate Confirmation Dialog */}
      {onActivate && (
        <Dialog open={activateDialogOpen} onOpenChange={setActivateDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Activate deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.
              </DialogDescription>
              <DeploymentInfo deployment={deployment} showStatus={true} />
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
    </WizardLayout>
  )
}
