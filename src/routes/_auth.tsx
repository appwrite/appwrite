import { createFileRoute } from '@tanstack/react-router'
import { ensureConsoleAccountOnAuthRoute } from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_auth')({
  ssr: false,
  loader: async ({ context }) => {
    if (typeof window !== 'undefined') {
      await ensureConsoleAccountOnAuthRoute(context.queryClient)
    }
    return {
      currentUser: null,
    }
  },
})
