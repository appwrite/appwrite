import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useQueries, useQuery } from '@tanstack/react-query'
import { GitPullRequest, Loader2, Plus } from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { VcsProviderButtons } from '@/components/global/shared/VcsProviderButtons'
import { GitInstallationReuseDialog } from '@/components/global/shared/GitInstallationReuseDialog'
import { useReusableVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { useParams } from '@tanstack/react-router'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  fetchRepositories,
  fetchRepository,
  REVIEW_REPOSITORIES_PAGE_SIZE,
  useConsoleVariables,
  useProject,
  useReviewedRepositories,
  useReviewRepositories,
  useReviewSummary,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import {
  buildVcsAuthUrl,
  getVisibleVcsOAuthProviders,
  isReviewVcsProvider,
  VcsIcon,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import type { ReviewInstallation } from '@/lib/appwrite/reviews'
import { NewRepositories } from './NewRepositories'
import {
  RepositoriesTable,
  type RepositoryActivity,
  type RepositoryRowData,
} from './RepositoriesTable'
import { ReviewAll } from './ReviewAll'

export type RepositoriesFilter = 'reviewed' | 'all'

const TOGGLE_ITEM_CLASS =
  'h-full px-3 text-[13px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground'

/**
 * What the repositories tab shows: reviewed repositories or an installation's,
 * and which installation. Held by the page, since its controls sit in the
 * page toolbar.
 */
export function useRepositoriesScope(projectId: string, search: string) {
  const { project } = useProject(projectId)
  const { vcsProviders } = useConsoleVariables()
  // Providers a new installation can be connected from to review its repositories.
  const connectProviders = getVisibleVcsOAuthProviders(vcsProviders).filter(
    (provider) => isReviewVcsProvider(provider.id),
  )
  const { data: installationsData, isLoading: installationsLoading } =
    useVcsInstallations(projectId, 0, 100)
  const installations = useMemo(
    () =>
      (installationsData?.installations ?? []).filter((installation) =>
        isReviewVcsProvider(installation.provider),
      ) as ReviewInstallation[],
    [installationsData],
  )
  const providers = useMemo(
    () =>
      new Map(
        installations.map((installation) => [
          installation.$id,
          installation.provider,
        ]),
      ),
    [installations],
  )
  const organizations = useMemo(
    () =>
      new Map(
        installations.map((installation) => [
          installation.$id,
          installation.organization,
        ]),
      ),
    [installations],
  )

  const { total: reviewedTotal, isLoading: reviewedTotalLoading } =
    useReviewRepositories(projectId)
  const [chosenFilter, setChosenFilter] = useState<RepositoriesFilter | null>(
    null,
  )
  // Open on what the project reviews, or on everything when it reviews nothing.
  // A search always looks through every repository of an installation.
  const filter: RepositoriesFilter = search
    ? 'all'
    : (chosenFilter ?? (reviewedTotal > 0 ? 'reviewed' : 'all'))

  const [installationId, setInstallationId] = useState('')
  useEffect(() => {
    if (!installations.some((i) => i.$id === installationId)) {
      setInstallationId(installations[0]?.$id ?? '')
    }
  }, [installations, installationId])

  const [reviewAllOpen, setReviewAllOpen] = useState(false)

  const returnUrl = `${typeof window === 'undefined' ? '' : window.location.origin}/projects/${projectId}/reviews/repositories`
  const connectUrl = (provider: VcsProviderId) =>
    buildVcsAuthUrl({
      endpoint: getApiEndpoint(project?.region),
      provider,
      projectId,
      successUrl: returnUrl,
      failureUrl: returnUrl,
    })

  return {
    loading: installationsLoading || reviewedTotalLoading,
    // Nothing to choose from until a Git provider is connected.
    connected: installations.length > 0 || reviewedTotal > 0,
    connectProviders,
    connectUrl,
    installations,
    providers,
    organizations,
    reviewedTotal,
    filter,
    setFilter: setChosenFilter,
    installationId,
    setInstallationId,
    installation: installations.find((i) => i.$id === installationId),
    reviewAllOpen,
    setReviewAllOpen,
  }
}

export type RepositoriesScope = ReturnType<typeof useRepositoriesScope>

/**
 * The toolbar's scope controls: reviewed or all repositories, and which
 * installation's.
 */
export function RepositoriesScopeControls({
  scope,
  search,
}: {
  scope: RepositoriesScope
  search: string
}) {
  const t = useT()
  if (scope.loading || !scope.connected) return null

  return (
    <>
      <ToggleGroup
        type="single"
        variant="outline"
        value={scope.filter}
        onValueChange={(value) => {
          if (value) scope.setFilter(value as RepositoriesFilter)
        }}
        className="h-9"
      >
        <ToggleGroupItem
          value="reviewed"
          className={TOGGLE_ITEM_CLASS}
          disabled={search !== ''}
        >
          {t('Reviewed')}
          <span className="ms-1.5 text-muted-foreground">
            {scope.reviewedTotal}
          </span>
        </ToggleGroupItem>
        <ToggleGroupItem value="all" className={TOGGLE_ITEM_CLASS}>
          {t('All repositories')}
        </ToggleGroupItem>
      </ToggleGroup>
      {scope.filter === 'all' && scope.installations.length > 1 && (
        <Select
          value={scope.installationId}
          onValueChange={scope.setInstallationId}
        >
          <SelectTrigger
            className="h-9 w-[200px] text-[13px]"
            aria-label={t('Installation')}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {scope.installations.map((installation) => (
              <SelectItem
                key={installation.$id}
                value={installation.$id}
                className="text-[13px]"
              >
                <span className="flex min-w-0 items-center gap-2">
                  <VcsIcon
                    type={installation.provider}
                    className="h-4 w-4 shrink-0"
                  />
                  <span className="truncate">{installation.organization}</span>
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </>
  )
}

/** The toolbar's actions on the chosen installation. */
export function RepositoriesActions({
  scope,
  canWrite,
}: {
  scope: RepositoriesScope
  canWrite: boolean
}) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { data: reusable = [] } = useReusableVcsInstallations(projectId)
  const [reuseOpen, setReuseOpen] = useState(false)
  if (scope.loading || scope.filter !== 'all' || !scope.installationId) {
    return null
  }

  return (
    <>
      {scope.connectProviders.length > 0 && (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="h-9 text-[13px]">
              <Plus className="h-4 w-4" />
              {t('Add installation')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {scope.connectProviders.map((provider) =>
              provider.id === 'github' && reusable.length > 0 ? (
                <DropdownMenuItem
                  key={provider.id}
                  onSelect={() => setReuseOpen(true)}
                >
                  <provider.Icon className="h-4 w-4" />
                  {provider.label}
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem key={provider.id} asChild>
                  <a href={scope.connectUrl(provider.id)}>
                    <provider.Icon className="h-4 w-4" />
                    {provider.label}
                  </a>
                </DropdownMenuItem>
              ),
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      )}
      <GitInstallationReuseDialog
        open={reuseOpen}
        onOpenChange={setReuseOpen}
        projectId={projectId}
        installations={reusable}
        installLink={
          <a href={scope.connectUrl('github')}>
            {t('Install on another account')}
          </a>
        }
      />
      <Button
        variant="outline"
        size="sm"
        className="h-9 text-[13px]"
        disabled={!canWrite}
        onClick={() => scope.setReviewAllOpen(true)}
      >
        {t('Review all')}
      </Button>
    </>
  )
}

/**
 * Each reviewed repository's last 30 days, from the summary the Performance
 * tab opens on, so moving between the tabs loads it once.
 */
function useRepositoryActivity(projectId: string): RepositoryActivity {
  const { repositories, isLoading, error } = useReviewSummary(projectId)
  const byRepository = useMemo(
    () =>
      new Map(
        repositories.map((repository) => [repository.repositoryId, repository]),
      ),
    [repositories],
  )
  return { byRepository, loading: isLoading, unavailable: !!error }
}

/** The title row of a repositories card. */
function CardHeader({
  title,
  description,
  aside,
}: {
  title: string
  description: string
  aside?: string
}) {
  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-1 px-6 py-4">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {description}
          </p>
        </div>
        {aside && (
          <span className="text-[13px] text-muted-foreground">{aside}</span>
        )}
      </div>
      <div className="border-t border-border" />
    </>
  )
}

type RepositoriesProps = {
  projectId: string
  canWrite: boolean
  /** Searches the installation's repositories on its provider. */
  search: string
  scope: RepositoriesScope
}

/**
 * The repositories tab. "Reviewed" pages through the repositories the project
 * reviews; "All" pages through the repositories of an installation, so
 * review is turned on with a switch right where the repository is found.
 */
export function Repositories({
  projectId,
  canWrite,
  search,
  scope,
}: RepositoriesProps) {
  const t = useT()
  const {
    filter,
    installation,
    installationId,
    installations,
    organizations,
    providers,
  } = scope

  const connectButtons = (
    <VcsProviderButtons
      providers={scope.connectProviders}
      link={(provider, content) => (
        <a href={scope.connectUrl(provider.id)}>{content}</a>
      )}
    />
  )

  if (scope.loading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!scope.connected) {
    return (
      <EmptyState
        icon={GitPullRequest}
        variant="card"
        title={t('Connect a Git provider to review pull requests')}
        description={t(
          'Connect GitHub, GitLab, Gitea, Bitbucket or Origin to the project, then pick the repositories to review.',
        )}
        action={connectButtons}
      />
    )
  }

  return (
    <div className="space-y-4">
      {/* Only a GitHub app hears of repositories added to its installation. */}
      {filter === 'all' && installation?.provider === 'github' && (
        <NewRepositories
          projectId={projectId}
          installation={installation}
          canWrite={canWrite}
        />
      )}

      {filter === 'reviewed' ? (
        <ReviewedRepositories
          projectId={projectId}
          canWrite={canWrite}
          organizations={organizations}
          providers={providers}
          showOrganization={installations.length > 1}
          onBrowse={() => scope.setFilter('all')}
        />
      ) : installationId ? (
        <InstallationRepositories
          projectId={projectId}
          installationId={installationId}
          provider={providers.get(installationId) ?? ''}
          canWrite={canWrite}
          search={search}
        />
      ) : (
        <EmptyState
          icon={GitPullRequest}
          variant="card"
          title={t('No installation')}
          description={t(
            'Connect a Git provider to the project to review its repositories.',
          )}
          action={connectButtons}
        />
      )}

      {installationId && (
        <ReviewAll
          projectId={projectId}
          installationId={installationId}
          organization={organizations.get(installationId) ?? ''}
          open={scope.reviewAllOpen}
          onOpenChange={scope.setReviewAllOpen}
        />
      )}
    </div>
  )
}

function ReviewedRepositories({
  projectId,
  canWrite,
  organizations,
  providers,
  showOrganization,
  onBrowse,
}: {
  projectId: string
  canWrite: boolean
  organizations: Map<string, string>
  providers: Map<string, string>
  showOrganization: boolean
  onBrowse: () => void
}) {
  const t = useT()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(REVIEW_REPOSITORIES_PAGE_SIZE)
  const { repositories, total, isLoading, isPlaceholderData, error } =
    useReviewRepositories(projectId, page - 1, pageSize)
  const activity = useRepositoryActivity(projectId)

  // Turning off the last repository on a page leaves it empty: step back to
  // the last page that has any. Only a loaded page counts, not the previous
  // page kept on screen while it loads or a failed load.
  useEffect(() => {
    if (
      !isLoading &&
      !isPlaceholderData &&
      !error &&
      repositories.length === 0 &&
      page > 1
    ) {
      setPage(Math.max(1, Math.ceil(total / pageSize)))
    }
  }, [
    isLoading,
    isPlaceholderData,
    error,
    repositories.length,
    total,
    pageSize,
    page,
  ])

  // A row stores the provider's repository ID only; its name comes from the provider.
  const names = useQueries({
    queries: repositories.map((repository) => ({
      queryKey: [
        'vcs',
        'repository',
        projectId,
        repository.installationId,
        repository.providerRepositoryId,
      ],
      queryFn: () =>
        fetchRepository(
          projectId,
          repository.installationId,
          repository.providerRepositoryId,
        ),
      staleTime: DEFAULT_STALE_TIME,
      retry: false,
    })),
  })

  const rows: RepositoryRowData[] = repositories.map((repository, index) => {
    const provider = names[index]?.data
    return {
      installationId: repository.installationId,
      provider: providers.get(repository.installationId) ?? '',
      providerRepositoryId: repository.providerRepositoryId,
      name: provider
        ? `${provider.organization}/${provider.name}`
        : repository.providerRepositoryId,
      private: provider?.private,
      organization: showOrganization
        ? organizations.get(repository.installationId)
        : undefined,
      repository,
    }
  })

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (total === 0) {
    return (
      <EmptyState
        icon={GitPullRequest}
        variant="card"
        title={t('No repositories reviewed yet')}
        description={t(
          'Turn on code review for a repository and its pull requests are reviewed when they are opened or get new commits.',
        )}
        action={
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={onBrowse}
          >
            {t('Browse repositories')}
          </Button>
        }
      />
    )
  }

  return (
    <>
      <RepositoriesTable
        projectId={projectId}
        rows={rows}
        canWrite={canWrite}
        activity={activity}
        header={
          <CardHeader
            title={t('Reviewed repositories')}
            description={t(
              'Pull requests reviewed in the last 30 days. Select a repository to see its reviews.',
            )}
            aside={t('{count} repositories').replace(
              '{count}',
              total.toLocaleString(),
            )}
          />
        }
      />
      <Pagination
        currentPage={page}
        totalItems={total}
        pageSize={pageSize}
        pageSizeOptions={[10, 25, 50, 100]}
        onPageChange={setPage}
        onPageSizeChange={(size) => {
          setPageSize(size)
          setPage(1)
        }}
        itemLabel={t('repositories')}
      />
    </>
  )
}

function InstallationRepositories({
  projectId,
  installationId,
  provider,
  canWrite,
  search,
}: {
  projectId: string
  installationId: string
  provider: string
  canWrite: boolean
  search: string
}) {
  const t = useT()
  const [page, setPage] = useState(1)
  useEffect(() => setPage(1), [search, installationId])

  // Every listed repository is inspected by the server, so keep pages small,
  // and keep the current page on screen while the next one loads.
  const { data, isLoading, isFetching } = useQuery({
    queryKey: [
      'vcs',
      'repositories',
      projectId,
      installationId,
      VCSDetectionType.Runtime,
      page - 1,
      REVIEW_REPOSITORIES_PAGE_SIZE,
      search,
      undefined,
    ],
    queryFn: () =>
      fetchRepositories(
        projectId,
        installationId,
        VCSDetectionType.Runtime,
        page - 1,
        REVIEW_REPOSITORIES_PAGE_SIZE,
        search,
      ),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })
  const repositories = useMemo(
    () => data?.runtimeProviderRepositories ?? [],
    [data],
  )
  const total = data?.total ?? 0

  const ids = useMemo(
    () => repositories.map((repository) => repository.id),
    [repositories],
  )
  const { reviewed, isLoading: reviewedLoading } = useReviewedRepositories(
    projectId,
    ids,
  )
  const activity = useRepositoryActivity(projectId)

  const rows: RepositoryRowData[] = repositories.map((repository) => ({
    installationId,
    provider,
    providerRepositoryId: repository.id,
    name: `${repository.organization}/${repository.name}`,
    private: repository.private,
    repository: reviewed.get(repository.id),
  }))

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (repositories.length === 0) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center text-[13px] text-muted-foreground">
        {search
          ? t('No repositories match your search')
          : t('No repositories found')}
      </div>
    )
  }

  return (
    <>
      <div
        className={
          isFetching || reviewedLoading
            ? 'opacity-60 transition-opacity'
            : 'transition-opacity'
        }
      >
        {/* Until the lookup is back a switch cannot tell on from off. */}
        <RepositoriesTable
          projectId={projectId}
          rows={rows}
          canWrite={canWrite && !reviewedLoading}
          activity={activity}
          header={
            <CardHeader
              title={t('All repositories')}
              description={t(
                'Turn on code review for a repository right where you find it. Reviewed ones show their last 30 days.',
              )}
              aside={t('{count} repositories').replace(
                '{count}',
                total.toLocaleString(),
              )}
            />
          }
        />
      </div>
      <Pagination
        currentPage={page}
        totalItems={total}
        pageSize={REVIEW_REPOSITORIES_PAGE_SIZE}
        onPageChange={setPage}
        onPageSizeChange={() => {}}
        showPageSizeSelector={false}
        itemLabel={t('repositories')}
      />
    </>
  )
}
