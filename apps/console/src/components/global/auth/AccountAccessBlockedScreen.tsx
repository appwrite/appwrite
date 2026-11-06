import { AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { CONSOLE_ACCOUNT_ACCESS_BLOCKED } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type AccountAccessBlockedScreenProps = {
  /**
   * `fill` - grow inside a parent that already defines full viewport height (e.g. under impersonation banner).
   * `fullscreen` - default standalone page.
   */
  layout?: 'fullscreen' | 'fill'
}

export function AccountAccessBlockedScreen({
  layout = 'fullscreen',
}: AccountAccessBlockedScreenProps) {
  const t = useT()
  const { title, message } = CONSOLE_ACCOUNT_ACCESS_BLOCKED
  const isFill = layout === 'fill'

  return (
    <div
      className={cn(
        'flex w-full flex-col items-center justify-center gap-8 px-4 py-8',
        isFill
          ? 'min-h-0 flex-1'
          : 'min-h-svh bg-background',
      )}
    >
      <div className="flex w-full max-w-md flex-col items-center gap-8">
        <div className="rounded-full bg-destructive/10 p-3">
          <AlertTriangle
            className="h-8 w-8 text-destructive"
            aria-hidden
          />
        </div>

        <div className="space-y-3 text-center">
          <h1 className="text-2xl font-semibold">{t(title)}</h1>
          <p className="text-sm leading-relaxed text-muted-foreground">
            {t(message)}
          </p>
        </div>
      </div>
    </div>
  )
}
