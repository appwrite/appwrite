import { useState } from 'react'
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
import { useCreateDistributionAndroidSigningIdentity } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CredentialField } from './CredentialField'
import { CredentialFile } from './CredentialFile'
import type { CredentialFileValue } from './CredentialFile'

interface ImportAndroidSigningIdentityProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function ImportAndroidSigningIdentity({
  projectId,
  open,
  onOpenChange,
}: ImportAndroidSigningIdentityProps) {
  const t = useT()
  const create = useCreateDistributionAndroidSigningIdentity(projectId)

  const handleOpenChange = (next: boolean) => {
    if (create.isPending) return
    if (!next) create.reset()
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Import Android keystore')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Upload the keystore that holds your upload key, so Appwrite signs bundles the way Google Play expects. Files and passwords are encrypted before they are stored.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <Form
          create={create}
          onCancel={() => handleOpenChange(false)}
          onDone={() => {
            create.reset()
            onOpenChange(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function Form({
  create,
  onCancel,
  onDone,
}: {
  create: ReturnType<typeof useCreateDistributionAndroidSigningIdentity>
  onCancel: () => void
  onDone: () => void
}) {
  const t = useT()
  const [name, setName] = useState('')
  const [keystore, setKeystore] = useState<CredentialFileValue[]>([])
  const [storePassword, setStorePassword] = useState('')
  const [keyAlias, setKeyAlias] = useState('')
  const [keyPassword, setKeyPassword] = useState('')
  const [submitted, setSubmitted] = useState(false)

  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    keystore: keystore.length > 0 ? null : t('Add the keystore file.'),
    storePassword: storePassword ? null : t('Enter the keystore password.'),
    keyAlias: keyAlias.trim() ? null : t('Enter the key alias.'),
  }
  const valid = Object.values(errors).every((error) => error === null)
  const shown = (error: string | null) => (submitted ? error : null)

  const handleImport = () => {
    setSubmitted(true)
    if (!valid) return
    create.mutate(
      {
        name: name.trim(),
        keystore: keystore[0].content,
        storePassword,
        keyAlias: keyAlias.trim(),
        keyPassword: keyPassword || storePassword,
      },
      {
        onSuccess: () => {
          toast.success(t('Signing identity imported'))
          onDone()
        },
      },
    )
  }

  return (
    <>
      <div className="px-6 py-4 space-y-4">
        <CredentialField
          id="android-signing-name"
          label={t('Name')}
          error={shown(errors.name)}
        >
          <Input
            id="android-signing-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder={t('Upload key')}
            aria-invalid={shown(errors.name) ? true : undefined}
            autoComplete="off"
          />
        </CredentialField>
        <CredentialFile
          id="android-signing-keystore"
          label={t('Keystore')}
          extensions={['.jks', '.keystore', '.p12', '.pfx']}
          encoding="base64"
          files={keystore}
          onChange={setKeystore}
          error={shown(errors.keystore) ?? undefined}
          hint={t(
            'A PKCS#12 keystore is checked right away. A JKS keystore is checked by its first signing job.',
          )}
        />
        <CredentialField
          id="android-signing-store-password"
          label={t('Keystore password')}
          error={shown(errors.storePassword)}
        >
          <Input
            id="android-signing-store-password"
            type="password"
            value={storePassword}
            onChange={(event) => setStorePassword(event.target.value)}
            aria-invalid={shown(errors.storePassword) ? true : undefined}
            autoComplete="new-password"
          />
        </CredentialField>
        <CredentialField
          id="android-signing-alias"
          label={t('Key alias')}
          error={shown(errors.keyAlias)}
        >
          <Input
            id="android-signing-alias"
            dir="ltr"
            value={keyAlias}
            onChange={(event) => setKeyAlias(event.target.value)}
            placeholder="upload"
            className="font-mono text-[13px]"
            aria-invalid={shown(errors.keyAlias) ? true : undefined}
            autoComplete="off"
            spellCheck={false}
          />
        </CredentialField>
        <CredentialField
          id="android-signing-key-password"
          label={t('Key password')}
          hint={t(
            'Leave empty to use the keystore password. A PKCS#12 keystore always uses the keystore password.',
          )}
        >
          <Input
            id="android-signing-key-password"
            type="password"
            value={keyPassword}
            onChange={(event) => setKeyPassword(event.target.value)}
            autoComplete="new-password"
          />
        </CredentialField>
        {create.error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
          >
            {getErrorMessage(
              create.error,
              t('The signing identity was not imported.'),
            )}
          </p>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button
          variant="outline"
          onClick={onCancel}
          disabled={create.isPending}
        >
          {t('Cancel')}
        </Button>
        <Button onClick={handleImport} disabled={create.isPending}>
          {t('Import')}
        </Button>
      </div>
    </>
  )
}
