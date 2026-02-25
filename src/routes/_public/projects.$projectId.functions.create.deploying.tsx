/**
 * Function Build Progress Screen
 *
 * Shows real-time build progress with logs after creating a function.
 */

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { DeployingView } from '@/components/pages/projects/$projectId/functions/create/DeployingView'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  functionId: z.string().optional(),
  deploymentId: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/deploying',
)({
  head: () => ({ meta: [{ title: pageTitle('Deploying', 'Functions') }] }),
  validateSearch: searchSchema,
  component: DeployingPage,
})

function DeployingPage() {
  const { functionId, deploymentId } = Route.useSearch()
  return <DeployingView functionId={functionId} deploymentId={deploymentId} />
}
