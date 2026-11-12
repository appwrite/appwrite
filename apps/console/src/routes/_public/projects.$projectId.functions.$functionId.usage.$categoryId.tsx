import { createFileRoute, redirect } from '@tanstack/react-router'
import { resourceUsageCategories } from '@/components/pages/projects/$projectId/shared/ResourceUsage'

/** Names the open category; the parent Usage route renders it. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/usage/$categoryId',
)({
  beforeLoad: ({ params }) => {
    const categories: string[] = resourceUsageCategories('function')
    if (!categories.includes(params.categoryId)) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId/usage',
        params,
        replace: true,
      })
    }
  },
  component: () => null,
})
