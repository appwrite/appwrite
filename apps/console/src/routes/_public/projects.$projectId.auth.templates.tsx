import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  emailTemplateQueryOptions,
  localeCodesQueryOptions,
} from '@/lib/react-query/hooks'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import {
  ProjectEmailTemplateId,
  ProjectEmailTemplateLocale,
} from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/templates',
)({
  head: () => ({ meta: [{ title: pageTitle('Templates', 'Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
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
      await Promise.all([
        queryClient.fetchQuery(localeCodesQueryOptions()),
        // The editor opens on Verification. Templates warms the others after mount.
        queryClient.prefetchQuery(
          emailTemplateQueryOptions(
            projectId,
            ProjectEmailTemplateId.Verification,
            ProjectEmailTemplateLocale.En,
          ),
        ),
      ])
    }
  },
  component: () => null,
})
