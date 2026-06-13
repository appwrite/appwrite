import type { LucideIcon } from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'

export function MockSwitchRow({
  label,
  description,
  checked = false,
  icon: Icon,
  className,
  highlightOnHover = false,
}: {
  label: string
  description?: string
  checked?: boolean
  icon?: LucideIcon
  className?: string
  highlightOnHover?: boolean
}) {
  return (
    <div
      className={cn(
        'flex items-start justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2.5 transition-colors',
        highlightOnHover &&
          'group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/40',
        className,
      )}
    >
      <div className="flex min-w-0 items-start gap-2.5">
        {Icon ? (
          <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
            <Icon className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
        ) : null}
        <div className="min-w-0">
          <p className="text-[12px] font-medium text-foreground">{label}</p>
          {description ? (
            <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">{description}</p>
          ) : null}
        </div>
      </div>
      <Switch
        checked={checked}
        disabled
        className="mt-0.5 shrink-0 data-[state=checked]:bg-foreground/80"
        aria-hidden
      />
    </div>
  )
}

export function MockProviderTile({
  name,
  iconSrc,
  enabled = false,
  className,
  style,
}: {
  name: string
  iconSrc: string
  enabled?: boolean
  className?: string
  style?: CSSProperties
}) {
  return (
    <div
      className={cn(
        'flex flex-col gap-2 rounded-lg border border-border bg-background/80 p-2.5 transition-[border-color,background-color,transform,box-shadow] duration-300',
        enabled && 'border-foreground/10 bg-muted/40',
        className,
      )}
      style={style}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2">
          <img
            src={iconSrc}
            alt=""
            className={cn(
              'size-4 shrink-0 transition-all duration-300',
              enabled
                ? 'opacity-100 grayscale-0'
                : 'opacity-60 grayscale group-hover/visual:opacity-100 group-hover/visual:grayscale-0 motion-reduce:opacity-100 motion-reduce:grayscale-0',
            )}
            aria-hidden
          />
          <span className="truncate text-[11px] font-medium text-foreground">{name}</span>
        </div>
        <Switch
          checked={enabled}
          disabled
          className="scale-90 data-[state=checked]:bg-foreground/80"
          aria-hidden
        />
      </div>
    </div>
  )
}

export function MockMemberRow({
  name,
  email,
  role,
  delayMs = 0,
}: {
  name: string
  email: string
  role: string
  delayMs?: number
}) {
  const roleVariant =
    role === 'Owner' ? 'info' : role === 'Developer' ? 'success' : 'inactive'

  return (
    <div
      className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2 opacity-90 transition-[opacity,transform] duration-500 group-hover/visual:translate-x-0 group-hover/visual:opacity-100 motion-reduce:translate-x-0 motion-reduce:opacity-100"
      style={{
        transitionDelay: `${delayMs}ms`,
        transform: 'translateX(4px)',
      }}
    >
      <div className="flex min-w-0 items-center gap-2.5">
        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground">
          {name.charAt(0)}
        </span>
        <div className="min-w-0">
          <p className="truncate text-[12px] font-medium text-foreground">{name}</p>
          <p className="truncate text-[11px] text-muted-foreground">{email}</p>
        </div>
      </div>
      <Badge variant={roleVariant} className="shrink-0 text-[10px]">
        {role}
      </Badge>
    </div>
  )
}

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

export function MockStatPill({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-border bg-background/80 px-3 py-2">
      <p className="text-[10px] uppercase tracking-wider text-muted-foreground">{label}</p>
      <p className="mt-0.5 text-[13px] font-semibold text-foreground">{value}</p>
    </div>
  )
}

export function MockPolicyCard({
  title,
  description,
  footer,
  className,
}: {
  title: string
  description: string
  footer: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
    >
      <div className="px-4 py-3">
        <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
        <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{description}</p>
      </div>
      <div className="border-t border-border bg-muted/15 px-4 py-3">{footer}</div>
    </div>
  )
}
