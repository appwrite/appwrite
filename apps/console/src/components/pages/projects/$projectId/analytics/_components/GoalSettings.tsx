import { useState } from 'react'
import { Pencil, Plus, Target, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import {
  useAnalyticsGoals,
  useDeleteAnalyticsGoal,
  type AnalyticsGoal,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { SaveGoal } from './SaveGoal'

/** Goals card in property settings: list, add, edit and delete. */
export function GoalSettings({
  projectId,
  propertyId,
  canWrite,
}: {
  projectId: string
  propertyId: string
  canWrite: boolean
}) {
  const t = useT()
  const { goals, isLoading } = useAnalyticsGoals(projectId, propertyId)
  const deleteMutation = useDeleteAnalyticsGoal(projectId, propertyId)
  const [editing, setEditing] = useState<AnalyticsGoal | null>(null)
  const [adding, setAdding] = useState(false)
  const [deleting, setDeleting] = useState<AnalyticsGoal | null>(null)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="flex items-start justify-between gap-4 px-6 py-4">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Goals')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Events or page views that count as a conversion. Select one on the Analytics tab to see conversion rates.',
            )}
          </p>
        </div>
        {canWrite ? (
          <Button
            variant="outline"
            size="sm"
            className="h-8 shrink-0 text-[13px]"
            onClick={() => setAdding(true)}
          >
            <Plus className="mr-1.5 h-3.5 w-3.5" />
            {t('Add goal')}
          </Button>
        ) : null}
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {goals.length === 0 ? (
          <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
            <Target className="h-4 w-4" aria-hidden />
            {isLoading ? t('Loading goals…') : t('No goals yet')}
          </p>
        ) : (
          <ul className="divide-y divide-border">
            {goals.map((goal) => (
              <li key={goal.$id} className="flex items-center gap-3 py-2">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-foreground">
                    {goal.name}
                  </p>
                  <p className="truncate font-mono text-[12px] text-muted-foreground">
                    {goal.type === 'event' ? goal.eventName : goal.pagePath}
                    {goal.queries.length > 0
                      ? ` · ${t('{count} property filters').replace('{count}', String(goal.queries.length))}`
                      : ''}
                  </p>
                </div>
                {canWrite ? (
                  <>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      aria-label={t('Edit goal')}
                      onClick={() => setEditing(goal)}
                    >
                      <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      aria-label={t('Delete goal')}
                      onClick={() => setDeleting(goal)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>

      <SaveGoal
        open={adding || editing !== null}
        onOpenChange={(open) => {
          if (open) return
          setAdding(false)
          setEditing(null)
        }}
        projectId={projectId}
        propertyId={propertyId}
        goal={editing ?? undefined}
      />
      <ConfirmActionDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={t('Delete goal')}
        description={t(
          '{name} stops counting conversions. Your event data is not affected.',
        ).replace('{name}', deleting?.name ?? '')}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        isConfirming={deleteMutation.isPending}
        onConfirm={() => {
          if (!deleting) return
          deleteMutation.mutate(deleting.$id, {
            onSuccess: () => {
              toast.success(t('Goal deleted'))
              setDeleting(null)
            },
            onError: (error) => toast.error(getErrorMessage(error)),
          })
        }}
      />
    </div>
  )
}
