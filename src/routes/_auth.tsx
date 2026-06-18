import { createFileRoute } from '@tanstack/react-router'
import { ensureConsoleAccountOnAuthRoute } from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_auth')({
  ssr: false,
  loader: async ({ context, location }) => {
    if (typeof window !== 'undefined') {
      // MFA route loader owns account/MFA prefetch; skip guest refresh here.
      if (location.pathname !== '/mfa') {
        await ensureConsoleAccountOnAuthRoute(context.queryClient)
      }
    }
    return {
      currentUser: null,
    }
  },
})
