import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_protected')({
  ssr: false,
  loader: async () => {
    // Client-side authentication is handled by RequireAuth component
    // Return null for currentUser - it will be fetched client-side
    // RequireAuth will handle redirects to sign-in if not authenticated
    return {
      currentUser: null,
    }
  },
})
