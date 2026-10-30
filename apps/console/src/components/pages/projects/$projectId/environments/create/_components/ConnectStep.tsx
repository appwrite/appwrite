import { CheckCircle2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'

function Field({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
      <dt className="text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className="min-w-0 text-[13px] text-foreground">{children}</dd>
    </div>
  )
}

/** The new environment is a project of its own: the ID an app uses to talk to it. */
export function ConnectStep({ environment }: { environment: Models.Project }) {
  const t = useT()

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-border bg-card/50 p-5">
        <div className="flex items-center gap-3">
          <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
          <h2 className="text-[15px] font-semibold text-foreground">
            {t('Environment created')}
          </h2>
        </div>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'An environment is a project of its own. Point an app at it with this project ID; the API endpoint is the one your project already uses.',
          )}
        </p>
        <dl className="mt-5 space-y-3">
          <Field label={t('Name')}>{environment.name}</Field>
          <Field label={t('Environment ID')}>
            <CopyableId
              id={environment.$id}
              variant="inline"
              size="md"
              copyToastLabel="ID"
              maxWidth={320}
              showCopyOnHover={false}
            />
          </Field>
          <Field label={t('API endpoint')}>
            <CopyableId
              id={getApiEndpoint(environment.region)}
              variant="inline"
              size="md"
              copyToastLabel="Endpoint"
              maxWidth={320}
              showCopyOnHover={false}
            />
          </Field>
        </dl>
      </div>
    </div>
  )
}
