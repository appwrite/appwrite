/**
 * Deployment Progress Screen
 *
 * Shows real-time deployment progress with logs.
 */

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { DeployingView } from '@/components/pages/projects/$projectId/sites/create/DeployingView'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  siteId: z.string().optional(),
  deploymentId: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/deploying',
)({
  head: () => ({ meta: [{ title: pageTitle('Deploying', 'Sites') }] }),
  validateSearch: searchSchema,
  component: DeployingPage,
})

function DeployingPage() {
  const { siteId, deploymentId } = Route.useSearch()
  return <DeployingView siteId={siteId} deploymentId={deploymentId} />
}
