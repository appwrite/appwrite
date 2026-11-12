import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { CreateProviderWizardView } from '@/components/pages/projects/$projectId/messaging/providers/create/CreateProviderWizardView'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Outcome of the Resend one-click setup OAuth2 round trip
 * (see `src/lib/smtp/quick-setup.ts`). The provider redirects straight back
 * here, because the OAuth2 token flow keeps the console session.
 *
 * Appwrite also appends a one-time `userId` + `secret` on success. Leaving them
 * undeclared is deliberate: the flow reads the provider token off the account's
 * identity instead, so this schema drops them from the parsed search and the
 * page rewrites the URL without them.
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
