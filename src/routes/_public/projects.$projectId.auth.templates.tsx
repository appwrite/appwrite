import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { fetchEmailTemplate, fetchLocaleCodes } from '@/lib/react-query/hooks'
import { EmailTemplateType, EmailTemplateLocale } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

// All email template types that need to be prefetched
const EMAIL_TEMPLATE_TYPES = [
  EmailTemplateType.Verification,
  EmailTemplateType.Magicsession,
  EmailTemplateType.Otpsession,
  EmailTemplateType.Recovery,
  EmailTemplateType.Invitation,
  EmailTemplateType.Mfachallenge,
  EmailTemplateType.Sessionalert,
]

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/templates',
)({
  head: () => ({ meta: [{ title: pageTitle('Templates', 'Auth') }] }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        // Fetch locale codes - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['localeCodes', 'console'],
          queryFn: fetchLocaleCodes,
          staleTime: 5 * 60 * 1000, // 5 minutes
        }),
        // Fetch all English templates - blocks navigation until ready
        // Use Promise.allSettled to ensure all templates are fetched even if some fail
        Promise.allSettled(
          EMAIL_TEMPLATE_TYPES.map((templateType) =>
            queryClient.fetchQuery({
              queryKey: [
                'emailTemplate',
                projectId,
                templateType,
                EmailTemplateLocale.En,
              ],
              queryFn: () =>
                fetchEmailTemplate(
                  projectId,
                  templateType,
                  EmailTemplateLocale.En,
                ),
              staleTime: 30 * 1000, // 30 seconds
            }),
          ),
        ),
      ])
    }
  },
  component: AuthTemplatesPage,
})

function AuthTemplatesPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-templates`} />
}
