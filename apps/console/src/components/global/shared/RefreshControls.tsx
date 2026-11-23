import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { useT } from '@/lib/i18n/translate'

export type RefreshInterval =
  | 'off'
  | 'auto'
  | '30s'
  | '1m'
  | '5m'
  | '15m'
  | '30m'
  | '1h'
  | '2h'
  | '1d'

export const REFRESH_INTERVALS: Record<RefreshInterval, number | null> = {
  off: null,
  auto: 30000, // 30 seconds
  '30s': 30000,
  '1m': 60000,
  '5m': 300000,
  '15m': 900000,
  '30m': 1800000,
  '1h': 3600000,
  '2h': 7200000,
  '1d': 86400000,
}

interface RefreshControlsProps {
  onRefresh: () => Promise<void> | void
  refreshInterval?: RefreshInterval
  onRefreshIntervalChange?: (interval: RefreshInterval) => void
  projectId?: string | null | undefined
  defaultInterval?: RefreshInterval
  className?: string
}

export function RefreshControls({
  onRefresh,
  refreshInterval: controlledRefreshInterval,
  onRefreshIntervalChange,
  projectId,
  defaultInterval = '30s',
  className,
}: RefreshControlsProps) {
  const t = useT()
  const [internalRefreshInterval, setInternalRefreshInterval] =
    useState<RefreshInterval>(defaultInterval)
  const [isRefreshing, setIsRefreshing] = useState(false)

  // Use controlled or internal state
  const refreshInterval = controlledRefreshInterval ?? internalRefreshInterval
  const setRefreshInterval =
    onRefreshIntervalChange ?? setInternalRefreshInterval

  // Auto-refresh logic
  useEffect(() => {
    const intervalMs = REFRESH_INTERVALS[refreshInterval]
    if (!intervalMs || !projectId) return

    const interval = setInterval(() => {
      setIsRefreshing(true)
      Promise.resolve(onRefresh()).finally(() => {
        setIsRefreshing(false)
      })
    }, intervalMs)

    return () => clearInterval(interval)
  }, [refreshInterval, projectId, onRefresh])

  const handleManualRefresh = () => {
    setIsRefreshing(true)
    Promise.resolve(onRefresh()).finally(() => {
      setIsRefreshing(false)
    })
  }

  return (
    <div
      className={cn(
        'inline-flex items-center rounded-md border border-input bg-background',
        className,
      )}
    >
      <RefreshButton
        variant="ghost"
        onClick={handleManualRefresh}
        isRefreshing={isRefreshing}
        className="rounded-e-none rounded-s-md border-0 border-e border-input hover:bg-accent"
      />
      <Select
        value={refreshInterval}
        onValueChange={(value) => setRefreshInterval(value as RefreshInterval)}
      >
        <SelectTrigger className="h-9 w-[70px] rounded-s-none border-0 border-s-0 bg-background px-3 text-[13px] shadow-none focus:ring-0 focus:ring-offset-0 hover:bg-accent">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="off">{t('Off')}</SelectItem>
          <SelectItem value="auto">{t('Auto')}</SelectItem>
          <SelectItem value="30s">30s</SelectItem>
          <SelectItem value="1m">1m</SelectItem>
          <SelectItem value="5m">5m</SelectItem>
          <SelectItem value="15m">{t('15m')}</SelectItem>
          <SelectItem value="30m">30m</SelectItem>
          <SelectItem value="1h">{t('1h')}</SelectItem>
          <SelectItem value="2h">2h</SelectItem>
          <SelectItem value="1d">{t('1d')}</SelectItem>
        </SelectContent>
      </Select>
    </div>
  )
}
