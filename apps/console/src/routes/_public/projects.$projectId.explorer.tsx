import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/explorer')({
  validateSearch: (search: Record<string, unknown>) => ({
    service: typeof search.service === 'string' ? search.service : undefined,
    operation:
      typeof search.operation === 'string' ? search.operation : undefined,
  }),
  beforeLoad: ({ params, search }) => {
    throw redirect({
      to: '/projects/$projectId/playground',
      params: { projectId: params.projectId },
      search,
      replace: true,
    })
  },
})
