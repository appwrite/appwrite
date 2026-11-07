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
  // Values come from links built outside the Console (emails, other sites). The router
  // JSON-parses search values, so a number-like one (`2024`, `true`) arrives as a number
  // or boolean: coerce it back to its text rather than fail the page.
  repo: z.coerce.string().optional(),
  owner: z.coerce.string().optional(),
  framework: z.coerce.string().optional(),
  branch: z.coerce.string().optional(),
  root: z.coerce.string().optional(),
  installCommand: z.coerce.string().optional(),
  buildCommand: z.coerce.string().optional(),
  startCommand: z.coerce.string().optional(),
  outputDirectory: z.coerce.string().optional(),
  envKeys: z.coerce.string().optional(), // Comma-separated list of env var keys
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
