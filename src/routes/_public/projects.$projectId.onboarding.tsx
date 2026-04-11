import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/onboarding/View'
import { onboardingSnapshotQueryOptions } from '@/lib/react-query/hooks/onboarding'
import { canAccessProjectOnboarding } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/onboarding')({
  head: () => ({ meta: [{ title: pageTitle('Get started') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    const canAccess = await canAccessProjectOnboarding(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId },
        replace: true,
      })
    }

    const snapshot = await queryClient.ensureQueryData(
      onboardingSnapshotQueryOptions(projectId),
    )
    return { snapshot }
  },
  component: OnboardingPage,
})

function OnboardingPage() {
  const loaderData = Route.useLoaderData()
  return (
    <View
      initialData={
        loaderData?.snapshot
          ? { snapshot: loaderData.snapshot }
          : undefined
      }
    />
  )
}
