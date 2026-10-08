import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useRouter,
} from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { MagicUrlLoginCard } from '@/components/global/auth/MagicUrlLoginCard'
import { sdk } from '@/lib/appwrite/sdk'
import { refreshConsoleAccountAfterAuth } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

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

  return <MagicUrlLoginCard errorMessage={errorMessage} />
}
