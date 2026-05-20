import { createFileRoute, Outlet } from '@tanstack/react-router'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import {
  consoleOAuth2CatalogQueryOptions,
  projectOAuth2ProvidersQueryOptions,
} from '@/lib/react-query/hooks/oauth2-providers'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/auth')({
  head: () => ({ meta: [{ title: pageTitle('Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await Promise.all([
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient.ensureQueryData(consoleOAuth2CatalogQueryOptions()),
        queryClient.ensureQueryData(
          projectOAuth2ProvidersQueryOptions(projectId),
        ),
      ])
    }
  },
  component: AuthPage,
})

function AuthPage() {
  return <Outlet />
}
