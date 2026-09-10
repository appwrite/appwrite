import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import {
  createFileRoute,
  redirect,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod, type OAuthLoginMethod } from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
  navigateToConsoleMfaAfterSession,
  isConsoleMfaRequiredError,
} from '@/lib/react-query/hooks/auth'
import {
  isValidRelativeRedirect,
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'

const searchSchema = z.object({
  redirect: z
    .string()
    .optional()
    .refine((val) => !val || isValidRelativeRedirect(val), {
      message: 'Redirect must be a relative URL',
    }),
})

export const Route = createFileRoute('/_auth/sign-in')({
  component: SignInPage,
  validateSearch: searchSchema,
  // Keep the visible header stable while this link is hovered or focused.
  preload: false,
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (account) {
      if (requiresConsoleEmailVerification(account)) {
        const pendingRedirect = (location.search as { redirect?: string })
          .redirect
        throw redirect({
          to: '/verify-email',
          search: pendingRedirect ? { redirect: pendingRedirect } : undefined,
          replace: true,
        })
      }
      // Already signed in: honor a console redirect (e.g. a /join invite link)
      // instead of always bouncing to the dashboard.
      const target = resolvePostAuthRedirect(
        (location.search as { redirect?: string }).redirect,
      )
      if (target) {
        throw redirect({ ...toRedirectNavigateOptions(target), replace: true })
      }
      throw redirect({ to: CONSOLE_ENTRY_PATH, replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Sign in') }] }),
})

function SignInPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/sign-in' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const handleOAuthLogin = async (provider: OAuthLoginMethod) => {
    setOauthLoading(provider)
    try {
      // Build success and failure URLs
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : `${window.location.origin}${CONSOLE_ENTRY_PATH}`
      const failureUrl = `${window.location.origin}/sign-in${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`

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

  const signInMutation = useMutation({
    mutationFn: async (data: { email: string; password: string }) => {
      try {
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
        // Check for MFA requirement - this is the key check
        if (
          error instanceof AppwriteException &&
          error.type === 'user_more_factors_required'
        ) {
          // Re-throw with a special marker so onError can handle it
          throw { ...error, isMfaRequired: true }
        }
        // Re-throw other errors
        throw error
      }
    },
    onSuccess: async () => {
      // Only called if account.get() succeeds (no MFA required)
      setLastLoginMethod('email')
      try {
        const account = await refreshConsoleAccountAfterAuth(queryClient)

        // Cloud requires verification before org/project APIs; send unverified
        // users to /verify-email instead of provisioning a personal org.
        if (requiresConsoleEmailVerification(account)) {
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
        const targetRedirect = resolvePostAuthRedirect(search.redirect)
        if (targetRedirect) {
          navigate(toRedirectNavigateOptions(targetRedirect))
        } else {
          const orgId = await resolvePostAuthOrganizationId(account)
          navigate({
            to: '/organizations/$orgId',
            params: { orgId },
            replace: true,
          })
        }
      } catch (error: unknown) {
        console.error('Post sign-in navigation error:', error)
        toast.error(
          getErrorMessage(error, t('Signed in but could not open the console')),
        )
      }
    },
    onError: async (error: unknown) => {
      // Handle MFA requirement - redirect to MFA page
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

      // Show error for other failures
      toast.error(getErrorMessage(error, t('Failed to sign in')))
      console.error('Sign in error:', error)
    },
  })

  return (
    <div className="bg-background relative h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-sm md:max-w-4xl">
          <SignIn
            mode="sign-in"
            onSubmit={(data) => signInMutation.mutate(data)}
            onOAuthLogin={handleOAuthLogin}
            isLoading={signInMutation.isPending || isOpeningMfa}
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
          <div className="mt-10 md:mt-16 flex justify-center">
            <AppwriteLogo className="h-6 w-auto" />
          </div>
        </div>
      </div>
    </div>
  )
}
