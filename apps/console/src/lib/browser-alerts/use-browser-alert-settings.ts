import { useCallback, useEffect, useState } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useBuildNotificationsOptedOut } from '@/lib/react-query/hooks'
import type { ConsoleAccountCache } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import {
  getNotificationPermission,
  notificationsSupported,
  requestNotificationPermission,
  showTestBrowserAlert,
  subscribeToNotificationPermissionChanges,
  type BrowserNotificationPermission,
} from './browser-notifications'

export function useBrowserAlertSettings() {
  const { account } = useAuth()
  const t = useT()
  const { optedOut, setOptedOut } = useBuildNotificationsOptedOut(
    account as ConsoleAccountCache | undefined,
  )
  const supported = notificationsSupported()
  const [permission, setPermission] = useState<BrowserNotificationPermission>(
    () => getNotificationPermission(),
  )
  const [isUpdating, setIsUpdating] = useState(false)
  const [isSendingTest, setIsSendingTest] = useState(false)

  useEffect(() => {
    if (!supported) return
    const unsubscribe = subscribeToNotificationPermissionChanges(setPermission)
    const syncOnFocus = () => {
      setPermission(getNotificationPermission())
    }
    window.addEventListener('focus', syncOnFocus)
    document.addEventListener('visibilitychange', syncOnFocus)
    return () => {
      unsubscribe()
      window.removeEventListener('focus', syncOnFocus)
      document.removeEventListener('visibilitychange', syncOnFocus)
    }
  }, [supported])

  const alertsEnabled = supported && !optedOut && permission === 'granted'

  const setAlertsEnabled = useCallback(
    async (enabled: boolean) => {
      if (!account) return
      setIsUpdating(true)
      try {
        if (!enabled) {
          setOptedOut(true)
          return
        }

        setOptedOut(false)

        if (!supported) return

        const current = getNotificationPermission()
        if (current === 'granted') return

        if (current === 'denied') {
          toast.message(t('Notifications blocked'), {
            description: t(
              'Allow notifications for this site in your browser settings, then return here.',
            ),
          })
          return
        }

        const next = await requestNotificationPermission()
        setPermission(next)

        if (next === 'granted') {
          toast.success(t('Notifications enabled'), {
            description: t('Reloading to apply…'),
            duration: 1500,
          })
          window.setTimeout(() => {
            try {
              window.location.reload()
            } catch {
              // Ignore reload failures.
            }
          }, 800)
        } else if (next === 'denied') {
          setOptedOut(true)
          toast.message(t('Notifications blocked'), {
            description: t(
              'You can re-enable them anytime from your browser settings.',
            ),
          })
        }
      } finally {
        setIsUpdating(false)
      }
    },
    [account, setOptedOut, supported, t],
  )

  const sendTestNotification = useCallback(async () => {
    if (isSendingTest) return
    setIsSendingTest(true)
    try {
      const result = await showTestBrowserAlert(
        t('Browser alerts test'),
        t('If you can read this, browser alerts are working correctly.'),
      )

      if (result === 'shown') {
        toast.success(t('Test notification sent'), {
          description: t(
            'If no banner appeared, check your system notification center or Do Not Disturb settings.',
          ),
        })
        return
      }

      if (result === 'denied') {
        toast.error(t('Could not send a test notification. Check browser permissions.'))
        return
      }

      if (result === 'unsupported') {
        toast.error(t('This browser does not support desktop notifications.'))
        return
      }

      toast.error(
        t('Could not send a test notification. Check browser permissions.'),
      )
    } finally {
      setIsSendingTest(false)
    }
  }, [isSendingTest, t])

  return {
    supported,
    permission,
    optedOut,
    alertsEnabled,
    isUpdating,
    isSendingTest,
    setAlertsEnabled,
    sendTestNotification,
  }
}
