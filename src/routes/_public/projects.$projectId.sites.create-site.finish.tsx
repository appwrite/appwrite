/**
 * Deployment Complete Screen
 *
 * Success screen with site preview and next steps.
 */

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { FinishView } from '@/components/pages/projects/$projectId/sites/create-site/FinishView'

const searchSchema = z.object({
  siteId: z.string().optional(),
  deploymentId: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create-site/finish',
)({
  validateSearch: searchSchema,
  component: FinishPage,
})

function FinishPage() {
  const { siteId, deploymentId } = Route.useSearch()
  return <FinishView siteId={siteId} deploymentId={deploymentId} />
}
