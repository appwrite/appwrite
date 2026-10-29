import { useEffect, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Copy, Check, ExternalLink, AlertCircle, Info } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useRetryDomainVerification } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface RetryVerificationProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domain: Models.Domain
  orgId: string
  /** Called after nameservers verify successfully (dialog already closes). */
  onVerified?: (domain: Models.Domain) => void
}

const NAMESERVERS = ['ns1.appwrite.zone', 'ns2.appwrite.zone']

export function RetryVerification({
  open,
  onOpenChange,
  domain,
  orgId,
  onVerified,
}: RetryVerificationProps) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [verificationError, setVerificationError] = useState<string | null>(
    null,
  )
  const [isPendingPropagation, setIsPendingPropagation] = useState(false)

  const retryVerificationMutation = useRetryDomainVerification(orgId)

  useEffect(() => {
    if (open) {
      setVerificationError(null)
      setIsPendingPropagation(false)
      setCopiedField(null)
    }
  }, [open])

  const handleVerify = async () => {
    setVerificationError(null)
    setIsPendingPropagation(false)
    try {
      const updatedDomain = await retryVerificationMutation.mutateAsync(
        domain.$id,
      )
      const isVerified =
        updatedDomain.nameservers?.toLowerCase() === 'appwrite'
      if (isVerified) {
        toast.success(`${domain.domain} ${t('has been verified')}`)
        onOpenChange(false)
        onVerified?.(updatedDomain)
        return
      }
      setIsPendingPropagation(true)
      setVerificationError(
        t(
          'We could not confirm Appwrite nameservers for this domain yet. DNS changes can take up to 48 hours to propagate. Confirm the nameservers below at your registrar, wait a bit, then try again.',
        ),
      )
    } catch (error) {
      setIsPendingPropagation(false)
      setVerificationError(
        getErrorMessage(error) ||
          t(
            'Domain verification failed. Please check your domain settings or try again later.',
          ),
      )
    }
  }

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopiedField(null), 2000)
  }

  const isLoading = retryVerificationMutation.isPending

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Retry verification')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Update the nameservers of your domain')}{' '}
            <strong className="font-medium text-foreground">
              {domain.domain}
            </strong>{' '}
            {t(
              'to point to Appwrite. It may take up to 48 hours for DNS changes to propagate.', // pragma: allowlist secret
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 py-4 space-y-4">
          {verificationError && (
            <Alert
              variant="default"
              className={
                isPendingPropagation
                  ? 'border-amber-500/30 bg-amber-500/10 [&>svg]:text-amber-600 dark:[&>svg]:text-amber-400'
                  : 'border-red-500/30 bg-red-500/10 [&>svg]:text-red-600 dark:[&>svg]:text-red-400'
              }
            >
              {isPendingPropagation ? (
                <Info className="h-4 w-4" />
              ) : (
                <AlertCircle className="h-4 w-4" />
              )}
              <AlertTitle
                className={
                  isPendingPropagation
                    ? 'text-[13px] font-medium text-amber-700 dark:text-amber-400'
                    : 'text-[13px] font-medium text-red-600 dark:text-red-400'
                }
              >
                {isPendingPropagation
                  ? t('Domain not verified yet')
                  : t('Verification failed')}
              </AlertTitle>
              <AlertDescription
                className={
                  isPendingPropagation
                    ? 'text-[13px] text-amber-700/90 dark:text-amber-400/90'
                    : 'text-[13px] text-red-600 dark:text-red-400'
                }
              >
                {verificationError}
              </AlertDescription>
            </Alert>
          )}

          <div className="rounded-lg border border-border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Nameserver')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {NAMESERVERS.map((nameserver) => (
                  <TableRow key={nameserver}>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {nameserver}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleCopy(nameserver, nameserver)}
                      >
                        {copiedField === nameserver ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <p className="text-[13px] text-muted-foreground">
            <DocsRouteLink
              className="inline-flex items-center gap-1 link-neutral"
              href="/docs/products/domains/external"
            >
              {t('Learn more about DNS settings')}
              <ExternalLink className="h-3 w-3" />
            </DocsRouteLink>
          </p>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleVerify} disabled={isLoading}>
            {t('Verify')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
