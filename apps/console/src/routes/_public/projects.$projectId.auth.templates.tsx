import { createFileRoute, redirect } from '@tanstack/react-router'
import { fetchEmailTemplate, fetchLocaleCodes } from '@/lib/react-query/hooks'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import {
  ProjectEmailTemplateId,
  ProjectEmailTemplateLocale,
} from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

const EMAIL_TEMPLATE_TYPES = [
  ProjectEmailTemplateId.Verification,
  ProjectEmailTemplateId.MagicSession,
  ProjectEmailTemplateId.OtpSession,
  ProjectEmailTemplateId.Recovery,
  ProjectEmailTemplateId.Invitation,
  ProjectEmailTemplateId.MfaChallenge,
  ProjectEmailTemplateId.SessionAlert,
]

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
        queryClient.fetchQuery({
          queryKey: ['localeCodes', 'console'],
          queryFn: fetchLocaleCodes,
          staleTime: 5 * 60 * 1000,
        }),
        Promise.allSettled(
          EMAIL_TEMPLATE_TYPES.map((templateType) =>
            queryClient.fetchQuery({
              queryKey: [
                'emailTemplate',
                projectId,
                templateType,
                ProjectEmailTemplateLocale.En,
              ],
              queryFn: () =>
                fetchEmailTemplate(
                  projectId,
                  templateType,
                  ProjectEmailTemplateLocale.En,
                ),
              staleTime: 30 * 1000,
            }),
          ),
        ),
      ])
    }
  },
  component: () => null,
})
