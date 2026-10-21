import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { DEDICATED_FEATURE_UNAVAILABLE } from '@/lib/databases/dedicated-engine'
import { useT } from '@/lib/i18n/translate'

type DatabaseTypeUnavailableProps = {
  projectId: string
}

export function DatabaseTypeUnavailable({
  projectId,
}: DatabaseTypeUnavailableProps) {
  const t = useT()

  return (
    <div className="flex h-full min-h-[400px] items-center justify-center px-4">
      <div className="max-w-md text-center">
        <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted ring-1 ring-border">
          <AlertCircle className="h-6 w-6 text-muted-foreground" />
        </div>
        <p className="text-[15px] font-medium text-foreground">
          {t(DEDICATED_FEATURE_UNAVAILABLE)}
        </p>
        <Button variant="link" className="mt-3" asChild>
          <Link
            to="/projects/$projectId/databases"
            params={{ projectId }}
          >
            {t('Back to databases')}
          </Link>
        </Button>
      </div>
    </div>
  )
}
