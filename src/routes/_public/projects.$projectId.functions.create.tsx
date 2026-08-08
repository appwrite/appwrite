/**
 * Function Creation Wizard Layout Route
 *
 * Parent layout for the function creation wizard.
 * Loads shared data (installations, runtimes, templates) for the create flow.
 */

import { createFileRoute, Outlet, useParams } from '@tanstack/react-router'
import { useEffect } from 'react'
import {
  fetchProject,
  functionTemplatesPageQueryOptions,
  vcsInstallationsQueryOptions,
  projectRuntimesQueryOptions,
  functionSpecificationsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { SpecificationType } from '@/lib/specifications'
import {
  CREATE_FUNCTION_WIZARD_BROWSE_LIMIT,
  CREATE_FUNCTION_WIZARD_STARTER_LIMIT,
} from '@/lib/react-query/hooks/constants'
import { useQuery } from '@tanstack/react-query'
import {
  FunctionWizardProvider,
  useFunctionWizard,
} from '@/components/pages/projects/$projectId/functions/create/WizardContext'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Functions') }] }),
  // Keep the previous page visible until the loader finishes (no pending flash).
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
          functionSpecificationsQueryOptions(
            projectId,
            SpecificationType.Builds,
          ),
        ),
        queryClient.ensureQueryData(
          functionTemplatesPageQueryOptions(
            projectId,
            0,
            CREATE_FUNCTION_WIZARD_STARTER_LIMIT,
            [],
            ['starter'],
          ),
        ),
        queryClient.ensureQueryData(
          functionTemplatesPageQueryOptions(
            projectId,
            0,
            CREATE_FUNCTION_WIZARD_BROWSE_LIMIT,
            [],
            [],
          ),
        ),
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
  const { setInstallations, setBaseDomain, setRegion } = useFunctionWizard()

  const { data: installationsData } = useQuery(
    vcsInstallationsQueryOptions(projectId, 0, 100),
  )
  const { data: project } = useQuery(projectQueryOptions(projectId as string))
  const { functionsDomain } = useConsoleVariables(project?.region)

  useEffect(() => {
    if (installationsData?.installations) {
      setInstallations(installationsData.installations)
    }
  }, [installationsData, setInstallations])

  useEffect(() => {
    setBaseDomain(functionsDomain ?? 'appwrite.network')
  }, [functionsDomain, setBaseDomain])

  useEffect(() => {
    if (project?.region) {
      setRegion(project.region)
    }
  }, [project?.region, setRegion])

  return <Outlet />
}
