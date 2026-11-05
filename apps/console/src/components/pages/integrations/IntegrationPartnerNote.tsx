import { Link } from '@tanstack/react-router'
import { Handshake } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type IntegrationPartnerNoteProps = {
  className?: string
}

export function IntegrationPartnerNote({ className }: IntegrationPartnerNoteProps) {
  const t = useT()
  return (
    <section className={cn('border-b border-border py-10 sm:py-14', className)}>
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="border-b border-border bg-muted/20 px-6 py-4 sm:px-8">
            <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
              {t('Technology Partner Program')}
            </p>
          </div>
          <div className="flex flex-col gap-6 px-6 py-6 sm:flex-row sm:items-center sm:justify-between sm:gap-8 sm:px-8 sm:py-7">
            <div className="flex min-w-0 items-start gap-4">
              <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/40">
                <Handshake className="size-5 text-muted-foreground" aria-hidden />
              </div>
              <div className="min-w-0">
                <h2 className="font-aeonik-pro text-[20px] font-normal leading-tight text-foreground sm:text-[22px]">
                  {t('Building on Appwrite?')} {/* pragma: allowlist secret */}
                </h2>
                <p className="mt-2 max-w-2xl text-[13px] leading-6 text-muted-foreground sm:text-[14px] sm:leading-7">
                  {t(
                    'Teams and developers who ship integrations, tools, or services on Appwrite can join our partner program for verified catalog placement, co-marketing, training, and engineering support.', // pragma: allowlist secret
                  )}
                </p>
              </div>
            </div>
            <div className="shrink-0">
              <Button
                variant="brandCta"
                size="lg"
                className="h-10 w-full text-[14px] sm:w-auto"
                asChild
              >
                <Link to="/partners">{t('Explore the partner program')}</Link>
              </Button>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
