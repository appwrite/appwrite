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
  siteTemplatesQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
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
      // Fetch project data first
      const projectData = await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })

      // Prefetch VCS installations, frameworks, and first page of templates in parallel
      // First page of templates: limit 9, offset 0, no filters (matches CreateSiteView initial request)
      await Promise.all([
        queryClient.ensureQueryData(vcsInstallationsQueryOptions(projectId)),
        queryClient.ensureQueryData(siteFrameworksQueryOptions(projectId)),
        queryClient.ensureQueryData(
          siteTemplatesQueryOptions(projectId, undefined, undefined, 9, 0),
        ),
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

  // TODO: Fetch base domain from console variables
  // For now, use a default value
  useEffect(() => {
    setBaseDomain('appwrite.network')
  }, [setBaseDomain])

  return <Outlet />
}
