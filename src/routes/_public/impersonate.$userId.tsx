import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/impersonate/$userId/View'
import { consoleUserQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import { translate } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/impersonate/$userId')({
  head: () => ({ meta: [{ title: pageTitle(translate('Impersonate user')) }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { userId } = params
    const { queryClient } = context

    try {
      const target = await queryClient.ensureQueryData(
        consoleUserQueryOptions(userId),
      )
      return { target }
    } catch {
      // Signed out, not an operator, or unknown id: RequireAuth / the View decide.
      return { target: null }
    }
  },
  component: ImpersonatePage,
})

function ImpersonatePage() {
  const { userId } = Route.useParams()
  const loaderData = Route.useLoaderData()

  return (
    <RequireAuth>
      <View
        key={`impersonate-${userId}`}
        userId={userId}
        initialData={loaderData ? { target: loaderData.target } : undefined}
      />
    </RequireAuth>
  )
}
