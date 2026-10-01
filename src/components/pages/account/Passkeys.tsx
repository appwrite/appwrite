import { useEffect, useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { Check, Fingerprint, Pencil, Plus, Trash2, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { PromptDialog } from '@/components/global/shared/PromptDialog'
import {
  useAccountPasskeys,
  useCreateAccountPasskey,
  useDeleteAccountPasskey,
  useUpdateAccountPasskey,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import {
  defaultPasskeyName,
  isPasskeyCancellation,
  isPasskeyReauthenticationError,
  isPasskeySupported,
  passkeyErrorMessage,
} from '@/lib/passkeys'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function PasskeysSection({
  initialData,
}: {
  initialData?: Models.PasskeyList
} = {}) {
  const t = useT()
  const { signOut } = useAuth()
  const { data: passkeysFromHook, isFetched } = useAccountPasskeys()
  const data = passkeysFromHook ?? initialData
  const createPasskey = useCreateAccountPasskey()
  const updatePasskey = useUpdateAccountPasskey()
  const deletePasskey = useDeleteAccountPasskey()
  const passkeys = data?.passkeys ?? []

  // Read after mount: the server render cannot know what the browser supports.
  const [supported, setSupported] = useState<boolean | null>(null)
  useEffect(() => {
    setSupported(isPasskeySupported())
  }, [])

  const [addDialogOpen, setAddDialogOpen] = useState(false)
  const [editing, setEditing] = useState<{ id: string; name: string } | null>(
    null,
  )
  // The passkey stays set while the dialog animates closed.
  const [passkeyToDelete, setPasskeyToDelete] = useState<Models.Passkey | null>(
    null,
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const showError = (error: unknown, fallback: string) => {
    if (isPasskeyCancellation(error)) return
    const message =
      passkeyErrorMessage(error, t) ?? getErrorMessage(error, fallback)
    if (isPasskeyReauthenticationError(error)) {
      toast.error(message, {
        action: { label: t('Sign in again'), onClick: () => void signOut() },
      })
      return
    }
    toast.error(message)
  }

  const handleAdd = (values: Record<string, string>) => {
    createPasskey.mutate((values.name ?? '').trim(), {
      onSuccess: () => {
        setAddDialogOpen(false)
        toast.success(t('Passkey added'))
      },
      onError: (error) => {
        if (!isPasskeyCancellation(error)) setAddDialogOpen(false)
        showError(error, t('Failed to add passkey'))
      },
    })
  }

  const handleRename = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!editing) return
    const name = editing.name.trim()
    const current = passkeys.find((passkey) => passkey.$id === editing.id)
    if (name === '' || name === current?.name) {
      setEditing(null)
      return
    }
    updatePasskey.mutate(
      { passkeyId: editing.id, name },
      {
        onSuccess: () => {
          setEditing(null)
          toast.success(t('Passkey renamed'))
        },
        onError: (error) => showError(error, t('Failed to rename passkey')),
      },
    )
  }

  const handleDelete = () => {
    if (!passkeyToDelete) return
    deletePasskey.mutate(passkeyToDelete.$id, {
      onSuccess: () => {
        setDeleteDialogOpen(false)
        toast.success(t('Passkey deleted'))
      },
      onError: (error) => {
        setDeleteDialogOpen(false)
        showError(error, t('Failed to delete passkey'))
      },
    })
  }

  return (
    <div
      data-card-id="passkeys"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Passkeys')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Sign in with your fingerprint, face or device PIN instead of a password.',
            )}
          </p>
        </div>
        {supported === null ? null : supported ? (
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-[13px]"
            onClick={() => setAddDialogOpen(true)}
            disabled={createPasskey.isPending}
          >
            <Plus className="h-4 w-4" />
            {t('Add passkey')}
          </Button>
        ) : (
          <p className="text-[12px] text-muted-foreground">
            {t('Passkeys are not supported in this browser.')}
          </p>
        )}
      </div>
      <div className="border-t border-border" />
      {!isFetched && !initialData ? null : passkeys.length === 0 ? (
        <div className="px-6 py-4">
          <div className="rounded-lg border border-border bg-muted/30 p-6 text-center">
            <p className="text-[14px] font-medium text-foreground mb-1">
              {t('No passkeys yet')}
            </p>
            <p className="text-[13px] text-muted-foreground">
              {t('Add a passkey to sign in without your password.')}
            </p>
          </div>
        </div>
      ) : (
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={`${HEAD_CLASS} ps-6`}>
                {t('Name')}
              </TableHead>
              <TableHead className={HEAD_CLASS}>{t('Type')}</TableHead>
              <TableHead className={HEAD_CLASS}>{t('Added')}</TableHead>
              <TableHead className={HEAD_CLASS}>{t('Last used')}</TableHead>
              <TableHead className={`${HEAD_CLASS} pe-6 w-[96px]`} />
            </TableRow>
          </TableHeader>
          <TableBody>
            {passkeys.map((passkey) => {
              const isEditing = editing?.id === passkey.$id
              return (
                <TableRow key={passkey.$id} data-passkey-id={passkey.$id}>
                  <TableCell className="ps-6 pe-4 py-3">
                    {isEditing ? (
                      <form
                        onSubmit={handleRename}
                        className="flex items-center gap-1"
                      >
                        <Input
                          autoFocus
                          value={editing.name}
                          maxLength={128}
                          aria-label={t('Passkey name')}
                          className="h-8 text-[13px]"
                          disabled={updatePasskey.isPending}
                          onChange={(event) =>
                            setEditing({
                              id: passkey.$id,
                              name: event.target.value,
                            })
                          }
                          onKeyDown={(event) => {
                            if (event.key === 'Escape') setEditing(null)
                          }}
                        />
                        <Button
                          type="submit"
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          disabled={updatePasskey.isPending}
                          aria-label={t('Save name')}
                        >
                          <Check className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          disabled={updatePasskey.isPending}
                          onClick={() => setEditing(null)}
                          aria-label={t('Cancel')}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </form>
                    ) : (
                      <span className="flex items-center gap-2 text-[13px] font-medium text-foreground">
                        <Fingerprint className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">
                          {passkey.name || t('Passkey')}
                        </span>
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge variant="info" className="text-[10px] font-medium">
                      {passkey.backedUp ? t('Synced') : t('This device')}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={new Date(passkey.$createdAt)}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {passkey.accessedAt ? (
                      <DateTooltip
                        date={new Date(passkey.accessedAt)}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        {t('Never')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="ps-4 pe-6 py-3">
                    <div className="flex justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() =>
                          setEditing({ id: passkey.$id, name: passkey.name })
                        }
                        disabled={isEditing}
                        aria-label={t('Rename passkey')}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => {
                          setPasskeyToDelete(passkey)
                          setDeleteDialogOpen(true)
                        }}
                        disabled={deletePasskey.isPending}
                        aria-label={t('Delete passkey')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
      <PromptDialog
        open={addDialogOpen}
        onOpenChange={setAddDialogOpen}
        title="Add passkey"
        description={t(
          'Your browser will ask you to create a passkey on this device or in your password manager.',
        )}
        fields={[
          {
            name: 'name',
            label: 'Name',
            defaultValue: defaultPasskeyName(),
            required: false,
            maxLength: 128,
          },
        ]}
        confirmLabel="Continue"
        onSubmit={handleAdd}
        isSubmitting={createPasskey.isPending}
      />
      <ConfirmActionDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete passkey"
        description={
          <>
            {t(
              'You will no longer be able to sign in with this passkey. This action cannot be undone.',
            )}
            {passkeyToDelete ? (
              <span className="mt-3 flex items-center gap-2 font-medium text-foreground">
                <Fingerprint className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="truncate">
                  {passkeyToDelete.name || t('Passkey')}
                </span>
              </span>
            ) : null}
          </>
        }
        confirmLabel="Delete"
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={deletePasskey.isPending}
      />
    </div>
  )
}
