/**
 * Create Function Index Route
 *
 * Entry point for the function creation wizard (Connect Git | Quick start).
 */

import { createFileRoute } from '@tanstack/react-router'
import { CreateFunctionView } from '@/components/pages/projects/$projectId/functions/create/CreateFunctionView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Functions') }] }),
  component: CreateFunctionPage,
})

function CreateFunctionPage() {
  return <CreateFunctionView />
}
