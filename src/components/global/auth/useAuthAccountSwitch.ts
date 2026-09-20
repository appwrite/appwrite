import { useCallback } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  getAuthFlowReturnUrl,
  resolveAuthSignInPath,
} from '@/lib/auth/auth-flow-return-url'
import { useT } from '@/lib/i18n/translate'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'

type UseAuthAccountSwitchOptions = {
  /** Preserve demo preview routes when opening sign-in after sign-out. */
  preview?: boolean
  /** Override return URL (defaults to current page). */
  returnUrl?: string
}

export function useAuthAccountSwitch(options: UseAuthAccountSwitchOptions = {}) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const t = useT()

  return useCallback(async () => {
    const returnUrl = options.returnUrl ?? getAuthFlowReturnUrl()
    const signInPath = resolveAuthSignInPath()

    try {
      await performConsoleSignOut(queryClient, {
        destination: returnUrl,
        requireServerRevocation: !options.preview,
      })
    } catch {
      if (options.preview || signInPath === '/debug/sign-in-preview') {
        navigate({
          to: '/debug/sign-in-preview',
          search:
            returnUrl && returnUrl !== '/debug/sign-in-preview'
              ? { redirect: returnUrl }
              : undefined,
        })
        return
      }
      toast.error(t('Could not sign out. Try switching accounts again.'))
    }
  }, [navigate, options.preview, options.returnUrl, queryClient, t])
}
