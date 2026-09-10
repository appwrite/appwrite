import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { CreateProviderWizardView } from '@/components/pages/projects/$projectId/messaging/providers/create/CreateProviderWizardView'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Outcome of the Resend one-click setup OAuth2 round trip, set by
 * `/auth/smtp/callback` (see `src/lib/smtp/quick-setup.ts`). The one-time
 * token never reaches this route; the callback consumes it.
 */
const createProviderSearchSchema = z.object({
  smtpSetup: z.enum(['connected', 'failed']).optional().catch(undefined),
  smtpProvider: z.string().optional().catch(undefined),
  error: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/create',
)({
  head: () => ({
    meta: [{ title: pageTitle('Add provider', 'Messaging') }],
  }),
  validateSearch: createProviderSearchSchema,
  // Disable lazy split for this route to match other create wizards.
  codeSplitGroupings: [],
  component: CreateProviderWizardView,
})
