import { createFileRoute } from '@tanstack/react-router'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/sign-out')({
  head: () => ({ meta: [{ title: pageTitle('Sign out') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    await performConsoleSignOut(context.queryClient)
  },
})
