import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/sandboxes')({
  head: () => ({ meta: [{ title: pageTitle('Sandboxes') }] }),
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().sandboxes) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: Outlet,
})
