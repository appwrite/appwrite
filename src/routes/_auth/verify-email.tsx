import { useEffect, useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { createFileRoute, redirect, useNavigate, useSearch } from '@tanstack/react-router'
import { z } from 'zod'
import { VerifyEmail } from '@/components/global/auth/VerifyEmail'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import {
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { useRouter } from '@tanstack/react-router'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

function isValidRelativeRedirect(url: string): boolean {
  try {
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
  userId: z.string().optional(),
  secret: z.string().optional(),
  expire: z.string().optional(),
})

/** Parse userId and secret from the current URL (used when following email link) so long tokens are not altered by router. */
function getVerificationParamsFromUrl(): {
  userId: string
  secret: string
} | null {
  if (typeof window === 'undefined') return null
  const params = new URLSearchParams(window.location.search)
  const userId = params.get('userId')
  const secret = params.get('secret')
  if (userId && secret) return { userId, secret }
  return null
}

export const Route = createFileRoute('/_auth/verify-email')({
  component: VerifyEmailPage,
  validateSearch: searchSchema,
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return

    const params = getVerificationParamsFromUrl()
    const account = await ensureConsoleAccountQueryData(context.queryClient)

    // Email link confirmations can complete without an active session.
    if (params) return

    if (!account) {
      const pendingRedirect = (location.search as { redirect?: string }).redirect
      throw redirect({
        to: '/sign-in',
        search: {
          redirect: pendingRedirect || '/verify-email',
        },
        replace: true,
      })
    }

    // Already verified: leave this page for the console.
    if (!requiresConsoleEmailVerification(account)) {
      throw redirect({ to: '/', replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Verify your email') }] }),
})

function VerifyEmailPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/verify-email' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()

  const confirmMutation = useMutation({
    mutationFn: async (params: { userId: string; secret: string }) => {
      await sdk.forConsole.account.updateEmailVerification({
        userId: params.userId,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      toast.success(t('Email verified successfully'))
      try {
        const account = await refreshConsoleAccountAfterAuth(queryClient)
        await prefetchPostAuthDestination(queryClient, account, search.redirect)
        await router.invalidate()

        // If we're headed to a specific destination (e.g. an OAuth2
        // consent/device flow), go straight there without provisioning a
        // personal org/project - provisioning throws on single-tenant
        // profiles and would otherwise drop the pending authorization.
        const targetRedirect = resolvePostAuthRedirect(search.redirect)
        if (targetRedirect) {
          navigate(toRedirectNavigateOptions(targetRedirect))
          return
        }

        const orgId = await resolvePostAuthOrganizationId(account)
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
          replace: true,
        })
      } catch {
        navigate({ to: '/' })
      }
    },
    onError: (error: unknown) => {
      const message =
        error instanceof AppwriteException
          ? error.message
          : t('Verification link is invalid or has expired.')
      toast.error(message)
    },
  })

  const resendMutation = useMutation({
    mutationFn: async () => {
      // Preserve the pending destination (e.g. an OAuth2 consent/device flow)
      // so the resent link returns the user to it after verification.
      const origin = typeof window !== 'undefined' ? window.location.origin : ''
      const redirectParam = search.redirect
        ? `?redirect=${encodeURIComponent(search.redirect)}`
        : ''
      const url = `${origin}/verify-email${redirectParam}`
      return await sdk.forConsole.account.createEmailVerification({ url })
    },
    onSuccess: () => {
      toast.success(t('Verification email sent'))
    },
    onError: (error: unknown) => {
      const message =
        error instanceof AppwriteException
          ? error.message
          : t('Failed to send verification email')
      toast.error(message)
    },
  })

  const hasTriggeredConfirm = useRef(false)

  // When landing with userId + secret (from email link), confirm and redirect.
  // Read from URL directly so the long secret is not altered by router/search parsing.
  useEffect(() => {
    const params = getVerificationParamsFromUrl()
    if (params && !hasTriggeredConfirm.current) {
      hasTriggeredConfirm.current = true
      confirmMutation.mutate(params)
    }
  }, [])

  const urlParams =
    typeof window !== 'undefined' ? getVerificationParamsFromUrl() : null
  const isConfirming = Boolean(urlParams) && confirmMutation.isPending

  if (isConfirming) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <div className="w-full max-w-sm md:max-w-4xl">
          <VerifyEmail status="confirming" />
          <p className="mt-6 text-center text-xs text-muted-foreground">
            {t('By continuing, you agree to our')}{' '}
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
    )
  }

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <VerifyEmail
          onResend={() => resendMutation.mutate()}
          isResendLoading={resendMutation.isPending}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          {t('By continuing, you agree to our')}{' '}
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
  )
}
