import type { ComponentType } from 'react'
import type { InitDayMockVisualId } from '@/lib/init/types'
import { ProductBentoSoftLights } from '@/components/pages/home/HomeSoftLights'
import { DatabasesProductVisual } from '@/components/pages/home/product-bento/DatabasesProductVisual'
import { FirewallProductVisual } from '@/components/pages/home/product-bento/FirewallProductVisual'
import { Appwrite2DayVisual } from './day-visuals/Appwrite2DayVisual'
import { PostgresDayVisual } from './day-visuals/PostgresDayVisual'
import { S3StorageDayVisual } from './day-visuals/S3StorageDayVisual'
import { cn } from '@/lib/utils'

const INIT_DAY_MOCK_VISUALS: Record<InitDayMockVisualId, ComponentType> = {
  'appwrite-2': Appwrite2DayVisual,
  postgres: PostgresDayVisual,
  databases: DatabasesProductVisual,
  's3-storage': S3StorageDayVisual,
  firewall: FirewallProductVisual,
}

export function InitDayMockVisual({
  mockVisualId,
  label,
  className,
}: {
  mockVisualId: InitDayMockVisualId
  label: string
  className?: string
}) {
  const Visual = INIT_DAY_MOCK_VISUALS[mockVisualId]

  return (
    <div
      className={cn(
        'group relative w-full overflow-hidden rounded-lg border border-border bg-muted/20',
        className,
      )}
      style={{ aspectRatio: '3 / 2' }}
      role="img"
      aria-label={label}
    >
      <ProductBentoSoftLights />
      <div className="absolute inset-0 p-2 sm:p-2.5">
        <div className="relative h-full min-h-0 w-full opacity-[0.94] transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100">
          <Visual />
        </div>
      </div>
    </div>
  )
}
