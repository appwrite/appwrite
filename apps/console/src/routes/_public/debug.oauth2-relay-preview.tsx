import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { OAuth2RelayCard } from '@/components/global/auth/OAuth2RelayCard'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const oauth2RelayPreviewSearchSchema = z.object({
  variant: z.enum(['success', 'failure', 'missing', 'error']).optional(),
})

export const Route = createFileRoute('/_public/debug/oauth2-relay-preview')({
  validateSearch: oauth2RelayPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('OAuth2 relay preview') }] }),
  component: OAuth2RelayPreviewPage,
})

function OAuth2RelayPreviewPage() {
  const t = useT()
  const { variant = 'success' } = Route.useSearch()

  const title =
    variant === 'failure' || variant === 'error'
      ? t('Login failed')
      : t("You're now logged in")

  const previewProject = variant === 'success' ? 'console' : null

  const previewError =
    variant === 'failure'
      ? { message: t('An error occurred during the OAuth login flow.') }
      : variant === 'error'
        ? {
            message: t('An error occurred during the OAuth login flow.'),
            type: 'oauth_provider_error',
          }
        : null

  const previewSearch =
    variant === 'success' ? '?project=console&userId=preview' : ''

  return (
    <AuthFlowShell width="narrow">
      <OAuth2RelayCard
        preview
        title={title}
        previewProject={previewProject}
        previewError={previewError}
        previewSearch={previewSearch}
      />
    </AuthFlowShell>
  )
}
