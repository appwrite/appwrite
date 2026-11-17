import { createFileRoute, redirect } from '@tanstack/react-router'
import { domainsContinueSearchSchema } from '@/routes/domains.continue'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/continue',
)({
  validateSearch: domainsContinueSearchSchema,
  beforeLoad: ({ search }) => {
    throw redirect({ to: '/domains/continue', search, replace: true })
  },
})
