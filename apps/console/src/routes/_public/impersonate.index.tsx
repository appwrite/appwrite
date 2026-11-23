import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/impersonate/$userId/View'
import { translate } from '@/lib/i18n/translate'
import { consoleImpersonationTargetQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import { pageTitle } from '@/lib/utils/page-title'

const impersonateSearchSchema = z.object({
  email: z.string().trim().min(1).optional(),
  redirect: z.string().min(1).optional(),
})

/**
 * `/impersonate?email=<address>&redirect=<path>`: same confirm flow as
 * `/impersonate/$userId`, for support links that only know the requester's
 * email. Optional `redirect` is the console page to open after confirm.
 */
export const Route = createFileRoute('/_public/impersonate/')({
  validateSearch: impersonateSearchSchema,
  head: () => ({ meta: [{ title: pageTitle(translate('Impersonate user')) }] }),
  loaderDeps: ({ search }) => ({ email: search.email }),
  loader: async ({ deps, context }) => {
    if (typeof window === 'undefined') return undefined

    const { email } = deps
    const { queryClient } = context

    if (!email) return { target: null }

    try {
      const target = await queryClient.ensureQueryData(
        consoleImpersonationTargetQueryOptions({ email }),
      )
      return { target }
    } catch {
      // Signed out or not an operator: RequireAuth / the View decide.
      return { target: null }
    }
  },
  component: ImpersonateByEmailPage,
})

function ImpersonateByEmailPage() {
  const { email, redirect } = Route.useSearch()
  const loaderData = Route.useLoaderData()

  return (
    <RequireAuth>
      <View
        key={`impersonate-email-${email ?? ''}`}
        email={email}
        redirect={redirect}
        initialData={loaderData ? { target: loaderData.target } : undefined}
      />
    </RequireAuth>
  )
}
