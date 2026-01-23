import { useState, useMemo, useEffect, useRef } from 'react'
import * as React from 'react'
import { useParams, Link } from '@tanstack/react-router'
import {
  Clock,
  GitBranch,
  GitCommit,
  Search,
  AlertCircle,
  CheckCircle2,
  Loader2,
  Copy,
  Download,
  Trash2,
  MoreHorizontal,
  ChevronLeft,
  ChevronRight,
  Play,
  ExternalLink,
  ArrowUp,
  ArrowDown,
} from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
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
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Query } from '@appwrite.io/console'
import {
  useFunctionDeployment,
  useProjectFunction,
  useFunctionDeployments,
  useFunctionExecutions,
  deleteFunctionDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'

// ANSI color code mapping
const ANSI_COLORS: Record<number, string> = {
  30: 'text-gray-700 dark:text-gray-300', // Black
  31: 'text-red-500', // Red
  32: 'text-green-500', // Green
  33: 'text-yellow-500', // Yellow
  34: 'text-blue-500', // Blue
  35: 'text-purple-500', // Magenta
  36: 'text-cyan-500', // Cyan
  37: 'text-gray-200 dark:text-gray-400', // White
  90: 'text-gray-500 dark:text-gray-500', // Bright Black (Gray)
  91: 'text-red-400', // Bright Red
  92: 'text-green-400', // Bright Green
  93: 'text-yellow-400', // Bright Yellow
  94: 'text-blue-400', // Bright Blue
  95: 'text-purple-400', // Bright Magenta
  96: 'text-cyan-400', // Bright Cyan
  97: 'text-gray-100 dark:text-gray-300', // Bright White
}

/**
 * Parse ANSI escape codes and convert to React elements with colors
 * Optionally highlights search terms
 */
function parseAnsiLogs(
  text: string,
  searchTerm?: string,
): React.ReactNode[] {
  if (!searchTerm || !searchTerm.trim()) {
    // No search term, just parse ANSI codes
    return parseAnsiLogsWithoutHighlight(text)
  }

  // First, parse ANSI codes to get colored segments
  const coloredSegments = parseAnsiLogsWithoutHighlight(text)
  
  // Then highlight search terms in each segment
  const searchLower = searchTerm.toLowerCase()
  const highlightedParts: React.ReactNode[] = []
  let keyCounter = 0

  coloredSegments.forEach((segment) => {
    if (typeof segment === 'string') {
      // Plain string segment - highlight it
      highlightedParts.push(...highlightText(segment, null, searchTerm, keyCounter))
      keyCounter += 1000
    } else if (React.isValidElement(segment) && segment.type === 'span') {
      // Colored span - extract text and color, then highlight
      const textContent = extractTextFromReactNode(segment)
      const className = (segment.props as any)?.className || null
      highlightedParts.push(...highlightText(textContent, className, searchTerm, keyCounter))
      keyCounter += 1000
    } else {
      // Other React element - keep as-is
      highlightedParts.push(segment)
    }
  })

  return highlightedParts.length > 0 ? highlightedParts : [text]
}

/**
 * Helper to extract text content from React node
 */
function extractTextFromReactNode(node: React.ReactNode): string {
  if (typeof node === 'string') return node
  if (typeof node === 'number') return String(node)
  if (React.isValidElement(node)) {
    const children = (node.props as any)?.children
    if (typeof children === 'string') return children
    if (Array.isArray(children)) {
      return children.map(extractTextFromReactNode).join('')
    }
  }
  return ''
}

/**
 * Parse ANSI codes without highlighting
 */
function parseAnsiLogsWithoutHighlight(text: string): React.ReactNode[] {
  // ANSI escape sequence pattern: \x1b[ or \u001b[ followed by numbers and 'm'
  const ansiRegex = /\x1b\[(\d+(?:;\d+)*)?m/g
  const parts: React.ReactNode[] = []
  let lastIndex = 0
  let currentColor: string | null = null
  let match

  while ((match = ansiRegex.exec(text)) !== null) {
    // Add text before the ANSI code
    if (match.index > lastIndex) {
      const textBefore = text.substring(lastIndex, match.index)
      if (textBefore) {
        parts.push(
          currentColor ? (
            <span key={`text-${lastIndex}`} className={currentColor}>
              {textBefore}
            </span>
          ) : (
            textBefore
          ),
        )
      }
    }

    // Parse the ANSI code
    const code = match[1]
    if (!code || code === '0') {
      // Reset
      currentColor = null
    } else {
      // Get the first color code (ANSI codes can have multiple semicolon-separated codes)
      const codes = code.split(';').map(Number)
      const colorCode = codes.find((c) => ANSI_COLORS[c])
      if (colorCode) {
        currentColor = ANSI_COLORS[colorCode]
      }
    }

    lastIndex = match.index + match[0].length
  }

  // Add remaining text
  if (lastIndex < text.length) {
    const remainingText = text.substring(lastIndex)
    if (remainingText) {
      parts.push(
        currentColor ? (
          <span key={`text-${lastIndex}`} className={currentColor}>
            {remainingText}
          </span>
        ) : (
          remainingText
        ),
      )
    }
  }

  return parts.length > 0 ? parts : [text]
}

/**
 * Highlight search term in text while preserving color
 */
function highlightText(
  text: string,
  color: string | null,
  searchTerm: string,
  baseKey: number,
): React.ReactNode[] {
  const searchLower = searchTerm.toLowerCase()
  const lowerText = text.toLowerCase()
  const parts: React.ReactNode[] = []
  let lastIndex = 0
  let keyCounter = baseKey

  while (true) {
    const searchIndex = lowerText.indexOf(searchLower, lastIndex)
    if (searchIndex === -1) {
      // No more matches, add remaining text
      if (lastIndex < text.length) {
        const remaining = text.substring(lastIndex)
        if (remaining) {
          parts.push(
            color ? (
              <span key={keyCounter++} className={color}>
                {remaining}
              </span>
            ) : (
              remaining
            ),
          )
        }
      }
      break
    }

    // Add text before match
    if (searchIndex > lastIndex) {
      const beforeMatch = text.substring(lastIndex, searchIndex)
      if (beforeMatch) {
        parts.push(
          color ? (
            <span key={keyCounter++} className={color}>
              {beforeMatch}
            </span>
          ) : (
            beforeMatch
          ),
        )
      }
    }

    // Add highlighted match
    const matchText = text.substring(searchIndex, searchIndex + searchTerm.length)
    parts.push(
      <mark
        key={keyCounter++}
        className="bg-yellow-200 dark:bg-yellow-900/50 text-foreground"
      >
        {color ? <span className={color}>{matchText}</span> : matchText}
      </mark>,
    )

    lastIndex = searchIndex + searchTerm.length
  }

  return parts.length > 0 ? parts : [text]
}

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

function getDeploymentStatusBadge(status: string) {
  const statusMap: Record<
    string,
    {
      label: string
      badgeVariant: 'completed' | 'failed' | 'pending' | 'processing'
      icon: typeof CheckCircle2
    }
  > = {
    ready: { label: 'Ready', badgeVariant: 'completed', icon: CheckCircle2 },
    building: { label: 'Building', badgeVariant: 'processing', icon: Loader2 },
    processing: {
      label: 'Processing',
      badgeVariant: 'processing',
      icon: Loader2,
    },
    waiting: { label: 'Waiting', badgeVariant: 'pending', icon: Clock },
    failed: { label: 'Failed', badgeVariant: 'failed', icon: AlertCircle },
  }
  const statusInfo = statusMap[status] || {
    label: status,
    badgeVariant: 'pending' as const,
    icon: Clock,
  }
  return {
    label: statusInfo.label,
    badgeVariant: statusInfo.badgeVariant,
    icon: statusInfo.icon,
  }
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

export function DeploymentDetailView() {
  const { projectId, functionId, deploymentId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [logsSearch, setLogsSearch] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [showScrollButtons, setShowScrollButtons] = useState(false)
  const scrollAreaRef = useRef<HTMLDivElement>(null)

  const { data: deployment, isLoading: deploymentLoading } =
    useFunctionDeployment(projectId, functionId, deploymentId)
  
  const { data: func } = useProjectFunction(projectId, functionId)

  // Fetch all deployments for navigation
  const { data: deploymentsData } = useFunctionDeployments(
    projectId,
    functionId,
    0,
    1000, // Get enough to find current deployment
  )

  // Get current deployment index and find previous/next
  const deploymentIndex = useMemo(() => {
    if (!deploymentsData?.deployments || !deploymentId) return -1
    return deploymentsData.deployments.findIndex(
      (d) => d.$id === deploymentId,
    )
  }, [deploymentsData?.deployments, deploymentId])

  const previousDeployment =
    deploymentIndex > 0
      ? deploymentsData?.deployments[deploymentIndex - 1]
      : null
  const nextDeployment =
    deploymentIndex >= 0 &&
    deploymentIndex < (deploymentsData?.deployments.length || 0) - 1
      ? deploymentsData?.deployments[deploymentIndex + 1]
      : null

  // Check if this is the active deployment
  const isActiveDeployment = func?.deployment === deploymentId

  // Get executions count for this deployment
  const { data: executionsData } = useFunctionExecutions(
    projectId,
    functionId,
    0,
    1,
    deploymentId ? [Query.equal('deploymentId', deploymentId)] : undefined,
  )

  // Get runtime name
  const runtimeName = func?.runtime || 'N/A'

  // Get VCS provider info
  const vcsProvider = deployment ? getVcsProvider(deployment) : null

  // Get repository URLs
  const repositoryUrl = deployment ? getRepositoryUrl(deployment) : null
  const commitUrl = deployment ? getCommitUrl(deployment) : null
  const branchUrl = deployment ? getBranchUrl(deployment) : null

  // Get status badge
  const statusBadge = deployment
    ? getDeploymentStatusBadge(deployment.status)
    : null

  const buildLogs = deployment?.buildLogs || ''

  // Delete deployment mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !functionId || !deploymentId) {
        throw new Error('Project ID, Function ID, and Deployment ID are required')
      }
      if (isActiveDeployment) {
        throw new Error(
          'Cannot delete the active deployment. Please activate another deployment first.',
        )
      }
      return await deleteFunctionDeployment(projectId, functionId, deploymentId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: Dependencies.DEPLOYMENTS,
      })
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success('Deployment deleted successfully')
      setDeleteDialogOpen(false)
      // Navigate back to deployments list
      navigate({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId: projectId!, functionId: functionId! },
      })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete deployment')
    },
  })

  // Handle download deployment
  const handleDownloadDeployment = async () => {
    if (!projectId || !functionId || !deploymentId) return

    try {
      const projectSdk = sdk.forProject(projectId)
      // Get deployment download URL
      const url = projectSdk.functions.getDeploymentDownload({
        functionId,
        deploymentId,
      })
      
      // Add mode=admin for console preview
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      
      // Open in new tab for download
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch (error) {
      toast.error('Failed to download deployment')
    }
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


  // Handle scroll to top
  const handleScrollToTop = () => {
    const viewport = scrollAreaRef.current?.querySelector('[data-slot="scroll-area-viewport"]') as HTMLElement | null
    if (viewport) {
      viewport.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  // Handle scroll to bottom
  const handleScrollToBottom = () => {
    const viewport = scrollAreaRef.current?.querySelector('[data-slot="scroll-area-viewport"]') as HTMLElement | null
    if (viewport) {
      viewport.scrollTo({ top: viewport.scrollHeight, behavior: 'smooth' })
    }
  }

  // Parse and filter logs - MUST be called before any conditional returns
  const parsedLogs = useMemo(() => {
    if (!buildLogs) return null

    let logsToDisplay = buildLogs
    const searchTerm = logsSearch.trim()

    // Apply search filter if needed
    if (searchTerm) {
      const searchLower = searchTerm.toLowerCase()
      const lines = buildLogs.split('\n')
      logsToDisplay = lines
        .filter((line: string) =>
          line.toLowerCase().includes(searchLower),
        )
        .join('\n')
    }

    // Split by lines and parse ANSI codes for each line with highlighting
    const lines = logsToDisplay.split('\n')
    return lines.map((line, lineIndex) => {
      const parsedLine = parseAnsiLogs(line, searchTerm || undefined)
      return (
        <span key={lineIndex}>
          {parsedLine}
          {lineIndex < lines.length - 1 && '\n'}
        </span>
      )
    })
  }, [buildLogs, logsSearch])

  // Check if content is scrollable and show/hide scroll buttons
  useEffect(() => {
    if (!buildLogs) {
      setShowScrollButtons(false)
      return
    }

    // Find the ScrollArea viewport element
    const findViewport = () => {
      return scrollAreaRef.current?.querySelector('[data-slot="scroll-area-viewport"]') as HTMLElement | null
    }

    let resizeObserver: ResizeObserver | null = null
    let viewport: HTMLElement | null = null
    let checkScrollable: (() => void) | null = null
    let timeoutId: NodeJS.Timeout | null = null
    let retryTimeoutId: NodeJS.Timeout | null = null

    const setupScrollDetection = () => {
      viewport = findViewport()
      if (!viewport) return false

      // Also check the content element
      const contentElement = viewport.querySelector('pre')
      if (!contentElement) return false

      checkScrollable = () => {
        if (!viewport) return
        const isScrollable = viewport.scrollHeight > viewport.clientHeight
        setShowScrollButtons(isScrollable)
      }

      // Check initially
      checkScrollable()
      
      // Use ResizeObserver to detect when content size changes
      // Observe both viewport and content element
      resizeObserver = new ResizeObserver(checkScrollable)
      resizeObserver.observe(viewport)
      resizeObserver.observe(contentElement)

      // Also check on scroll to handle dynamic content
      viewport.addEventListener('scroll', checkScrollable)

      return true
    }

    // Try to setup immediately
    if (!setupScrollDetection()) {
      // If not found, try after a delay
      timeoutId = setTimeout(() => {
        if (!setupScrollDetection()) {
          // One more retry
          retryTimeoutId = setTimeout(() => {
            setupScrollDetection()
          }, 300)
        }
      }, 100)
    }

    return () => {
      if (timeoutId) clearTimeout(timeoutId)
      if (retryTimeoutId) clearTimeout(retryTimeoutId)
      if (resizeObserver) resizeObserver.disconnect()
      if (viewport && checkScrollable) {
        viewport.removeEventListener('scroll', checkScrollable)
      }
    }
  }, [buildLogs, logsSearch])

  if (deploymentLoading) {
    return (
      <WizardLayout
        title="Deployment Details"
        fullscreen
        useSidebar={false}
        fallbackPath={`/projects/${projectId}/functions/${functionId}`}
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
        fallbackPath={`/projects/${projectId}/functions/${functionId}`}
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
          {func?.name && (
            <>
              {' '}
              <span className="text-muted-foreground hidden sm:inline">for</span>{' '}
              <Link
                to="/projects/$projectId/functions/$functionId"
                params={{ projectId: projectId!, functionId: functionId! }}
                className="text-primary hover:underline font-medium"
              >
                {func.name}
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
                    to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                    params: {
                      projectId: projectId!,
                      functionId: functionId!,
                      deploymentId: previousDeployment.$id,
                    },
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
                    to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                    params: {
                      projectId: projectId!,
                      functionId: functionId!,
                      deploymentId: nextDeployment.$id,
                    },
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
                      (deployment.buildSize || 0) +
                        (deployment.sourceSize || 0),
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

                {/* Runtime */}
                {func?.runtime && (
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">Runtime</span>
                    <div className="flex items-center gap-1.5 min-w-0">
                      <RuntimeIcon runtime={func.runtime} size="sm" className="h-3.5 w-3.5 shrink-0" />
                      <span className="text-[12px] sm:text-[13px] font-mono font-medium text-foreground truncate">
                        {runtimeName}
                      </span>
                    </div>
                  </div>
                )}

                {/* Type */}
                <div className="flex items-center gap-2">
                  <span className="text-[13px] text-muted-foreground">Type</span>
                  <span className="text-[13px] font-medium text-foreground">
                    {deployment.type === 'cli'
                      ? 'CLI'
                      : deployment.type === 'manual'
                        ? 'Manual'
                        : deployment.type || 'N/A'}
                  </span>
                </div>

                {/* Build duration and Status - at end */}
                {(deployment.buildDuration || statusBadge) && (
                  <div className="flex items-center gap-3 sm:gap-6 ml-auto">
                    {deployment.buildDuration && (
                      <div className="flex items-center gap-2">
                        <span className="text-[12px] sm:text-[13px] text-muted-foreground">Duration</span>
                        <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                          {formatDuration(deployment.buildDuration)}
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
                  </div>
                )}
              </div>
            </div>
          </div>
          {/* Search - Part of Header */}
          <div className="border-t border-border px-4 sm:px-6 py-3">
            <TooltipProvider delayDuration={0}>
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
      onClose={() => {
        navigate({
          to: '/projects/$projectId/functions/$functionId',
          params: { projectId: projectId!, functionId: functionId! },
        })
      }}
      contentClassName="flex flex-col h-full min-h-0 overflow-hidden -mx-6 -my-6"
      footer={
        <div className="hidden sm:flex flex-row items-center justify-between gap-2 w-full">
          {/* Left side - Delete button */}
          <div className="flex items-center">
            {!isActiveDeployment && (
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setDeleteDialogOpen(true)}
                className="h-9 text-[13px]"
              >
                <Trash2 className="mr-1.5 h-4 w-4" />
                Delete
              </Button>
            )}
          </div>
          
          {/* Right side - Individual buttons */}
          <div className="flex items-center gap-2 ml-auto">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                navigate({
                  to: '/projects/$projectId/functions/$functionId/executions',
                  params: {
                    projectId: projectId!,
                    functionId: functionId!,
                  },
                  search: { deploymentId },
                })
              }}
              className="h-9 text-[13px]"
            >
              <ExternalLink className="mr-1.5 h-4 w-4" />
              Executions
              {executionsData?.total !== undefined && executionsData.total > 0 && (
                <span className="ml-1.5 text-xs text-muted-foreground">
                  ({executionsData.total})
                </span>
              )}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleDownloadDeployment}
              className="h-9 text-[13px]"
            >
              <Download className="mr-1.5 h-4 w-4" />
              Download
            </Button>
            {!isActiveDeployment && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  // TODO: Implement activate deployment
                  toast.info('Activate deployment functionality coming soon')
                }}
                className="h-9 text-[13px]"
              >
                <Play className="mr-1.5 h-4 w-4" />
                Activate
              </Button>
            )}
          </div>

        </div>
      }
    >
      <div className="flex flex-col flex-1 min-h-0 overflow-hidden relative">
        {/* Build Logs */}
        {buildLogs ? (
          <div ref={scrollAreaRef} className="flex-1 min-h-0 w-full">
            <ScrollArea className="h-full w-full">
              <div className="px-4 sm:px-6 py-4 min-w-0">
                <pre className="text-[11px] sm:text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                  {parsedLogs}
                </pre>
              </div>
            </ScrollArea>
          </div>
        ) : (
          <div className="px-4 sm:px-6 py-4 text-[12px] sm:text-[13px] text-muted-foreground">
            No build logs available.
          </div>
        )}

        {/* Scroll to Top/Bottom Buttons */}
        {showScrollButtons && buildLogs && (
          <div className="absolute bottom-16 sm:bottom-4 right-4 flex flex-col gap-2 z-50">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleScrollToTop}
                  className="h-8 w-8 p-0 shadow-lg bg-background/95 backdrop-blur-sm border-border hover:bg-muted"
                >
                  <ArrowUp className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Scroll to top</TooltipContent>
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleScrollToBottom}
                  className="h-8 w-8 p-0 shadow-lg bg-background/95 backdrop-blur-sm border-border hover:bg-muted"
                >
                  <ArrowDown className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Scroll to bottom</TooltipContent>
            </Tooltip>
          </div>
        )}
      </div>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete deployment</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this deployment? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="rounded-lg border border-border bg-muted/30 p-3">
              <div className="flex items-center gap-2">
                <CopyableId id={deployment.$id} size="xs" />
                {deployment.providerCommitHash && (
                  <span className="text-[11px] text-muted-foreground font-mono">
                    {deployment.providerCommitHash.slice(0, 7)}
                  </span>
                )}
              </div>
            </div>
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
              {deleteMutation.isPending ? (
                <>
                  <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                  Deleting...
                </>
              ) : (
                'Delete'
              )}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </WizardLayout>
  )
}
