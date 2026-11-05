import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy `/app` entry: permanent client redirect to `/`. */
export const Route = createFileRoute('/_public/app')({
  ssr: false,
  loader: ({ location }) => {
    throw redirect({
      to: '/',
      replace: true,
      search: location.search,
    })
  },
  component: () => null,
})
