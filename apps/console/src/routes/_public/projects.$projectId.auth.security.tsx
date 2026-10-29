import { createFileRoute, redirect } from '@tanstack/react-router'

/** @deprecated Auth Security tab removed; use Settings or Social providers. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/security',
)({
  beforeLoad: ({ params }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    if (!projectId) return
    throw redirect({
      to: '/projects/$projectId/auth/settings',
      params: { projectId },
      replace: true,
    })
  },
})
