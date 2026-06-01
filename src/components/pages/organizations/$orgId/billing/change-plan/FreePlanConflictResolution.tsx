import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { deleteOrganization } from '@/lib/react-query/hooks/organizations'
import {
  NEW_ORG_CHOICE,
  resolveOrgToDelete,
  type OrgSummary,
} from '@/lib/billing/free-plan-conflict'
import { cn } from '@/lib/utils'

interface FreePlanConflictResolutionProps {
  otherFreeOrg: OrgSummary
  currentOrg?: OrgSummary | null
  pendingOrgName?: string
  showCurrentOrgOption?: boolean
  keepChoiceId?: string
  onKeepChoiceChange?: (keepChoiceId: string) => void
  onCurrentOrgDeleted?: () => void
}

export function FreePlanConflictResolution({
  otherFreeOrg,
  currentOrg,
  pendingOrgName,
  showCurrentOrgOption = false,
  keepChoiceId: keepChoiceIdProp,
  onKeepChoiceChange,
  onCurrentOrgDeleted,
}: FreePlanConflictResolutionProps) {
  const queryClient = useQueryClient()
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
  const [confirmDialogOpen, setConfirmDialogOpen] = useState(false)
  const [confirmation, setConfirmation] = useState('')

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
        name: pendingOrgName?.trim() || 'New organization',
      },
      { id: otherFreeOrg.$id, name: otherFreeOrg.name },
    ]
  }, [currentOrg, otherFreeOrg, pendingOrgName, showCurrentOrgOption])

  const orgToDelete = resolveOrgToDelete(
    keepChoiceId,
    otherFreeOrg,
    currentOrg,
    showCurrentOrgOption,
  )

  const deleteOrgMutation = useMutation({
    mutationFn: deleteOrganization,
    onSuccess: async (_, deletedOrgId) => {
      await queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
      toast.success('Organization deleted successfully')
      setConfirmDialogOpen(false)
      setConfirmation('')
      if (deletedOrgId === currentOrg?.$id) {
        onCurrentOrgDeleted?.()
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete organization')
    },
  })

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Choose which organization to keep
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Only one free organization is allowed per account.
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
              >
                <RadioGroupItem value={id} id={`keep-org-${id}`} />
                <Label
                  htmlFor={`keep-org-${id}`}
                  className="cursor-pointer text-[13px] font-medium text-foreground"
                >
                  Keep {name}
                </Label>
              </div>
            ))}
          </RadioGroup>

          {orgToDelete ? (
            <p className="text-[13px] text-red-600 dark:text-red-400">
              {orgToDelete.name} and all its resources will be deleted.
            </p>
          ) : (
            <p className="text-[13px] text-muted-foreground">
              Choose a paid plan for the new organization instead.
            </p>
          )}
        </div>

        {orgToDelete ? (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4 bg-muted/30 flex justify-end">
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setConfirmDialogOpen(true)}
              >
                Continue
              </Button>
            </div>
          </>
        ) : null}
      </div>

      <Dialog
        open={confirmDialogOpen}
        onOpenChange={(open) => {
          if (!open) {
            setConfirmDialogOpen(false)
            setConfirmation('')
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete {orgToDelete?.name}?</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              This permanently deletes the organization and everything in it.
              This cannot be undone.
            </DialogDescription>
          </DialogHeader>

          <div className="border-t border-border" />

          <div className="px-6 py-4">
            <label className="text-[13px] text-muted-foreground">
              Type{' '}
              {orgToDelete ? (
                <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                  {orgToDelete.name}
                </span>
              ) : null}{' '}
              to confirm
            </label>
            <Input
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              placeholder="Enter organization name"
              className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
            />
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setConfirmDialogOpen(false)
                setConfirmation('')
              }}
              disabled={deleteOrgMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !orgToDelete ||
                confirmation !== orgToDelete.name ||
                deleteOrgMutation.isPending
              }
              onClick={() => {
                if (!orgToDelete || confirmation !== orgToDelete.name) return
                deleteOrgMutation.mutate(orgToDelete.$id)
              }}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
