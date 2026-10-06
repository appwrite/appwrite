import type { ComponentType } from 'react'
import { RealtimePayloadVisual } from '@/components/pages/products/features/realtime/RealtimePayloadVisual'
import { RealtimePermissionsVisual } from '@/components/pages/products/features/realtime/RealtimePermissionsVisual'
import { RealtimePresencesVisual } from '@/components/pages/products/features/realtime/RealtimePresencesVisual'
import { RealtimeQueriesVisual } from '@/components/pages/products/features/realtime/RealtimeQueriesVisual'
import { RealtimeSubscribeVisual } from '@/components/pages/products/features/realtime/RealtimeSubscribeVisual'

/** The `channels` section renders `RealtimeChannelCatalog` as a companion instead of a visual. */
export const REALTIME_FEATURE_VISUALS: Record<string, ComponentType> = {
  subscribe: RealtimeSubscribeVisual,
  queries: RealtimeQueriesVisual,
  presences: RealtimePresencesVisual,
  payload: RealtimePayloadVisual,
  permissions: RealtimePermissionsVisual,
}
