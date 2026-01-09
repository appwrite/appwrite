import { createFileRoute } from '@tanstack/react-router'
import { AuthView } from '@/components/pages/projects/$projectId/auth/View'
import { fetchEmailTemplate, fetchLocaleCodes } from '@/lib/react-query/hooks'
import { EmailTemplateType, EmailTemplateLocale } from '@appwrite.io/console'

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

export const Route = createFileRoute('/_public/projects/$projectId/auth/templates')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Ensure locale codes are loaded before component renders
      await queryClient.ensureQueryData({
        queryKey: ['localeCodes', 'console'],
        queryFn: fetchLocaleCodes,
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Ensure all English templates are loaded before component renders
      // Use Promise.allSettled to ensure all templates are fetched even if some fail
      await Promise.allSettled(
        EMAIL_TEMPLATE_TYPES.map((templateType) =>
          queryClient.ensureQueryData({
            queryKey: ['emailTemplate', projectId, templateType, EmailTemplateLocale.En],
            queryFn: () => fetchEmailTemplate(projectId, templateType, EmailTemplateLocale.En),
            staleTime: 30 * 1000, // 30 seconds
          }),
        ),
      )
    }
  },
  component: AuthTemplatesPage,
})

function AuthTemplatesPage() {
  const { projectId } = Route.useParams()
  return <AuthView key={`auth-${projectId}-templates`} />
}
