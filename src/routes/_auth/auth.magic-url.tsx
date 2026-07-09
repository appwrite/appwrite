import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  createFileRoute,
  Link,
  useNavigate,
  useRouter,
} from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import { AppwriteException } from '@appwrite.io/console'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { sdk } from '@/lib/appwrite/sdk'
import { refreshConsoleAccountAfterAuth } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Landing page for the Appwrite backend's console magic-URL emails:
 * {host}/auth/magic-url?userId={userId}&secret={secret}&expire={expire}&project=console
 */

/** Read userId/secret from the URL directly so long secrets are not altered by router/search parsing. */
function getMagicUrlParamsFromUrl(): {
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

export const Route = createFileRoute('/_auth/auth/magic-url')({
  component: MagicUrlPage,
  head: () => ({ meta: [{ title: pageTitle('Magic URL login') }] }),
})

function MagicUrlPage() {
  const t = useT()
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const confirmMutation = useMutation({
    mutationFn: async (params: { userId: string; secret: string }) => {
      await sdk.forConsole.account.createSession({
        userId: params.userId,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      try {
        await refreshConsoleAccountAfterAuth(queryClient)
        await router.invalidate()
      } catch {
        // Root loader re-fetches the account; proceed regardless.
      }
      // Root loader resolves (or provisions) the organization and redirects.
      navigate({ to: '/', replace: true })
    },
    onError: (error: unknown) => {
      setErrorMessage(
        error instanceof AppwriteException
          ? error.message
          : t('The magic URL is invalid or has expired.'),
      )
    },
  })

  const hasTriggeredConfirm = useRef(false)

  // The ref gate makes re-runs no-ops, so listing deps is safe.
  useEffect(() => {
    if (hasTriggeredConfirm.current) return
    hasTriggeredConfirm.current = true
    const params = getMagicUrlParamsFromUrl()
    if (params) {
      confirmMutation.mutate(params)
    } else {
      setErrorMessage(t('The magic URL is missing required parameters.'))
    }
  }, [confirmMutation.mutate, t])

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <Card className="overflow-hidden py-0">
          <div className="grid md:grid-cols-2">
            <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
              <div className="space-y-6">
                {errorMessage ? (
                  <>
                    <div className="space-y-2">
                      <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Unable to sign you in')}
                      </h1>
                      <p className="text-sm text-muted-foreground">
                        {errorMessage}
                      </p>
                    </div>
                    <Link to="/sign-in">
                      <Button className="w-full">{t('Go to sign in')}</Button>
                    </Link>
                  </>
                ) : (
                  <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      {t('Signing you in')}
                    </h1>
                    <p className="text-sm text-muted-foreground flex items-center gap-2">
                      <Loader2 className="h-4 w-4 animate-spin" />
                      {t('Please wait while we confirm your magic URL.')}
                    </p>
                  </div>
                )}
              </div>
            </div>
            <div className="hidden bg-background md:block min-h-[600px]">
              <img
                alt="Appwrite console illustration"
                className="h-full w-full object-cover"
                height="600"
                src="/cover.avif"
                width="600"
              />
            </div>
          </div>
        </Card>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
