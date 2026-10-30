import { createFileRoute } from '@tanstack/react-router'
import {
  AuthEmailPage,
  authEmailSearchSchema,
  loadAuthEmailPage,
} from '@/components/global/auth/AuthEmailPage'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/partner/sign-in')({
  component: PartnerSignInPage,
  validateSearch: authEmailSearchSchema,
  preload: false,
  loader: async ({ context, location }) => {
    await loadAuthEmailPage({
      queryClient: context.queryClient,
      location,
      audience: 'partner',
    })
  },
  head: () => ({ meta: [{ title: pageTitle('Partner sign in') }] }),
})

function PartnerSignInPage() {
  return <AuthEmailPage mode="sign-in" audience="partner" />
}
