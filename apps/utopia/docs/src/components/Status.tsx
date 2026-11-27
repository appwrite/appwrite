import { STATUS, type Status } from '@/lib/docs'

const DOT: Record<Status, string> = {
  rust: 'bg-ok',
  partial: 'bg-progress',
  switchable: 'bg-started',
  planned: 'bg-planned',
}
const BADGE: Record<Status, string> = {
  rust: 'bg-ok-soft text-ok-ink',
  partial: 'bg-progress-soft text-progress-ink',
  switchable: 'bg-started-soft text-started-ink',
  planned: 'bg-planned-soft text-planned-ink',
}

/** Every badge on the site: a square tag in mono capitals, its tint from the caller. */
export const badge = 'inline-flex items-center gap-1.5 px-1.5 py-0.5 font-mono text-[10px] font-medium tracking-[0.06em] whitespace-nowrap uppercase'

export function StatusDot({ status, className = '' }: { status: Status; className?: string }) {
  return <span className={`inline-block size-1.5 flex-none ${DOT[status]} ${className}`} title={STATUS[status].label} />
}

export function StatusBadge({ status }: { status: Status }) {
  return (
    <span className={`${badge} ${BADGE[status]}`} title={STATUS[status].note}>
      <StatusDot status={status} />
      {STATUS[status].label}
    </span>
  )
}
