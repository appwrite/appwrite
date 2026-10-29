import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/$reviewId/View'
import {
  projectQueryOptions,
  reviewQueryOptions,
} from '@/lib/react-query/hooks'
import type { Review } from '@/lib/appwrite/reviews'
import { pageTitle } from '@/lib/utils/page-title'

// The router types this route's loader data as never, as on other detail routes.
type ReviewLoaderData = { review: Review | null } | undefined

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/$reviewId',
)({
  head: ({ loaderData }) => {
    const review = (loaderData as ReviewLoaderData)?.review
    const title = review
      ? `${review.providerRepositoryName} #${review.providerPullRequestId}`
      : 'Review'
    return { meta: [{ title: pageTitle(title, 'Review') }] }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, reviewId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const review = await queryClient
      .fetchQuery(reviewQueryOptions(projectId, reviewId))
      .catch(() => null)
    return { review }
  },
  component: ReviewPage,
})

function ReviewPage() {
  const { reviewId } = Route.useParams()
  const data = Route.useLoaderData() as ReviewLoaderData
  return (
    <View
      key={`review-${reviewId}`}
      initialReview={data?.review ?? undefined}
    />
  )
}
