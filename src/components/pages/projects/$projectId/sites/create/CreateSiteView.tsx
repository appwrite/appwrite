/**
 * Create Site View Component
 *
 * Combined entry point for site creation with 50/50 grid:
 * - Left: Templates gallery
 * - Right: Repository import
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
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
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  CreateWizardLeftColumn,
  CreateWizardRightColumn,
} from '@/components/global/shared/CreateWizardColumns'
import { SimplePagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { SiteTemplateGallery } from '@/components/pages/projects/$projectId/sites/_components/SiteTemplateGallery'
import {
  Search,
  Lock,
  Plus,
  RefreshCw,
} from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepositories,
  useProject,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

const REPO_PAGE_SIZE = 7
const DEFAULT_TEMPLATE_PAGE_SIZE = 9

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

export function CreateSiteView() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { installations, updateFormData, setCurrentPath } = useWizard()

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

  return (
    <WizardLayout
      title={t('Create site')}
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-[1400px]"
    >
      <div className="grid gap-12 lg:grid-cols-5">
        <CreateWizardLeftColumn title={t('Import repository')}>
          {!hasInstallations ? (
            <div className="rounded-lg border border-border bg-card/50 p-6 text-center">
              <div className="flex justify-center mb-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <GitHubIcon className="h-5 w-5 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-[13px] font-medium text-foreground mb-1">
                {t('Connect Git provider')}
              </h3>
              <p className="text-[11px] text-muted-foreground mb-3">
                {t('Import repositories for automatic deployments')}
              </p>
              <Button size="sm" asChild>
                <a href={getGitHubAuthUrl}>
                  <GitHubIcon className="me-1.5 h-3.5 w-3.5" />
                  {t('Connect GitHub')}
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
                    <SelectValue placeholder={t('Select organization')}>
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
                        {t('Add account')}
                      </a>
                    </div>
                  </SelectContent>
                </Select>

                <div className="relative flex-1">
                  <Search className="absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    value={repoSearch}
                    onChange={(e) => setRepoSearch(e.target.value)}
                    placeholder={t('Search...')}
                    className="h-9 ps-9 text-[13px]"
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
                          {t('Connect')}
                        </Button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="py-8 text-center">
                    <p className="text-[12px] text-muted-foreground">
                      {repoSearch
                        ? t('No repositories found')
                        : t('No repositories available')}
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
                  {t("Can't find a repository?")}
                </p>
                <p className="text-[12px] text-muted-foreground leading-snug mb-3">
                  {t(
                    'If you selected specific repositories during setup, you may need to update your GitHub permissions to include additional ones.',
                  )}
                </p>
                <a
                  href={getGitHubAuthUrl}
                  className="inline-flex items-center gap-1.5 text-[12px] link-neutral"
                >
                  <GitHubIcon className="h-3.5 w-3.5" />
                  {t('Update GitHub permissions')}
                </a>
              </div>
            </div>
          )}
        </CreateWizardLeftColumn>

        <CreateWizardRightColumn title={t('Clone template')}>
          {projectId ? (
            <SiteTemplateGallery
              projectId={projectId}
              defaultPageSize={DEFAULT_TEMPLATE_PAGE_SIZE}
              pageSizeOptions={[12, 18, 36, 72]}
              onSelectTemplate={handleSelectTemplate}
            />
          ) : null}
        </CreateWizardRightColumn>
      </div>

      {/* Manual upload footnote */}
      <div className="mt-6 pt-6 border-t border-border">
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Want to deploy without connecting a repository or using a template?',
          )}{' '}
          <Link
            to="/projects/$projectId/sites/create/manual"
            params={{ projectId: projectId! }}
            className="link-neutral"
          >
            {t('Upload your website manually')}
          </Link>
        </p>
      </div>
    </WizardLayout>
  )
}
