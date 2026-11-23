import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/education/join/View'
import { pageTitle } from '@/lib/utils/page-title'
import {
  accountIdentitiesQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks/auth'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { isCloudProfile } from '@/lib/console-profiles'

const searchSchema = z.object({
  /** Set by the OAuth failure redirect so the page can explain the retry. */
  status: z.literal('failure').optional(),
})

export const Route = createFileRoute('/_auth/education/join')({
  component: View,
  validateSearch: searchSchema,
  beforeLoad: () => {
    if (!isCloudProfile()) {
      throw redirect({ to: '/', replace: true })
    }
  },
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account || requiresConsoleEmailVerification(account)) return

    // A GitHub identity may have been linked in another tab since the cache was
    // filled. Refresh at the enrollment boundary, before deciding to enroll.
    await context.queryClient
      .fetchQuery({ ...accountIdentitiesQueryOptions(), staleTime: 0 })
      .catch(() => {
        // The view exposes the query error and a retry without starting OAuth.
      })
  },
  head: () => ({ meta: [{ title: pageTitle('Appwrite Education Program') }] }),
})
