import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type FirewallChartLiveIndicatorProps = {
  isLive: boolean
  isPolling?: boolean
  className?: string
}

export function FirewallChartLiveIndicator({
  isLive,
  isPolling = false,
  className,
}: FirewallChartLiveIndicatorProps) {
  const t = useT()

  return (
    <div
      className={cn(
        'flex h-5 shrink-0 items-center gap-1.5 rounded-md border px-2 text-[12px] font-medium leading-none',
        'transform transition-[opacity,transform,box-shadow,border-color,background-color] duration-300 ease-out motion-reduce:transition-none',
        isLive
          ? 'scale-100 border-border/80 bg-background/85 text-muted-foreground opacity-100 shadow-sm'
          : 'pointer-events-none scale-[0.97] border-transparent bg-transparent opacity-0 shadow-none',
        className,
      )}
      aria-hidden={!isLive}
    >
      <span
        className={cn(
          'h-2 w-2 shrink-0 rounded-full bg-emerald-500 transition-transform duration-300 ease-out motion-reduce:transition-none',
          isLive ? 'scale-100' : 'scale-75',
          isLive && isPolling && 'motion-reduce:animate-none animate-pulse',
        )}
      />
      {t('Live')}
    </div>
  )
}
