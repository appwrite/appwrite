import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Outcome of the SMTP quick setup OAuth2 round trip, set by
 * `/auth/smtp/callback` (see `src/lib/smtp/quick-setup.ts`). The one-time
 * token never reaches this route; the callback consumes it.
 */
const smtpSearchSchema = z.object({
  smtpSetup: z.enum(['connected', 'failed']).optional().catch(undefined),
  smtpProvider: z.string().optional().catch(undefined),
  error: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/smtp',
)({
  head: () => ({ meta: [{ title: pageTitle('SMTP', 'Settings') }] }),
  validateSearch: smtpSearchSchema,
  component: SettingsSmtpPage,
})

function SettingsSmtpPage() {
  return <View />
}
