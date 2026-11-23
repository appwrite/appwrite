'use client'

import { Loader2, RefreshCw } from 'lucide-react'
import type { RealtimeReconnectState } from '@/lib/realtime/session-client'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ReconnectBannerProps = {
  state: RealtimeReconnectState
}

export function ReconnectBanner({ state }: ReconnectBannerProps) {
  const t = useT()
  if (state.status === 'idle') return null

  const delaySeconds =
    state.delayMs != null ? Math.ceil(state.delayMs / 1000) : undefined

  return (
    <div
      className={cn(
        'flex items-center gap-2 border-b border-amber-500/20 bg-amber-500/[0.06] px-4 py-2 text-[12px] text-amber-800 dark:text-amber-300',
      )}
      role="status"
      aria-live="polite"
    >
      {state.status === 'connecting' ? (
        <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden />
      ) : (
        <RefreshCw className="h-3.5 w-3.5 shrink-0" aria-hidden />
      )}
      <span>
        {state.status === 'connecting'
          ? `${t('Reconnecting…')} ${t('attempt')} ${state.attempt} ${t('of')} ${state.maxAttempts}`
          : `${t('Connection lost. Reconnecting in')} ${delaySeconds ?? '?'}s… ${t('attempt')} ${state.attempt} ${t('of')} ${state.maxAttempts}`}
      </span>
    </div>
  )
}
