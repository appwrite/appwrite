/**
 * Deployment Complete Screen
 *
 * Redirects to the deploying route with the same params. The deploying view
 * evolves in place when status is ready, so one URL and one view for the full flow.
 */

import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  siteId: z.string().optional(),
  deploymentId: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/finish',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Sites') }] }),
  validateSearch: searchSchema,
  beforeLoad: ({ params, search }) => {
    throw redirect({
      to: '/projects/$projectId/sites/create/deploying',
      params: { projectId: params.projectId },
      search: { siteId: search.siteId, deploymentId: search.deploymentId },
      replace: true,
    })
  },
  component: FinishRedirect,
})

function FinishRedirect() {
  return null
}
