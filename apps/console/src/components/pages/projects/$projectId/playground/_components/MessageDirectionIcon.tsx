import { ArrowDownLeft, ArrowUpRight, Info } from 'lucide-react'
import type { RealtimeMessageDirection } from '@/lib/realtime/session-client'
import {
  REALTIME_INCOMING_ICON_CLASS,
  REALTIME_OUTGOING_ICON_CLASS,
} from '@/lib/realtime/message-direction-styles'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type MessageDirectionIconProps = {
  direction: RealtimeMessageDirection
  type?: string
  className?: string
}

export function MessageDirectionIcon({
  direction,
  type = 'unknown',
  className = 'h-3.5 w-3.5 shrink-0',
}: MessageDirectionIconProps) {
  const t = useT()

  if (type === 'info') {
    return (
      <Info
        className={cn(className, 'text-muted-foreground')}
        aria-label={t('Info message')}
      />
    )
  }

  if (direction === 'in') {
    return (
      <ArrowDownLeft
        className={cn(className, REALTIME_INCOMING_ICON_CLASS)}
        aria-label={t('Incoming message')}
      />
    )
  }

  return (
    <ArrowUpRight
      className={cn(className, REALTIME_OUTGOING_ICON_CLASS)}
      aria-label={t('Outgoing message')}
    />
  )
}
