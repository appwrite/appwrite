import { useCallback, useEffect, useRef, useState } from 'react'
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
import { getSmtpQuickSetupProvider } from '@/lib/smtp/providers'
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
 *
 * The claim runs automatically only when it matches the record this browser
 * wrote before leaving. Otherwise the user confirms it by hand: a crafted link
 * must never sign somebody in silently, but refusing outright would strand a
 * real user whose session storage did not survive the round trip.
 */
export const Route = createFileRoute('/_auth/auth/smtp/callback')({
  ssr: false,
  component: SmtpQuickSetupCallbackPage,
  head: () => ({ meta: [{ title: pageTitle('Connecting email provider') }] }),
})

interface CallbackParams {
  status: string | null
  providerId: string
  projectId: string
  userId: string
  secret: string
  error: string
}

/** Read params off the raw URL so the long JWT secret is never re-encoded. */
function readCallbackParams(): CallbackParams | null {
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

type Phase = 'claiming' | 'confirm' | 'error'

function SmtpQuickSetupCallbackPage() {
  const t = useT()
  const [phase, setPhase] = useState<Phase>('claiming')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [params, setParams] = useState<CallbackParams | null>(null)
  const startedRef = useRef(false)

  const claim = useCallback(
    async (callback: CallbackParams) => {
      setPhase('claiming')
      try {
        await claimProviderIdentity({
          userId: callback.userId,
          secret: callback.secret,
        })
        clearQuickSetupPending()
        // Hard navigation so the restored session is picked up everywhere.
        window.location.replace(
          buildQuickSetupReturnPath({
            projectId: callback.projectId,
            providerId: callback.providerId,
            status: 'connected',
          }),
        )
      } catch {
        clearQuickSetupPending()
        setErrorMessage(
          t(
            'This authorization link was already used or has expired. Start the setup again from SMTP settings.',
          ),
        )
        setPhase('error')
      }
    },
    [t],
  )

  useEffect(() => {
    if (startedRef.current) return
    startedRef.current = true

    const callback = readCallbackParams()
    setParams(callback)

    if (!callback?.providerId || !callback.projectId) {
      setErrorMessage(t('This link is missing required parameters.'))
      setPhase('error')
      return
    }

    // Provider or Appwrite refused; the SMTP tab shows why.
    if (
      callback.status !== 'connected' ||
      !callback.userId ||
      !callback.secret
    ) {
      clearQuickSetupPending()
      window.location.replace(
        buildQuickSetupReturnPath({
          projectId: callback.projectId,
          providerId: callback.providerId,
          status: 'failed',
          error: callback.error || undefined,
        }),
      )
      return
    }

    const pending = readQuickSetupPending()
    if (
      !isExpectedQuickSetupClaim(pending, {
        providerId: callback.providerId,
        projectId: callback.projectId,
        userId: callback.userId,
      })
    ) {
      // This browser has no matching record of starting the flow, so the claim
      // needs a deliberate click rather than running on page load.
      setPhase('confirm')
      return
    }

    void claim(callback)
  }, [claim, t])

  const providerName = params?.providerId
    ? (getSmtpQuickSetupProvider(params.providerId)?.name ?? params.providerId)
    : ''

  const settingsPath =
    params?.projectId && params.providerId
      ? buildQuickSetupReturnPath({
          projectId: params.projectId,
          providerId: params.providerId,
          status: 'failed',
        })
      : null

  return (
    <div className="bg-background relative h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-md">
          <Card className="overflow-hidden p-6 md:p-8">
            {phase === 'claiming' ? (
              <div className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Finishing setup')}
                </h1>
                <p className="text-muted-foreground text-[13px] flex items-center justify-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('Restoring your session and returning to SMTP settings.')}
                </p>
              </div>
            ) : null}

            {phase === 'confirm' && params ? (
              <div className="space-y-4">
                <div className="space-y-2 text-center">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Confirm setup')}
                  </h1>
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {t(
                      'This browser has no record of starting this authorization, so we will not sign you in automatically.',
                    )}
                  </p>
                </div>

                <dl className="rounded-lg border border-border bg-background px-4 py-3 text-[12px]">
                  <div className="flex items-center justify-between gap-4">
                    <dt className="text-muted-foreground">{t('Provider')}</dt>
                    <dd className="text-foreground">{providerName}</dd>
                  </div>
                  <div className="mt-1 flex items-center justify-between gap-4">
                    <dt className="text-muted-foreground">{t('Project')}</dt>
                    <dd className="text-foreground truncate">
                      {params.projectId}
                    </dd>
                  </div>
                </dl>

                <p className="text-muted-foreground text-[12px] leading-relaxed">
                  {t(
                    'Continuing signs you in to the Appwrite account that authorized this provider.',
                  )}
                </p>

                <div className="flex flex-col gap-2">
                  <Button className="w-full" onClick={() => void claim(params)}>
                    {t('Continue')}
                  </Button>
                  <Link to="/sign-in">
                    <Button variant="outline" className="w-full">
                      {t('Go to sign in')}
                    </Button>
                  </Link>
                </div>
              </div>
            ) : null}

            {phase === 'error' ? (
              <div className="space-y-4 text-center">
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Unable to finish setup')}
                  </h1>
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {errorMessage}
                  </p>
                </div>
                <div className="flex flex-col gap-2">
                  {settingsPath ? (
                    <Button
                      className="w-full"
                      onClick={() => window.location.assign(settingsPath)}
                    >
                      {t('Back to SMTP settings')}
                    </Button>
                  ) : null}
                  <Link to="/sign-in">
                    <Button
                      variant={settingsPath ? 'outline' : 'default'}
                      className="w-full"
                    >
                      {t('Go to sign in')}
                    </Button>
                  </Link>
                </div>
              </div>
            ) : null}
          </Card>
          <div className="mt-10 flex justify-center md:mt-16">
            <AppwriteLogo className="h-6 w-auto" />
          </div>
        </div>
      </div>
    </div>
  )
}
