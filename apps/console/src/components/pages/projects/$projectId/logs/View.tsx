import { RuntimeLogsView } from '@/components/global/shared/RuntimeLogsView'
import { useT } from '@/lib/i18n/translate'
import { Route } from '@/routes/_public/projects.$projectId.logs'
import { ServiceHeader } from '../shared/ServiceHeader'

/** Every line the project's functions, sites and databases wrote, in one place. */
export function View() {
  const t = useT()
  const { projectId } = Route.useParams()

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="sticky top-0 z-20 shrink-0 bg-background">
        <ServiceHeader title={t('Logs')} />
      </div>
      <div className="flex min-h-0 flex-1 flex-col px-4 md:px-6">
        <RuntimeLogsView projectId={projectId} resourceType={null} />
      </div>
    </div>
  )
}
