import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/alerts/unsubscribe/View'
import { pageTitle } from '@/lib/utils/page-title'

/** `?token=` from an alert email's unsubscribe link; it works without a session. */
const searchSchema = z.object({
  token: z.string().catch(''),
})

export const Route = createFileRoute('/_auth/auth/alerts/unsubscribe')({
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Alert emails') }] }),
  component: AlertsUnsubscribePage,
})

function AlertsUnsubscribePage() {
  const { token } = Route.useSearch()
  return <View token={token} />
}
