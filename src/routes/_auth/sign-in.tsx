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
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { AppwriteException, OAuthProvider } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
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
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / (but not // - protocol-relative) and not contain ://
    return url.startsWith('/') && !url.startsWith('//') && !url.includes('://')
  } catch {
    return false
  }
}

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
      throw redirect({ to: '/', replace: true })
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
  const [isGitHubLoading, setIsGitHubLoading] = useState(false)
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const handleGitHubLogin = async () => {
    setIsGitHubLoading(true)
    try {
      // Build success and failure URLs
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : `${window.location.origin}/`
      const failureUrl = `${window.location.origin}/sign-in${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`

      // Store GitHub as last login method before redirecting
      setLastLoginMethod('github')

      // Create OAuth2 session - this may return a URL or void (if it redirects automatically)
      const url = await sdk.forConsole.account.createOAuth2Session({
        provider: OAuthProvider.Github,
        success: successUrl,
        failure: failureUrl,
      })

      // If URL is returned, redirect manually; otherwise SDK handles redirect automatically
      if (typeof url === 'string') {
        window.location.href = url
      }
      // If void, the SDK has already initiated the redirect, so we don't need to do anything
    } catch (error: unknown) {
      setIsGitHubLoading(false)
      toast.error(getErrorMessage(error, t('Failed to initiate GitHub login')))
      console.error('GitHub OAuth error:', error)
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
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <SignIn
          mode="sign-in"
          onSubmit={(data) => signInMutation.mutate(data)}
          onGitHubLogin={handleGitHubLogin}
          isLoading={signInMutation.isPending || isOpeningMfa}
          isGitHubLoading={isGitHubLoading}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          {t('By clicking continue, you agree to our')}{' '}
          <a
            href="#"
            className="link-neutral"
          >
            {t('Terms of Service')}
          </a>{' '}
          {t('and')}{' '}
          <a
            href="#"
            className="link-neutral"
          >
            {t('Privacy Policy')}
          </a>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
