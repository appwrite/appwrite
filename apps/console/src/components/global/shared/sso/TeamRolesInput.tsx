import { useRef } from 'react'
import { Plus, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useT } from '@/lib/i18n/translate'
import { teamRoleError } from '@/lib/sso-target'
import { SSO_ERROR_TEXT } from './SSOCardHelpers'

/**
 * Roles of a project's team that an identity provider gives, typed like the
 * roles of a membership (Auth, Teams, Memberships): a field that adds one on
 * Enter or with the button, and the added ones as chips. The typed role is
 * kept by the caller, so a save can take one that was not added yet.
 */
export function TeamRolesInput({
  id,
  value,
  onChange,
  draft,
  onDraftChange,
  disabled,
  describedBy,
}: {
  id: string
  value: string[]
  onChange: (roles: string[]) => void
  draft: string
  onDraftChange: (draft: string) => void
  disabled?: boolean
  /** Id of the hint under the field. */
  describedBy?: string
}) {
  const t = useT()
  const input = useRef<HTMLInputElement>(null)
  const role = draft.trim()
  const reason = role === '' ? null : teamRoleError(role)
  const error =
    reason === 'length'
      ? t('A role can be at most 36 characters.')
      : reason === 'format'
        ? t(
            'Use letters, numbers, periods, hyphens and underscores, starting with a letter or number.',
          )
        : null
  const errorId = `${id}-error`

  const add = () => {
    if (role === '' || reason !== null) return
    if (!value.includes(role)) onChange([...value, role])
    onDraftChange('')
  }

  const remove = (removed: string) => {
    onChange(value.filter((item) => item !== removed))
    // The chip and its button go away: keep focus in the field.
    input.current?.focus()
  }

  return (
    <div className="space-y-2">
      <div className="flex gap-2">
        <Input
          ref={input}
          id={id}
          dir="ltr"
          value={draft}
          onChange={(event) => onDraftChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              // Adds the role; the form is saved with its own button.
              event.preventDefault()
              add()
            }
          }}
          placeholder={t('Add roles')}
          autoComplete="off"
          aria-invalid={!!error}
          aria-describedby={
            [error ? errorId : null, describedBy].filter(Boolean).join(' ') ||
            undefined
          }
          disabled={disabled}
        />
        <Button
          type="button"
          variant="outline"
          onClick={add}
          disabled={disabled || role === '' || reason !== null}
          aria-label={t('Add role')}
        >
          <Plus className="h-4 w-4" />
        </Button>
      </div>
      {error ? (
        <p id={errorId} className={`text-[12px] ${SSO_ERROR_TEXT}`}>
          {error}
        </p>
      ) : null}
      {value.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {value.map((item) => (
            <Badge
              key={item}
              variant="info"
              className="text-[10px] shrink-0 pe-1"
            >
              <span dir="ltr">{item}</span>
              <button
                type="button"
                onClick={() => remove(item)}
                className="ms-0.5 rounded p-0.5 text-muted-foreground hover:text-foreground"
                disabled={disabled}
                aria-label={`${t('Remove')} ${item}`}
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      ) : null}
    </div>
  )
}
