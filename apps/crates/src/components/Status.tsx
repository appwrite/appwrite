import { STATUS, type Status } from '@/lib/docs'

const DOT: Record<Status, string> = { complete: 'bg-ok', progress: 'bg-progress', started: 'bg-started', planned: 'bg-planned' }
const BADGE: Record<Status, string> = {
  complete: 'bg-ok-soft text-ok-ink',
  progress: 'bg-progress-soft text-progress-ink',
  started: 'bg-started-soft text-started-ink',
  planned: 'bg-planned-soft text-planned-ink',
}

export function StatusDot({ status, className = '' }: { status: Status; className?: string }) {
  return <span className={`inline-block size-1.5 flex-none rounded-full ${DOT[status]} ${className}`} title={STATUS[status].label} />
}

export function StatusBadge({ status }: { status: Status }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ${BADGE[status]}`} title={STATUS[status].note}>
      <StatusDot status={status} />
      {STATUS[status].label}
    </span>
  )
}
