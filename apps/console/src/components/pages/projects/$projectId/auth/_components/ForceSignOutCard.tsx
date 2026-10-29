import { useState } from 'react'
import { toast } from 'sonner'
import { LogOut } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { useDeleteProjectSessions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

/**
 * Signs out every user in the project by invalidating all their sessions.
 *
 * Intended to host further project-wide security actions, such as a future
 * "Force password reset all users".
 */
export function ForceSignOutCard({ projectId }: { projectId: string }) {
  const t = useT()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const mutation = useDeleteProjectSessions(projectId)

  const handleConfirm = () => {
    mutation.mutate(undefined, {
      onSuccess: () => {
        setConfirmOpen(false)
        toast.success(t('All users have been signed out.'))
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to sign out users'))
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Force sign out')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t(
            'Immediately invalidate every active session in the project. Users are signed out of all their clients and can sign in again afterwards. API keys are not affected.',
          )}
        </p>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={mutation.isPending}
          onClick={() => setConfirmOpen(true)}
        >
          <LogOut className="h-4 w-4 shrink-0 me-1.5" />
          {t('Sign out all users')}
        </Button>
      </div>
      <ConfirmActionDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('Sign out all users')}
        description={t(
          'This will immediately sign out every user in the project by invalidating all their active sessions. Users can sign in again afterwards, and API keys are not affected.',
        )}
        confirmLabel={t('Sign out all users')}
        confirmVariant="destructive"
        onConfirm={handleConfirm}
        isConfirming={mutation.isPending}
      />
    </div>
  )
}
