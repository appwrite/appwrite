import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { cn } from '@/lib/utils'

const FRAMEWORK_ICON: Record<string, string> = {
  ios: 'apple',
}

export function AppFrameworkMark({ framework }: { framework: string }) {
  return (
    <FrameworkIcon
      framework={FRAMEWORK_ICON[framework] ?? framework}
      size="sm"
    />
  )
}

export function AppFrameworkIcon({
  framework,
  className,
}: {
  framework: string
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground',
        className,
      )}
    >
      <AppFrameworkMark framework={framework} />
    </div>
  )
}
