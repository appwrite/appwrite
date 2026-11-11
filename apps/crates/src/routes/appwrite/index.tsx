import { createFileRoute, redirect } from '@tanstack/react-router'

// The Appwrite migration moved to the migration dashboard.
export const Route = createFileRoute('/appwrite/')({
  beforeLoad: () => {
    throw redirect({ to: '/migration/services' })
  },
})
