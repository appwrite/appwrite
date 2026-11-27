import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/signing/View'
import { canAccessDistributionCredentials } from '@/lib/console-rbac-loader'
import {
  DEFAULT_PAGE_SIZE,
  distributionSigningIdentitiesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/signing',
)({
  head: () => ({ meta: [{ title: pageTitle('Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!(await canAccessDistributionCredentials(queryClient, projectId))) {
      throw redirect({
        to: '/projects/$projectId/distribution',
        params: { projectId },
        replace: true,
      })
    }
    await queryClient.ensureQueryData(
      distributionSigningIdentitiesQueryOptions(
        projectId,
        0,
        DEFAULT_PAGE_SIZE,
      ),
    )
  },
  component: SigningPage,
})

function SigningPage() {
  const { projectId } = Route.useParams()
  return <View key={`distribution-${projectId}-signing`} />
}
