import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/account/payments')({
  beforeLoad: () => {
    throw redirect({ to: '/account/payment-methods', replace: true })
  },
})
