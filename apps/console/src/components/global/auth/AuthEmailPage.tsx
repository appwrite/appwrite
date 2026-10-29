import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { QueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  Link,
  isRedirect,
  redirect,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'
import { AppwriteException, ID } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  setLastLoginMethod,
  type LoginMethod,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  isPasskeyAutofillAvailable,
  isPasskeyCancellation,
  isPasskeySupported,
  passkeySignInErrorMessage,
  signInWithPasskey,
} from '@/lib/passkeys'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
  navigateToConsoleMfaAfterSession,
  isConsoleMfaRequiredError,
} from '@/lib/react-query/hooks/auth'
import {
  markOpenAiAdsRegistrationIntent,
  measureOpenAiAdsRegistrationCompleted,
} from '@/lib/openai-ads'
import {
  finishPostAuthNavigation,
  isValidRelativeRedirect,
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthHomeTarget,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import {
  PARTNER_AUTH_ENTRY_PATH,
  getAuthSignInPath,
  getAuthSignUpPath,
  setAuthAudience,
  type AuthAudience,
} from '@/lib/auth-audience'
import { PartnerOrganizationRequiredError } from '@/lib/ensure-personal-org'

export const authEmailSearchSchema = z.object({
  redirect: z
    .string()
    .optional()
    .refine((val) => !val || isValidRelativeRedirect(val), {
      message: 'Redirect must be a relative URL',
    }),
  audience: z.enum(['partner']).optional(),
})

export function audienceFromSearch(
  search: unknown,
  fallback: AuthAudience = 'console',
): AuthAudience {
  if (
    typeof search === 'object' &&
    search !== null &&
    'audience' in search &&
    (search as { audience?: string }).audience === 'partner'
  ) {
    return 'partner'
  }
  return fallback
}

export async function loadAuthEmailPage({
  queryClient,
  location,
  audience,
}: {
  queryClient: QueryClient
  location: { search: unknown }
  audience: AuthAudience
}) {
  if (typeof window === 'undefined') return
  setAuthAudience(audience)
  const account = await ensureConsoleAccountQueryData(queryClient)
  if (!account) return

  const pendingRedirect = (location.search as { redirect?: string }).redirect
  if (requiresConsoleEmailVerification(account)) {
    throw redirect({
      to: '/verify-email',
      search: pendingRedirect ? { redirect: pendingRedirect } : undefined,
      replace: true,
    })
  }
  // Already signed in: honor a console redirect (e.g. a /join invite link)
  // instead of always bouncing to the dashboard.
  const target = resolvePostAuthRedirect(pendingRedirect)
  if (target) {
    throw redirect({ ...toRedirectNavigateOptions(target), replace: true })
  }
  try {
    throw redirect(
      await resolvePostAuthHomeTarget(account, queryClient, { audience }),
    )
  } catch (error) {
    if (isRedirect(error)) throw error
    if (error instanceof PartnerOrganizationRequiredError) {
      throw redirect({ to: PARTNER_AUTH_ENTRY_PATH, replace: true })
    }
    throw redirect({ to: CONSOLE_ENTRY_PATH, replace: true })
  }
}

interface PasskeyRequest {
  controller: AbortController
  /** `conditional` for the email field's autofill, `optional` for the button's prompt. */
  mediation: 'conditional' | 'optional'
  /** When the server's challenge expires, in milliseconds. */
  expire: number
  selected: boolean
}

export function AuthEmailPage({
  mode,
  audience,
}: {
  mode: 'sign-in' | 'sign-up'
  audience: AuthAudience
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const search = useSearch({ strict: false }) as {
    redirect?: string
    audience?: 'partner'
  }
  const resolvedAudience: AuthAudience =
    search.audience === 'partner' ? 'partner' : audience
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  useEffect(() => {
    setAuthAudience(resolvedAudience)
  }, [resolvedAudience])

  const handleOAuthLogin = async (provider: OAuthLoginMethod) => {
    setOauthLoading(provider)
    try {
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : resolvedAudience === 'partner'
          ? `${window.location.origin}/sign-in?audience=partner`
          : `${window.location.origin}${CONSOLE_ENTRY_PATH}`
      const failurePath =
        mode === 'sign-up'
          ? getAuthSignUpPath(resolvedAudience)
          : getAuthSignInPath(resolvedAudience)
      const failureSearch = new URLSearchParams()
      if (search.redirect) failureSearch.set('redirect', search.redirect)
      if (resolvedAudience === 'partner') {
        failureSearch.set('audience', 'partner')
      }
      const failureQuery = failureSearch.toString()
      const failureUrl = `${window.location.origin}${failurePath}${failureQuery ? `?${failureQuery}` : ''}`

      setLastLoginMethod(provider)
      if (mode === 'sign-up') markOpenAiAdsRegistrationIntent()

      const url = await sdk.forConsole.account.createOAuth2Session({
        provider: CONSOLE_OAUTH_PROVIDERS[provider],
        success: successUrl,
        failure: failureUrl,
      })

      if (typeof url === 'string') {
        window.location.href = url
      }
    } catch (error: unknown) {
      setOauthLoading(null)
      toast.error(getErrorMessage(error, t(OAUTH_LOGIN_ERROR[provider])))
      console.error(`${provider} OAuth error:`, error)
    }
  }

  const finishAuth = async (method: LoginMethod) => {
    setLastLoginMethod(method)
    try {
      const account = await refreshConsoleAccountAfterAuth(queryClient)

      // Cloud requires verification before org/project APIs; send unverified
      // users to /verify-email instead of provisioning a personal org.
      if (requiresConsoleEmailVerification(account)) {
        navigate({
          to: '/verify-email',
          search: search.redirect ? { redirect: search.redirect } : undefined,
        })
        return
      }

      if (mode === 'sign-up' && account?.$id) {
        measureOpenAiAdsRegistrationCompleted(account.$id, {
          email: account.email,
          phone: account.phone,
        })
      }

      await prefetchPostAuthDestination(queryClient, account, search.redirect)
      await router.invalidate()
      await finishPostAuthNavigation(navigate, account, queryClient, {
        redirect: search.redirect,
        audience: resolvedAudience,
      })
    } catch (error: unknown) {
      if (error instanceof PartnerOrganizationRequiredError) {
        toast.error(t('This account is not a member of a partner organization.'))
        navigate({ to: PARTNER_AUTH_ENTRY_PATH, replace: true })
        return
      }
      console.error(
        mode === 'sign-up'
          ? 'Post sign-up navigation error:'
          : 'Post sign-in navigation error:',
        error,
      )
      toast.error(
        getErrorMessage(
          error,
          mode === 'sign-up'
            ? t('Account created but could not open the console')
            : t('Signed in but could not open the console'),
        ),
      )
    }
  }

  /** Returns false when the error is not an MFA challenge. */
  const openMfaIfRequired = async (error: unknown): Promise<boolean> => {
    const isMfaRequired =
      typeof error === 'object' &&
      error !== null &&
      'isMfaRequired' in error &&
      (error as { isMfaRequired?: boolean }).isMfaRequired === true
    if (!isMfaRequired && !isConsoleMfaRequiredError(error)) return false

    setIsOpeningMfa(true)
    try {
      await navigateToConsoleMfaAfterSession(
        queryClient,
        navigate,
        search.redirect,
      )
    } catch (navigationError: unknown) {
      setIsOpeningMfa(false)
      toast.error(
        getErrorMessage(navigationError, t('Could not open MFA verification')),
      )
    }
    return true
  }

  const authMutation = useMutation({
    mutationFn: async (data: {
      email: string
      password: string
      name?: string
      skipAccountCreate?: boolean
    }) => {
      try {
        if (mode === 'sign-up' && !data.skipAccountCreate) {
          await sdk.forConsole.account.create({
            userId: ID.unique(),
            email: data.email,
            password: data.password,
            name: data.name,
          })
        }

        await sdk.forConsole.account.createEmailPasswordSession({
          email: data.email,
          password: data.password,
        })

        // Detect MFA. Force a fresh fetch so it can't replay the guest account.get
        // the _auth loader fires on load (cached/in-flight 401 "missing scopes
        // account") - a race password managers hit by autofilling and submitting
        // before that guest request settled.
        await fetchConsoleAccount({ force: true })
      } catch (error: unknown) {
        if (
          error instanceof AppwriteException &&
          error.type === 'user_more_factors_required'
        ) {
          throw { ...error, isMfaRequired: true }
        }
        if (mode === 'sign-up' && error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to sign up')
        }
        throw error
      }
    },
    onSuccess: () => finishAuth('email'),
    onError: async (error: unknown) => {
      if (await openMfaIfRequired(error)) return

      toast.error(
        getErrorMessage(
          error,
          mode === 'sign-up' ? t('Failed to sign up') : t('Failed to sign in'),
        ),
      )
      console.error(
        mode === 'sign-up' ? 'Sign up error:' : 'Sign in error:',
        error,
      )
    },
  })

  const [passkeySelected, setPasskeySelected] = useState(false)
  const passkeyRequest = useRef<PasskeyRequest | null>(null)
  const passkeyRateLimited = useRef(false)
  const passkeysEnabled = mode === 'sign-in' && features.accountPasskeys

  // Autofill offers the console's passkeys in the email field's suggestions,
  // and shows nothing until one exists. The button opens the browser's prompt.
  const passkeySignInMutation = useMutation({
    mutationFn: async (request: PasskeyRequest) => {
      const token = await signInWithPasskey({
        mediation: request.mediation,
        signal: request.controller.signal,
        onChallenge: (expire) => {
          request.expire = Date.parse(expire) || Number.POSITIVE_INFINITY
        },
        onSelected: () => {
          request.selected = true
          setPasskeySelected(true)
        },
      })
      // A passkey session already satisfies MFA, so finishAuth's account
      // refresh is the only account.get needed.
      await sdk.forConsole.account.createSession({
        userId: token.userId,
        secret: token.secret,
      })
    },
    onSuccess: () => finishAuth('passkey'),
    onError: async (error: unknown, request: PasskeyRequest) => {
      // A newer request replaced this one and owns the state now.
      if (passkeyRequest.current !== request) return
      passkeyRequest.current = null
      setPasskeySelected(false)
      if (error instanceof AppwriteException && error.code === 429) {
        passkeyRateLimited.current = true
      }
      if (isPasskeyCancellation(error)) return
      if (await openMfaIfRequired(error)) return
      // Autofill starts before any choice, so stay quiet until the user has picked a passkey.
      if (request.mediation === 'conditional' && !request.selected) return

      toast.error(
        passkeySignInErrorMessage(error, t) ??
          getErrorMessage(error, t('Failed to sign in with a passkey')),
      )
      console.error('Passkey sign in error:', error)
    },
  })
  const { mutate: mutatePasskeySignIn } = passkeySignInMutation

  // Starts the autofill request, or replaces one that ended or whose challenge
  // expired, so the email field keeps offering passkeys after an error or a wait.
  const startPasskeyAutofill = useCallback(() => {
    if (passkeyRateLimited.current) return
    const current = passkeyRequest.current
    if (current && (current.selected || Date.now() < current.expire)) return
    current?.controller.abort()
    const request: PasskeyRequest = {
      controller: new AbortController(),
      mediation: 'conditional',
      expire: Number.POSITIVE_INFINITY,
      selected: false,
    }
    passkeyRequest.current = request
    mutatePasskeySignIn(request)
  }, [mutatePasskeySignIn])

  // Runs for everyone: the page cannot know the user yet, and the browser only
  // offers a passkey it already holds for the console.
  const [passkeySupported, setPasskeySupported] = useState(false)
  const [passkeyAutofill, setPasskeyAutofill] = useState(false)
  useEffect(() => {
    if (!passkeysEnabled) return
    let active = true
    setPasskeySupported(isPasskeySupported())
    void isPasskeyAutofillAvailable().then((available) => {
      if (!active || !available) return
      setPasskeyAutofill(true)
      startPasskeyAutofill()
    })
    return () => {
      active = false
      passkeyRequest.current?.controller.abort()
      passkeyRequest.current = null
    }
  }, [passkeysEnabled, startPasskeyAutofill])

  // The browser runs one passkey request at a time, so the prompt replaces the
  // autofill request, and autofill comes back if the prompt ends without a session.
  const handlePasskeySignIn = () => {
    passkeyRequest.current?.controller.abort()
    const request: PasskeyRequest = {
      controller: new AbortController(),
      mediation: 'optional',
      expire: Number.POSITIVE_INFINITY,
      selected: false,
    }
    passkeyRequest.current = request
    setPasskeySelected(true)
    mutatePasskeySignIn(request, {
      onError: () => {
        if (passkeyAutofill) startPasskeyAutofill()
      },
    })
  }

  const passkeyBusy = passkeySignInMutation.isPending && passkeySelected

  return (
    <AuthFlowShell width="illustration">
      <SignIn
        mode={mode}
        audience={resolvedAudience}
        onSubmit={(data, options) =>
          authMutation.mutate({
            ...data,
            skipAccountCreate: options?.skipAccountCreate,
          })
        }
        onOAuthLogin={features.accountOAuth ? handleOAuthLogin : undefined}
        onPasskeyAutofill={
          passkeysEnabled && passkeyAutofill ? startPasskeyAutofill : undefined
        }
        onPasskeySignIn={
          passkeysEnabled && passkeySupported ? handlePasskeySignIn : undefined
        }
        isLoading={authMutation.isPending || passkeyBusy || isOpeningMfa}
        oauthLoading={oauthLoading}
        redirect={search.redirect}
      />
      {mode === 'sign-in' ? (
        <p className="mt-4 text-center text-sm text-muted-foreground">
          {resolvedAudience === 'partner' ? (
            <>
              {t('Looking for the developer console?')}{' '}
              <Link
                to="/sign-in"
                className="link-neutral"
                onClick={() => setAuthAudience('console')}
              >
                {t('Developer login')}
              </Link>
            </>
          ) : (
            <>
              {t('Are you a partner?')}{' '}
              <Link
                to="/sign-in"
                search={{ audience: 'partner' }}
                className="link-neutral"
              >
                {t('Partner login')}
              </Link>
            </>
          )}
        </p>
      ) : null}
    </AuthFlowShell>
  )
}
