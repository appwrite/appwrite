/**
 * Quick Deploy Screen
 *
 * Deploy directly from a GitHub repository URL.
 * Typically accessed via external links with repository info in URL parameters.
 */

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { QuickDeployView } from '@/components/pages/projects/$projectId/sites/create/QuickDeployView'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  repo: z.string().optional(),
  owner: z.string().optional(),
  framework: z.string().optional(),
  branch: z.string().optional(),
  root: z.string().optional(),
  installCommand: z.string().optional(),
  buildCommand: z.string().optional(),
  startCommand: z.string().optional(),
  outputDirectory: z.string().optional(),
  envKeys: z.string().optional(), // Comma-separated list of env var keys
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/deploy',
)({
  head: () => ({ meta: [{ title: pageTitle('Quick deploy', 'Sites') }] }),
  validateSearch: searchSchema,
  component: QuickDeployPage,
})

function QuickDeployPage() {
  const search = Route.useSearch()
  return <QuickDeployView {...search} />
}
