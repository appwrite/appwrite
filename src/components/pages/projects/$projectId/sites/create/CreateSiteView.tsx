/**
 * Create Site View Component
 *
 * Combined entry point for site creation with 50/50 grid:
 * - Left: Templates gallery
 * - Right: Repository import
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  CreateWizardLeftColumn,
  CreateWizardRightColumn,
} from '@/components/global/shared/CreateWizardColumns'
import {
  Pagination,
  SimplePagination,
} from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  Search,
  Lock,
  Plus,
  LayoutTemplate,
  RefreshCw,
  ChevronsUpDown,
} from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepositories,
  useSiteTemplates,
  useProject,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'

// Provider icons
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

function GitLabIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z" />
    </svg>
  )
}

function BitbucketIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M.778 1.213a.768.768 0 0 0-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 0 0 .77-.646l3.27-20.03a.768.768 0 0 0-.768-.891zM14.52 15.53H9.522L8.17 8.466h7.561z" />
    </svg>
  )
}

function ProviderIcon({
  provider,
  className,
}: {
  provider?: string
  className?: string
}) {
  const normalizedProvider = provider?.toLowerCase() || 'github'
  switch (normalizedProvider) {
    case 'gitlab':
      return <GitLabIcon className={className} />
    case 'bitbucket':
      return <BitbucketIcon className={className} />
    default:
      return <GitHubIcon className={className} />
  }
}

// Helper to safely extract framework string
function getFrameworkString(framework: unknown): string {
  if (!framework) return ''
  if (typeof framework === 'string') return framework
  if (typeof framework === 'object' && framework !== null) {
    const obj = framework as Record<string, unknown>
    if (typeof obj.key === 'string') return obj.key
    if (typeof obj.name === 'string') return obj.name
    if (typeof obj.id === 'string') return obj.id
  }
  return ''
}

// Repository skeleton - single line layout
function RepositorySkeleton({
  index = 0,
  provider,
}: {
  index?: number
  provider?: string
}) {
  const nameWidths = ['w-28', 'w-36', 'w-32', 'w-24', 'w-40']
  const dateWidths = ['w-14', 'w-16', 'w-12', 'w-18', 'w-14']

  return (
    <div className="flex w-full items-center gap-3 px-4 py-3.5">
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
        <ProviderIcon provider={provider} className="h-3.5 w-3.5" />
      </div>
      <div className="flex-1 min-w-0 flex items-center gap-2">
        <Skeleton
          className={cn('h-3.5', nameWidths[index % nameWidths.length])}
        />
        <Skeleton
          className={cn('h-3 shrink-0', dateWidths[index % dateWidths.length])}
        />
      </div>
      <Skeleton className="h-7 w-[68px] shrink-0 rounded-md" />
    </div>
  )
}

// Template skeleton
function TemplateSkeleton() {
  return (
    <div className="h-[180px] rounded-2xl border border-border bg-card overflow-hidden flex flex-col">
      <div className="px-3 pt-3 pb-1.5 h-[80px]">
        <Skeleton className="h-3.5 w-24 mb-1.5" />
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-3/4 mt-0.5" />
      </div>
      <div className="relative flex-1 overflow-hidden">
        <div className="absolute inset-x-3 top-4 transform -rotate-3">
          <Skeleton className="w-full h-[120px] rounded-lg" />
        </div>
      </div>
    </div>
  )
}

// Fade-in image component
function FadeImage({
  src,
  alt,
  className,
}: {
  src: string
  alt: string
  className?: string
}) {
  const [loaded, setLoaded] = useState(false)

  return (
    <img
      src={src}
      alt={alt}
      className={cn(
        className,
        'transition-opacity duration-300',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
      onLoad={() => setLoaded(true)}
    />
  )
}

const REPO_PAGE_SIZE = 7
const DEFAULT_TEMPLATE_PAGE_SIZE = 9

// Use case options for template filtering (must match API enum values)
const USE_CASE_OPTIONS = [
  { value: 'all', label: 'All use cases' },
  { value: 'starter', label: 'Starter' },
  { value: 'ai', label: 'AI' },
  { value: 'databases', label: 'Databases' },
  { value: 'messaging', label: 'Messaging' },
  { value: 'dev-tools', label: 'Dev tools' },
  { value: 'utilities', label: 'Utilities' },
]

export function CreateSiteView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { theme, resolvedTheme } = useTheme()
  const { installations, frameworks, updateFormData, setCurrentPath } =
    useWizard()

  // Get project for region/endpoint
  const { project } = useProject(projectId)

  // Get project endpoint for VCS authorization (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  // Repository state
  const [selectedInstallationId, setSelectedInstallationId] =
    useState<string>('')

  // Build GitHub authorization URL with proper redirect (includes current installation ID)
  const getGitHubAuthUrl = useMemo(() => {
    if (typeof window === 'undefined' || !projectId) return '#'
    const origin = window.location.origin
    // Include current installation ID in redirect so we can restore selection
    let redirectUrl = `${origin}/projects/${projectId}/sites/create`
    if (selectedInstallationId) {
      redirectUrl += `?installation=${selectedInstallationId}`
    }
    const successUrl = encodeURIComponent(redirectUrl)
    const failureUrl = encodeURIComponent(redirectUrl)
    return `${projectEndpoint}/vcs/github/authorize?project=${projectId}&success=${successUrl}&failure=${failureUrl}&mode=admin`
  }, [projectEndpoint, projectId, selectedInstallationId])

  const [repoSearch, setRepoSearch] = useState('')
  const [debouncedRepoSearch, setDebouncedRepoSearch] = useState('')
  const [repoPage, setRepoPage] = useState(1)

  // Template state (requestedPage drives fetch; displayedPage stays until new page is ready - no-flash pagination)
  const [templateSearch, setTemplateSearch] = useState('')
  const [debouncedTemplateSearch, setDebouncedTemplateSearch] = useState('')
  const [templateRequestedPage, setTemplateRequestedPage] = useState(1)
  const [templateDisplayedPage, setTemplateDisplayedPage] = useState(1)
  const [templatePageSize, setTemplatePageSize] = useState(
    DEFAULT_TEMPLATE_PAGE_SIZE,
  )
  const [selectedFramework, setSelectedFramework] = useState<string>('all')
  const [selectedUseCase, setSelectedUseCase] = useState<string>('all')
  const [useCaseOpen, setUseCaseOpen] = useState(false)
  const [frameworkOpen, setFrameworkOpen] = useState(false)

  // Set current path
  useEffect(() => {
    setCurrentPath('repository')
  }, [setCurrentPath])

  // Initialize selected installation - prioritize recently created, then URL param, then first
  useEffect(() => {
    if (installations.length > 0 && !selectedInstallationId) {
      // Check for recently created installation (within last 30s)
      const twoMinutesAgo = new Date(Date.now() - 30 * 1000)
      const recentInstallation = installations.find((inst) => {
        const createdAt = new Date(inst.$createdAt)
        return createdAt > twoMinutesAgo
      })

      if (recentInstallation) {
        // Select the most recently created installation
        setSelectedInstallationId(recentInstallation.$id)
        return
      }

      // Check URL for installation parameter (restored from GitHub auth redirect)
      if (typeof window !== 'undefined') {
        const urlParams = new URLSearchParams(window.location.search)
        const installationFromUrl = urlParams.get('installation')
        if (
          installationFromUrl &&
          installations.some((inst) => inst.$id === installationFromUrl)
        ) {
          setSelectedInstallationId(installationFromUrl)
          return
        }
      }

      // Default to first installation
      setSelectedInstallationId(installations[0].$id)
    }
  }, [installations, selectedInstallationId])

  // Debounce repo search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedRepoSearch(repoSearch)
      setRepoPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [repoSearch])

  // Debounce template search (reset both pages on search change)
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedTemplateSearch(templateSearch)
      setTemplateRequestedPage(1)
      setTemplateDisplayedPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [templateSearch])

  // Theme for screenshots
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return resolvedTheme === 'dark' || theme === 'dark'
  }, [theme, resolvedTheme])

  // Fetch repositories
  const {
    data: repositoriesData,
    isLoading: reposLoading,
    isFetching: reposFetching,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    VCSDetectionType.Framework,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
  )

  const repositories = useMemo(() => {
    return repositoriesData?.frameworkProviderRepositories || []
  }, [repositoriesData])

  const hasMoreRepos = repositories.length === REPO_PAGE_SIZE

  // Fetch templates: requested page (triggers load) and displayed page (what we show until new page is ready)
  const frameworkFilter =
    selectedFramework !== 'all' ? [selectedFramework] : undefined
  const useCaseFilter =
    selectedUseCase !== 'all' ? [selectedUseCase] : undefined
  const { isLoading: templatesLoading, isFetching: templatesFetching } =
    useSiteTemplates(
      projectId,
      frameworkFilter,
      useCaseFilter,
      templatePageSize,
      (templateRequestedPage - 1) * templatePageSize,
    )

  const {
    templates: displayedTemplates,
    total: templatesTotal,
    isLoading: templatesDisplayedLoading,
  } = useSiteTemplates(
    projectId,
    frameworkFilter,
    useCaseFilter,
    templatePageSize,
    (templateDisplayedPage - 1) * templatePageSize,
  )

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !templatesFetching &&
      templateRequestedPage !== templateDisplayedPage &&
      !templatesLoading
    ) {
      setTemplateDisplayedPage(templateRequestedPage)
    }
  }, [
    templatesFetching,
    templatesLoading,
    templateRequestedPage,
    templateDisplayedPage,
  ])

  // Only show full loading when we have no data to display (initial load)
  const showTemplatesLoading =
    templatesDisplayedLoading && displayedTemplates.length === 0

  // Filter templates by search (client-side since API may not support text search)
  const filteredTemplates = useMemo(() => {
    if (!debouncedTemplateSearch.trim()) return displayedTemplates
    const searchLower = debouncedTemplateSearch.toLowerCase()
    return displayedTemplates.filter(
      (t) =>
        t.name.toLowerCase().includes(searchLower) ||
        (t.tagline && t.tagline.toLowerCase().includes(searchLower)),
    )
  }, [displayedTemplates, debouncedTemplateSearch])

  // Get unique frameworks from the wizard context for the filter
  const frameworkOptions = useMemo(() => {
    const options = [{ value: 'all', label: 'All frameworks' }]
    if (frameworks && frameworks.length > 0) {
      frameworks.forEach((fw: unknown) => {
        const key = typeof fw === 'string' ? fw : fw.key || fw.name || fw.id
        const name = typeof fw === 'string' ? fw : fw.name || fw.key || fw.id
        if (key && name) {
          options.push({ value: key, label: name })
        }
      })
    }
    return options
  }, [frameworks])

  const hasInstallations = installations.length > 0
  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )

  const handleSelectRepository = (repo: unknown) => {
    const installationId = selectedInstallationId!
    const providerRepositoryId = repo.id
    updateFormData({
      installationId,
      providerRepositoryId,
      repositoryOwner: repo.organization,
      repositoryName: repo.name,
      repositoryUrl: repo.url,
      siteName: repo.name,
    })

    navigate({
      to: '/projects/$projectId/sites/create/repositories/$installationId/$repositoryId',
      params: {
        projectId: projectId!,
        installationId,
        repositoryId: providerRepositoryId,
      },
    })
  }

  const handleSelectTemplate = (template: Models.TemplateSite) => {
    updateFormData({
      templateId: template.key,
      template,
      siteName: template.name,
      framework: getFrameworkString(template.frameworks?.[0]),
    })

    navigate({
      to: '/projects/$projectId/sites/create/templates/$template',
      params: {
        projectId: projectId!,
        template: encodeURIComponent(template.key),
      },
    })
  }

  const getScreenshotUrl = (template: Models.TemplateSite) => {
    return isDark ? template.screenshotDark : template.screenshotLight
  }

  return (
    <WizardLayout
      title="Create site"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-[1400px]"
    >
      <div className="grid gap-12 lg:grid-cols-5">
        <CreateWizardLeftColumn title="Import repository">
          {!hasInstallations ? (
            <div className="rounded-lg border border-border bg-card/50 p-6 text-center">
              <div className="flex justify-center mb-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <GitHubIcon className="h-5 w-5 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-[13px] font-medium text-foreground mb-1">
                Connect Git provider
              </h3>
              <p className="text-[11px] text-muted-foreground mb-3">
                Import repositories for automatic deployments
              </p>
              <Button size="sm" asChild>
                <a href={getGitHubAuthUrl}>
                  <GitHubIcon className="mr-1.5 h-3.5 w-3.5" />
                  Connect GitHub
                </a>
              </Button>
            </div>
          ) : (
            <div className="flex flex-1 flex-col">
              {/* Controls */}
              <div className="flex items-center gap-2 mb-4">
                <Select
                  value={selectedInstallationId}
                  onValueChange={(value) => {
                    setSelectedInstallationId(value)
                    setRepoPage(1)
                  }}
                >
                  <SelectTrigger className="w-[180px] h-9 text-[13px]">
                    <SelectValue placeholder="Select organization">
                      {selectedInstallation && (
                        <span className="flex items-center gap-2">
                          <ProviderIcon
                            provider={selectedInstallation.provider}
                            className="h-4 w-4 shrink-0"
                          />
                          <span className="truncate">
                            {selectedInstallation.organization}
                          </span>
                        </span>
                      )}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    {installations.map((installation) => (
                      <SelectItem
                        key={installation.$id}
                        value={installation.$id}
                      >
                        <span className="flex items-center gap-2">
                          <ProviderIcon
                            provider={installation.provider}
                            className="h-4 w-4 shrink-0"
                          />
                          <span>{installation.organization}</span>
                        </span>
                      </SelectItem>
                    ))}
                    <div className="border-t border-border mt-1 pt-1">
                      <a
                        href={getGitHubAuthUrl}
                        className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                      >
                        <Plus className="h-3 w-3" />
                        Add account
                      </a>
                    </div>
                  </SelectContent>
                </Select>

                <div className="relative flex-1">
                  <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    value={repoSearch}
                    onChange={(e) => setRepoSearch(e.target.value)}
                    placeholder="Search..."
                    className="h-9 pl-9 text-[13px]"
                  />
                </div>

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => refetchRepos()}
                  disabled={reposFetching}
                  className="h-9 w-9 p-0 shrink-0"
                >
                  <RefreshCw
                    className={cn('h-4 w-4', reposFetching && 'animate-spin')}
                  />
                </Button>
              </div>

              {/* Repository list */}
              <div className="rounded-lg border border-border overflow-hidden mb-4">
                {reposLoading ? (
                  <div className="divide-y divide-border">
                    {Array.from({ length: REPO_PAGE_SIZE }).map((_, i) => (
                      <RepositorySkeleton
                        key={i}
                        index={i}
                        provider={selectedInstallation?.provider}
                      />
                    ))}
                  </div>
                ) : repositories.length > 0 ? (
                  <div
                    className={cn(
                      'divide-y divide-border',
                      reposFetching && 'opacity-60 pointer-events-none',
                    )}
                  >
                    {repositories.map((repo: unknown) => (
                      <div
                        key={repo.id}
                        className="flex w-full items-center gap-3 px-4 py-3.5 hover:bg-accent/50 transition-colors"
                      >
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
                          {repo.framework ? (
                            <FrameworkIcon
                              framework={repo.framework}
                              size="sm"
                            />
                          ) : (
                            <ProviderIcon
                              provider={selectedInstallation?.provider}
                              className="h-3.5 w-3.5"
                            />
                          )}
                        </div>
                        <div className="flex-1 min-w-0 flex items-center gap-2">
                          <span className="text-[13px] font-medium text-foreground truncate">
                            {repo.name}
                          </span>
                          {repo.private && (
                            <Lock className="h-3 w-3 shrink-0 text-muted-foreground/70" />
                          )}
                          {repo.pushedAt && (
                            <span className="text-[11px] text-muted-foreground shrink-0">
                              <DateTooltip date={repo.pushedAt} />
                            </span>
                          )}
                        </div>
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-7 text-[12px] shrink-0"
                          onClick={() => handleSelectRepository(repo)}
                        >
                          Connect
                        </Button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="py-8 text-center">
                    <p className="text-[12px] text-muted-foreground">
                      {repoSearch
                        ? 'No repositories found'
                        : 'No repositories available'}
                    </p>
                  </div>
                )}
              </div>

              {/* Pagination */}
              <SimplePagination
                currentPage={repoPage}
                hasMore={hasMoreRepos}
                onPageChange={setRepoPage}
                disabled={reposFetching}
              />

              {/* Help note for missing repos */}
              <div className="mt-8 rounded-lg border border-border bg-muted/30 px-4 py-4">
                <p className="text-[14px] font-semibold text-foreground leading-tight mb-1.5">
                  Can't find a repository?
                </p>
                <p className="text-[12px] text-muted-foreground leading-snug mb-3">
                  If you selected specific repositories during setup, you may
                  need to update your GitHub permissions to include additional
                  ones.
                </p>
                <a
                  href={getGitHubAuthUrl}
                  className="inline-flex items-center gap-1.5 text-[12px] font-medium text-primary hover:underline"
                >
                  <GitHubIcon className="h-3.5 w-3.5" />
                  Update GitHub permissions
                </a>
              </div>
            </div>
          )}
        </CreateWizardLeftColumn>

        <CreateWizardRightColumn title="Clone template">
          <div className="flex items-center gap-2 flex-wrap">
            {/* Search */}
            <div className="relative flex-1 min-w-[140px]">
              <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
              <Input
                value={templateSearch}
                onChange={(e) => setTemplateSearch(e.target.value)}
                placeholder="Search templates..."
                className="h-9 pl-9 text-[13px]"
              />
            </div>
            {/* Use case filter */}
            <Popover
              open={useCaseOpen}
              onOpenChange={setUseCaseOpen}
              modal={true}
            >
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  role="combobox"
                  aria-expanded={useCaseOpen}
                  className="w-[150px] h-9 justify-between text-[13px] font-normal"
                >
                  {USE_CASE_OPTIONS.find((opt) => opt.value === selectedUseCase)
                    ?.label || 'All use cases'}
                  <ChevronsUpDown className="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent
                className="w-[200px] p-0 z-[100]"
                align="start"
                sideOffset={4}
              >
                <Command>
                  <CommandInput
                    placeholder="Search use cases..."
                    className="h-9"
                  />
                  <CommandList>
                    <CommandEmpty>No use case found.</CommandEmpty>
                    <CommandGroup>
                      {USE_CASE_OPTIONS.map((option) => (
                        <CommandItem
                          key={option.value}
                          value={option.value}
                          onSelect={() => {
                            setSelectedUseCase(option.value)
                            setTemplateRequestedPage(1)
                            setTemplateDisplayedPage(1)
                            setUseCaseOpen(false)
                          }}
                        >
                          {option.label}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>

            {/* Framework filter */}
            <Popover
              open={frameworkOpen}
              onOpenChange={setFrameworkOpen}
              modal={true}
            >
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  role="combobox"
                  aria-expanded={frameworkOpen}
                  className="w-[160px] h-9 justify-between text-[13px] font-normal"
                >
                  <span className="flex items-center gap-2 truncate">
                    {selectedFramework !== 'all' && (
                      <FrameworkIcon framework={selectedFramework} size="sm" />
                    )}
                    <span className="capitalize truncate">
                      {frameworkOptions.find(
                        (opt) => opt.value === selectedFramework,
                      )?.label || 'All frameworks'}
                    </span>
                  </span>
                  <ChevronsUpDown className="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent
                className="w-[220px] p-0 z-[100]"
                align="start"
                sideOffset={4}
              >
                <Command>
                  <CommandInput
                    placeholder="Search frameworks..."
                    className="h-9"
                  />
                  <CommandList>
                    <CommandEmpty>No framework found.</CommandEmpty>
                    <CommandGroup>
                      {frameworkOptions.map((option) => (
                        <CommandItem
                          key={option.value}
                          value={option.label}
                          onSelect={() => {
                            setSelectedFramework(option.value)
                            setTemplateRequestedPage(1)
                            setTemplateDisplayedPage(1)
                            setFrameworkOpen(false)
                          }}
                        >
                          {option.value !== 'all' && (
                            <FrameworkIcon
                              framework={option.value}
                              size="sm"
                              className="mr-2"
                            />
                          )}
                          <span className="capitalize">{option.label}</span>
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
          </div>

          {/* Templates grid */}
          {showTemplatesLoading ? (
            <div className="grid gap-4 grid-cols-3">
              {Array.from({ length: templatePageSize }).map((_, i) => (
                <TemplateSkeleton key={i} />
              ))}
            </div>
          ) : filteredTemplates.length > 0 ? (
            <div
              className={cn(
                'grid gap-4 grid-cols-3',
                templatesFetching && 'opacity-60 pointer-events-none',
              )}
            >
              {filteredTemplates.map((template) => {
                const screenshotUrl = getScreenshotUrl(template)
                return (
                  <button
                    key={template.key}
                    onClick={() => handleSelectTemplate(template)}
                    className="group h-[180px] text-left rounded-2xl border border-border bg-card overflow-hidden transition-all cursor-pointer hover:border-border/80 flex flex-col"
                  >
                    <div className="px-4 pt-4 pb-2 h-[80px]">
                      <h3 className="text-[14px] font-semibold text-foreground leading-tight line-clamp-1 group-hover:text-primary transition-colors">
                        {template.name}
                      </h3>
                      {template.tagline && (
                        <p className="text-[12px] text-muted-foreground line-clamp-2 mt-1.5 leading-snug">
                          {template.tagline}
                        </p>
                      )}
                    </div>
                    {/* Screenshot slot: aspect-video reserves space to avoid layout shift when image loads */}
                    <div className="relative flex-1 min-h-0 overflow-hidden">
                      {screenshotUrl ? (
                        <div className="absolute left-8 -right-4 top-4 aspect-video transform -rotate-3 transition-transform group-hover:-rotate-2">
                          <div className="relative h-full w-full overflow-hidden rounded-lg ring-1 ring-border bg-muted/30">
                            <FadeImage
                              src={screenshotUrl}
                              alt={template.name}
                              className="absolute inset-0 h-full w-full object-cover object-top"
                            />
                          </div>
                        </div>
                      ) : (
                        <div className="absolute left-8 -right-4 top-4 aspect-video transform -rotate-3 flex items-center justify-center rounded-lg bg-muted/50 ring-1 ring-border">
                          <LayoutTemplate className="h-8 w-8 text-muted-foreground/30" />
                        </div>
                      )}
                    </div>
                  </button>
                )
              })}
            </div>
          ) : (
            <div className="py-8 text-center">
              <p className="text-[12px] text-muted-foreground">
                {templateSearch
                  ? 'No templates found'
                  : 'No templates available'}
              </p>
            </div>
          )}

          {/* Template pagination */}
          {templatesTotal > templatePageSize && (
            <Pagination
              currentPage={templateDisplayedPage}
              totalItems={templatesTotal}
              pageSize={templatePageSize}
              pageSizeOptions={[12, 18, 36, 72]}
              onPageChange={setTemplateRequestedPage}
              onPageSizeChange={(size) => {
                setTemplatePageSize(size)
                setTemplateRequestedPage(1)
                setTemplateDisplayedPage(1)
              }}
            />
          )}
        </CreateWizardRightColumn>
      </div>

      {/* Manual upload footnote */}
      <div className="mt-6 pt-6 border-t border-border">
        <p className="text-[12px] text-muted-foreground">
          Want to deploy without connecting a repository or using a template?{' '}
          <Link
            to="/projects/$projectId/sites/create/manual"
            params={{ projectId: projectId! }}
            className="text-foreground hover:underline"
          >
            Upload your website manually
          </Link>
        </p>
      </div>
    </WizardLayout>
  )
}
