import { useMemo, useState } from 'react'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  resolveOrgToDelete,
  type OrgSummary,
} from '@/lib/billing/free-plan-conflict'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface FreePlanConflictResolutionProps {
  otherFreeOrg: OrgSummary
  currentOrg?: OrgSummary | null
  deleteChoiceId?: string
  onDeleteChoiceChange?: (deleteChoiceId: string) => void
}

export function FreePlanConflictResolution({
  otherFreeOrg,
  currentOrg,
  deleteChoiceId: deleteChoiceIdProp,
  onDeleteChoiceChange,
}: FreePlanConflictResolutionProps) {
  const t = useT()
  const [deleteChoiceIdState, setDeleteChoiceIdState] = useState(
    () => otherFreeOrg.$id,
  )
  const deleteChoiceId = deleteChoiceIdProp ?? deleteChoiceIdState

  const setDeleteChoiceId = (value: string) => {
    onDeleteChoiceChange?.(value)
    if (deleteChoiceIdProp === undefined) {
      setDeleteChoiceIdState(value)
    }
  }

  // The organization being downgraded comes second: deleting it is the
  // drastic choice, and keeping it is what the default preserves.
  const deleteChoices = useMemo(() => {
    const choices = [{ id: otherFreeOrg.$id, name: otherFreeOrg.name }]
    if (currentOrg) {
      choices.push({ id: currentOrg.$id, name: currentOrg.name })
    }
    return choices
  }, [currentOrg, otherFreeOrg])

  const orgToDelete = resolveOrgToDelete(
    deleteChoiceId,
    otherFreeOrg,
    currentOrg,
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Choose which organization to delete')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Only one free organization is allowed per account.')}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        <RadioGroup
          value={deleteChoiceId}
          onValueChange={setDeleteChoiceId}
          className="space-y-2"
        >
          {deleteChoices.map(({ id, name }) => (
            <div
              key={id}
              className={cn(
                'flex items-center gap-3 rounded-lg border px-4 py-3',
                deleteChoiceId === id
                  ? 'border-primary bg-card'
                  : 'border-border bg-background/60',
              )}
              {...analyticsAttrs('upgrade-free-conflict-choice')}
            >
              <RadioGroupItem value={id} id={`delete-org-${id}`} />
              <Label
                htmlFor={`delete-org-${id}`}
                className="cursor-pointer text-[13px] font-medium text-foreground"
              >
                {t('Delete')} {name}
              </Label>
            </div>
          ))}
        </RadioGroup>

        {orgToDelete ? (
          <p className="text-[13px] text-red-600 dark:text-red-400">
            {orgToDelete.name} {t('and all its resources will be deleted.')}
          </p>
        ) : null}
      </div>
    </div>
  )
}
