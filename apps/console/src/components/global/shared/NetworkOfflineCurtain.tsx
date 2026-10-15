import { WifiOff } from 'lucide-react'
import { useConfirmedOffline } from '@/lib/network-connectivity'
import { useT } from '@/lib/i18n/translate'
import { FullScreenCurtain } from './FullScreenCurtain'

/**
 * Full-viewport curtain when the browser reports no network connection.
 * Blocks interaction with the console until the connection is restored.
 */
export function NetworkOfflineCurtain() {
  const t = useT()
  const offline = useConfirmedOffline()

  if (!offline) return null

  return (
    <FullScreenCurtain
      role="alert"
      className="z-[125]"
      icon={WifiOff}
      title={t("You're offline")}
      description={t(
        "The console needs an internet connection to reach Appwrite's data centers. We'll restore the page automatically when you are back online.", // pragma: allowlist secret
      )}
    />
  )
}
