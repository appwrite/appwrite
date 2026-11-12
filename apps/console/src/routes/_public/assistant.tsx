import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy path: redirect to /agent. */
export const Route = createFileRoute('/_public/assistant')({
  beforeLoad: () => {
    throw redirect({ to: '/agent', replace: true })
  },
})
