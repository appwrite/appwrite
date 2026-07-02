import { useEffect, useRef } from 'react'
import { ExternalLink } from 'lucide-react'
import { AnimatePresence, motion } from 'motion/react'

import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import {
  getMockReportTitle,
  getStatusIcon,
  getStatusPresentation,
  formatLocalMaintenanceWindow,
} from '@/lib/cloud-status-copy'
import type { MockCloudStatusAlert } from '@/lib/debug-overrides'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  useAppwriteCloudStatus,
  type AppwriteCloudAggregateState,
  type AppwriteCloudStatusSummary,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

const BANNER_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
const BANNER_DURATION_S = 0.55

/** Persists across layout remounts (route changes) while a non-operational incident is showing. */
let cloudStatusBannerEnterAnimationAlreadyPlayed = false

type CloudStatusBannerInnerProps = {
  aggregateState: Exclude<AppwriteCloudAggregateState, 'operational'>
  data: AppwriteCloudStatusSummary | undefined
  mockCloudStatusAlert: MockCloudStatusAlert
  skipEnterAnimation: boolean
}

function CloudStatusBannerInner({
  aggregateState,
  data,
  mockCloudStatusAlert,
  skipEnterAnimation,
}: CloudStatusBannerInnerProps) {
  const t = useT()
  const enterAnimationRecordedRef = useRef(false)

  const handleAnimationComplete = () => {
    if (skipEnterAnimation || enterAnimationRecordedRef.current) return
    enterAnimationRecordedRef.current = true
    cloudStatusBannerEnterAnimationAlreadyPlayed = true
  }
  const presentation = getStatusPresentation(aggregateState)
  const Icon = getStatusIcon(aggregateState)
  const activeReportTitle =
    mockCloudStatusAlert !== 'live'
      ? getMockReportTitle(mockCloudStatusAlert)
      : data?.activeReport?.title
  const maintenanceWindow =
    aggregateState === 'maintenance'
      ? mockCloudStatusAlert !== 'live'
        ? formatLocalMaintenanceWindow(
            new Date(Date.now() + 30 * 60 * 1000).toISOString(),
            new Date(Date.now() + 2 * 60 * 60 * 1000).toISOString(),
          )
        : formatLocalMaintenanceWindow(
            data?.activeReport?.startsAt,
            data?.activeReport?.endsAt,
          )
      : undefined

  const regionsLine =
    mockCloudStatusAlert === 'live' ? data?.regionsLine : undefined

  const statusUrl = 'https://status.appwrite.online'

  return (
    <motion.div
      initial={skipEnterAnimation ? false : { gridTemplateRows: '0fr' }}
      animate={{ gridTemplateRows: '1fr' }}
      exit={{ gridTemplateRows: '0fr' }}
      transition={{ duration: BANNER_DURATION_S, ease: BANNER_EASE }}
      style={{ display: 'grid' }}
      className="overflow-hidden"
      onAnimationComplete={handleAnimationComplete}
    >
      <div className="min-h-0 overflow-hidden">
        <a
          href={statusUrl}
          target="_blank"
          rel="noopener noreferrer"
          className={cn(
            'relative flex min-h-14 min-w-0 flex-col gap-3 px-4 py-3 transition-all duration-200 hover:opacity-95 sm:flex-row sm:items-center sm:gap-4',
            presentation.containerClassName,
          )}
        >
          <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 sm:mt-0" />
            <p className="text-[13px] font-medium leading-snug">
              {t(presentation.title)}
              {activeReportTitle ? (
                <>
                  {' '}
                  <span className="text-foreground">
                    {t(activeReportTitle)}
                  </span>
                </>
              ) : null}
              {maintenanceWindow ? (
                <>
                  {' '}
                  <span className="text-foreground/70">{maintenanceWindow}</span>
                </>
              ) : null}
              {regionsLine ? (
                <>
                  {' '}
                  <span className="text-foreground/70">{t(regionsLine)}</span>
                </>
              ) : null}
            </p>
          </div>
          <span
            className={cn(
              'flex h-8 w-fit shrink-0 items-center gap-2 rounded-md px-3 text-[13px] font-medium sm:ms-auto',
              presentation.buttonClassName,
            )}
          >
            <span className="hidden sm:inline">{t('View Status')}</span>
            <span className="sm:hidden">{t('Status')}</span>
            <ExternalLink className="h-3.5 w-3.5" />
          </span>
        </a>
      </div>
    </motion.div>
  )
}

export function CloudStatusBanner() {
  const { isCloud, features } = useConsoleProfile()
  const { account, isFetched } = useAuth()
  const cloudStatusEnabled = isCloud && features.systemStatus
  const showToOperator =
    isFetched && isOperatorAccount(account as OperatorAccount)
  const { mockCloudStatusAlert } = useDebugOverrides()
  const { data, isSuccess } = useAppwriteCloudStatus(
    cloudStatusEnabled && showToOperator,
  )

  const aggregateState: AppwriteCloudAggregateState =
    !cloudStatusEnabled
      ? 'operational'
      : mockCloudStatusAlert !== 'live'
        ? mockCloudStatusAlert
        : isSuccess
          ? (data?.consoleAlertState ?? 'operational')
          : 'operational'

  useEffect(() => {
    if (aggregateState === 'operational') {
      cloudStatusBannerEnterAnimationAlreadyPlayed = false
    }
  }, [aggregateState])

  if (!cloudStatusEnabled || !showToOperator) {
    return null
  }

  return (
    <AnimatePresence>
      {aggregateState !== 'operational' ? (
        <CloudStatusBannerInner
          key="cloud-status-banner"
          aggregateState={aggregateState}
          data={data}
          mockCloudStatusAlert={mockCloudStatusAlert}
          skipEnterAnimation={cloudStatusBannerEnterAnimationAlreadyPlayed}
        />
      ) : null}
    </AnimatePresence>
  )
}
