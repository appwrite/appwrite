import { cn } from '@/lib/utils'

export function MockPermissionChip({
  label,
  tone = 'muted',
}: {
  label: string
  tone?: 'muted' | 'accent'
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px]',
        tone === 'accent'
          ? 'border-foreground/10 bg-muted/50 text-foreground'
          : 'border-border bg-muted/30 text-muted-foreground',
      )}
    >
      {label}
    </span>
  )
}
