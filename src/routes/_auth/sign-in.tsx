import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import {
  createFileRoute,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException, OAuthProvider } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { pageTitle } from '@/lib/utils/page-title'

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / and not contain :// (which would indicate a protocol)
    return url.startsWith('/') && !url.includes('://')
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
  head: () => ({ meta: [{ title: pageTitle('Sign in') }] }),
})

function SignInPage() {
  const search = useSearch({ from: '/_auth/sign-in' })
  const navigate = useNavigate()
  const router = useRouter()
  const [isGitHubLoading, setIsGitHubLoading] = useState(false)

  const handleGitHubLogin = async () => {
    setIsGitHubLoading(true)
    try {
      // Build success and failure URLs
      const successUrl =
        search.redirect && isValidRelativeRedirect(search.redirect)
          ? `${window.location.origin}${search.redirect}`
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
      toast.error(getErrorMessage(error, 'Failed to initiate GitHub login'))
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

        // After session creation, check if we can get account (MFA might be required)
        // This will throw if MFA is required
        await sdk.forConsole.account.get()
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
      // Store email as last login method
      setLastLoginMethod('email')
      await router.invalidate()
      if (search.redirect && isValidRelativeRedirect(search.redirect)) {
        navigate({ to: search.redirect })
      } else {
        navigate({ to: '/' })
      }
    },
    onError: async (error: unknown) => {
      // Handle MFA requirement - redirect to MFA page
      const isMfaRequired =
        typeof error === 'object' &&
        error !== null &&
        'isMfaRequired' in error &&
        (error as { isMfaRequired?: boolean }).isMfaRequired === true
      if (
        isMfaRequired ||
        (error instanceof AppwriteException &&
          error.type === 'user_more_factors_required')
      ) {
        const redirectUrl =
          search.redirect && isValidRelativeRedirect(search.redirect)
            ? search.redirect
            : undefined
        navigate({
          to: '/mfa',
          search: redirectUrl ? { redirect: redirectUrl } : undefined,
        })
        return
      }

      // Show error for other failures
      toast.error(getErrorMessage(error, 'Failed to sign in'))
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
          isLoading={signInMutation.isPending}
          isGitHubLoading={isGitHubLoading}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By clicking continue, you agree to our{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Terms of Service
          </a>{' '}
          and{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Privacy Policy
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
