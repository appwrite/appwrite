import { createFileRoute } from '@tanstack/react-router'
import { CreateProviderWizardView } from '@/components/pages/projects/$projectId/messaging/providers/create/CreateProviderWizardView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/create',
)({
  head: () => ({
    meta: [{ title: pageTitle('Add provider', 'Messaging') }],
  }),
  // Disable lazy split for this route to match other create wizards.
  codeSplitGroupings: [],
  component: CreateProviderWizardView,
})
