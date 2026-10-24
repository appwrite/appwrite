import { createFileRoute, redirect } from '@tanstack/react-router'

// The Appwrite migration's pages moved to the migration dashboard's tabs.
export const Route = createFileRoute('/appwrite/$')({
  beforeLoad: ({ params }) => {
    const page = params._splat ?? ''
    if (page === 'facts' || page === 'tasks') throw redirect({ to: `/migration/${page}` })
    if (page === 'behaviour') throw redirect({ to: '/migration/hooks' })
    if (!page || page === 'benchmarks') throw redirect({ to: '/migration/services' })
    throw redirect({ to: '/migration/services/$service', params: { service: page } })
  },
})
