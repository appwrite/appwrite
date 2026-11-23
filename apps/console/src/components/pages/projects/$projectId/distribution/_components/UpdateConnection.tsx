import { useEffect, useState } from 'react'
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
import type { DistributionConnection } from '@/lib/appwrite/distribution'
import {
  useUpdateDistributionAppleConnection,
  useUpdateDistributionGoogleConnection,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CredentialField } from './CredentialField'
import { CredentialFile } from './CredentialFile'
import type { CredentialFileValue } from './CredentialFile'
import {
  appleIssuerIdError,
  appleKeyIdError,
  applePrivateKeyError,
  parseServiceAccount,
} from '@/lib/distribution/credentials'

interface UpdateConnectionProps {
  projectId: string
  connection: DistributionConnection | null
  onOpenChange: (open: boolean) => void
}

export function UpdateConnection({
  projectId,
  connection,
  onOpenChange,
}: UpdateConnectionProps) {
  const t = useT()
  const updateApple = useUpdateDistributionAppleConnection(projectId)
  const updateGoogle = useUpdateDistributionGoogleConnection(projectId)
  const [name, setName] = useState('')
  const [issuerId, setIssuerId] = useState('')
  const [keyId, setKeyId] = useState('')
  const [key, setKey] = useState<CredentialFileValue[]>([])
  const [submitted, setSubmitted] = useState(false)

  const apple = connection?.provider === 'apple'
  const update = apple ? updateApple : updateGoogle
  const { reset: resetApple } = updateApple
  const { reset: resetGoogle } = updateGoogle

  useEffect(() => {
    setName(connection?.name ?? '')
    setIssuerId('')
    setKeyId('')
    setKey([])
    setSubmitted(false)
    resetApple()
    resetGoogle()
  }, [connection, resetApple, resetGoogle])

  const keyText = key[0]?.content ?? ''
  const translated = (error: string | null) => (error ? t(error) : null)
  const replacing = apple
    ? Boolean(issuerId.trim() || keyId.trim() || keyText.trim())
    : Boolean(keyText.trim())
  const parsed = parseServiceAccount(keyText)
  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    issuerId:
      apple && replacing ? translated(appleIssuerIdError(issuerId)) : null,
    keyId: apple && replacing ? translated(appleKeyIdError(keyId)) : null,
    key: !replacing
      ? null
      : apple
        ? translated(applePrivateKeyError(keyText))
        : translated(parsed.error),
  }
  const valid = Object.values(errors).every((error) => error === null)
  const shown = (error: string | null) => (submitted ? error : null)

  const handleOpenChange = (next: boolean) => {
    if (update.isPending) return
    onOpenChange(next)
  }

  const handleUpdate = () => {
    if (!connection) return
    setSubmitted(true)
    if (!valid) return
    const renamed = name.trim() !== connection.name
    if (!renamed && !replacing) {
      onOpenChange(false)
      return
    }
    const onSuccess = () => {
      toast.success(t('Connection updated'))
      onOpenChange(false)
    }
    const changes = {
      connectionId: connection.$id,
      ...(renamed ? { name: name.trim() } : {}),
    }
    if (apple) {
      updateApple.mutate(
        replacing
          ? {
              ...changes,
              issuerId: issuerId.trim(),
              keyId: keyId.trim(),
              privateKey: keyText.trim(),
            }
          : changes,
        { onSuccess },
      )
      return
    }
    updateGoogle.mutate(
      replacing && parsed.serviceAccount
        ? { ...changes, serviceAccount: parsed.serviceAccount }
        : changes,
      { onSuccess },
    )
  }

  return (
    <Dialog open={connection !== null} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Update connection')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {apple
              ? t(
                  'Rename the connection or replace its key. Appwrite checks a new key before saving it.',
                )
              : t(
                  'Rename the connection or replace its service account key. Appwrite checks a new key before saving it.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {connection?.status === 'invalid' && connection.error ? (
            <p className="rounded-lg border border-border bg-muted/30 px-3 py-2 text-[12px] text-muted-foreground">
              <span className="font-medium text-foreground">
                {t('Last check failed:')}
              </span>{' '}
              {connection.error}
            </p>
          ) : null}
          <CredentialField
            id="update-connection-name"
            label={t('Name')}
            error={shown(errors.name)}
          >
            <Input
              id="update-connection-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              aria-invalid={shown(errors.name) ? true : undefined}
              autoComplete="off"
            />
          </CredentialField>
          {apple ? (
            <>
              <CredentialField
                id="update-connection-issuer"
                label={t('Issuer ID')}
                error={shown(errors.issuerId)}
              >
                <Input
                  id="update-connection-issuer"
                  dir="ltr"
                  value={issuerId}
                  onChange={(event) => setIssuerId(event.target.value)}
                  placeholder={connection?.account.issuerId}
                  className="font-mono text-[13px]"
                  aria-invalid={shown(errors.issuerId) ? true : undefined}
                  autoComplete="off"
                  spellCheck={false}
                />
              </CredentialField>
              <CredentialField
                id="update-connection-key"
                label={t('Key ID')}
                error={shown(errors.keyId)}
              >
                <Input
                  id="update-connection-key"
                  dir="ltr"
                  value={keyId}
                  onChange={(event) => setKeyId(event.target.value)}
                  placeholder={connection?.account.keyId}
                  className="font-mono text-[13px]"
                  aria-invalid={shown(errors.keyId) ? true : undefined}
                  autoComplete="off"
                  spellCheck={false}
                />
              </CredentialField>
            </>
          ) : null}
          <CredentialFile
            id="update-connection-credentials"
            label={
              apple
                ? t('New private key (.p8)')
                : t('New service account key (JSON)')
            }
            extensions={apple ? ['.p8'] : ['.json']}
            encoding="text"
            files={key}
            onChange={setKey}
            pastePlaceholder={apple ? '-----BEGIN PRIVATE KEY-----' : '{ }'}
            error={shown(errors.key) ?? undefined}
            hint={
              apple
                ? t('Leave the key fields empty to keep the current key.')
                : t('Leave this empty to keep the current key.')
            }
          />
          {update.error ? (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
            >
              {getErrorMessage(
                update.error,
                t('The connection was not updated.'),
              )}
            </p>
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={update.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleUpdate} disabled={update.isPending}>
            {t('Update')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
