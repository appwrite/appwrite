/**
 * Sites Creation Wizard Layout Route
 *
 * Parent layout for the multi-step site creation wizard.
 * Loads shared data (installations, frameworks) and provides context to child routes.
 */

import { createFileRoute, Outlet, useParams } from '@tanstack/react-router'
import { useEffect } from 'react'
import {
  vcsInstallationsQueryOptions,
  siteFrameworksQueryOptions,
  fetchProject,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { useQuery } from '@tanstack/react-query'
import {
  WizardProvider,
  useWizard,
} from '@/components/pages/projects/$projectId/sites/create/WizardContext'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Sites') }] }),
  // Keep the previous page visible until the loader finishes (no pending flash).
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Fetch project data first
      const projectData = await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })

      await Promise.all([
        queryClient.ensureQueryData(vcsInstallationsQueryOptions(projectId)),
        queryClient.ensureQueryData(siteFrameworksQueryOptions(projectId)),
      ])

      return { projectData }
    }
  },
  component: CreateSiteLayout,
})

function CreateSiteLayout() {
  return (
    <WizardProvider>
      <CreateSiteLayoutInner />
    </WizardProvider>
  )
}

function CreateSiteLayoutInner() {
  const { projectId } = useParams({ strict: false })
  const { setInstallations, setFrameworks, setBaseDomain } = useWizard()

  // Fetch installations
  const { data: installationsData } = useQuery(
    vcsInstallationsQueryOptions(projectId),
  )

  // Fetch frameworks
  const { data: frameworksData } = useQuery(
    siteFrameworksQueryOptions(projectId),
  )

  const { data: project } = useQuery(projectQueryOptions(projectId as string))
  const { sitesDomain } = useConsoleVariables(project?.region)

  // Update context when data loads
  useEffect(() => {
    if (installationsData?.installations) {
      setInstallations(installationsData.installations)
    }
  }, [installationsData, setInstallations])

  useEffect(() => {
    if (frameworksData?.frameworks) {
      setFrameworks(frameworksData.frameworks)
    }
  }, [frameworksData, setFrameworks])

  useEffect(() => {
    setBaseDomain(sitesDomain ?? 'appwrite.network')
  }, [sitesDomain, setBaseDomain])

  return <Outlet />
}
