import { createFileRoute } from '@tanstack/react-router'
import { CreateEnvironmentWizardView } from '@/components/pages/projects/$projectId/environments/create/CreateEnvironmentWizardView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/environments/create',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create environment', 'Environments') }],
  }),
  component: CreateEnvironmentWizardPage,
})

function CreateEnvironmentWizardPage() {
  const { projectId } = Route.useParams()
  return <CreateEnvironmentWizardView projectId={projectId} />
}
