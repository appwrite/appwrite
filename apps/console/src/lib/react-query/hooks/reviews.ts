/**
 * React Query hooks for Reviews
 *
 * Handles code reviews of pull requests and the repositories they run for.
 */

import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import {
  AppwriteException,
  Query,
  VCSDetectionType,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  Review,
  ReviewEventList,
  ReviewList,
  ReviewRepositoryList,
} from '@/lib/appwrite/reviews'
import { DEFAULT_STALE_TIME } from './constants'
import { fetchRepositories } from './vcs'

/** Reviews are fanned out by the review service, so poll while one is running. */
const REVIEW_POLL_INTERVAL = 5 * 1000

/** Steps loaded per request; a long trace takes several. */
const REVIEW_EVENTS_PAGE_SIZE = 500

/** A project reviews a handful of repositories; load them in one page. */
export const REVIEW_REPOSITORIES_LIMIT = 100

/** Every listed repository is inspected by the server, so page in small steps. */
const INSTALLATION_REPOSITORIES_PAGE_SIZE = 25

/** Repositories turned on at the same time when adding all of them. */
const ADD_ALL_CONCURRENCY = 5

export function isReviewRunning(review: Pick<Review, 'status'>): boolean {
  return review.status === 'queued' || review.status === 'processing'
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch a page of reviews, newest first.
 */
export async function fetchReviews(
  projectId: string,
  page: number,
  limit: number,
): Promise<ReviewList> {
  if (!projectId) {
    return { reviews: [], total: 0 }
  }

  return await sdk.forProject(projectId).reviews.list({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
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
 * Query function to fetch the repositories the project reviews.
 */
export async function fetchReviewRepositories(
  projectId: string,
): Promise<ReviewRepositoryList> {
  if (!projectId) {
    return { repositories: [], total: 0 }
  }

  return await sdk.forProject(projectId).reviews.listRepositories({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(REVIEW_REPOSITORIES_LIMIT),
    ],
  })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

export function reviewsQueryOptions(
  projectId: string | null | undefined,
  page: number,
  limit: number,
) {
  return queryOptions({
    queryKey: ['reviews', 'project', projectId, page, limit],
    queryFn: () => fetchReviews(projectId!, page, limit),
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
) {
  return queryOptions({
    queryKey: ['review-repositories', 'project', projectId],
    queryFn: () => fetchReviewRepositories(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

export function useReviews(
  projectId: string | null | undefined,
  page: number,
  limit: number,
) {
  const { data, isLoading, isFetching, error } = useQuery(
    reviewsQueryOptions(projectId, page, limit),
  )

  return {
    reviews: data?.reviews ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
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

export function useReviewRepositories(projectId: string | null | undefined) {
  const { data, isLoading, error } = useQuery(
    reviewRepositoriesQueryOptions(projectId),
  )

  return {
    repositories: data?.repositories ?? [],
    total: data?.total ?? 0,
    isLoading,
    error,
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
 * project does not review yet. A repository that is already on counts as
 * skipped; any other failure is counted and the rest carry on.
 */
export function useCreateAllReviewRepositories(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      installationId: string
      enrolledIds: ReadonlySet<string>
    }) => {
      const ids: string[] = []
      for (let page = 0; ; page++) {
        const result = await fetchRepositories(
          projectId!,
          params.installationId,
          VCSDetectionType.Runtime,
          page,
          INSTALLATION_REPOSITORIES_PAGE_SIZE,
        )
        const repositories = result.runtimeProviderRepositories ?? []
        ids.push(...repositories.map((repository) => repository.id))
        if (
          repositories.length < INSTALLATION_REPOSITORIES_PAGE_SIZE ||
          ids.length >= result.total
        ) {
          break
        }
      }

      const pending = ids.filter((id) => !params.enrolledIds.has(id))
      const outcome = {
        added: 0,
        skipped: ids.length - pending.length,
        failed: 0,
      }
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
        for (const result of results) {
          if (result.status === 'fulfilled') {
            outcome.added++
          } else if (
            result.reason instanceof AppwriteException &&
            result.reason.code === 409
          ) {
            outcome.skipped++
          } else {
            outcome.failed++
          }
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
