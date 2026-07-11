import { Cloud, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useT } from '@/lib/i18n/translate'

const APPWRITE_CLOUD_URL = 'https://cloud.appwrite.io'

interface ExecutionLogsCloudNoticeProps {
  variant: 'function' | 'site'
}

/**
 * Shown in place of the executions/logs list on self-hosted instances,
 * where execution documents are no longer persisted (cloud-only feature).
 */
export function ExecutionLogsCloudNotice({
  variant,
}: ExecutionLogsCloudNoticeProps) {
  const t = useT()
  const title =
    variant === 'function'
      ? 'Execution logs are available on Appwrite Cloud'
      : 'Site logs are available on Appwrite Cloud'
  const description =
    variant === 'function'
      ? 'Self-hosted Appwrite no longer stores execution history. Your functions still run as usual, but their executions, logs, and errors can only be viewed on Appwrite Cloud.'
      : 'Self-hosted Appwrite no longer stores site request logs. Your site still serves traffic as usual, but its logs and errors can only be viewed on Appwrite Cloud.'

  return (
    <div className="flex flex-1 flex-col items-center justify-center py-16">
      <EmptyState
        icon={Cloud}
        title={title}
        description={description}
        variant="centered"
        iconSize="md"
        className="max-w-md"
        action={
          <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
            <a
              href={APPWRITE_CLOUD_URL}
              target="_blank"
              rel="noopener noreferrer"
            >
              <ExternalLink className="me-1.5 h-4 w-4" />
              {t('Explore Appwrite Cloud')}
            </a>
          </Button>
        }
      />
    </div>
  )
}
