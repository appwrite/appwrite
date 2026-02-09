import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_auth')({
  loader: async () => {
    // Client-side authentication is handled by RequireAuth component
    // Return null for currentUser - it will be fetched client-side
    return {
      currentUser: null,
    }
  },
})
