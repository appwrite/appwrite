import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  consoleVariablesQueryOptions,
  isReviewsEnabled,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/reviews')({
  head: () => ({ meta: [{ title: pageTitle('Review') }] }),
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().reviews) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    // Self-hosted instances run the review service only when they opt in.
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const variables = await queryClient
      .ensureQueryData(consoleVariablesQueryOptions(project?.region))
      .catch(() => undefined)
    if (!isReviewsEnabled(variables)) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId },
        replace: true,
      })
    }
  },
  component: Outlet,
})
