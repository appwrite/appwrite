import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/migration/')({
  beforeLoad: () => {
    throw redirect({ to: '/migration/utopia' })
  },
})
