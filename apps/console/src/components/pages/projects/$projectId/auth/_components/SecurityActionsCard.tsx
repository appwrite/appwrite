import { useState } from 'react'
import { toast } from 'sonner'
import { KeyRound, LogOut, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import {
  useDeleteProjectPasswords,
  useDeleteProjectSessions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

/**
 * Project-wide actions that apply to every user at once, such as signing
 * everyone out or forcing a password reset. Each action is a row; add new
 * ones as further rows.
 */
export function SecurityActionsCard({ projectId }: { projectId: string }) {
  const t = useT()
  const signOut = useDeleteProjectSessions(projectId)
  const resetPasswords = useDeleteProjectPasswords(projectId)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Security actions')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t(
            'Apply an action to every user in the project at once, for example when responding to a security incident.',
          )}
        </p>
      </div>
      <div className="divide-y divide-border border-t border-border">
        <SecurityActionRow
          title={t('Force sign out')}
          description={t(
            'Immediately invalidate every active session in the project. Users are signed out of all their clients and can sign in again afterwards. API keys are not affected.',
          )}
          label={t('Sign out all users')}
          icon={LogOut}
          confirmDescription={t(
            'This will immediately sign out every user in the project by invalidating all their active sessions. Users can sign in again afterwards, and API keys are not affected.',
          )}
          isPending={signOut.isPending}
          onConfirm={(close) =>
            signOut.mutate(undefined, {
              onSuccess: () => {
                close()
                toast.success(t('All users have been signed out.'))
              },
              onError: (error: Error) => {
                toast.error(error.message || t('Failed to sign out users'))
              },
            })
          }
        />
        <SecurityActionRow
          title={t('Force password reset')}
          description={t(
            'Require every user to set a new password before they can sign in with email and password again. Existing sessions stay active, and users without a password are not affected.',
          )}
          label={t('Reset all passwords')}
          icon={KeyRound}
          confirmDescription={t(
            'Every user will have to set a new password through password recovery before they can sign in with email and password again. Existing sessions stay active, so sign out all users as well if sessions may be compromised. This action cannot be undone.',
          )}
          isPending={resetPasswords.isPending}
          onConfirm={(close) =>
            resetPasswords.mutate(undefined, {
              onSuccess: () => {
                close()
                toast.success(t('All users must now reset their password.'))
              },
              onError: (error: Error) => {
                toast.error(error.message || t('Failed to reset passwords'))
              },
            })
          }
        />
      </div>
    </div>
  )
}

function SecurityActionRow({
  title,
  description,
  label,
  icon: Icon,
  confirmDescription,
  isPending,
  onConfirm,
}: {
  title: string
  description: string
  label: string
  icon: LucideIcon
  confirmDescription: string
  isPending: boolean
  onConfirm: (close: () => void) => void
}) {
  const [confirmOpen, setConfirmOpen] = useState(false)

  return (
    <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
      <div className="min-w-0">
        <h4 className="text-[13px] font-medium text-foreground">{title}</h4>
        <p className="text-[13px] text-muted-foreground mt-1">{description}</p>
      </div>
      <Button
        variant="outline"
        size="sm"
        className="h-9 shrink-0 self-start text-[13px] sm:self-auto"
        disabled={isPending}
        onClick={() => setConfirmOpen(true)}
      >
        <Icon className="h-4 w-4 shrink-0 me-1.5" />
        {label}
      </Button>
      <ConfirmActionDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={label}
        description={confirmDescription}
        confirmLabel={label}
        confirmVariant="destructive"
        onConfirm={() => onConfirm(() => setConfirmOpen(false))}
        isConfirming={isPending}
      />
    </div>
  )
}
