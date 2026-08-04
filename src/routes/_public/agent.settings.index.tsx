import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/agent/settings/')({
  beforeLoad: () => {
    throw redirect({ to: '/agent/settings/models', replace: true })
  },
})
