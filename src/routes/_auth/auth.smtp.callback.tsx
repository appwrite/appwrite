import { useEffect, useRef, useState } from 'react'
import { createFileRoute, Link } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import {
  QUICK_SETUP_PROJECT_PARAM,
  QUICK_SETUP_PROVIDER_PARAM,
  QUICK_SETUP_STATUS_PARAM,
  buildQuickSetupReturnPath,
  clearQuickSetupPending,
  isExpectedQuickSetupClaim,
  readQuickSetupPending,
} from '@/lib/smtp/quick-setup'
import { claimProviderIdentity } from '@/lib/smtp/quick-setup-oauth'

/**
 * OAuth2 landing page for the project SMTP quick setup.
 *
 * Appwrite deletes the caller's session as soon as an OAuth2 flow starts for a
 * signed-in user, so the browser arrives here signed out with a one-time token
 * in the URL. This route lives under `_auth` (guests allowed), turns the token
 * back into a console session, and only then sends the user to the SMTP tab.
 * Landing on the tab directly would bounce through /sign-in and strand the
 * secret in the redirect URL.
 */
export const Route = createFileRoute('/_auth/auth/smtp/callback')({
  ssr: false,
  component: SmtpQuickSetupCallbackPage,
  head: () => ({ meta: [{ title: pageTitle('Connecting email provider') }] }),
})

/** Read params off the raw URL so the long JWT secret is never re-encoded. */
function readCallbackParams() {
  if (typeof window === 'undefined') return null
  const params = new URLSearchParams(window.location.search)
  return {
    status: params.get(QUICK_SETUP_STATUS_PARAM),
    providerId: params.get(QUICK_SETUP_PROVIDER_PARAM)?.trim() ?? '',
    projectId: params.get(QUICK_SETUP_PROJECT_PARAM)?.trim() ?? '',
    userId: params.get('userId')?.trim() ?? '',
    secret: params.get('secret') ?? '',
    error: params.get('error') ?? '',
  }
}

function SmtpQuickSetupCallbackPage() {
  const t = useT()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const hasRunRef = useRef(false)

  useEffect(() => {
    if (hasRunRef.current) return
    hasRunRef.current = true

    const params = readCallbackParams()
    if (!params?.providerId || !params.projectId) {
      setErrorMessage(t('This link is missing required parameters.'))
      return
    }

    const { providerId, projectId } = params
    const goToSettings = (status: 'connected' | 'failed', error?: string) => {
      // Hard navigation so the restored session is picked up everywhere.
      window.location.replace(
        buildQuickSetupReturnPath({ projectId, providerId, status, error }),
      )
    }

    // Provider or Appwrite refused; the tab shows why.
    if (params.status !== 'connected' || !params.userId || !params.secret) {
      clearQuickSetupPending()
      goToSettings('failed', params.error || undefined)
      return
    }

    const pending = readQuickSetupPending()
    if (
      !isExpectedQuickSetupClaim(pending, {
        providerId,
        projectId,
        userId: params.userId,
      })
    ) {
      // Either this browser never started the flow, or the token is for a
      // different account. Creating a session here would sign the user in as
      // whoever the link points at.
      clearQuickSetupPending()
      setErrorMessage(
        t('This authorization does not match the account that started it.'),
      )
      return
    }

    void (async () => {
      try {
        await claimProviderIdentity({
          userId: params.userId,
          secret: params.secret,
        })
        clearQuickSetupPending()
        goToSettings('connected')
      } catch {
        clearQuickSetupPending()
        setErrorMessage(
          t(
            'We could not finish connecting your email provider. Sign in and try again.',
          ),
        )
      }
    })()
  }, [t])

  return (
    <div className="bg-background relative h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-md">
          <Card className="overflow-hidden p-6 md:p-8">
            {errorMessage ? (
              <div className="space-y-4 text-center">
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Unable to finish setup')}
                  </h1>
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {errorMessage}
                  </p>
                </div>
                <Link to="/sign-in">
                  <Button className="w-full">{t('Go to sign in')}</Button>
                </Link>
              </div>
            ) : (
              <div className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Finishing setup')}
                </h1>
                <p className="text-muted-foreground text-[13px] flex items-center justify-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('Restoring your session and returning to SMTP settings.')}
                </p>
              </div>
            )}
          </Card>
          <div className="mt-10 flex justify-center md:mt-16">
            <AppwriteLogo className="h-6 w-auto" />
          </div>
        </div>
      </div>
    </div>
  )
}
