import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Search values are JSON-parsed by the router, so an all-digit user id would
 * arrive as a number; accept both and normalize to a string.
 */
const looseString = z
  .union([z.string(), z.number()])
  .transform((value) => String(value))
  .optional()
  .catch(undefined)

/**
 * Params appended by the SMTP quick setup OAuth2 round trip
 * (see `src/lib/smtp/quick-setup.ts`).
 */
const smtpSearchSchema = z.object({
  smtpSetup: z.enum(['connected', 'failed']).optional().catch(undefined),
  smtpProvider: looseString,
  userId: looseString,
  secret: looseString,
  error: looseString,
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
