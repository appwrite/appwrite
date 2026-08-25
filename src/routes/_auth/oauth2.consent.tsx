import { useEffect, useState } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Loader2, TriangleAlert } from 'lucide-react'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  OAuth2ConsentCard,
  type OAuth2Outcome,
} from '@/components/global/auth/OAuth2ConsentCard'
import { OAuth2OutcomeCard } from '@/components/global/auth/OAuth2OutcomeCard'
import { getOAuth2App } from '@/lib/oauth2/cimd'
import { isWebRedirect } from '@/lib/oauth2/redirect'
import { OAuth2ErrorMessage, OAuth2ErrorType } from '@/lib/oauth2/errors'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
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

type Phase = 'loading' | 'ready' | 'approved' | 'denied' | 'error'
type Account = Models.User<Models.Preferences>

const ACCOUNT_SWITCH_STORAGE_PREFIX = 'oauth2-account-switch:'

function rememberAccountSwitchUrl(key: string, url: string) {
  try {
    sessionStorage.setItem(`${ACCOUNT_SWITCH_STORAGE_PREFIX}${key}`, url)
  } catch {
    // Best-effort: without storage the chip simply won't offer switching.
  }
}

function accountSwitchUrlFor(key: string): string | null {
  try {
    return sessionStorage.getItem(`${ACCOUNT_SWITCH_STORAGE_PREFIX}${key}`)
  } catch {
    return null
  }
}

/**
 * OIDC `max_age` must be a non-negative integer count of seconds. Anything else
 * (e.g. `max_age=abc`) is dropped rather than forwarding `NaN`.
 */
function parseMaxAge(raw: string | null): number | undefined {
  if (!raw) return undefined
  const value = Number(raw)
  return Number.isInteger(value) && value >= 0 ? value : undefined
}

/**
 * The SDK types `resource` as a single string, but its client flattens arrays
 * into the indexed repeated params (`resource[0]`, `resource[1]`) the server
 * expects for RFC 8707 resource lists - the reference console relies on the
 * same behavior. Contain the unavoidable cast in this one documented place.
 */
function toResourceParam(resources: string[]): string | undefined {
  if (resources.length === 0) return undefined
  return resources as unknown as string
}

// The authorize-request fields shared verbatim between createPAR (pre-login
// push) and authorize (authenticated direct path). Read from URLSearchParams -
// not the router's parsed search - because `resource` may legally repeat and
// the values must pass through untouched.
function readAuthorizeParams(params: URLSearchParams) {
  return {
    redirectUri: params.get('redirect_uri') ?? '',
    responseType: params.get('response_type') ?? 'code',
    scope: params.get('scope') ?? '',
    state: params.get('state') ?? undefined,
    nonce: params.get('nonce') ?? undefined,
    codeChallenge: params.get('code_challenge') ?? undefined,
    codeChallengeMethod: params.get('code_challenge_method') ?? undefined,
    prompt: params.get('prompt') ?? undefined,
    maxAge: parseMaxAge(params.get('max_age')),
    authorizationDetails: params.get('authorization_details') ?? undefined,
    resource: toResourceParam(params.getAll('resource')),
  }
}

function getAccount(): Promise<Account | null> {
  return sdk.forConsole.account
    .get()
    .catch(() => null) as Promise<Account | null>
}

