/**
 * Create Site View Component
 *
 * Combined entry point for site creation with 50/50 grid:
 * - Left: Templates gallery
 * - Right: Repository import
 */

import { useState, useEffect, useMemo, useRef } from 'react'
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
import { Search, Lock, GitBranch } from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepositories,
  useProject,
  useNamespacesForInstallations,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { VcsInstallationErrorState } from '@/components/global/shared/VcsInstallationError'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import {
  getKnownVcsProvider,
  buildVcsAuthUrl,
  VcsIcon,
  VCS_PROVIDERS,
  buildVcsOrgOptions,
  type VcsProviderId,
} from '@/lib/vcs/providers'

const REPO_PAGE_SIZE = 7
const DEFAULT_TEMPLATE_PAGE_SIZE = 9

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
        <VcsIcon type={provider} className="h-3.5 w-3.5" />
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

  // Build VCS authorization URL with proper redirect (includes current installation ID)
  const getVcsAuthUrl = useMemo(() => {
    return (
      provider: VcsProviderId = 'github',
      mode: 'create' | 'update' = 'create',
    ) => {
      void mode // this redirect doesn't distinguish create/update, unlike Overview.tsx
      if (typeof window === 'undefined' || !projectId) return '#'
      const origin = window.location.origin
      // Include current installation ID in redirect so we can restore selection
      let redirectUrl = `${origin}/projects/${projectId}/sites/create`
      if (selectedInstallationId) {
        redirectUrl += `?installation=${selectedInstallationId}`
      }
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectEndpoint, projectId, selectedInstallationId])
  const getGitHubAuthUrl = getVcsAuthUrl('github')

  const [repoSearch, setRepoSearch] = useState('')
  const [debouncedRepoSearch, setDebouncedRepoSearch] = useState('')
  const [repoPage, setRepoPage] = useState(1)

  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )

  // Personal namespace vs. group selected within a GitLab installation.
  const [selectedNamespace, setSelectedNamespace] = useState('')

  const { namespacesByInstallation } = useNamespacesForInstallations(
    projectId,
    installations,
  )
  const orgOptions = useMemo(
    () => buildVcsOrgOptions(installations, namespacesByInstallation),
    [installations, namespacesByInstallation],
  )
  const selectedOptionKey = selectedNamespace
    ? `${selectedInstallationId}:${selectedNamespace}`
    : selectedInstallationId
  const selectedOption = orgOptions.find((o) => o.key === selectedOptionKey)

  // Whenever the selected installation changes (including on initial load)
  // or its namespaces finish loading, make sure the namespace is a valid
  // row for that installation -- an installation with multiple namespaces
  // has no bare-installationId row, so without this the picker would show
  // blank until the user manually picks one.
  useEffect(() => {
    if (!selectedInstallationId) {
      if (selectedNamespace) setSelectedNamespace('')
      return
    }
    const isValid = orgOptions.some((o) => o.key === selectedOptionKey)
    if (!isValid) {
      const firstForInstallation = orgOptions.find(
        (o) => o.installationId === selectedInstallationId,
      )
      setSelectedNamespace(firstForInstallation?.providerNamespace ?? '')
    }
  }, [selectedInstallationId, orgOptions, selectedOptionKey, selectedNamespace])

  // Filter the combined org list client-side -- it's already fully loaded
  // (every installation's namespaces are fetched up front), so there's no
  // need for a server round-trip just to narrow a list this size.
  const [orgFilter, setOrgFilter] = useState('')
  const filteredOrgOptions = useMemo(
    () =>
      orgFilter.trim()
        ? orgOptions.filter((o) =>
            o.label.toLowerCase().includes(orgFilter.trim().toLowerCase()),
          )
        : orgOptions,
    [orgOptions, orgFilter],
  )

  const selectOption = (key: string) => {
    const option = orgOptions.find((o) => o.key === key)
    if (!option) return
    setSelectedInstallationId(option.installationId)
    setSelectedNamespace(option.providerNamespace ?? '')
    setRepoPage(1)
  }

  // Set current path
  useEffect(() => {
    setCurrentPath('repository')
  }, [setCurrentPath])

  // Initialize selected installation - prioritize recently created, then URL param, then first.
  // Runs only once, so a later intentional clear isn't silently re-defaulted.
  const hasAutoSelectedInstallation = useRef(false)
  useEffect(() => {
    if (
      installations.length > 0 &&
      !selectedInstallationId &&
      !hasAutoSelectedInstallation.current
    ) {
      hasAutoSelectedInstallation.current = true

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
    error: reposError,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    VCSDetectionType.Framework,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
    selectedNamespace || undefined,
  )

  const repositories = useMemo(() => {
    return repositoriesData?.frameworkProviderRepositories || []
  }, [repositoriesData])

  const hasMoreRepos = repositories.length === REPO_PAGE_SIZE

  const hasInstallations = installations.length > 0

  // An installation that can no longer authenticate returns nothing, which is
  // indistinguishable here from an organization that genuinely has no repos.
  // Read the error so the list stops reporting the failure as a valid result.
  const reposErrorKind = getVcsInstallationErrorKind(reposError)

  // Reconnecting is a full-page redirect and the wizard keeps its state in
  // memory, so come back to this step with the installation still selected
  // rather than to whatever deep URL the browser happens to be on.
  const reconnectReturnUrl = useMemo(() => {
    if (typeof window === 'undefined' || !projectId) return undefined
    const base = `${window.location.origin}/projects/${projectId}/sites/create`
    return selectedInstallationId
      ? `${base}?installation=${selectedInstallationId}`
      : base
  }, [projectId, selectedInstallationId])

  const { reconnectUrl } = useVcsInstallationReconnect(
    projectId,
    selectedInstallationId || null,
    reconnectReturnUrl,
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
                  <GitBranch className="h-5 w-5 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-[13px] font-medium text-foreground mb-1">
                {t('Connect Git provider')}
              </h3>
              <p className="text-[11px] text-muted-foreground mb-3">
                {t('Import repositories for automatic deployments')}
              </p>
              <div className="flex flex-wrap items-center justify-center gap-2">
                <Button size="sm" variant="secondary" asChild>
                  <a href={getGitHubAuthUrl}>
                    <VcsIcon type="github" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect GitHub')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('gitlab')}>
                    <VcsIcon type="gitlab" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect GitLab')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('bitbucket')}>
                    <VcsIcon type="bitbucket" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect Bitbucket')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('origin')}>
                    <VcsIcon type="origin" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect Origin')}
                  </a>
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex flex-1 flex-col">
              {/* Controls */}
              <div className="mb-4 flex items-center gap-2">
                <Select
                  value={selectedOptionKey}
                  onValueChange={(key) => selectOption(key)}
                  onOpenChange={(open) => {
                    if (!open) setOrgFilter('')
                  }}
                >
                  <SelectTrigger className="w-[200px] h-9 text-[13px]">
                    <SelectValue placeholder={t('Select organization')}>
                      {selectedOption && (
                        <span className="flex items-center gap-2">
                          <VcsIcon
                            type={selectedOption.provider}
                            className="h-4 w-4 shrink-0"
                          />
                          <span className="truncate">
                            {selectedOption.label}
                          </span>
                        </span>
                      )}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    <div
                      className="px-1 pb-1 mb-1 border-b border-border"
                      onKeyDown={(e) => e.stopPropagation()}
                    >
                      <div className="relative">
                        <Search className="absolute start-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                        <Input
                          value={orgFilter}
                          onChange={(e) => setOrgFilter(e.target.value)}
                          placeholder={t('Filter organizations...')}
                          className="h-8 ps-7 text-[12px]"
                        />
                      </div>
                    </div>
                    {filteredOrgOptions.length > 0 ? (
                      filteredOrgOptions.map((option) => (
                        <SelectItem key={option.key} value={option.key}>
                          <span className="flex items-center gap-2">
                            <VcsIcon
                              type={option.provider}
                              className="h-4 w-4 shrink-0"
                            />
                            <span>{option.label}</span>
                          </span>
                        </SelectItem>
                      ))
                    ) : (
                      <p className="px-2 py-1.5 text-[12px] text-muted-foreground">
                        {t('No matches')}
                      </p>
                    )}
                    <div className="border-t border-border mt-1 pt-1">
                      {Object.values(VCS_PROVIDERS).map((p) => (
                        <a
                          key={p.id}
                          href={getVcsAuthUrl(p.id)}
                          className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                        >
                          <p.Icon className="h-3 w-3" />
                          {t(`Add ${p.label} account`)}
                        </a>
                      ))}
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

                <RefreshButton
                  onClick={() => refetchRepos()}
                  isRefreshing={reposFetching}
                  tooltip={t('Refresh repositories')}
                />
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
                            <VcsIcon
                              type={selectedInstallation?.provider}
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
                ) : reposErrorKind ? (
                  <div className="py-8 px-4">
                    <VcsInstallationErrorState
                      kind={reposErrorKind}
                      provider={selectedInstallation?.provider}
                      organization={selectedInstallation?.organization}
                      reconnectUrl={reconnectUrl}
                      onRetry={() => refetchRepos()}
                      isRetrying={reposFetching}
                      className="py-0"
                    />
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
              {(() => {
                // A broken installation is not a scope problem; this note would
                // send the user to widen permissions that already cover the repos.
                if (reposErrorKind) return null
                const knownProvider = getKnownVcsProvider(
                  selectedInstallation?.provider,
                )
                if (!knownProvider) return null
                return (
                  <div className="mt-8 rounded-lg border border-border bg-muted/30 px-4 py-4">
                    <p className="text-[14px] font-semibold text-foreground leading-tight mb-1.5">
                      {t("Can't find a repository?")}
                    </p>
                    <p className="text-[12px] text-muted-foreground leading-snug mb-3">
                      {t(
                        'If you selected specific repositories during setup, you may need to update your permissions to include additional ones.',
                      )}
                    </p>
                    <a
                      href={getVcsAuthUrl(knownProvider.id, 'update')}
                      className="inline-flex items-center gap-1.5 text-[12px] link-neutral"
                    >
                      <VcsIcon
                        type={knownProvider.id}
                        className="h-3.5 w-3.5"
                      />
                      {t(`Update ${knownProvider.label} permissions`)}
                    </a>
                  </div>
                )
              })()}
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
