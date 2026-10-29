import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/partners/$partnerId/projects')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/partners/$partnerId',
      params: { partnerId: params.partnerId },
      replace: true,
    })
  },
})
