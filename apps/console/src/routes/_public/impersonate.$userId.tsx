import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/impersonate/$userId/View'
import { consoleImpersonationTargetQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import { translate } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const impersonateUserSearchSchema = z.object({
  redirect: z.string().min(1).optional(),
})

export const Route = createFileRoute('/_public/impersonate/$userId')({
  validateSearch: impersonateUserSearchSchema,
  head: () => ({ meta: [{ title: pageTitle(translate('Impersonate user')) }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { userId } = params
    const { queryClient } = context

    try {
      const target = await queryClient.ensureQueryData(
        consoleImpersonationTargetQueryOptions({ userId }),
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
  const { redirect } = Route.useSearch()
  const loaderData = Route.useLoaderData()

  return (
    <RequireAuth>
      <View
        key={`impersonate-${userId}`}
        userId={userId}
        redirect={redirect}
        initialData={loaderData ? { target: loaderData.target } : undefined}
      />
    </RequireAuth>
  )
}
