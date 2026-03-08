import { createFileRoute, useNavigate, useParams } from '@tanstack/react-router'
import { useEffect } from 'react'
import { CreateDatabaseWizardView } from '@/components/pages/projects/$projectId/databases/create/CreateDatabaseWizardView'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/create',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create database', 'Databases') }],
  }),
  component: CreateDatabaseWizardPage,
})

function CreateDatabaseWizardPage() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const showWizard = features.createDatabaseWizard

  useEffect(() => {
    if (!showWizard && projectId) {
      navigate({
        to: '/projects/$projectId/databases/',
        params: { projectId },
        search: { create: 'database' },
        replace: true,
      })
    }
  }, [showWizard, projectId, navigate])

  if (!showWizard) {
    return null
  }

  return <CreateDatabaseWizardView />
}
