import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useQueries, useQuery } from '@tanstack/react-query'
import { GitPullRequest, Loader2 } from 'lucide-react'
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
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  fetchRepositories,
  fetchRepository,
  REVIEW_REPOSITORIES_PAGE_SIZE,
  useProject,
  useReviewedRepositories,
  useReviewRepositories,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import {
  buildVcsAuthUrl,
  VcsIcon,
  vcsProviderButtonIconClassName,
} from '@/lib/vcs/providers'
import { RepositoriesTable, type RepositoryRowData } from './RepositoriesTable'
import { ReviewAll } from './ReviewAll'

export type RepositoriesFilter = 'reviewed' | 'all'

const TOGGLE_ITEM_CLASS =
  'h-full px-3 text-[13px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground'

type RepositoriesProps = {
  projectId: string
  canWrite: boolean
  /** Searches the installation's repositories on GitHub. */
  search: string
}

/**
 * The repositories tab. "Reviewed" pages through the repositories the project
 * reviews; "All" pages through the repositories of a GitHub installation, so
 * review is turned on with a switch right where the repository is found.
 */
export function Repositories({
  projectId,
  canWrite,
  search,
}: RepositoriesProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { data: installationsData, isLoading: installationsLoading } =
    useVcsInstallations(projectId, 0, 100)
  const installations = useMemo(
    () =>
      (installationsData?.installations ?? []).filter(
        (installation) => installation.provider === 'github',
      ),
    [installationsData],
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
  // A search always looks through every repository on GitHub.
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

  const connectUrl = buildVcsAuthUrl({
    endpoint: getApiEndpoint(project?.region),
    provider: 'github',
    projectId,
    successUrl: `${typeof window === 'undefined' ? '' : window.location.origin}/projects/${projectId}/reviews/repositories`,
    failureUrl: `${typeof window === 'undefined' ? '' : window.location.origin}/projects/${projectId}/reviews/repositories`,
  })

  if (installationsLoading || reviewedTotalLoading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (installations.length === 0 && reviewedTotal === 0) {
    return (
      <EmptyState
        icon={GitPullRequest}
        variant="card"
        title={t('Connect GitHub to review pull requests')}
        description={t(
          'Install the GitHub app on your account or organization, then pick the repositories to review.',
        )}
        action={
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <a href={connectUrl}>
              <VcsIcon
                type="github"
                className={vcsProviderButtonIconClassName}
              />
              {t('Connect to GitHub')}
            </a>
          </Button>
        }
      />
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <ToggleGroup
            type="single"
            variant="outline"
            value={filter}
            onValueChange={(value) => {
              if (value) setChosenFilter(value as RepositoriesFilter)
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
                {reviewedTotal}
              </span>
            </ToggleGroupItem>
            <ToggleGroupItem value="all" className={TOGGLE_ITEM_CLASS}>
              {t('All repositories')}
            </ToggleGroupItem>
          </ToggleGroup>
          {filter === 'all' && installations.length > 1 && (
            <Select value={installationId} onValueChange={setInstallationId}>
              <SelectTrigger className="h-9 w-[200px] text-[13px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {installations.map((installation) => (
                  <SelectItem
                    key={installation.$id}
                    value={installation.$id}
                    className="text-[13px]"
                  >
                    {installation.organization}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>
        {filter === 'all' && installationId && (
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-9 text-[13px]"
              asChild
            >
              <a href={connectUrl}>
                <VcsIcon type="github" className="h-4 w-4" />
                {t('Add installation')}
              </a>
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              disabled={!canWrite}
              onClick={() => setReviewAllOpen(true)}
            >
              {t('Review all')}
            </Button>
          </div>
        )}
      </div>

      {filter === 'reviewed' ? (
        <ReviewedRepositories
          projectId={projectId}
          canWrite={canWrite}
          organizations={organizations}
          showOrganization={installations.length > 1}
          onBrowse={() => setChosenFilter('all')}
        />
      ) : installationId ? (
        <InstallationRepositories
          projectId={projectId}
          installationId={installationId}
          canWrite={canWrite}
          search={search}
        />
      ) : (
        <EmptyState
          icon={GitPullRequest}
          variant="card"
          title={t('No GitHub installation')}
          description={t(
            'Connect a GitHub installation to the project to review its repositories.',
          )}
          action={
            <Button
              variant="secondary"
              size="sm"
              className="h-9 text-[13px]"
              asChild
            >
              <a href={connectUrl}>
                <VcsIcon
                  type="github"
                  className={vcsProviderButtonIconClassName}
                />
                {t('Connect to GitHub')}
              </a>
            </Button>
          }
        />
      )}

      {installationId && (
        <ReviewAll
          projectId={projectId}
          installationId={installationId}
          organization={organizations.get(installationId) ?? ''}
          open={reviewAllOpen}
          onOpenChange={setReviewAllOpen}
        />
      )}
    </div>
  )
}

function ReviewedRepositories({
  projectId,
  canWrite,
  organizations,
  showOrganization,
  onBrowse,
}: {
  projectId: string
  canWrite: boolean
  organizations: Map<string, string>
  showOrganization: boolean
  onBrowse: () => void
}) {
  const t = useT()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(REVIEW_REPOSITORIES_PAGE_SIZE)
  const { repositories, total, isLoading } = useReviewRepositories(
    projectId,
    page - 1,
    pageSize,
  )

  // Turning off the last repository on a page leaves it empty: step back.
  useEffect(() => {
    if (!isLoading && repositories.length === 0 && page > 1) {
      setPage(page - 1)
    }
  }, [isLoading, repositories.length, page, setPage])

  // A row stores GitHub's repository ID only; its name comes from GitHub.
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
  canWrite,
  search,
}: {
  projectId: string
  installationId: string
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

  const rows: RepositoryRowData[] = repositories.map((repository) => ({
    installationId,
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
