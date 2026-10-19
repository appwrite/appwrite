/**
 * Select Installation
 *
 * Opened by ConnectVcsLink. Lists the provider accounts the user already
 * connected in other projects, so a new project can reuse one instead of going
 * through the provider's installation settings again. Without any, it continues
 * straight to the provider.
 */

import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { reusableInstallationsQueryOptions } from '@/lib/react-query/hooks'
import {
  closeInstallationPicker,
  useInstallationPickerRequest,
} from '@/lib/vcs/installation-picker'
import {
  VcsIcon,
  vcsInlineProviderIconBoxClassName,
  vcsInlineProviderIconClassName,
} from '@/lib/vcs/providers'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const VISIBLE_PROJECT_NAMES = 3

export function SelectInstallation() {
  const t = useT()
  const request = useInstallationPickerRequest()
  const [selected, setSelected] = useState('')
  const { data: installations, isError } = useQuery({
    ...reusableInstallationsQueryOptions(request?.projectId, request?.provider),
    enabled: !!request,
  })

  useEffect(() => {
    setSelected('')
  }, [request])

  // Nothing to reuse (or no way to tell): behave exactly like the plain link.
  useEffect(() => {
    if (!request) return
    if (isError || installations?.length === 0) {
      window.location.assign(request.href)
    }
  }, [request, installations, isError])

  const handleUse = () => {
    closeInstallationPicker()
    toast.info(t('Using an existing installation is not available yet.'))
  }

  return (
    <Dialog
      open={!!request}
      onOpenChange={(open) => {
        if (!open) closeInstallationPicker()
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Choose an installation')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'These accounts are already connected to your other projects. Use one of them, or add a new installation.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {installations?.length ? (
            <RadioGroup
              value={selected}
              onValueChange={setSelected}
              className="max-h-72 overflow-y-auto gap-2"
            >
              {installations.map((installation) => {
                const id = installation.providerInstallationId
                const names = installation.projects.map((p) => p.name)
                const hidden = names.length - VISIBLE_PROJECT_NAMES
                return (
                  <Label
                    key={id}
                    htmlFor={`installation-${id}`}
                    className={cn(
                      'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-all',
                      selected === id
                        ? 'border-foreground bg-card/80'
                        : 'border-border bg-card/50 hover:border-border/80',
                    )}
                  >
                    <RadioGroupItem
                      value={id}
                      id={`installation-${id}`}
                      className="shrink-0"
                    />
                    <div className={vcsInlineProviderIconBoxClassName}>
                      <VcsIcon
                        type={installation.provider}
                        className={vcsInlineProviderIconClassName}
                      />
                    </div>
                    <div className="min-w-0">
                      <p className="text-[13px] font-medium text-foreground truncate">
                        {installation.organization}
                      </p>
                      <p className="text-[12px] font-normal text-muted-foreground truncate">
                        {names.slice(0, VISIBLE_PROJECT_NAMES).join(', ')}
                        {hidden > 0 ? ` +${hidden}` : ''}
                      </p>
                    </div>
                  </Label>
                )
              })}
            </RadioGroup>
          ) : (
            <div className="flex items-center gap-2 text-[13px] text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t('Checking your existing installations...')}
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" asChild>
            <a
              href={request?.href}
              target={request?.target}
              rel="noreferrer"
              onClick={closeInstallationPicker}
            >
              {t('Add new installation')}
            </a>
          </Button>
          <Button disabled={!selected} onClick={handleUse}>
            {t('Use installation')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
