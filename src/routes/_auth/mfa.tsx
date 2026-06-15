import { useEffect, useState } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import { Loader2 } from 'lucide-react'
import { MFAChallenge } from '@/components/global/auth/MFAChallenge'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { fetchMFAFactors } from '@/lib/react-query/hooks'
import { resolvePostAuthRedirect } from '@/lib/post-auth-navigation'
import { pageTitle } from '@/lib/utils/page-title'

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
})

type MfaFactorsWithRecovery = Models.MfaFactors & { recoveryCode: boolean }

export const Route = createFileRoute('/_auth/mfa')({
  component: MFAPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Two-factor authentication') }] }),
})

function MFAPage() {
  const navigate = useNavigate()
  const search = Route.useSearch({ from: '/_auth/mfa' })
  const [factors, setFactors] = useState<MfaFactorsWithRecovery | null>(null)
  const [isInitializing, setIsInitializing] = useState(true)

  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        await sdk.forConsole.account.get()

        const target = resolvePostAuthRedirect(search.redirect) ?? '/'
        navigate({ to: target, replace: true })
        return
      } catch (error: unknown) {
        if (!(error instanceof AppwriteException)) {
          navigate({ to: '/sign-in', replace: true })
          return
        }

        if (error.type === 'user_more_factors_required') {
          try {
            const availableFactors = await fetchMFAFactors()
            if (cancelled) return
            setFactors({
              ...availableFactors,
              recoveryCode: true,
            })
            setIsInitializing(false)
          } catch {
            if (!cancelled) {
              navigate({ to: '/sign-in', replace: true })
            }
          }
          return
        }

        if (error.code === 401) {
          navigate({
            to: '/sign-in',
            search:
              search.redirect && isValidRelativeRedirect(search.redirect)
                ? { redirect: search.redirect }
                : undefined,
            replace: true,
          })
          return
        }

        navigate({ to: '/sign-in', replace: true })
      }
    }

    void init()

    return () => {
      cancelled = true
    }
  }, [navigate, search.redirect])

  if (isInitializing || !factors) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <MFAChallenge factors={factors} redirect={search.redirect} />
      </div>
    </div>
  )
}
