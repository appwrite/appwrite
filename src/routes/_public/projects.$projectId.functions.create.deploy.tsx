/**
 * Deploy from URL Route
 *
 * Create a function by deploying from a GitHub repo URL (e.g. "Deploy to Appwrite" flow).
 * Query params: repo / repository, optional runtime, entrypoint, install, rootDir, env.
 */

import { createFileRoute } from '@tanstack/react-router'
import { DeployFromUrlView } from '@/components/pages/projects/$projectId/functions/create/DeployFromUrlView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/deploy',
)({
  head: () => ({
    meta: [{ title: pageTitle('Deploy from URL', 'Functions') }],
  }),
  component: DeployFromUrlPage,
})

function DeployFromUrlPage() {
  const search = Route.useSearch({ strict: false }) as {
    repo?: string
    repository?: string
    runtime?: string
    entrypoint?: string
    install?: string
    rootDir?: string
    env?: string
  }
  return (
    <DeployFromUrlView
      repoFromSearch={search?.repo || search?.repository}
      runtimeFromSearch={search?.runtime}
      entrypointFromSearch={search?.entrypoint}
      installFromSearch={search?.install}
      rootDirFromSearch={search?.rootDir}
      envFromSearch={search?.env}
    />
  )
}
