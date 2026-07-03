import { WifiOff } from 'lucide-react'
import { useConfirmedOffline } from '@/lib/network-connectivity'
import { useT } from '@/lib/i18n/translate'

/**
 * Full-viewport curtain when the browser reports no network connection.
 * Blocks interaction with the console until the connection is restored.
 */
export function NetworkOfflineCurtain() {
  const t = useT()
  const offline = useConfirmedOffline()

  if (!offline) return null

  return (
    <div
      role="alert"
      aria-live="assertive"
      aria-relevant="additions"
      className="fixed inset-0 z-[125] flex h-[100dvh] max-h-[100dvh] w-full items-center justify-center bg-background/95 backdrop-blur-sm"
    >
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <WifiOff className="size-9" aria-hidden />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t("You're offline")}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">
          {t(
            "The console needs an internet connection to reach Appwrite's data centers. We'll restore the page automatically when you are back online.", // pragma: allowlist secret
          )}
        </p>
      </div>
    </div>
  )
}
