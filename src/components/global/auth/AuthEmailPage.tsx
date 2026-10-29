import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import {
  redirect,
  isRedirect,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { SignIn } from '@/components/global/auth/SignIn'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'
import { AppwriteException, ID } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod, type OAuthLoginMethod } from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
  navigateToConsoleMfaAfterSession,
  isConsoleMfaRequiredError,
} from '@/lib/react-query/hooks/auth'
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
import { Link } from '@tanstack/react-router'
import type { QueryClient } from '@tanstack/react-query'
import { z } from 'zod'

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
  if (account) {
    if (requiresConsoleEmailVerification(account)) {
      const pendingRedirect = (location.search as { redirect?: string }).redirect
      throw redirect({
        to: '/verify-email',
        search: pendingRedirect ? { redirect: pendingRedirect } : undefined,
        replace: true,
      })
    }
    const target = resolvePostAuthRedirect(
      (location.search as { redirect?: string }).redirect,
    )
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
}

export function AuthEmailPage({
  mode,
  audience,
}: {
  mode: 'sign-in' | 'sign-up'
  audience: AuthAudience
}) {
  const t = useT()
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

  const signInPath = getAuthSignInPath(resolvedAudience)
  const signUpPath = getAuthSignUpPath(resolvedAudience)

  const handleOAuthLogin = async (provider: OAuthLoginMethod) => {
    setOauthLoading(provider)
    try {
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : resolvedAudience === 'partner'
          ? `${window.location.origin}/sign-in?audience=partner`
          : `${window.location.origin}${CONSOLE_ENTRY_PATH}`
      const failurePath = mode === 'sign-up' ? signUpPath : signInPath
      const failureSearch = new URLSearchParams()
      if (search.redirect) failureSearch.set('redirect', search.redirect)
      if (resolvedAudience === 'partner') failureSearch.set('audience', 'partner')
      const failureQuery = failureSearch.toString()
      const failureUrl = `${window.location.origin}${failurePath}${failureQuery ? `?${failureQuery}` : ''}`

      setLastLoginMethod(provider)

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
    onSuccess: async () => {
      setLastLoginMethod('email')
      try {
        const account = await refreshConsoleAccountAfterAuth(queryClient)

        if (requiresConsoleEmailVerification(account)) {
          if (mode === 'sign-up') {
            try {
              const verifyUrl = `${window.location.origin}/verify-email${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`
              await sdk.forConsole.account.createEmailVerification({
                url: verifyUrl,
              })
            } catch (err) {
              console.error('Failed to send verification email:', err)
              toast.error(
                getErrorMessage(
                  err,
                  t('Account created but verification email could not be sent'),
                ),
              )
            }
          }
          navigate({
            to: '/verify-email',
            search: search.redirect
              ? { redirect: search.redirect }
              : undefined,
          })
          return
        }

        await prefetchPostAuthDestination(
          queryClient,
          account,
          search.redirect,
        )
        await router.invalidate()
        await finishPostAuthNavigation(navigate, account, queryClient, {
          redirect: search.redirect,
          audience: resolvedAudience,
        })
      } catch (error: unknown) {
        if (error instanceof PartnerOrganizationRequiredError) {
          toast.error(
            t('This account is not a member of a partner organization.'),
          )
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
    },
    onError: async (error: unknown) => {
      const isMfaRequired =
        typeof error === 'object' &&
        error !== null &&
        'isMfaRequired' in error &&
        (error as { isMfaRequired?: boolean }).isMfaRequired === true
      if (isMfaRequired || isConsoleMfaRequiredError(error)) {
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
            getErrorMessage(
              navigationError,
              t('Could not open MFA verification'),
            ),
          )
        }
        return
      }

      toast.error(
        getErrorMessage(
          error,
          mode === 'sign-up' ? t('Failed to sign up') : t('Failed to sign in'),
        ),
      )
      console.error(mode === 'sign-up' ? 'Sign up error:' : 'Sign in error:', error)
    },
  })

  return (
    <div className="bg-background relative h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-sm md:max-w-4xl">
          <SignIn
            mode={mode}
            audience={resolvedAudience}
            onSubmit={(data, options) =>
              authMutation.mutate({
                ...data,
                skipAccountCreate: options?.skipAccountCreate,
              })
            }
            onOAuthLogin={handleOAuthLogin}
            isLoading={authMutation.isPending || isOpeningMfa}
            oauthLoading={oauthLoading}
            redirect={search.redirect}
          />
          <p className="mt-6 text-center text-xs text-muted-foreground">
            {t('By clicking continue, you agree to our')}{' '}
            <MarketingSiteLink className="link-neutral" href="/terms">
              {t('Terms of Service')}
            </MarketingSiteLink>{' '}
            {t('and')}{' '}
            <MarketingSiteLink className="link-neutral" href="/privacy">
              {t('Privacy Policy')}
            </MarketingSiteLink>
            .
          </p>
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
          <div className="mt-10 md:mt-16 flex justify-center">
            <AppwriteLogo className="h-6 w-auto" />
          </div>
        </div>
      </div>
    </div>
  )
}