function OAuth2ConsentPage() {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const search = Route.useSearch()
  const [phase, setPhase] = useState<Phase>('loading')
  const [grant, setGrant] = useState<Models.Oauth2Grant | null>(null)
  const [app, setApp] = useState<Models.App | null>(null)
  const [account, setAccount] = useState<Account | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [completedRedirectUrl, setCompletedRedirectUrl] = useState<
    string | undefined
  >(undefined)
  const [accountSwitchResumeUrl, setAccountSwitchResumeUrl] = useState<
    string | null
  >(null)

  const onDone = (outcome: OAuth2Outcome, redirectUrl?: string) => {
    setCompletedRedirectUrl(redirectUrl)
    setPhase(outcome === 'approved' ? 'approved' : 'denied')
  }

  const switchAccount = async () => {
    if (!accountSwitchResumeUrl) return
    setPhase('loading')
    // Clears the session and lands on /sign-in with the consent URL as the
    // post-login redirect, so the new account resumes this authorization.
    await performConsoleSignOut(queryClient, {
      redirect: accountSwitchResumeUrl,
    })
  }

  useEffect(() => {
    let cancelled = false

    // Re-runs when the authorize params change (this route can stay mounted as
    // the router moves between requests). Reset to loading so a previously
    // loaded grant can never be approved against a different request.
    setPhase('loading')
    setError(null)
    setCompletedRedirectUrl(undefined)
    setAccountSwitchResumeUrl(null)

    const currentRelativeUrl = window.location.pathname + window.location.search
    const params = new URLSearchParams(window.location.search)

    const goSignIn = (resumeUrl?: string) => {
      navigate({
        to: '/sign-in',
        search: { redirect: resumeUrl ?? currentRelativeUrl },
        replace: true,
      })
    }

    const fail = (e: unknown, fallback: string) => {
      setError(getErrorMessage(e, t(fallback)))
      setPhase('error')
    }

    // Load a grant + its app branding and render the consent card.
    async function loadConsent(grantId: string, knownAccount?: Account | null) {
      const loadedGrant = await sdk.forConsole.oauth2.getGrant({
        grantId,
      })
      const [loadedApp, loadedAccount] = await Promise.all([
        getOAuth2App(loadedGrant.appId),
        knownAccount !== undefined
          ? Promise.resolve(knownAccount)
          : getAccount(),
      ])
      if (cancelled) return
      setGrant(loadedGrant)
      setApp(loadedApp)
      setAccount(loadedAccount)
      setPhase('ready')
    }

    async function handleAuthorizeResult(
      result: Models.Oauth2Authorize,
      loggedInAccount: Account,
      clientId: string | null,
      fromRequestUri: boolean,
      resumeUrl: string | null,
    ) {
      if (result.redirectUrl) {
        // Already consented - go straight back to the client.
        window.location.href = result.redirectUrl
        // A native deep link can't navigate the tab away, so show the
        // success outcome (with an "Open app" retry) instead of a blank page.
        if (!isWebRedirect(result.redirectUrl)) {
          setCompletedRedirectUrl(result.redirectUrl)
          setAccount(loggedInAccount)
          const loadedApp = clientId
            ? await getOAuth2App(clientId).catch(() => null)
            : null
          if (cancelled) return
          setApp(loadedApp)
          setPhase('approved')
        }
        return
      }
      if (result.grantId) {
        if (resumeUrl) {
          rememberAccountSwitchUrl(result.grantId, resumeUrl)
        }
        if (fromRequestUri) {
          // The handle is now consumed - rewrite to the grant URL so
          // reloads resume via getGrant instead of a dead request_uri.
          navigate({
            to: '/oauth2/consent',
            search: { grant_id: result.grantId },
            replace: true,
          })
          return
        }
        await loadConsent(result.grantId, loggedInAccount)
        return
      }
      setError(t(OAuth2ErrorMessage.AUTHORIZE_FAILED))
      setPhase('error')
    }

    async function resumeFromGrant(grantId: string) {
      setAccountSwitchResumeUrl(accountSwitchUrlFor(grantId))
      try {
        await loadConsent(grantId)
      } catch (e: unknown) {
        if (cancelled) return
        if (e instanceof AppwriteException && e.code === 401) {
          goSignIn()
          return
        }
        fail(e, OAuth2ErrorMessage.GRANT_INVALID)
      }
    }

    async function resumeFromRequestUri(
      clientId: string | null,
      requestUri: string,
    ) {
      const resumeUrl = accountSwitchUrlFor(requestUri)
      setAccountSwitchResumeUrl(resumeUrl)
      const loggedInAccount = await getAccount()
      if (cancelled) return

      if (!loggedInAccount) {
        // Dereferencing while logged out can only 401; keep the
        // single-use handle untouched and go straight to login.
        goSignIn()
        return
      }

      try {
        // The server rejects request_uri combined with any other
        // authorization param; only client_id may accompany it.
        const result = await sdk.forConsole.oauth2.authorize({
          clientId: clientId ?? undefined,
          requestUri,
        })
        if (cancelled) return
        await handleAuthorizeResult(
          result,
          loggedInAccount,
          clientId,
          true,
          resumeUrl,
        )
      } catch (e: unknown) {
        if (cancelled) return
        // Since only the handle is sent, oauth2_invalid_request can only
        // mean the handle is unusable.
        if (
          e instanceof AppwriteException &&
          e.type === OAuth2ErrorType.INVALID_REQUEST
        ) {
          setError(t(OAuth2ErrorMessage.HANDLE_EXPIRED))
          setPhase('error')
          return
        }
        fail(e, OAuth2ErrorMessage.AUTHORIZE_FAILED)
      }
    }

    // Pre-login entry with raw authorize params in the URL.
    async function startAuthorize(clientId: string) {
      setAccountSwitchResumeUrl(currentRelativeUrl)
      const loggedInAccount = await getAccount()
      if (cancelled) return

      if (!loggedInAccount) {
        // Carry only a short request_uri through login - the full consent URL
        // travels inside the OAuth provider's `state` during GitHub sign-in
        // and can exceed its size limits.
        try {
          const par = await sdk.forConsole.oauth2.createPAR({
            clientId,
            ...readAuthorizeParams(params),
          })
          if (cancelled) return
          rememberAccountSwitchUrl(par.request_uri, currentRelativeUrl)
          goSignIn(
            `/oauth2/consent?client_id=${encodeURIComponent(clientId)}&request_uri=${encodeURIComponent(par.request_uri)}`,
          )
        } catch (e: unknown) {
          if (cancelled) return
          // A malformed request fails identically after login - surface it
          // now instead of bouncing the user through sign-in first.
          if (
            e instanceof AppwriteException &&
            e.type === OAuth2ErrorType.INVALID_REQUEST
          ) {
            fail(e, OAuth2ErrorMessage.AUTHORIZE_FAILED)
            return
          }
          // PAR unavailable (older server) - fall back to the legacy
          // full-URL redirect through login.
          goSignIn()
        }
        return
      }

      // Authenticated: ask the server to create a grant (or detect an existing
      // approved identity) via the SDK, then render consent or redirect.
      try {
        const result = await sdk.forConsole.oauth2.authorize({
          clientId,
          ...readAuthorizeParams(params),
        })
        if (cancelled) return
        await handleAuthorizeResult(
          result,
          loggedInAccount,
          clientId,
          false,
          currentRelativeUrl,
        )
      } catch (e: unknown) {
        if (cancelled) return
        fail(e, OAuth2ErrorMessage.AUTHORIZE_FAILED)
      }
    }

    async function init() {
      const grantId = params.get('grant_id')
      if (grantId) {
        await resumeFromGrant(grantId)
        return
      }

      const clientId = params.get('client_id')
      const requestUri = params.get('request_uri')
      if (requestUri) {
        await resumeFromRequestUri(clientId, requestUri)
        return
      }
      if (clientId) {
        await startAuthorize(clientId)
        return
      }

      setError(t(OAuth2ErrorMessage.MISSING_REQUEST))
      setPhase('error')
    }

    void init()
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const accountLabel = account?.email || account?.name || undefined

  return (
    <div className="bg-background h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-xl">
          {phase === 'loading' && (
            <div className="flex min-h-64 items-center justify-center">
              <Loader2 className="text-muted-foreground size-8 animate-spin" />
            </div>
          )}

          {phase === 'error' && (
            <Card className="overflow-hidden p-6 md:p-8">
              <div className="space-y-6">
                <div className="flex flex-col items-center gap-4 text-center">
                  <div className="bg-destructive/10 flex size-10 items-center justify-center rounded-xl">
                    <TriangleAlert className="text-destructive size-4" />
                  </div>
                  <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      {t('Authorization failed')}
                    </h1>
                    <p className="text-muted-foreground text-[13px] leading-relaxed">
                      {error}
                    </p>
                  </div>
                </div>
                <Button
                  variant="outline"
                  className="w-full"
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
              accountLabel={accountLabel}
              flow="authorization"
              onSwitchAccount={
                accountSwitchResumeUrl ? switchAccount : undefined
              }
              onDone={onDone}
            />
          )}

          {(phase === 'approved' || phase === 'denied') && (
            <OAuth2OutcomeCard
              outcome={phase}
              flow="authorization"
              app={app}
              accountLabel={accountLabel}
              redirectUrl={completedRedirectUrl}
            />
          )}
        </div>
      </div>
    </div>
  )
}
