import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy path: redirect to /agent/mcp/callback, preserving OAuth search params. */
export const Route = createFileRoute('/_auth/assistant/mcp/callback')({
  validateSearch: (search: Record<string, unknown>) => search,
  beforeLoad: ({ search }) => {
    throw redirect({
      to: '/agent/mcp/callback',
      search,
      replace: true,
    })
  },
})
