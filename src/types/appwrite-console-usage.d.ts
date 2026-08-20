import type { Models } from '@appwrite.io/console'

type UsageEventDimensionName =
  | 'path'
  | 'method'
  | 'status'
  | 'service'
  | 'resourceType'
  | 'country'
  | 'continentCode'
  | 'city'
  | 'region'
  | 'hostname'
  | 'ip'
  | 'osName'
  | 'clientType'
  | 'clientName'
  | 'deviceName'
  | 'sdk'
  | 'sdkVersion'
  | 'teamId'
  | 'resourceId'

type UsageGaugeDimensionName =
  | 'resourceId'
  | 'teamId'
  | 'service'
  | 'resourceType'
  | 'ordinal'

/**
 * Usage metric names are intentionally server-defined and extensible. The
 * generated SDK metric enums can lag producers, while the request wire format
 * accepts metric strings. Keep exact local unions for every closed field and
 * widen only metric names plus the current gauge aggregate contract.
 */
declare module '@appwrite.io/console' {
  interface Usage {
    listEvents(params: {
      metrics: string[]
      queries?: string[]
      interval?: '1m' | '15m' | '30m' | '1h' | '1d'
      dimensions?: UsageEventDimensionName[]
      startAt?: string
      endAt?: string
      orderBy?: 'time' | 'value'
      orderDir?: 'asc' | 'desc'
      limit?: number
      offset?: number
    }): Promise<Models.UsageEventList>

    listGauges(params: {
      metrics: string[]
      queries?: string[]
      interval?: '1m' | '15m' | '30m' | '1h' | '1d'
      dimensions?: UsageGaugeDimensionName[]
      startAt?: string
      endAt?: string
      orderBy?: 'time' | 'value'
      orderDir?: 'asc' | 'desc'
      limit?: number
      offset?: number
      aggregate?: 'last' | 'max'
    }): Promise<Models.UsageGaugeList>
  }
}
