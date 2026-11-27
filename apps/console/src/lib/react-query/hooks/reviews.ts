/**
 * React Query hooks for Reviews
 *
 * Handles code reviews of pull requests and the repositories they run for.
 */

import { useCallback } from 'react'
import {
  infiniteQueryOptions,
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import {
  AppwriteException,
  Query,
  VCSDetectionType,
  type Models,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  Review,
  ReviewAuthorList,
  ReviewAuthorRange,
  ReviewCategoryList,
  ReviewEventList,
  ReviewLearningList,
  ReviewList,
  ReviewRepository,
  ReviewRepositoryList,
  ReviewStatus,
  ReviewStrength,
  ReviewSummary,
} from '@/lib/appwrite/reviews'
import { isReviewVcsProvider } from '@/lib/vcs/providers'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'
import {
  fetchRepositories,
  installationQueryOptions,
  repositoryQueryOptions,
  useVcsInstallations,
} from './vcs'

/** Reviews are fanned out by the review service, so poll while one is running. */
const REVIEW_POLL_INTERVAL = 5 * 1000

/** Steps loaded per request; a long trace takes several. */
const REVIEW_EVENTS_PAGE_SIZE = 500

/** Repositories listed per page, on both the reviewed and the GitHub list. */
export const REVIEW_REPOSITORIES_PAGE_SIZE = 10

/** Every listed repository is inspected by the server, so page in small steps. */
const INSTALLATION_REPOSITORIES_PAGE_SIZE = 25

/** Repositories turned on at the same time when adding all of them. */
const ADD_ALL_CONCURRENCY = 5

/**
 * Reviews loaded per page on a function's or site's Reviews tab. Large, since
 * the tab may keep only the reviews that touch the resource's directory.
 */
export const REPOSITORY_REVIEWS_PAGE_SIZE = 50

export function isReviewRunning(review: Pick<Review, 'status'>): boolean {
  return review.status === 'queued' || review.status === 'processing'
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch a page of reviews, newest first. `filterQueries` are
 * the encoded queries of the list's filters.
 */
export async function fetchReviews(
  projectId: string,
  page: number,
  limit: number,
  filterQueries?: string[],
): Promise<ReviewList> {
  if (!projectId) {
    return { reviews: [], total: 0 }
  }

  return await sdk.forProject(projectId).reviews.list({
    queries: [
      ...(filterQueries ?? []),
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

/** Statuses the reviews list counts in its tabs, each as the filter its tab applies. */
export const REVIEW_STATUS_GROUPS = {
  inProgress: ['queued', 'processing'],
  completed: ['completed'],
  failed: ['failed'],
  skipped: ['skipped'],
} as const satisfies Record<string, readonly ReviewStatus[]>

export type ReviewStatusGroup = keyof typeof REVIEW_STATUS_GROUPS

export type ReviewStatusCounts = Record<'all' | ReviewStatusGroup, number>

/**
 * Query function to count the project's reviews in each status group, within
 * the list's other filters.
 */
export async function fetchReviewStatusCounts(
  projectId: string,
  filterQueries?: string[],
): Promise<ReviewStatusCounts> {
  const count = async (status?: readonly ReviewStatus[]) =>
    (
      await fetchReviews(projectId, 0, 1, [
        ...(filterQueries ?? []),
        ...(status ? [Query.equal('status', [...status])] : []),
      ])
    ).total
  const [all, inProgress, completed, failed, skipped] = await Promise.all([
    count(),
    count(REVIEW_STATUS_GROUPS.inProgress),
    count(REVIEW_STATUS_GROUPS.completed),
    count(REVIEW_STATUS_GROUPS.failed),
    count(REVIEW_STATUS_GROUPS.skipped),
  ])
  return { all, inProgress, completed, failed, skipped }
}

/**
 * Query function to fetch a page of one reviewed repository's reviews, newest
 * first, after the review with ID `cursor` when given.
 */
export async function fetchRepositoryReviews(
  projectId: string,
  repositoryId: string,
  cursor?: string,
): Promise<ReviewList> {
  return await sdk.forProject(projectId).reviews.list({
    queries: [
      Query.equal('repositoryId', [repositoryId]),
      Query.orderDesc('$createdAt'),
      Query.limit(REPOSITORY_REVIEWS_PAGE_SIZE),
      ...(cursor ? [Query.cursorAfter(cursor)] : []),
    ],
    total: false,
  })
}

/**
 * Query function to fetch a review with its findings.
 */
export async function fetchReview(
  projectId: string,
  reviewId: string,
): Promise<Review> {
  if (!projectId || !reviewId) {
    throw new Error('Project ID and Review ID are required')
  }
  return await sdk.forProject(projectId).reviews.get({ reviewId })
}

/**
 * Query function to fetch a review's whole trace, oldest step first, a page at
 * a time so a long review is not cut short.
 */
export async function fetchReviewEvents(
  projectId: string,
  reviewId: string,
): Promise<ReviewEventList> {
  const events: ReviewEventList['events'] = []
  for (;;) {
    const last = events.at(-1)
    const page = await sdk.forProject(projectId).reviews.listEvents({
      reviewId,
      queries: [
        Query.limit(REVIEW_EVENTS_PAGE_SIZE),
        ...(last ? [Query.cursorAfter(last.$id)] : []),
      ],
    })
    events.push(...page.events)
    if (page.events.length < REVIEW_EVENTS_PAGE_SIZE) {
      return { total: events.length, events }
    }
  }
}

/**
 * Query function to fetch a page of the repositories the project reviews,
 * newest first.
 */
export async function fetchReviewRepositories(
  projectId: string,
  page: number,
  limit: number,
): Promise<ReviewRepositoryList> {
  if (!projectId) {
    return { repositories: [], total: 0 }
  }

  return await sdk.forProject(projectId).reviews.listRepositories({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

/**
 * Query function to fetch which of the given GitHub repositories the project
 * reviews, keyed by GitHub repository ID.
 */
export async function fetchReviewedRepositories(
  projectId: string,
  providerRepositoryIds: readonly string[],
): Promise<Map<string, ReviewRepository>> {
  if (!projectId || providerRepositoryIds.length === 0) {
    return new Map()
  }

  const { repositories } = await sdk
    .forProject(projectId)
    .reviews.listRepositories({
      queries: [
        Query.equal('providerRepositoryId', [...providerRepositoryIds]),
        Query.limit(providerRepositoryIds.length),
      ],
      total: false,
    })
  return new Map(
    repositories.map((repository) => [
      repository.providerRepositoryId,
      repository,
    ]),
  )
}

/**
 * Query function to fetch a page of what the reviewer was asked to remember,
 * newest first.
 */
export async function fetchReviewLearnings(
  projectId: string,
  page: number,
  limit: number,
  repositoryId: string = '',
): Promise<ReviewLearningList> {
  if (!projectId) {
    return { learnings: [], total: 0 }
  }

  return await sdk.forProject(projectId).reviews.listLearnings({
    queries: [
      ...(repositoryId ? [Query.equal('repositoryId', [repositoryId])] : []),
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

/**
 * Query function to fetch how the authors of the project's pull requests fared
 * in review over a range, optionally for one repository.
 */
export async function fetchReviewAuthors(
  projectId: string,
  range: ReviewAuthorRange,
  repositoryId: string,
): Promise<ReviewAuthorList> {
  if (!projectId) {
    return { authors: [], total: 0 }
  }

  return await sdk
    .forProject(projectId)
    .reviews.listAuthors({ range, repositoryId: repositoryId || undefined })
}

/**
 * Query function to fetch how many findings of each category were fixed or
 * dismissed over a range, optionally for one repository.
 */
export async function fetchReviewCategories(
  projectId: string,
  range: ReviewAuthorRange,
  repositoryId: string,
): Promise<ReviewCategoryList> {
  if (!projectId) {
    return { categories: [], total: 0 }
  }

  return await sdk
    .forProject(projectId)
    .reviews.listCategories({ range, repositoryId: repositoryId || undefined })
}

/**
 * Query function to fetch how the project's pull requests fared in review over
 * a range and the same length of time before it, with each day of the range.
 */
export async function fetchReviewSummary(
  projectId: string,
  range: ReviewAuthorRange,
  repositoryId: string,
): Promise<ReviewSummary> {
  if (!projectId) {
    return { periods: [], days: [], repositories: [] }
  }

  return await sdk
    .forProject(projectId)
    .reviews.getSummary({ range, repositoryId: repositoryId || undefined })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

export function reviewStatusCountsQueryOptions(
  projectId: string | null | undefined,
  filterQueries?: string[],
) {
  return queryOptions({
    // Under the list's key, so refreshing the list refreshes the counts.
    queryKey: ['reviews', 'project', projectId, 'counts', filterQueries],
    queryFn: () => fetchReviewStatusCounts(projectId!, filterQueries),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    // Running reviews move between tabs as they finish.
    refetchInterval: (query) =>
      (query.state.data?.inProgress ?? 0) > 0 ? REVIEW_POLL_INTERVAL : false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewsQueryOptions(
  projectId: string | null | undefined,
  page: number,
  limit: number,
  filterQueries?: string[],
) {
  return queryOptions({
    queryKey: ['reviews', 'project', projectId, page, limit, filterQueries],
    queryFn: () => fetchReviews(projectId!, page, limit, filterQueries),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    refetchInterval: (query) =>
      query.state.data?.reviews.some(isReviewRunning)
        ? REVIEW_POLL_INTERVAL
        : false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * One reviewed repository's reviews, a page at a time. Polls while one of the
 * loaded reviews runs.
 */
export function repositoryReviewsQueryOptions(
  projectId: string | null | undefined,
  repositoryId: string | null | undefined,
) {
  return infiniteQueryOptions({
    queryKey: ['reviews', 'project', projectId, 'repository', repositoryId],
    queryFn: ({ pageParam }) =>
      fetchRepositoryReviews(projectId!, repositoryId!, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      lastPage.reviews.length < REPOSITORY_REVIEWS_PAGE_SIZE
        ? undefined
        : lastPage.reviews.at(-1)?.$id,
    enabled: !!projectId && !!repositoryId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    refetchInterval: (query) =>
      query.state.data?.pages.some((page) => page.reviews.some(isReviewRunning))
        ? REVIEW_POLL_INTERVAL
        : false,
  })
}

export function reviewQueryOptions(
  projectId: string | null | undefined,
  reviewId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['review', 'project', projectId, reviewId],
    queryFn: () => fetchReview(projectId!, reviewId!),
    enabled: !!projectId && !!reviewId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchInterval: (query) =>
      query.state.data && isReviewRunning(query.state.data)
        ? REVIEW_POLL_INTERVAL
        : false,
  })
}

/**
 * The review agent's trace. Polls while the review runs, so the steps appear live.
 */
export function reviewEventsQueryOptions(
  projectId: string | null | undefined,
  reviewId: string | null | undefined,
  running: boolean,
) {
  return queryOptions({
    queryKey: ['review-events', 'project', projectId, reviewId],
    queryFn: () => fetchReviewEvents(projectId!, reviewId!),
    enabled: !!projectId && !!reviewId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchInterval: running ? REVIEW_POLL_INTERVAL : false,
  })
}

export function reviewRepositoriesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = REVIEW_REPOSITORIES_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['review-repositories', 'project', projectId, page, limit],
    queryFn: () => fetchReviewRepositories(projectId!, page, limit),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewLearningsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  repositoryId: string = '',
) {
  return queryOptions({
    queryKey: [
      'review-learnings',
      'project',
      projectId,
      page,
      limit,
      repositoryId,
    ],
    queryFn: () => fetchReviewLearnings(projectId!, page, limit, repositoryId),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewAuthorsQueryOptions(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  return queryOptions({
    queryKey: ['review-authors', 'project', projectId, range, repositoryId],
    queryFn: () => fetchReviewAuthors(projectId!, range, repositoryId),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewSummaryQueryOptions(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  return queryOptions({
    queryKey: ['review-summary', 'project', projectId, range, repositoryId],
    queryFn: () => fetchReviewSummary(projectId!, range, repositoryId),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewCategoriesQueryOptions(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  return queryOptions({
    queryKey: ['review-categories', 'project', projectId, range, repositoryId],
    queryFn: () => fetchReviewCategories(projectId!, range, repositoryId),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function reviewedRepositoriesQueryOptions(
  projectId: string | null | undefined,
  providerRepositoryIds: readonly string[],
) {
  return queryOptions({
    queryKey: [
      'review-repositories',
      'project',
      projectId,
      'reviewed',
      providerRepositoryIds,
    ],
    queryFn: () => fetchReviewedRepositories(projectId!, providerRepositoryIds),
    enabled: !!projectId && providerRepositoryIds.length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/**
 * Loads what a function's or site's Reviews tab shows, so it opens without a
 * loader: the repository's installation and name, whether the project reviews
 * it, and the first page of its reviews. A failure is left for the tab to show.
 */
export async function prefetchRepositoryReviews(
  queryClient: QueryClient,
  projectId: string,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
): Promise<void> {
  if (!installationId || !providerRepositoryId) return
  try {
    const installation = await queryClient.ensureQueryData(
      installationQueryOptions(projectId, installationId),
    )
    // Code review runs on some Git providers only.
    if (!isReviewVcsProvider(installation.provider)) return

    const [reviewed] = await Promise.all([
      queryClient.ensureQueryData(
        reviewedRepositoriesQueryOptions(projectId, [providerRepositoryId]),
      ),
      queryClient.ensureQueryData(
        repositoryQueryOptions(projectId, installationId, providerRepositoryId),
      ),
    ])
    const repository = reviewed.get(providerRepositoryId)
    if (repository) {
      await queryClient.ensureInfiniteQueryData(
        repositoryReviewsQueryOptions(projectId, repository.$id),
      )
    }
  } catch {
    // The tab shows the failed request.
  }
}

// ============================================================================
// HOOKS
// ============================================================================

export function useReviews(
  projectId: string | null | undefined,
  page: number,
  limit: number,
  filterQueries?: string[],
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    reviewsQueryOptions(projectId, page, limit, filterQueries),
  )

  return {
    reviews: data?.reviews ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/** How many reviews each status tab holds, within the list's other filters. */
export function useReviewStatusCounts(
  projectId: string | null | undefined,
  filterQueries?: string[],
) {
  const { data, isLoading } = useQuery(
    reviewStatusCountsQueryOptions(projectId, filterQueries),
  )

  return { counts: data, isLoading }
}

/**
 * One reviewed repository's reviews, newest first, with `loadMore` for the
 * next page.
 */
export function useRepositoryReviews(
  projectId: string | null | undefined,
  repositoryId: string | null | undefined,
) {
  const {
    data,
    isLoading,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    refetch,
  } = useInfiniteQuery(repositoryReviewsQueryOptions(projectId, repositoryId))

  const loadMore = useCallback(() => {
    void fetchNextPage({ cancelRefetch: false })
  }, [fetchNextPage])

  return {
    reviews: data?.pages.flatMap((page) => page.reviews) ?? [],
    pages: data?.pages.length ?? 0,
    isLoading,
    isFetching,
    isFetchingNextPage,
    hasMore: hasNextPage,
    loadMore,
    refetch,
  }
}

export function useReview(
  projectId: string | null | undefined,
  reviewId: string | null | undefined,
) {
  const { data, isLoading, error } = useQuery(
    reviewQueryOptions(projectId, reviewId),
  )

  return { review: data ?? null, isLoading, error }
}

export function useReviewEvents(
  projectId: string | null | undefined,
  reviewId: string | null | undefined,
  running: boolean,
) {
  const { data, isLoading, error } = useQuery(
    reviewEventsQueryOptions(projectId, reviewId, running),
  )

  return {
    events: data?.events ?? [],
    total: data?.total ?? 0,
    isLoading,
    error,
  }
}

export function useReviewRepositories(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = REVIEW_REPOSITORIES_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error } = useQuery(
    reviewRepositoriesQueryOptions(projectId, page, limit),
  )

  return {
    repositories: data?.repositories ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
  }
}

export function useReviewLearnings(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  repositoryId: string = '',
) {
  const { data, isLoading, isFetching, isPlaceholderData, error } = useQuery(
    reviewLearningsQueryOptions(projectId, page, limit, repositoryId),
  )

  return {
    learnings: data?.learnings ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
  }
}

export function useReviewAuthors(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  const { data, isLoading, isFetching, error } = useQuery(
    reviewAuthorsQueryOptions(projectId, range, repositoryId),
  )

  return {
    authors: data?.authors ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
  }
}

export function useReviewSummary(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  const { data, isLoading, isFetching, error } = useQuery(
    reviewSummaryQueryOptions(projectId, range, repositoryId),
  )

  return {
    current: data?.periods[0],
    previous: data?.periods[1],
    days: data?.days ?? [],
    // An API that predates the breakdown leaves it out.
    repositories: data?.repositories ?? [],
    isLoading,
    isFetching,
    error,
  }
}

export function useReviewCategories(
  projectId: string | null | undefined,
  range: ReviewAuthorRange = '30d',
  repositoryId: string = '',
) {
  const { data, isLoading } = useQuery(
    reviewCategoriesQueryOptions(projectId, range, repositoryId),
  )

  return {
    categories: data?.categories ?? [],
    isLoading,
  }
}

/**
 * The installation the repository a review ran on is connected through, which
 * names its provider and, for a self-hosted one, its host. Null until both are
 * loaded, or when the installation is gone.
 */
export function useReviewInstallation(
  projectId: string | null | undefined,
  repositoryId: string | null | undefined,
): Models.Installation | null {
  const { data: repository } = useQuery({
    queryKey: ['reviews', 'repository', projectId, repositoryId],
    queryFn: async () => {
      const { repositories } = await sdk
        .forProject(projectId!)
        .reviews.listRepositories({
          queries: [Query.equal('$id', [repositoryId!]), Query.limit(1)],
          total: false,
        })
      return repositories[0] ?? null
    },
    enabled: !!projectId && !!repositoryId,
    staleTime: DEFAULT_STALE_TIME,
  })
  const { data: installations } = useVcsInstallations(projectId, 0, 100)

  return (
    installations?.installations.find(
      (installation) => installation.$id === repository?.installationId,
    ) ?? null
  )
}

/**
 * Which of the given repositories the project reviews, keyed by the provider's
 * repository ID. Works for any number of reviewed repositories, since only the
 * listed ones are looked up.
 */
export function useReviewedRepositories(
  projectId: string | null | undefined,
  providerRepositoryIds: readonly string[],
) {
  const { data, isLoading } = useQuery(
    reviewedRepositoriesQueryOptions(projectId, providerRepositoryIds),
  )

  return { reviewed: data ?? new Map<string, ReviewRepository>(), isLoading }
}

/**
 * The project's row for one repository, when it reviews it.
 */
export function useReviewedRepository(
  projectId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
) {
  const { reviewed, isLoading } = useReviewedRepositories(
    projectId,
    providerRepositoryId ? [providerRepositoryId] : [],
  )

  return {
    repository: providerRepositoryId
      ? reviewed.get(providerRepositoryId)
      : undefined,
    isLoading,
  }
}

/**
 * Turns code review on for a repository.
 */
export function useCreateReviewRepository(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: {
      installationId: string
      providerRepositoryId: string
    }) => sdk.forProject(projectId!).reviews.createRepository(params),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['review-repositories', 'project', projectId],
      })
    },
  })
}

/**
 * Turns code review on for every repository of a GitHub installation that the
 * project does not review yet, a page at a time. A repository that is already
 * on counts as skipped; any other failure is counted and the rest carry on.
 */
export function useCreateAllReviewRepositories(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      installationId: string
      onProgress?: (done: number, total: number) => void
    }) => {
      const outcome = { added: 0, skipped: 0, failed: 0 }
      let seen = 0
      for (let page = 0; ; page++) {
        const result = await fetchRepositories(
          projectId!,
          params.installationId,
          VCSDetectionType.Runtime,
          page,
          INSTALLATION_REPOSITORIES_PAGE_SIZE,
        )
        const ids = (result.runtimeProviderRepositories ?? []).map(
          (repository) => repository.id,
        )
        const reviewed = await fetchReviewedRepositories(projectId!, ids)
        const pending = ids.filter((id) => !reviewed.has(id))
        outcome.skipped += ids.length - pending.length

        for (let i = 0; i < pending.length; i += ADD_ALL_CONCURRENCY) {
          const results = await Promise.allSettled(
            pending
              .slice(i, i + ADD_ALL_CONCURRENCY)
              .map((providerRepositoryId) =>
                sdk.forProject(projectId!).reviews.createRepository({
                  installationId: params.installationId,
                  providerRepositoryId,
                }),
              ),
          )
          for (const settled of results) {
            if (settled.status === 'fulfilled') {
              outcome.added++
            } else if (
              settled.reason instanceof AppwriteException &&
              settled.reason.code === 409
            ) {
              outcome.skipped++
            } else {
              outcome.failed++
            }
          }
        }

        seen += ids.length
        params.onProgress?.(seen, result.total)
        if (
          ids.length < INSTALLATION_REPOSITORIES_PAGE_SIZE ||
          seen >= result.total
        ) {
          break
        }
      }
      return outcome
    },
    onSettled: async () => {
      await queryClient.refetchQueries({
        queryKey: ['review-repositories', 'project', projectId],
      })
    },
  })
}

/**
 * Changes how hard a repository's pull requests are reviewed.
 */
export function useUpdateReviewRepositoryStrength(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: { repositoryId: string; strength: ReviewStrength }) =>
      sdk.forProject(projectId!).reviews.updateRepositoryStrength(params),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['review-repositories', 'project', projectId],
      })
    },
  })
}

/**
 * Turns code review off for a repository. Its past reviews stay.
 */
export function useDeleteReviewRepository(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (repositoryId: string) =>
      sdk.forProject(projectId!).reviews.deleteRepository({ repositoryId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['review-repositories', 'project', projectId],
      })
    },
  })
}

/**
 * Chooses whether repositories added to a GitHub installation later are
 * reviewed, and how hard.
 */
export function useUpdateReviewInstallation(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: {
      installationId: string
      newRepositories: boolean
    }) => sdk.forProject(projectId!).reviews.updateInstallation(params),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['vcs', 'installations', projectId],
      })
    },
  })
}

/**
 * Forgets a learning: later reviews and answers no longer follow it.
 */
export function useDeleteReviewLearning(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (learningId: string) =>
      sdk.forProject(projectId!).reviews.deleteLearning({ learningId }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['review-learnings', 'project', projectId],
      })
    },
  })
}
