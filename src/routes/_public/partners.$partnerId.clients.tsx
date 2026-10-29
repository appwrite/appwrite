import { Outlet, createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/partners/$partnerId/clients')({
  component: ClientsLayout,
})

function ClientsLayout() {
  return <Outlet />
}
