import { useState } from 'react'
import { Download, FileKey, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import type {
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSigningIdentityFile,
  DistributionSigningIdentityPasswords,
} from '@/lib/appwrite/distribution'
import { fetchDistributionSigningIdentityDownload } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ConsoleLink } from './ConsoleLink'
import { PLAY_CONSOLE_URL } from '@/lib/distribution/credentials'

const UPLOAD_CERTIFICATE = 'upload-certificate.pem'

const OBJECT_URL_LIFETIME = 1000

const PASSWORD_LABELS: Record<
  keyof DistributionSigningIdentityPasswords,
  string
> = {
  certificate: 'Certificate password',
  storePassword: 'Keystore password',
  keyPassword: 'Key password',
}

const PASSWORD_KEYS = Object.keys(
  PASSWORD_LABELS,
) as (keyof DistributionSigningIdentityPasswords)[]

function decode(content: string): Uint8Array<ArrayBuffer> {
  const binary = atob(content)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) {
    bytes[index] = binary.charCodeAt(index)
  }
  return bytes
}

function saveFile(file: DistributionSigningIdentityFile) {
  const url = URL.createObjectURL(
    new Blob([decode(file.content)], { type: 'application/octet-stream' }),
  )
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = file.name
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  setTimeout(() => URL.revokeObjectURL(url), OBJECT_URL_LIFETIME)
}

function formatSize(content: string): string {
  const bytes = Math.floor((content.length * 3) / 4)
  return bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(1)} KB`
}

interface DownloadSigningIdentityProps {
  projectId: string
  signingIdentity: DistributionSigningIdentity | null
  onOpenChange: (open: boolean) => void
}

export function DownloadSigningIdentity({
  projectId,
  signingIdentity,
  onOpenChange,
}: DownloadSigningIdentityProps) {
  const t = useT()
  return (
    <Dialog open={signingIdentity !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Download signing files')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Anyone with these files and passwords can sign apps as you. Store them somewhere safe, such as a password manager.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        {signingIdentity ? (
          <Files
            key={signingIdentity.$id}
            projectId={projectId}
            signingIdentity={signingIdentity}
            onClose={() => onOpenChange(false)}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function Files({
  projectId,
  signingIdentity,
  onClose,
}: {
  projectId: string
  signingIdentity: DistributionSigningIdentity
  onClose: () => void
}) {
  const t = useT()
  const [download, setDownload] =
    useState<DistributionSigningIdentityDownload | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [isLoading, setIsLoading] = useState(false)

  const reveal = async () => {
    setIsLoading(true)
    setError(null)
    try {
      setDownload(
        await fetchDistributionSigningIdentityDownload(
          projectId,
          signingIdentity.$id,
        ),
      )
    } catch (caught) {
      setError(caught)
    } finally {
      setIsLoading(false)
    }
  }

  const close = () => {
    setDownload(null)
    onClose()
  }

  const save = (file: DistributionSigningIdentityFile) => {
    try {
      saveFile(file)
    } catch {
      toast.error(t('The file could not be saved.'))
    }
  }

  return (
    <>
      <div className="px-6 py-4 space-y-5">
        {error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
          >
            {getErrorMessage(
              error,
              t('The signing files could not be loaded.'),
            )}
          </p>
        ) : null}
        {download ? (
          <Contents download={download} onSave={save} />
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {t('Every download is recorded in the audit log.')}
          </p>
        )}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="outline" onClick={close}>
          {t('Close')}
        </Button>
        {download ? null : (
          <Button onClick={() => void reveal()} disabled={isLoading}>
            {isLoading ? (
              <Loader2 className="h-4 w-4 me-1.5 animate-spin" />
            ) : null}
            {t('Show signing files')}
          </Button>
        )}
      </div>
    </>
  )
}

function Contents({
  download,
  onSave,
}: {
  download: DistributionSigningIdentityDownload
  onSave: (file: DistributionSigningIdentityFile) => void
}) {
  const t = useT()
  const passwords = PASSWORD_KEYS.flatMap((key) => {
    const value = download.passwords[key]
    return value ? [{ key, label: t(PASSWORD_LABELS[key]), value }] : []
  })
  const uploadCertificate = download.files.some(
    (file) => file.name === UPLOAD_CERTIFICATE,
  )

  return (
    <>
      <section className="space-y-2">
        <h3 className="text-[12px] font-medium text-foreground">
          {t('Files')}
        </h3>
        <ul className="space-y-2">
          {download.files.map((file) => (
            <li
              key={file.name}
              className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5"
            >
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background">
                <FileKey className="h-4 w-4 text-muted-foreground" />
              </div>
              <div className="min-w-0 flex-1">
                <p
                  className="truncate font-mono text-[13px] text-foreground rtl:text-right"
                  dir="ltr"
                >
                  {file.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {formatSize(file.content)}
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-[13px]"
                onClick={() => onSave(file)}
                aria-label={`${t('Save')} ${file.name}`}
              >
                <Download className="h-4 w-4 me-1.5" />
                {t('Save')}
              </Button>
            </li>
          ))}
        </ul>
      </section>

      {download.alias ? (
        <dl>
          <Field label={t('Key alias')} value={download.alias} />
        </dl>
      ) : null}

      {passwords.length > 0 ? (
        <section className="space-y-2">
          <h3 className="text-[12px] font-medium text-foreground">
            {t('Passwords')}
          </h3>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Passwords are shown only in this dialog. Copy them before you close it.',
            )}
          </p>
          <dl className="space-y-2">
            {passwords.map(({ key, label, value }) => (
              <Field key={key} label={label} value={value} />
            ))}
          </dl>
        </section>
      ) : null}

      {uploadCertificate ? (
        <section className="space-y-2 rounded-lg border border-border bg-muted/30 px-3 py-3">
          <h3 className="text-[12px] font-medium text-foreground">
            {t('Resetting the upload key of an existing app?')}
          </h3>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'In Play Console, open Protected with Play → Manage Play app signing → Request upload key reset, and upload upload-certificate.pem. Google holds the app signing key, so users keep receiving updates.',
            )}
          </p>
          <ConsoleLink href={PLAY_CONSOLE_URL} label={t('Open Play Console')} />
        </section>
      ) : null}
    </>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
      <dt className="text-[13px] text-muted-foreground">{label}</dt>
      <dd className="min-w-0">
        <CopyableId
          id={value}
          size="md"
          maxWidth={240}
          copyToastLabel={label}
        />
      </dd>
    </div>
  )
}
