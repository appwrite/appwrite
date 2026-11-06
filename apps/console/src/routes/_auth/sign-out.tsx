import { createFileRoute } from '@tanstack/react-router'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/sign-out')({
  head: () => ({ meta: [{ title: pageTitle('Sign out') }] }),
  // Blank shell if the loader has not hard-redirected yet (cover hides this).
  component: () => null,
  loader: async ({ context, cause, preload }) => {
    if (typeof window === 'undefined') return
    // Intent preload of a <Link to="/sign-out"> must not destroy the session.
    if (cause === 'preload' || preload) return
    await performConsoleSignOut(context.queryClient)
  },
})
