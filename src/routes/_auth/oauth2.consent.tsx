import { useEffect, useState } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { Loader2, TriangleAlert } from 'lucide-react'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { OAuth2ConsentCard } from '@/components/global/auth/OAuth2ConsentCard'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

// Loose validation: the consent screen receives either a `grant_id` or the full
// set of OAuth2 authorize params (whose values include URLs we must not touch).
const searchSchema = (
  search: Record<string, unknown>,
): Record<string, string | undefined> => {
  const out: Record<string, string | undefined> = {}
  for (const [key, value] of Object.entries(search)) {
    if (typeof value === 'string') out[key] = value
  }
  return out
}

export const Route = createFileRoute('/_auth/oauth2/consent')({
  component: OAuth2ConsentPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Authorize application') }] }),
})

type Phase = 'loading' | 'ready' | 'error'

function OAuth2ConsentPage() {
  const t = useT()
  const navigate = useNavigate()
  const search = Route.useSearch()
  const [phase, setPhase] = useState<Phase>('loading')
  const [grant, setGrant] = useState<Models.Oauth2Grant | null>(null)
  const [app, setApp] = useState<Models.App | null>(null)
  const [account, setAccount] =
    useState<Models.User<Models.Preferences> | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    // Re-runs when the authorize params change (this route can stay mounted as
    // the router moves between requests). Reset to loading so a previously
    // loaded grant can never be approved against a different request.
    setPhase('loading')
    setError(null)

    const currentRelativeUrl = window.location.pathname + window.location.search

    const goSignIn = () => {
      navigate({
        to: '/sign-in',
        search: { redirect: currentRelativeUrl },
        replace: true,
      })
    }

    // Load a grant + its app branding and render the consent card.
    async function loadConsent(
      grantId: string,
      knownAccount?: Models.User<Models.Preferences> | null,
    ) {
      const loadedGrant = await sdk.forConsole.oauth2.getGrant({
        grantId,
      })
      const [loadedApp, loadedAccount] = await Promise.all([
        sdk.forConsole.apps.get({ appId: loadedGrant.appId }),
        knownAccount !== undefined
          ? Promise.resolve(knownAccount)
          : (sdk.forConsole.account
              .get()
              .catch(
                () => null,
              ) as Promise<Models.User<Models.Preferences> | null>),
      ])
      if (cancelled) return
      setGrant(loadedGrant)
      setApp(loadedApp)
      setAccount(loadedAccount)
      setPhase('ready')
    }

    async function init() {
      const grantId = search.grant_id

      if (grantId) {
        try {
          await loadConsent(grantId)
        } catch (e: unknown) {
          if (cancelled) return
          if (e instanceof AppwriteException && e.code === 401) {
            goSignIn()
            return
          }
          setError(
            getErrorMessage(
              e,
              t('This authorization request is invalid or has expired.'),
            ),
          )
          setPhase('error')
        }
        return
      }

      // No grant yet: this is the pre-login entry with raw authorize params.
      if (search.client_id) {
        const loggedInAccount = (await sdk.forConsole.account
          .get()
          .catch(() => null)) as Models.User<Models.Preferences> | null
        if (cancelled) return

        if (!loggedInAccount) {
          goSignIn()
          return
        }

        // Authenticated: ask the server to create a grant (or detect an existing
        // approved identity) via the SDK, then render consent or redirect.
        try {
          const result = await sdk.forConsole.oauth2.authorize({
            clientId: search.client_id,
            redirectUri: search.redirect_uri ?? '',
            responseType: search.response_type ?? 'code',
            scope: search.scope ?? '',
            state: search.state,
            nonce: search.nonce,
            codeChallenge: search.code_challenge,
            codeChallengeMethod: search.code_challenge_method,
            prompt: search.prompt,
            maxAge: search.max_age ? Number(search.max_age) : undefined,
            authorizationDetails: search.authorization_details,
          })
          if (cancelled) return
          if (result.redirectUrl) {
            // Already consented — go straight back to the client.
            window.location.assign(result.redirectUrl)
            return
          }
          if (result.grantId) {
            await loadConsent(result.grantId, loggedInAccount)
            return
          }
          setError(t('Could not start authorization.'))
          setPhase('error')
        } catch (e: unknown) {
          if (cancelled) return
          setError(getErrorMessage(e, t('Could not start authorization.')))
          setPhase('error')
        }
        return
      }

      setError(
        t(
          'Missing authorization request. Open this page from an application sign-in.',
        ),
      )
      setPhase('error')
    }

    void init()
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-md">
        {phase === 'loading' && (
          <div className="flex min-h-64 items-center justify-center">
            <Loader2 className="text-muted-foreground size-8 animate-spin" />
          </div>
        )}

        {phase === 'error' && (
          <Card className="overflow-hidden p-6 md:p-8">
            <div className="space-y-4 text-center">
              <div className="bg-destructive/10 mx-auto flex size-12 items-center justify-center rounded-full">
                <TriangleAlert className="text-destructive size-6" />
              </div>
              <h1 className="text-xl font-semibold tracking-tight">
                {t('Authorization failed')}
              </h1>
              <p className="text-muted-foreground text-sm">{error}</p>
              <Button
                variant="outline"
                onClick={() => navigate({ to: '/', replace: true })}
              >
                {t('Go to console')}
              </Button>
            </div>
          </Card>
        )}

        {phase === 'ready' && grant && app && (
          <OAuth2ConsentCard
            grant={grant}
            app={app}
            accountLabel={account?.email || account?.name || undefined}
            flow="authorization"
          />
        )}

        <div className="mt-10 flex justify-center md:mt-16">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
