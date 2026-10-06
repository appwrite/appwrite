import { createFileRoute, redirect } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canUsePasskeys } from '@/lib/passkeys'
import {
  ensureConsoleAccountQueryData,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { projectAuthSecurityQueryOptions } from '@/lib/project-settings'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/policies/passkeys',
)({
  head: () => ({ meta: [{ title: pageTitle('Passkeys', 'Policies') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return
    const canAccess = await canAccessAuthSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/auth',
        params: { projectId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const project = queryClient.getQueryData<Models.Project>(
      projectQueryOptions(projectId).queryKey,
    )
    const features = getActiveProfileFeatures()
    const account = features.passkeysFlag
      ? await ensureConsoleAccountQueryData(queryClient).catch(() => null)
      : null
    if (!canUsePasskeys(features, account?.prefs)) {
      throw redirect({
        to: '/projects/$projectId/auth/policies/sessions',
        params: { projectId },
        replace: true,
      })
    }
    await queryClient.ensureQueryData(
      projectAuthSecurityQueryOptions(projectId, project?.region),
    )
  },
  component: () => null,
})
