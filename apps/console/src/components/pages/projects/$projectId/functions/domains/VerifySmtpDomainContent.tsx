import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Copy, Check, Loader2, Info, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import {
  dnsPendingVerificationError,
  type DomainVerificationError,
} from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  getSmtpDnsRecords,
  type SmtpProxyRule,
} from '@/lib/function-smtp-domain'

interface VerifySmtpDomainContentProps {
  rule: SmtpProxyRule
  region?: string
  verificationError?: DomainVerificationError | string | null
}

/**
 * MX + ownership TXT instructions for custom inbound email domains.
 * Distinct from HTTP VerifyDomainContent (CNAME / nameservers / TLS).
 */
export function VerifySmtpDomainContent({
  rule,
  region,
  verificationError,
}: VerifySmtpDomainContentProps) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const { smtpTarget, isLoading } = useConsoleVariables(region)
  const { projectId } = useParams({ strict: false })
  const mxTarget = typeof smtpTarget === 'string' ? smtpTarget.trim() : ''

  const records = projectId
    ? getSmtpDnsRecords({ domain: rule.domain, projectId, mxTarget })
    : []

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success(t('Copied'))
    setTimeout(() => setCopiedField(null), 2000)
  }

  const structuredError =
    verificationError && typeof verificationError !== 'string'
      ? verificationError
      : null
  const stringError =
    typeof verificationError === 'string' ? verificationError : null

  if (isLoading) {
    return (
      <div className="flex min-h-[180px] items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('DNS records')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Point MX at Appwrite SMTP and prove ownership with a TXT record. No TLS certificate is issued for email domains.',
            )}
          </p>
          <p className="text-[13px] text-muted-foreground mt-1 font-mono">
            {rule.domain}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {!mxTarget ? (
            <Alert>
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t('MX target unavailable')}</AlertTitle>
              <AlertDescription>
                {t(
                  'This region does not report its mail server target yet, so the MX record cannot be shown here.',
                )}
              </AlertDescription>
            </Alert>
          ) : null}
          {records.length > 0 ? (
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Type')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Name')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Value')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[72px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {records.map((record) => {
                  const fieldKey = `${record.type}-${record.name}`
                  return (
                    <TableRow key={fieldKey}>
                      <TableCell className="px-4 py-3">
                        <Badge variant="secondary" className="text-[10px]">
                          {record.type}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3 font-mono text-[13px]">
                        {record.name}
                      </TableCell>
                      <TableCell className="px-4 py-3 font-mono text-[13px] break-all">
                        {record.value}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0"
                          onClick={() => handleCopy(record.value, fieldKey)}
                          aria-label={t('Copy')}
                        >
                          {copiedField === fieldKey ? (
                            <Check className="h-3.5 w-3.5" />
                          ) : (
                            <Copy className="h-3.5 w-3.5" />
                          )}
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          ) : null}

          <Alert>
            <Info className="h-4 w-4" />
            <AlertTitle>{t('Propagation')}</AlertTitle>
            <AlertDescription>
              {t(
                'DNS changes can take up to 48 hours. The MX record must point straight at Appwrite on TCP port 25, not through a proxy.',
              )}
            </AlertDescription>
          </Alert>
        </div>
      </div>

      {structuredError ? (
        <Alert variant={structuredError.pending ? 'default' : 'destructive'}>
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>{structuredError.title}</AlertTitle>
          <AlertDescription>{structuredError.message}</AlertDescription>
        </Alert>
      ) : null}
      {stringError ? (
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>{t('Verification failed')}</AlertTitle>
          <AlertDescription>{stringError}</AlertDescription>
        </Alert>
      ) : null}
    </div>
  )
}

export { dnsPendingVerificationError }
