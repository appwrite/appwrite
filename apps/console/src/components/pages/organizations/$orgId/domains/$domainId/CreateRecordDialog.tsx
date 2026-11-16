import { useState, useEffect } from 'react'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { useT } from '@/lib/i18n/translate'
import {
  RECORD_TTL_MAX,
  parseRecordNumber,
  recordNumberFieldMessage,
} from '@/lib/domains/record-numeric-fields'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const DNS_RECORD_TYPES = [
  'A',
  'AAAA',
  'CNAME',
  'MX',
  'TXT',
  'NS',
  'SRV',
  'CAA',
  'HTTPS',
  'ALIAS',
] as const

const DNS_RECORD_DESCRIPTIONS: Record<string, string> = {
  A: 'A records map a domain to an IPv4 address, allowing browsers to find your website by translating the domain name to an IP address.',
  AAAA: 'AAAA records map a domain to an IPv6 address, providing the same function as A records but for IPv6-enabled devices.',
  CNAME:
    'CNAME records alias one domain name to another, allowing you to point subdomains or other domain names to an existing domain.',
  MX: 'MX records specify mail servers responsible for receiving emails for a domain, helping route email traffic to the correct mail server.',
  TXT: 'TXT records store arbitrary text data in DNS, commonly used for verification purposes, such as domain ownership or email security settings.',
  NS: "NS records define the authoritative DNS servers for a domain, directing queries to the servers that manage the domain's DNS settings.",
  SRV: 'SRV records specify the location (hostname and port number) of servers for specific services, directing traffic to particular servers based on service types.',
  CAA: 'CAA records define which certificate authorities can issue SSL certificates for your domain. To avoid setup issues, make sure certainly.com is authorized.',
  HTTPS:
    'HTTPS records define which service or endpoint handles secure HTTPS traffic for your domain, typically used in SSL/TLS configurations.',
  ALIAS:
    'ALIAS records are similar to CNAMEs but can be used for the root domain, allowing you to point your domain to another domain or server.',
}

const DEFAULT_TTL = 3600

interface CreateRecordDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: {
    type: string
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  }) => void | Promise<unknown>
  isLoading?: boolean
}

