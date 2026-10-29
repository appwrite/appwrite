import { AlertTriangle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

export function PartnerOrganizationUnavailable() {
  const t = useT()

  return (
    <div className="flex min-h-svh w-full flex-col items-center justify-center bg-background px-4 py-8">
      <div className="flex w-full max-w-md flex-col items-center gap-8">
        <div className="rounded-full bg-destructive/10 p-3">
          <AlertTriangle className="h-8 w-8 text-destructive" aria-hidden />
        </div>
        <div className="space-y-3 text-center">
          <h1 className="text-2xl font-semibold">
            {t('This organization is not available here')}
          </h1>
          <p className="text-sm leading-relaxed text-muted-foreground">
            {t(
              'Partner organizations cannot be opened in the console. Use partner login to access them.',
            )}
          </p>
        </div>
        <div className="flex w-full flex-col gap-3 sm:flex-row">
          <Button asChild className="w-full sm:flex-1">
            <Link to="/">{t('Go to console')}</Link>
          </Button>
          <Button asChild variant="outline" className="w-full sm:flex-1">
            <Link to="/sign-in" search={{ audience: 'partner' }}>
              {t('Partner login')}
            </Link>
          </Button>
        </div>
      </div>
    </div>
  )
}
