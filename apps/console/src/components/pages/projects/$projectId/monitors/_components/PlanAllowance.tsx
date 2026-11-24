import { Link } from '@tanstack/react-router'
import { Info } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

/** What the plan includes of an alerting resource, and an upgrade link once the cap is reached. */
export function PlanAllowance({
  message,
  organizationId,
  upgrade = false,
}: {
  message: string
  organizationId: string
  upgrade?: boolean
}) {
  const t = useT()
  return (
    <Alert>
      <Info className="h-4 w-4" />
      <AlertDescription className="flex flex-wrap items-center justify-between gap-2 text-[12px]">
        <span>{message}</span>
        {upgrade && (
          <Button
            asChild
            size="sm"
            variant="outline"
            className="h-7 text-[12px]"
          >
            <Link to="/upgrade" search={{ orgId: organizationId }}>
              {t('Upgrade')}
            </Link>
          </Button>
        )}
      </AlertDescription>
    </Alert>
  )
}
