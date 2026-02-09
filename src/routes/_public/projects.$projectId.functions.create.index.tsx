/**
 * Create Function Index Route
 *
 * Entry point for the function creation wizard (Connect Git | Quick start).
 */

import { createFileRoute } from '@tanstack/react-router'
import { CreateFunctionView } from '@/components/pages/projects/$projectId/functions/create/CreateFunctionView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/',
)({
  component: CreateFunctionPage,
})

function CreateFunctionPage() {
  return <CreateFunctionView />
}
