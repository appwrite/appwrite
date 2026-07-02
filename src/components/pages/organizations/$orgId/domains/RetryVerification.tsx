import { useState } from 'react'
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
import { Copy, Check, ExternalLink } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

interface RetryVerificationProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domain: Models.Domain
  onRetry: (domainId: string) => void
  isLoading?: boolean
}

const NAMESERVERS = ['ns1.appwrite.io', 'ns2.appwrite.io']

export function RetryVerification({
  open,
  onOpenChange,
  domain,
  onRetry,
  isLoading = false,
}: RetryVerificationProps) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const handleRetry = () => {
    onRetry(domain.$id)
  }

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopiedField(null), 2000)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Retry verification')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Update the nameservers of your domain')}{' '}
            <strong>{domain.domain}</strong>{' '}
            {t(
              'to point to Appwrite. It may take up to 48 hours for DNS changes to propagate.', // pragma: allowlist secret
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Nameserver')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
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
              <DocsRouteLink className="inline-flex items-center gap-1 link-neutral" href="/docs/domains">
                {t('Learn more about DNS settings')}
                <ExternalLink className="h-3 w-3" />
              </DocsRouteLink>
            </p>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleRetry} disabled={isLoading}>
            {t('Retry')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