export function CreateRecordDialog({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateRecordDialogProps) {
  const t = useT()
  const [type, setType] = useState<string>('A')
  const [name, setName] = useState('')
  const [value, setValue] = useState('')
  const [ttl, setTtl] = useState(DEFAULT_TTL.toString())
  const [priority, setPriority] = useState('')
  const [weight, setWeight] = useState('')
  const [port, setPort] = useState('')
  const [comment, setComment] = useState('')
  const [fieldErrors, setFieldErrors] = useState<{
    priority?: string
    weight?: string
    port?: string
  }>({})
  const [error, setError] = useState<string | null>(null)

  // Reset form when dialog closes, and drop a previous rejection on reopen.
  useEffect(() => {
    setError(null)
    if (!open) {
      setType('A')
      setName('')
      setValue('')
      setTtl(DEFAULT_TTL.toString())
      setPriority('')
      setWeight('')
      setPort('')
      setComment('')
      setFieldErrors({})
    }
  }, [open])

  const change =
    (setter: (value: string) => void, field?: 'priority' | 'weight' | 'port') =>
    (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
      setter(event.target.value)
      setError(null)
      if (field) {
        setFieldErrors((prev) =>
          prev[field] ? { ...prev, [field]: undefined } : prev,
        )
      }
    }

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      if (!newOpen) setError(null)
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const priorityError =
      type === 'MX' || type === 'SRV'
        ? recordNumberFieldMessage(priority, 'priority')
        : null
    const weightError =
      type === 'SRV' ? recordNumberFieldMessage(weight, 'weight') : null
    const portError =
      type === 'SRV' ? recordNumberFieldMessage(port, 'port') : null

    setFieldErrors({
      priority: priorityError ?? undefined,
      weight: weightError ?? undefined,
      port: portError ?? undefined,
    })

    if (
      isLoading ||
      !name.trim() ||
      !value.trim() ||
      priorityError ||
      weightError ||
      portError
    ) {
      return
    }

    const parsedTtl = parseRecordNumber(ttl)
    const parsedPriority = parseRecordNumber(priority)
    const parsedWeight = parseRecordNumber(weight)
    const parsedPort = parseRecordNumber(port)

    setError(null)
    try {
      await onCreate({
        type,
        name: name.trim(),
        value: value.trim(),
        ttl: parsedTtl ?? DEFAULT_TTL,
        priority: type === 'MX' || type === 'SRV' ? parsedPriority : undefined,
        weight: type === 'SRV' ? parsedWeight : undefined,
        port: type === 'SRV' ? parsedPort : undefined,
        comment: comment.trim() || undefined,
      })
    } catch (submitError) {
      setError(getErrorMessage(submitError))
    }
  }

  const requiresPriority = type === 'MX' || type === 'SRV'
  const requiresSRVFields = type === 'SRV'

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={t('Create DNS Record')}
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              <div className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="type">
                    {t('Type')} <span className="text-destructive">*</span>
                  </Label>
                  <Select
                    value={type}
                    onValueChange={(next) => {
                      setType(next)
                      setFieldErrors({})
                      setError(null)
                    }}
                    disabled={isLoading}
                  >
                    <SelectTrigger id="type">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {DNS_RECORD_TYPES.map((recordType) => (
                        <SelectItem key={recordType} value={recordType}>
                          {recordType}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-[12px] text-muted-foreground">
                    {DNS_RECORD_DESCRIPTIONS[type]
                      ? t(DNS_RECORD_DESCRIPTIONS[type])
                      : ''}
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="name">
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="name"
                    value={name}
                    onChange={change(setName)}
                    placeholder={t('@ or subdomain')}
                    disabled={isLoading}
                    autoFocus
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t(
                      'Use @ for the root domain, or enter a subdomain (e.g., www, mail)',
                    )}
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="value">
                    {t('Value')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="value"
                    value={value}
                    onChange={change(setValue)}
                    placeholder={
                      type === 'A'
                        ? '192.0.2.1'
                        : type === 'AAAA'
                          ? '2001:db8::1'
                          : type === 'CNAME' || type === 'MX' || type === 'NS'
                            ? 'example.com'
                            : type === 'TXT'
                              ? 'v=spf1 include:_spf.example.com ~all'
                              : type === 'SRV'
                                ? 'example.com'
                                : type === 'CAA'
                                  ? '0 issue "letsencrypt.org"'
                                  : ''
                    }
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="ttl">
                    TTL <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="ttl"
                    type="number"
                    value={ttl}
                    onChange={change(setTtl)}
                    min="1"
                    max={RECORD_TTL_MAX}
                    disabled={isLoading}
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t('Time to live in seconds (default: 3600)')}
                  </p>
                </div>

                {requiresPriority && (
                  <div className="space-y-2">
                    <Label htmlFor="priority">
                      {t('Priority')}{' '}
                      <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="priority"
                      type="number"
                      value={priority}
                      onChange={change(setPriority, 'priority')}
                      placeholder={type === 'MX' ? '10' : '0'}
                      step="any"
                      disabled={isLoading}
                      aria-invalid={!!fieldErrors.priority}
                      className={
                        fieldErrors.priority ? 'border-destructive' : ''
                      }
                    />
                    {fieldErrors.priority && (
                      <p className="text-[12px] text-destructive">
                        {t(fieldErrors.priority)}
                      </p>
                    )}
                    <p className="text-[12px] text-muted-foreground">
                      {type === 'MX'
                        ? t('Lower numbers have higher priority')
                        : t('Priority for SRV record')}
                    </p>
                  </div>
                )}

                {requiresSRVFields && (
                  <>
                    <div className="space-y-2">
                      <Label htmlFor="weight">
                        {t('Weight')}{' '}
                        <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="weight"
                        type="number"
                        value={weight}
                        onChange={change(setWeight, 'weight')}
                        placeholder="10"
                        step="any"
                        disabled={isLoading}
                        aria-invalid={!!fieldErrors.weight}
                        className={
                          fieldErrors.weight ? 'border-destructive' : ''
                        }
                      />
                      {fieldErrors.weight && (
                        <p className="text-[12px] text-destructive">
                          {t(fieldErrors.weight)}
                        </p>
                      )}
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="port">
                        {t('Port')} <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="port"
                        type="number"
                        value={port}
                        onChange={change(setPort, 'port')}
                        placeholder="443"
                        step="any"
                        disabled={isLoading}
                        aria-invalid={!!fieldErrors.port}
                        className={fieldErrors.port ? 'border-destructive' : ''}
                      />
                      {fieldErrors.port && (
                        <p className="text-[12px] text-destructive">
                          {t(fieldErrors.port)}
                        </p>
                      )}
                    </div>
                  </>
                )}

                <div className="space-y-2">
                  <Label htmlFor="comment">{t('Comment (optional)')}</Label>
                  <Textarea
                    id="comment"
                    value={comment}
                    onChange={change(setComment)}
                    placeholder={t('Optional comment')}
                    disabled={isLoading}
                    rows={3}
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="flex-shrink-0 border-t border-border bg-muted/30">
            {error ? (
              <div className="px-6 pt-4">
                <Alert variant="destructive" className="border-destructive/30">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    {error}
                  </AlertDescription>
                </Alert>
              </div>
            ) : null}
            <div className="flex items-center justify-start gap-2 px-6 py-4">
              <Button
                type="submit"
                disabled={isLoading || !name.trim() || !value.trim()}
              >
                {t('Create Record')}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(false)}
                disabled={isLoading}
              >
                {t('Cancel')}
              </Button>
            </div>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
