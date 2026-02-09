/**
 * Function Creation Wizard Layout Route
 *
 * Parent layout for the function creation wizard.
 * Loads shared data (installations, runtimes, templates) for the create flow.
 */

import { createFileRoute, Outlet, useParams } from '@tanstack/react-router'
import { useEffect } from 'react'
import {
  fetchFunctionTemplates,
  fetchProject,
  vcsInstallationsQueryOptions,
  projectRuntimesQueryOptions,
  functionSpecificationsQueryOptions,
} from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import {
  FunctionWizardProvider,
  useFunctionWizard,
} from '@/components/pages/projects/$projectId/functions/create/WizardContext'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create',
)({
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading wizard...</div>
    </div>
  ),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })

      await Promise.all([
        queryClient.ensureQueryData(
          vcsInstallationsQueryOptions(projectId, 0, 100),
        ),
        queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
        queryClient.ensureQueryData(
          functionSpecificationsQueryOptions(projectId),
        ),
        queryClient.ensureQueryData({
          queryKey: [
            'function-templates',
            'project',
            projectId,
            0,
            100,
            0,
            null,
            null,
          ],
          queryFn: () =>
            fetchFunctionTemplates(
              projectId,
              undefined,
              undefined,
              100,
              0,
              true,
            ),
          staleTime: 5 * 60 * 1000,
        }),
        queryClient.ensureQueryData({
          queryKey: [
            'function-templates',
            'project',
            projectId,
            0,
            6,
            0,
            null,
            'starter',
          ],
          queryFn: () =>
            fetchFunctionTemplates(
              projectId,
              undefined,
              ['starter'],
              6,
              0,
              true,
            ),
          staleTime: 5 * 60 * 1000,
        }),
      ])

      return {}
    }
  },
  component: CreateFunctionLayout,
})

function CreateFunctionLayout() {
  return (
    <FunctionWizardProvider>
      <CreateFunctionLayoutInner />
    </FunctionWizardProvider>
  )
}

function CreateFunctionLayoutInner() {
  const { projectId } = useParams({ strict: false })
  const { setInstallations, setBaseDomain } = useFunctionWizard()

  const { data: installationsData } = useQuery(
    vcsInstallationsQueryOptions(projectId, 0, 100),
  )

  useEffect(() => {
    if (installationsData?.installations) {
      setInstallations(installationsData.installations)
    }
  }, [installationsData, setInstallations])

  useEffect(() => {
    setBaseDomain('appwrite.network')
  }, [setBaseDomain])

  return <Outlet />
}
