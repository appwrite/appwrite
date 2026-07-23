import { useMemo, useState } from 'react'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  NEW_ORG_CHOICE,
  resolveOrgToDelete,
  type OrgSummary,
} from '@/lib/billing/free-plan-conflict'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface FreePlanConflictResolutionProps {
  otherFreeOrg: OrgSummary
  currentOrg?: OrgSummary | null
  pendingOrgName?: string
  showCurrentOrgOption?: boolean
  keepChoiceId?: string
  onKeepChoiceChange?: (keepChoiceId: string) => void
}

export function FreePlanConflictResolution({
  otherFreeOrg,
  currentOrg,
  pendingOrgName,
  showCurrentOrgOption = false,
  keepChoiceId: keepChoiceIdProp,
  onKeepChoiceChange,
}: FreePlanConflictResolutionProps) {
  const t = useT()
  const [keepChoiceIdState, setKeepChoiceIdState] = useState(() =>
    showCurrentOrgOption && currentOrg ? currentOrg.$id : NEW_ORG_CHOICE,
  )
  const keepChoiceId = keepChoiceIdProp ?? keepChoiceIdState

  const setKeepChoiceId = (value: string) => {
    onKeepChoiceChange?.(value)
    if (keepChoiceIdProp === undefined) {
      setKeepChoiceIdState(value)
    }
  }
  const keepChoices = useMemo(() => {
    if (showCurrentOrgOption && currentOrg) {
      return [
        { id: currentOrg.$id, name: currentOrg.name },
        { id: otherFreeOrg.$id, name: otherFreeOrg.name },
      ]
    }

    return [
      {
        id: NEW_ORG_CHOICE,
        name: pendingOrgName?.trim() || t('New organization'),
      },
      { id: otherFreeOrg.$id, name: otherFreeOrg.name },
    ]
  }, [currentOrg, otherFreeOrg, pendingOrgName, showCurrentOrgOption, t])

  const orgToDelete = resolveOrgToDelete(
    keepChoiceId,
    otherFreeOrg,
    currentOrg,
    showCurrentOrgOption,
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Choose which organization to keep')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Only one free organization is allowed per account.')}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        <RadioGroup
          value={keepChoiceId}
          onValueChange={setKeepChoiceId}
          className="space-y-2"
        >
          {keepChoices.map(({ id, name }) => (
            <div
              key={id}
              className={cn(
                'flex items-center gap-3 rounded-lg border px-4 py-3',
                keepChoiceId === id
                  ? 'border-primary bg-card'
                  : 'border-border bg-background/60',
              )}
              {...analyticsAttrs('upgrade-free-conflict-choice')}
            >
              <RadioGroupItem value={id} id={`keep-org-${id}`} />
              <Label
                htmlFor={`keep-org-${id}`}
                className="cursor-pointer text-[13px] font-medium text-foreground"
              >
                {t('Keep')} {name}
              </Label>
            </div>
          ))}
        </RadioGroup>

        {orgToDelete ? (
          <p className="text-[13px] text-red-600 dark:text-red-400">
            {orgToDelete.name} {t('and all its resources will be deleted.')}
          </p>
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {t('Choose a paid plan for the new organization instead.')}
          </p>
        )}
      </div>
    </div>
  )
}
