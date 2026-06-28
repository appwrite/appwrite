export {
  BUCKET_FILE_STORAGE_GAUGE_METRIC as BUCKET_STORAGE_GAUGE_METRIC,
  fetchProjectStorageOverview,
  formatStorageBytesTotal as formatStorageTotal,
  formatStorageBytesValue as formatStorageValue,
  formatStorageBytesAxisValue as formatStorageAxisValue,
  type StorageTopConsumer,
} from '@/lib/usage/storage-usage'

import type { StorageTopConsumer } from '@/lib/usage/storage-usage'

export interface ProjectStorageOverview {
  changePercent: number
  latestValue: number
  topConsumers: StorageTopConsumer[]
}
