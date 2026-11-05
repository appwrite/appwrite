import { createFileRoute } from '@tanstack/react-router'
import { ImportWizardView } from '@/components/pages/projects/$projectId/settings/migrations/ImportWizardView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/migrations/import',
)({
  head: () => ({
    meta: [{ title: pageTitle('Import data', 'Migrations', 'Settings') }],
  }),
  component: ImportWizardPage,
})

function ImportWizardPage() {
  return <ImportWizardView />
}
