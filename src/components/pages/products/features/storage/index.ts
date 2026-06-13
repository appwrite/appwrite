import type { ComponentType } from 'react'
import { StorageBucketsVisual } from '@/components/pages/products/features/storage/StorageBucketsVisual'
import { StorageCdnVisual } from '@/components/pages/products/features/storage/StorageCdnVisual'
import { StorageCompressionVisual } from '@/components/pages/products/features/storage/StorageCompressionVisual'
import { StorageEncryptionVisual } from '@/components/pages/products/features/storage/StorageEncryptionVisual'
import { StorageFileTokensVisual } from '@/components/pages/products/features/storage/StorageFileTokensVisual'
import { StoragePermissionsVisual } from '@/components/pages/products/features/storage/StoragePermissionsVisual'
import { StorageS3Visual } from '@/components/pages/products/features/storage/StorageS3Visual'
import { StorageTransformWizardVisual } from '@/components/pages/products/features/storage/StorageTransformWizardVisual'
import { StorageTransformsVisual } from '@/components/pages/products/features/storage/StorageTransformsVisual'

export const STORAGE_FEATURE_VISUALS: Record<string, ComponentType> = {
  transforms: StorageTransformsVisual,
  cdn: StorageCdnVisual,
  compression: StorageCompressionVisual,
  permissions: StoragePermissionsVisual,
  encryption: StorageEncryptionVisual,
  'file-tokens': StorageFileTokensVisual,
  buckets: StorageBucketsVisual,
  s3: StorageS3Visual,
  'transform-wizard': StorageTransformWizardVisual,
}
