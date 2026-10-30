import type { ReactNode } from 'react'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { cn } from '@/lib/utils'

export function PartnerListLoading({ label }: { label: string }) {
  return (
    <div className="rounded-lg border border-border bg-card py-12 text-center">
      <p className="text-[13px] text-muted-foreground">{label}</p>
    </div>
  )
}

type PartnerPageProps = {
  title: string
  createLabel?: string
  onCreate?: () => void
  children: ReactNode
  className?: string
}

export function PartnerPage({
  title,
  createLabel,
  onCreate,
  children,
  className,
}: PartnerPageProps) {
  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={title}
        createLabel={createLabel}
        onCreate={onCreate}
        showFilters={false}
        fullWidthBorder
      />
      <div
        className={cn(
          'mx-auto w-full max-w-7xl px-4 py-4 sm:px-6',
          className,
        )}
      >
        {children}
      </div>
    </div>
  )
}
