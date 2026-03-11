import { useEffect, useRef } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { VerifyEmail } from '@/components/global/auth/VerifyEmail'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { pageTitle } from '@/lib/utils/page-title'
import { ensurePersonalOrgAndFirstProject } from '@/lib/ensure-personal-org'
import { useRouter } from '@tanstack/react-router'

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
function getVerificationParamsFromUrl(): { userId: string; secret: string } | null {
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
  head: () => ({ meta: [{ title: pageTitle('Verify your email') }] }),
})

function VerifyEmailPage() {
  const search = useSearch({ from: '/_auth/verify-email' })
  const navigate = useNavigate()
  const router = useRouter()

  const confirmMutation = useMutation({
    mutationFn: async (params: { userId: string; secret: string }) => {
      await sdk.forConsole.account.updateEmailVerification({
        userId: params.userId,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      toast.success('Email verified successfully')
      try {
        const orgId = await ensurePersonalOrgAndFirstProject()
        await router.invalidate()
        if (search.redirect && isValidRelativeRedirect(search.redirect)) {
          navigate({ to: search.redirect })
        } else {
          navigate({
            to: '/organizations/$orgId',
            params: { orgId },
            replace: true,
          })
        }
      } catch {
        const target =
          search.redirect && isValidRelativeRedirect(search.redirect)
            ? search.redirect
            : '/'
        navigate({ to: target })
      }
    },
    onError: (error: unknown) => {
      const message =
        error instanceof AppwriteException
          ? error.message
          : 'Verification link is invalid or has expired.'
      toast.error(message)
    },
  })

  const resendMutation = useMutation({
    mutationFn: async () => {
      const url = `${typeof window !== 'undefined' ? window.location.origin : ''}/verify-email`
      return await sdk.forConsole.account.createEmailVerification({ url })
    },
    onSuccess: () => {
      toast.success('Verification email sent')
    },
    onError: (error: unknown) => {
      const message =
        error instanceof AppwriteException
          ? error.message
          : 'Failed to send verification email'
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

  const urlParams = typeof window !== 'undefined' ? getVerificationParamsFromUrl() : null
  const isConfirming =
    Boolean(urlParams) && confirmMutation.isPending

  if (isConfirming) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <div className="w-full max-w-sm md:max-w-4xl">
          <VerifyEmail status="confirming" />
          <p className="mt-6 text-center text-xs text-muted-foreground">
            By continuing, you agree to our{' '}
            <a
              href="https://appwrite.io/terms"
              target="_blank"
              rel="noopener noreferrer"
              className="underline underline-offset-4 hover:text-primary"
            >
              Terms of Service
            </a>{' '}
            and{' '}
            <a
              href="https://appwrite.io/privacy"
              target="_blank"
              rel="noopener noreferrer"
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

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <VerifyEmail
          onResend={() => resendMutation.mutate()}
          isResendLoading={resendMutation.isPending}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By continuing, you agree to our{' '}
          <a
            href="https://appwrite.io/terms"
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-4 hover:text-primary"
          >
            Terms of Service
          </a>{' '}
          and{' '}
          <a
            href="https://appwrite.io/privacy"
            target="_blank"
            rel="noopener noreferrer"
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
