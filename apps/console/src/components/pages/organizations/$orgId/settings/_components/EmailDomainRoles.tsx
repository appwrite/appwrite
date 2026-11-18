import { Plus, X } from 'lucide-react'
import { ROLE_OPTIONS } from '@/components/pages/organizations/$orgId/overview/InviteMembersDialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useT } from '@/lib/i18n/translate'

/**
 * Roles people get when they join through an email domain. Owner is left out:
 * a domain must never hand full control of the organization to everyone with a
 * company email.
 */
export const EMAIL_DOMAIN_ROLE_OPTIONS = ROLE_OPTIONS.filter(
  (role) => role.value !== 'owner',
)

export function emailDomainRoleLabel(role: string): string {
  return ROLE_OPTIONS.find((option) => option.value === role)?.label ?? role
}

/** Role badges, as the user labels input shows them, plus a picker that adds one. */
export function EmailDomainRoles({
  value,
  onChange,
  disabled,
}: {
  value: string[]
  onChange: (roles: string[]) => void
  disabled?: boolean
}) {
  const t = useT()
  const available = EMAIL_DOMAIN_ROLE_OPTIONS.filter(
    (role) => !value.includes(role.value),
  )

  return (
    <div className="flex min-h-[36px] flex-wrap items-center gap-1.5 rounded-md border bg-transparent px-3 py-1.5">
      {value.map((role) => {
        const option = ROLE_OPTIONS.find((item) => item.value === role)
        const Icon = option?.icon
        return (
          <Badge
            key={role}
            variant="info"
            className="h-6 shrink-0 gap-1 pe-1 text-[11px]"
          >
            {Icon ? <Icon className="h-3 w-3" /> : null}
            {t(emailDomainRoleLabel(role))}
            <button
              type="button"
              onClick={() => onChange(value.filter((item) => item !== role))}
              className="ms-0.5 rounded-full p-0.5 text-muted-foreground hover:bg-muted/80 hover:text-foreground"
              disabled={disabled}
              aria-label={`${t('Remove')} ${t(emailDomainRoleLabel(role))}`}
            >
              <X className="h-3 w-3" />
            </button>
          </Badge>
        )
      })}
      {available.length > 0 ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-6 gap-1 px-2 text-[12px] text-muted-foreground"
              disabled={disabled}
            >
              <Plus className="h-3 w-3" />
              {t('Add role')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="min-w-[240px]">
            {available.map((role) => {
              const Icon = role.icon
              return (
                <DropdownMenuItem
                  key={role.value}
                  onClick={() => onChange([...value, role.value])}
                >
                  <div className="flex w-full items-start gap-2">
                    <Icon className="mt-0.5 h-4 w-4 shrink-0" />
                    <div className="flex min-w-0 flex-1 flex-col">
                      <span className="text-[13px] font-medium">
                        {t(role.label)}
                      </span>
                      <span className="mt-0.5 text-[11px] leading-tight text-muted-foreground">
                        {t(role.description)}
                      </span>
                    </div>
                  </div>
                </DropdownMenuItem>
              )
            })}
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}
    </div>
  )
}
