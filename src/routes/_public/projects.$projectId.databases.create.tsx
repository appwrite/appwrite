import { createFileRoute, useNavigate, useParams } from '@tanstack/react-router'
import { useEffect } from 'react'
import { CreateDatabaseWizardView } from '@/components/pages/projects/$projectId/databases/create/CreateDatabaseWizardView'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  databaseSpecificationsQueryOptions,
  enabledDatabaseSpecificationsSources,
  organizationPlanQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { projectSupportsDedicatedDatabaseCompute } from '@/lib/databases/dedicated-database-regions'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/create',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create database', 'Databases') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    if (projectData?.teamId) {
      await queryClient
        .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
        .catch(() => null)
    }
    const shouldPrefetchSpecs = projectSupportsDedicatedDatabaseCompute(
      projectData?.region,
    )
    if (shouldPrefetchSpecs) {
      await Promise.all(
        enabledDatabaseSpecificationsSources().map((source) =>
          queryClient.ensureQueryData(
            databaseSpecificationsQueryOptions(projectId, source),
          ),
        ),
      )
    }
  },
  // Disable lazy split for this route: avoids dev failures loading
  // `*.tsx?tsr-split=component` (e.g. rolldown/vite transform or HMR edge cases).
  codeSplitGroupings: [],
  component: CreateDatabaseWizardPage,
})

function CreateDatabaseWizardPage() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const showWizard = features.dedicatedDbsSupport

  useEffect(() => {
    if (!showWizard && projectId) {
      navigate({
        to: '/projects/$projectId/databases',
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
