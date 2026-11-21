import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { Loader2, Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { roleLabel } from '@/components/pages/projects/$projectId/monitors/RuleFields'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { policySummary } from '@/lib/alerts/escalation'
import type { EscalationPolicy } from '@/lib/appwrite/monitors'
import { canWriteMonitors } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  useDeleteEscalationPolicy,
  useEscalationMembers,
  useEscalationPolicies,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { EscalationPolicyDialog } from './_components/EscalationPolicyDialog'

/** The organization's escalation policies, which monitors notify step by step. */
export function EscalationPolicies() {
  const t = useT()
  const params = useParams({ strict: false })
  const orgId = params.orgId as string | undefined
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(orgId)
  const canWrite = canWriteMonitors(access, features)
  const { policies, isLoading, error, refetch } = useEscalationPolicies(orgId)
  const { memberName } = useEscalationMembers(orgId)
  const deleteMutation = useDeleteEscalationPolicy(orgId)
  const [editing, setEditing] = useState<EscalationPolicy | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [toDelete, setToDelete] = useState<EscalationPolicy | null>(null)

  if (!orgId) return null

  const openDialog = (policy: EscalationPolicy | null) =>
    openDialogAfterOverlayCloses(() => {
      setEditing(policy)
      setDialogOpen(true)
    })

  const handleDelete = () => {
    if (!toDelete) return
    deleteMutation.mutate(toDelete.$id, {
      onSuccess: () => {
        closeDialogBeforeOverlayUnmount(() => setToDelete(null))
        toast.success(t('Escalation policy has been deleted'))
      },
      onError: (err: Error) =>
        toast.error(
          err instanceof AppwriteException &&
            err.type === 'escalation_policy_in_use'
            ? t(
                'Monitors use this policy. Move them to another policy or to roles first.',
              )
            : getErrorMessage(err, t('Failed to delete escalation policy')),
        ),
    })
  }

  const createButton = (
    <Button
      type="button"
      size="sm"
      className="h-8"
      onClick={() => openDialog(null)}
      disabled={!canWrite}
    >
      <Plus className="me-1.5 h-3.5 w-3.5" />
      {t('Create policy')}
    </Button>
  )

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Escalation policies')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'Notify your team step by step until someone acknowledges the alert. Pick a policy on any monitor.',
            )}
          </p>
        </div>
        {canWrite ? (
          createButton
        ) : (
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex">{createButton}</span>
            </TooltipTrigger>
            <TooltipContent>
              {t("You don't have permission to manage monitors.")}
            </TooltipContent>
          </Tooltip>
        )}
      </div>
      <div className="border-t border-border">
        {isLoading ? (
          <div className="flex justify-center px-6 py-8">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        ) : error ? (
          <div className="flex items-center gap-2 px-6 py-4 text-[13px] text-destructive">
            <span>{t('Could not load escalation policies.')}</span>
            <button
              type="button"
              className="underline underline-offset-2"
              onClick={() => void refetch()}
            >
              {t('Retry')}
            </button>
          </div>
        ) : policies.length === 0 ? (
          <p className="px-6 py-6 text-[13px] text-muted-foreground">
            {t('No escalation policies yet.')}
          </p>
        ) : (
          <ul className="divide-y divide-border">
            {policies.map((policy) => (
              <li
                key={policy.$id}
                className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-6 py-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-[13px] font-medium text-foreground">
                    {policy.name}
                  </p>
                  <p className="truncate text-[12px] text-muted-foreground">
                    {policySummary(
                      policy.steps,
                      memberName,
                      (role) => t(roleLabel(role)),
                      t,
                    )}
                    {policy.repeat > 0 &&
                      ` · ${t('repeats {count}×').replace('{count}', String(policy.repeat))}`}
                  </p>
                </div>
                {canWrite && (
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <RowActionsMenuTrigger
                        aria-label={`${t('Actions for')} ${policy.name}`}
                      />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onClick={() => openDialog(policy)}>
                        <MenuItemContent icon={Pencil}>
                          {t('Edit')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onClick={() =>
                          openDialogAfterOverlayCloses(() =>
                            setToDelete(policy),
                          )
                        }
                      >
                        <MenuItemContent icon={Trash2}>
                          {t('Delete')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <EscalationPolicyDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        organizationId={orgId}
        policy={editing}
      />

      <Dialog
        open={!!toDelete}
        onOpenChange={(open) => {
          if (!open && !deleteMutation.isPending) setToDelete(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete escalation policy')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{toDelete?.name}</strong>?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setToDelete(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
