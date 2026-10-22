import { useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  resolveOrgToDelete,
  type OrgSummary,
} from '@/lib/billing/free-plan-conflict'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { ConfirmDowngradeDeletes } from './ConfirmDowngradeDeletes'
import { DowngradeConfirmedSelection } from './DowngradeConfirmedSelection'

interface FreePlanConflictResolutionProps {
  otherFreeOrg: OrgSummary
  currentOrg?: OrgSummary | null
  deleteChoiceId?: string
  onDeleteChoiceChange?: (deleteChoiceId: string) => void
  confirmed?: boolean
  onConfirmedChange?: (confirmed: boolean) => void
}

export function FreePlanConflictResolution({
  otherFreeOrg,
  currentOrg,
  deleteChoiceId: deleteChoiceIdProp,
  onDeleteChoiceChange,
  confirmed = false,
  onConfirmedChange,
}: FreePlanConflictResolutionProps) {
  const t = useT()
  const [deleteChoiceIdState, setDeleteChoiceIdState] = useState(
    () => otherFreeOrg.$id,
  )
  const [confirmOpen, setConfirmOpen] = useState(false)
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
        {confirmed && orgToDelete ? (
          <DowngradeConfirmedSelection
            title={t('1 organization marked for deletion')}
            labels={[orgToDelete.name]}
            onEditSelection={() => onConfirmedChange?.(false)}
          />
        ) : (
          <>
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

            <div className="flex justify-end border-t border-border pt-4">
              <Button
                type="button"
                size="sm"
                className="h-8 text-[13px]"
                disabled={!orgToDelete}
                onClick={() => setConfirmOpen(true)}
              >
                {t('Confirm selection')}
              </Button>
            </div>
          </>
        )}
      </div>

      <ConfirmDowngradeDeletes
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('Delete organization')}
        items={
          orgToDelete ? [{ id: orgToDelete.$id, label: orgToDelete.name }] : []
        }
        confirming={false}
        onConfirm={() => {
          onConfirmedChange?.(true)
          setConfirmOpen(false)
        }}
      />
    </div>
  )
}
