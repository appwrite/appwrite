import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { toast } from 'sonner'
import { SignIn } from '@/components/global/auth/SignIn'
import { AuthFlowPreviewLayout } from '@/components/global/auth/debug/AuthFlowPreviewLayout'
import type { OAuthLoginMethod } from '@/lib/utils/auth-storage'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/sign-up-preview')({
  head: () => ({ meta: [{ title: pageTitle('Sign up preview') }] }),
  component: SignUpPreviewPage,
})

function SignUpPreviewPage() {
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )

  return (
    <AuthFlowPreviewLayout>
      <SignIn
        preview
        mode="sign-up"
        onSubmit={() => {
          toast.message('Preview only', {
            description: 'Use the real /sign-up route to create an account.',
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
