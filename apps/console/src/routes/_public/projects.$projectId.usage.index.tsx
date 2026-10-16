import { createFileRoute, redirect } from '@tanstack/react-router'
import { getDefaultUsageCategoryId } from '@/components/pages/projects/$projectId/usage/usage-nav'

export const Route = createFileRoute('/_public/projects/$projectId/usage/')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/usage/$categoryId',
      params: {
        projectId: params.projectId,
        categoryId: getDefaultUsageCategoryId(),
      },
      replace: true,
    })
  },
})
