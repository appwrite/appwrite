import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/agent/automations')({
  component: () => <Outlet />,
})
