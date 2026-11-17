import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sandboxes/$sandboxId/View'
import type { Sandbox } from '@/lib/appwrite/sandboxes'
import {
  projectQueryOptions,
  sandboxQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

// The router types this route's loader data as never, as on other detail routes.
type SandboxLoaderData = { sandbox: Sandbox | null } | undefined

export const Route = createFileRoute(
  '/_public/projects/$projectId/sandboxes/$sandboxId',
)({
  head: ({ params }) => ({
    meta: [{ title: pageTitle(params.sandboxId, 'Sandboxes') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, sandboxId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const sandbox = await queryClient
      .fetchQuery(sandboxQueryOptions(projectId, sandboxId))
      .catch(() => null)
    return { sandbox }
  },
  component: SandboxPage,
})

function SandboxPage() {
  const { sandboxId } = Route.useParams()
  const data = Route.useLoaderData() as SandboxLoaderData
  return (
    <View
      key={`sandbox-${sandboxId}`}
      initialSandbox={data?.sandbox ?? undefined}
    />
  )
}
