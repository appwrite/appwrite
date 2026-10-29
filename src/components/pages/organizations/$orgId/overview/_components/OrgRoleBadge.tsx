import { Code, CreditCard, Edit, Eye, Shield, Users } from '@/lib/icons'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ROLE_DISPLAY = [
  { value: 'owner', label: 'Owner', icon: Shield },
  { value: 'developer', label: 'Developer', icon: Code },
  { value: 'editor', label: 'Editor', icon: Edit },
  { value: 'analyst', label: 'Analyst', icon: Eye },
  { value: 'billing', label: 'Billing', icon: CreditCard },
] as const

export function orgMembershipRoleDisplay(role: string): {
  Icon: (typeof ROLE_DISPLAY)[number]['icon'] | typeof Users
  label: string
} {
  const opt = ROLE_DISPLAY.find((r) => r.value === role)
  if (opt) return { Icon: opt.icon, label: opt.label }
  return {
    Icon: Users,
    label: role.charAt(0).toUpperCase() + role.slice(1),
  }
}

/** Single source for role badges, so the table and its tooltips cannot drift. */
export function OrgRoleBadge({ role }: { role: string }) {
  const t = useT()
  const { Icon, label } = orgMembershipRoleDisplay(role)
  return (
    <Badge
      variant="secondary"
      className={cn(
        'inline-flex items-center gap-1 border px-2 py-0.5 text-[11px] font-medium',
      )}
    >
      <Icon className="h-3 w-3 shrink-0" aria-hidden />
      {t(label)}
    </Badge>
  )
}
