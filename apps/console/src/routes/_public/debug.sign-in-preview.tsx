import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { toast } from 'sonner'
import { SignIn } from '@/components/global/auth/SignIn'
import { AuthFlowPreviewLayout } from '@/components/global/auth/debug/AuthFlowPreviewLayout'
import type { OAuthLoginMethod } from '@/lib/utils/auth-storage'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/sign-in-preview')({
  head: () => ({ meta: [{ title: pageTitle('Sign in preview') }] }),
  component: SignInPreviewPage,
})

function SignInPreviewPage() {
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )

  return (
    <AuthFlowPreviewLayout>
      <SignIn
        preview
        mode="sign-in"
        onSubmit={() => {
          toast.message('Preview only', {
            description: 'Use the real /sign-in route to authenticate.',
          })
        }}
        onOAuthLogin={(provider) => {
          setOauthLoading(provider)
          toast.message('Preview only', {
            description: `${provider} OAuth is disabled on this preview route.`,
          })
          window.setTimeout(() => setOauthLoading(null), 400)
        }}
        oauthLoading={oauthLoading}
      />
    </AuthFlowPreviewLayout>
  )
}
