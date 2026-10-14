import { STATUS, type Status } from '@/lib/docs'

// Not converted, by design: a hollow neutral dot, so it never reads as a stage of the conversion.
const DOT: Record<Status, string> = {
  complete: 'bg-ok',
  progress: 'bg-progress',
  started: 'bg-started',
  planned: 'bg-planned',
  retired: 'bg-transparent ring-1 ring-inset ring-muted-foreground',
}
const BADGE: Record<Status, string> = {
  complete: 'bg-ok-soft text-ok-ink',
  progress: 'bg-progress-soft text-progress-ink',
  started: 'bg-started-soft text-started-ink',
  planned: 'bg-planned-soft text-planned-ink',
  retired: 'border border-dashed border-border bg-transparent text-muted-foreground',
}

export function StatusDot({ status, className = '' }: { status: Status; className?: string }) {
  return <span className={`inline-block size-1.5 flex-none ${DOT[status]} ${className}`} title={STATUS[status].label} />
}

export function StatusBadge({ status }: { status: Status }) {
  return (
    <span className={`inline-flex items-center gap-1.5 px-1.5 py-0.5 font-mono text-[10px] font-medium tracking-[0.06em] whitespace-nowrap uppercase ${BADGE[status]}`} title={STATUS[status].note}>
      <StatusDot status={status} />
      {STATUS[status].label}
    </span>
  )
}
