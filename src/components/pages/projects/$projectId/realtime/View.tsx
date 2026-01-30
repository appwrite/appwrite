import { useMemo } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  RealtimeOverviewKPIs,
  RealtimeOverviewControlsAndCharts,
} from './Overview'
import { RealtimeMessages } from './Messages'
import { RealtimeChannels } from './Channels'
import { ComingSoonCurtain } from '@/components/ui/coming-soon-curtain'

export function View() {
  const { projectId } = useParams({ strict: false })
  const location = useLocation()

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const realtimeIndex = pathParts.findIndex((part) => part === 'realtime')

    if (realtimeIndex >= 0) {
      if (pathParts[realtimeIndex + 1]) {
        const tabFromPath = pathParts[realtimeIndex + 1]
        if (['messages', 'channels'].includes(tabFromPath)) {
          return tabFromPath
        }
      }
    }

    // Default to overview for index route
    return 'overview'
  }, [location.pathname])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: 'Overview',
        to: '/projects/$projectId/realtime/',
        params: { projectId: projectId as string },
      },
      {
        id: 'messages',
        label: 'Messages',
        to: '/projects/$projectId/realtime/messages',
        params: { projectId: projectId as string },
      },
      {
        id: 'channels',
        label: 'Channels',
        to: '/projects/$projectId/realtime/channels',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const isFullWidthTab = activeTab === 'messages' || activeTab === 'channels'

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Realtime"
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        fullWidth={isFullWidthTab}
      />

      <ComingSoonCurtain
        featureId="realtime"
        message="Monitor live connections, channels, and messages in real-time with detailed analytics and insights."
      >
        <div
          className={cn('flex-1', isFullWidthTab ? 'w-full px-0' : 'w-full')}
        >
          {activeTab === 'overview' && (
            <div className="flex flex-col">
              {/* KPI Cards Section */}
              <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
                <RealtimeOverviewKPIs projectId={projectId} />
              </div>
              {/* Separator - full width */}
              <div className="border-b border-border" />
              {/* Controls and Charts Section */}
              <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
                <RealtimeOverviewControlsAndCharts projectId={projectId} />
              </div>
            </div>
          )}
          {activeTab === 'messages' && (
            <RealtimeMessages projectId={projectId} />
          )}
          {activeTab === 'channels' && (
            <RealtimeChannels projectId={projectId} />
          )}
        </div>
      </ComingSoonCurtain>
    </div>
  )
}
