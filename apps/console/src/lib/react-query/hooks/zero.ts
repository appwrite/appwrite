/**
 * Zero projects: claimable projects handed out without signup.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useRouter } from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { sdk, setProjectRegion } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { refreshConsoleAccountAfterAuth } from '@/lib/react-query/hooks/auth'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** How often the banner and expired curtain re-read a Zero project's status. */
export const ZERO_POLL_INTERVAL_MS = 30 * 1000

export const ACCOUNT_ZERO_QUERY_KEY = ['zero', 'account'] as const

/**
 * The Zero project the signed-in anonymous account owns. The server returns
 * its claim link, so nothing about the claim is stored in the browser.
 */
export function useAccountZero(options?: {
  poll?: boolean
  enabled?: boolean
}) {
  return useQuery({
    queryKey: ACCOUNT_ZERO_QUERY_KEY,
    queryFn: () => sdk.forConsole.zero.getAccount(),
    enabled: options?.enabled ?? true,
    staleTime: ZERO_POLL_INTERVAL_MS,
    retry: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchInterval: options?.poll ? ZERO_POLL_INTERVAL_MS : false,
  })
}

/**
 * Starts a Zero project: the server signs this browser in as its anonymous
 * owner, and the console opens the project.
 */
export function useStartZero() {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const router = useRouter()

  return useMutation({
    mutationFn: () => sdk.forConsole.zero.create({ source: 'console' }),
    onSuccess: async (zero) => {
      queryClient.setQueryData(ACCOUNT_ZERO_QUERY_KEY, zero)
      setProjectRegion(zero.projectId, zero.region)
      await refreshConsoleAccountAfterAuth(queryClient)
      await router.invalidate()
      navigate({
        to: '/projects/$projectId',
        params: { projectId: zero.projectId },
        replace: true,
      })
    },
    onError: (error: unknown) => {
      if (
        error instanceof AppwriteException &&
        (error.code === 429 || error.type === 'zero_unavailable')
      ) {
        toast.error(
          t(
            'No projects are available without an account right now. Try again shortly or sign up.',
          ),
        )
        return
      }
      toast.error(
        getErrorMessage(
          error,
          t('Could not start a project without an account'),
        ),
      )
    },
  })
}
