import type { ComponentType } from 'react'
import { PostgresBackupsVisual } from '@/components/pages/products/features/postgres/PostgresBackupsVisual'
import { PostgresBranchesVisual } from '@/components/pages/products/features/postgres/PostgresBranchesVisual'
import { PostgresConnectVisual } from '@/components/pages/products/features/postgres/PostgresConnectVisual'
import { PostgresHighAvailabilityVisual } from '@/components/pages/products/features/postgres/PostgresHighAvailabilityVisual'
import { PostgresMonitoringVisual } from '@/components/pages/products/features/postgres/PostgresMonitoringVisual'
import { PostgresPoolingVisual } from '@/components/pages/products/features/postgres/PostgresPoolingVisual'

export const POSTGRES_FEATURE_VISUALS: Record<string, ComponentType> = {
  connections: PostgresConnectVisual,
  pooling: PostgresPoolingVisual,
  branches: PostgresBranchesVisual,
  backups: PostgresBackupsVisual,
  'high-availability': PostgresHighAvailabilityVisual,
  monitoring: PostgresMonitoringVisual,
}
