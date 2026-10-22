import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

/**
 * Enterprise upsell on function runtime specification settings (always-on + higher specs).
 */
export function FunctionRuntimeEnterpriseOfferingNote() {
  const t = useT()
  return (
    <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-3">
      <Alert variant="default" className="border-0 bg-transparent p-0 shadow-none">
        <AlertCircle className="h-4 w-4 text-amber-500" />
        <div className="col-start-2 flex flex-1 items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {t('Enterprise runtime options')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span className="inline">
                {t(
                  'The Enterprise plan includes always-on functions to minimize cold starts, as well as higher CPU and memory configurations for more demanding workloads.',
                )}{' '}
                <ContactSalesLink
                  className="font-medium underline hover:no-underline"
                  data-analytics-track="false"
                >
                  {t('Contact us to learn more.')}
                </ContactSalesLink>
              </span>
            </AlertDescription>
          </div>
          <Button
            asChild
            size="sm"
            className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
          >
            <ContactSalesLink
              {...analyticsAttrs('functions-always-on-contact-sales')}
            >
              {t('Contact us')}
            </ContactSalesLink>
          </Button>
        </div>
      </Alert>
    </div>
  )
}
