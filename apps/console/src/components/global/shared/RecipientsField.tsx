import { useState } from 'react'
import { Check, Plus, Route, Users, X } from 'lucide-react'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useEscalationMembers, useEscalations } from '@/lib/react-query/hooks'
import { useModalAwarePopover } from '@/lib/layout/modal-portal-host'
import { roleLabel } from '@/components/pages/projects/$projectId/monitors/RuleFields'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type RecipientsValue = {
  roles: string[]
  userIds: string[]
  escalationId: string
}

/**
 * Who hears about an alert, as one list: roles, members and, where offered,
 * an escalation. An escalation notifies on its own, so picking one replaces
 * the rest, and picking anything else drops it.
 */
export function RecipientsField({
  organizationId,
  roles,
  value,
  onChange,
  members = true,
  escalations = false,
  disabled,
  label,
}: {
  organizationId: string | undefined
  /** The roles that can be added. */
  roles: string[]
  value: RecipientsValue
  onChange: (next: RecipientsValue) => void
  /** Offers the organization's members. */
  members?: boolean
  /** Offers the organization's escalations. */
  escalations?: boolean
  disabled?: boolean
  label: string
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { rootRef, portalContainer, modal, handleOpenChange } =
    useModalAwarePopover()
  const people = useEscalationMembers(members ? organizationId : null)
  const chains = useEscalations(escalations ? organizationId : null)
  const escalation = chains.escalations.find(
    (entry) => entry.$id === value.escalationId,
  )

  const toggle = (key: 'roles' | 'userIds', id: string) => {
    const list = value[key]
    onChange({
      ...value,
      escalationId: '',
      [key]: list.includes(id)
        ? list.filter((entry) => entry !== id)
        : [...list, id],
    })
  }
  const pickEscalation = (escalationId: string) => {
    onChange(
      value.escalationId === escalationId
        ? { ...value, escalationId: '' }
        : { roles: [], userIds: [], escalationId },
    )
    setOpen(false)
  }

  const empty =
    value.roles.length === 0 &&
    value.userIds.length === 0 &&
    !value.escalationId

  return (
    <div
      ref={rootRef}
      className={cn(
        'flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-border bg-background px-1.5 py-1',
        disabled && 'opacity-60',
      )}
    >
      {value.escalationId && (
        <Chip
          icon={<Route aria-hidden className="h-3.5 w-3.5" />}
          label={escalation?.name ?? t('Escalation')}
          remove={t('Remove {name}').replace(
            '{name}',
            escalation?.name ?? t('Escalation'),
          )}
          onRemove={() => onChange({ ...value, escalationId: '' })}
          disabled={disabled}
        />
      )}
      {value.roles.map((role) => (
        <Chip
          key={role}
          icon={<Users aria-hidden className="h-3.5 w-3.5" />}
          label={t(roleLabel(role))}
          remove={t('Remove {name}').replace('{name}', t(roleLabel(role)))}
          onRemove={() => toggle('roles', role)}
          disabled={disabled}
        />
      ))}
      {value.userIds.map((userId) => {
        const name = people.memberName(userId)
        return (
          <Chip
            key={userId}
            icon={<Initial name={name} />}
            label={name}
            remove={t('Remove {name}').replace('{name}', name)}
            onRemove={() => toggle('userIds', userId)}
            disabled={disabled}
          />
        )
      })}
      <Popover
        open={open}
        modal={modal}
        onOpenChange={(next) => {
          handleOpenChange(next)
          setOpen(next)
        }}
      >
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label={label}
            disabled={disabled}
            className="inline-flex h-6 items-center gap-1 rounded-md px-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed"
          >
            <Plus aria-hidden className="h-3.5 w-3.5" />
            {empty ? t('Add who gets notified') : t('Add')}
          </button>
        </PopoverTrigger>
        <PopoverContent
          container={portalContainer}
          className="max-h-[min(360px,var(--radix-popover-content-available-height))] w-72 overflow-hidden p-0"
          align="start"
          onWheelCapture={(event) => event.stopPropagation()}
          onCloseAutoFocus={(event) => {
            if (portalContainer) event.preventDefault()
          }}
        >
          <Command>
            <CommandInput
              placeholder={
                members ? t('Search roles and members...') : t('Search...')
              }
              className="h-9 text-[13px]"
              onKeyDown={(event) => event.stopPropagation()}
            />
            <CommandList className="max-h-[280px] overflow-y-auto overscroll-contain p-1">
              <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                {people.isLoading || chains.isLoading
                  ? t('Loading...')
                  : t('No matches')}
              </CommandEmpty>
              <CommandGroup heading={t('Roles')}>
                {roles.map((role) => (
                  <Option
                    key={role}
                    value={`role ${t(roleLabel(role))} ${role}`}
                    selected={value.roles.includes(role)}
                    icon={<Users aria-hidden className="h-3.5 w-3.5" />}
                    label={t(roleLabel(role))}
                    hint={t('Every member with this role')}
                    onSelect={() => toggle('roles', role)}
                  />
                ))}
              </CommandGroup>
              {members && people.members.length > 0 && (
                <CommandGroup heading={t('Members')}>
                  {people.members.map((member) => (
                    <Option
                      key={member.userId}
                      value={`member ${member.name} ${member.email} ${member.userId}`}
                      selected={value.userIds.includes(member.userId)}
                      icon={<Initial name={member.name} />}
                      label={member.name}
                      hint={
                        member.email !== member.name ? member.email : undefined
                      }
                      onSelect={() => toggle('userIds', member.userId)}
                    />
                  ))}
                </CommandGroup>
              )}
              {escalations && chains.escalations.length > 0 && (
                <CommandGroup heading={t('Escalations')}>
                  {chains.escalations.map((entry) => (
                    <Option
                      key={entry.$id}
                      value={`escalation ${entry.name} ${entry.$id}`}
                      selected={value.escalationId === entry.$id}
                      icon={<Route aria-hidden className="h-3.5 w-3.5" />}
                      label={entry.name}
                      hint={(entry.steps.length === 1
                        ? t('{count} step')
                        : t('{count} steps')
                      ).replace('{count}', String(entry.steps.length))}
                      onSelect={() => pickEscalation(entry.$id)}
                    />
                  ))}
                </CommandGroup>
              )}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

function Chip({
  icon,
  label,
  remove,
  onRemove,
  disabled,
}: {
  icon: React.ReactNode
  label: string
  remove: string
  onRemove: () => void
  disabled?: boolean
}) {
  return (
    <span className="inline-flex h-6 max-w-full items-center gap-1.5 rounded-md bg-muted ps-2 pe-0.5 text-[12px] text-foreground">
      <span className="shrink-0 text-muted-foreground">{icon}</span>
      <span className="truncate">{label}</span>
      <button
        type="button"
        aria-label={remove}
        disabled={disabled}
        onClick={onRemove}
        className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-not-allowed"
      >
        <X aria-hidden className="h-3 w-3" />
      </button>
    </span>
  )
}

function Option({
  value,
  selected,
  icon,
  label,
  hint,
  onSelect,
}: {
  value: string
  selected: boolean
  icon: React.ReactNode
  label: string
  hint?: string
  onSelect: () => void
}) {
  return (
    <CommandItem
      value={value}
      onSelect={onSelect}
      className="gap-2 rounded-md px-2 py-1.5 aria-selected:bg-accent"
    >
      <span className="flex h-5 w-5 shrink-0 items-center justify-center text-muted-foreground">
        {icon}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] text-foreground">
          {label}
        </span>
        {hint && (
          <span className="block truncate text-[11px] text-muted-foreground">
            {hint}
          </span>
        )}
      </span>
      <Check
        aria-hidden
        className={cn(
          'h-4 w-4 shrink-0',
          selected ? 'opacity-100' : 'opacity-0',
        )}
      />
    </CommandItem>
  )
}

/** A member's first letter, standing in for an avatar. */
function Initial({ name }: { name: string }) {
  return (
    <span
      aria-hidden
      className="flex h-4 w-4 items-center justify-center rounded-full bg-foreground/10 text-[9px] font-semibold uppercase text-foreground"
    >
      {name.trim().charAt(0) || '?'}
    </span>
  )
}
