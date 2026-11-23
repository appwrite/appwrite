/**
 * The connect card of a project environment on the Get started page: an
 * environment is reached with the project's ID and its own, so this is what
 * an app needs to point at it and nothing else. The three values copy on their
 * own, and the client snippet below shows where each one goes. Shown in place
 * of the project's connect checklist, whose platforms, keys and CLI are the
 * project's and set up from production.
 */

import { Layers } from 'lucide-react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useProject } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const CARD_SHELL = 'rounded-xl border border-border bg-card/50 overflow-hidden'

/** A Web SDK client pointed at the environment, in the shape every connect snippet uses. */
export function environmentClientSnippet(
  endpoint: string,
  projectId: string,
  environmentId: string,
): string {
  return [
    "import { Client } from 'appwrite';",
    '',
    'const client = new Client()',
    `    .setEndpoint('${endpoint}')`,
    `    .setProject('${projectId}')`,
    `    .setEnvironment('${environmentId}');`,
  ].join('\n')
}

function ValueRow({
  label,
  value,
  copyToastLabel,
}: {
  label: string
  value: string
  copyToastLabel: string
}) {
  return (
    <div className="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
      <span className="text-[13px] text-muted-foreground">{label}</span>
      <CopyableId
        id={value}
        size="md"
        maxWidth={360}
        constrainToContainer
        copyToastLabel={copyToastLabel}
        className="font-mono"
      />
    </div>
  )
}

export function EnvironmentConnect({
  projectId,
  environmentId,
}: {
  projectId: string
  environmentId: string
}) {
  const t = useT()
  const { project } = useProject(projectId)
  const endpoint = getApiEndpoint(project?.region)

  return (
    <section className={CARD_SHELL} aria-label={t('Connect to this environment')}>
      <div className="flex items-start gap-3 border-b border-border bg-muted/10 px-4 py-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
          <Layers className="h-4 w-4" />
        </div>
        <div className="min-w-0">
          <h2 className="text-[13px] font-semibold text-foreground">
            {t('Connect to this environment')}
          </h2>
          <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
            {t(
              'Same endpoint and project ID as production. The environment ID is what tells them apart.',
            )}
          </p>
        </div>
      </div>

      <div className="space-y-3 px-4 py-4">
        <ValueRow
          label={t('Endpoint')}
          value={endpoint}
          copyToastLabel={t('Endpoint')}
        />
        <ValueRow
          label={t('Project ID')}
          value={projectId}
          copyToastLabel={t('Project ID')}
        />
        <ValueRow
          label={t('Environment ID')}
          value={environmentId}
          copyToastLabel={t('Environment ID')}
        />
      </div>

      <div className="border-t border-border px-4 py-4">
        <CodeBlock
          code={environmentClientSnippet(endpoint, projectId, environmentId)}
          language="javascript"
          label={t('Client')}
          showCopy
          surface="muted"
        />
      </div>
    </section>
  )
}
