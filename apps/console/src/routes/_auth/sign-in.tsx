import { createFileRoute } from '@tanstack/react-router'
import {
  AuthEmailPage,
  audienceFromSearch,
  authEmailSearchSchema,
  loadAuthEmailPage,
} from '@/components/global/auth/AuthEmailPage'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/sign-in')({
  component: SignInPage,
  validateSearch: authEmailSearchSchema,
  preload: false,
  loader: async ({ context, location }) => {
    await loadAuthEmailPage({
      queryClient: context.queryClient,
      location,
      audience: audienceFromSearch(location.search),
    })
  },
  head: () => ({ meta: [{ title: pageTitle('Sign in') }] }),
})

function SignInPage() {
  const search = Route.useSearch()
  return (
    <AuthEmailPage
      mode="sign-in"
      audience={search.audience === 'partner' ? 'partner' : 'console'}
    />
  )
}
