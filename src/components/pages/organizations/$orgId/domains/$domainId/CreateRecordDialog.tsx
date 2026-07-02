import { useState, useEffect } from 'react'
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
  }) => void
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

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setType('A')
      setName('')
      setValue('')
      setTtl(DEFAULT_TTL.toString())
      setPriority('')
      setWeight('')
      setPort('')
      setComment('')
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim() || !value.trim()) return

    onCreate({
      type,
      name: name.trim(),
      value: value.trim(),
      ttl: parseInt(ttl) || DEFAULT_TTL,
      priority:
        type === 'MX' || type === 'SRV'
          ? parseInt(priority) || undefined
          : undefined,
      weight: type === 'SRV' ? parseInt(weight) || undefined : undefined,
      port: type === 'SRV' ? parseInt(port) || undefined : undefined,
      comment: comment.trim() || undefined,
    })
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

        <form
          onSubmit={handleSubmit}
          className="flex flex-1 flex-col min-h-0"
>
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              <div className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="type">
                    {t('Type')} <span className="text-destructive">*</span>
                  </Label>
                  <Select
                    value={type}
                    onValueChange={setType}
                    disabled={isLoading}
>
                    <SelectTrigger
                      id="type"
>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {DNS_RECORD_TYPES.map((recordType) => (
                        <SelectItem
                          key={recordType}
                          value={recordType}
>
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
                    onChange={(e) => setName(e.target.value)}
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
                    onChange={(e) => setValue(e.target.value)}
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
                    onChange={(e) => setTtl(e.target.value)}
                    min="1"
                    disabled={isLoading}
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t('Time to live in seconds (default: 3600)')}
                  </p>
                </div>

                {requiresPriority && (
                  <div className="space-y-2">
                    <Label htmlFor="priority">
                      {t('Priority')} <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="priority"
                      type="number"
                      value={priority}
                      onChange={(e) => setPriority(e.target.value)}
                      placeholder={type === 'MX' ? '10' : '0'}
                      min="0"
                      disabled={isLoading}
                    />
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
                        {t('Weight')} <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="weight"
                        type="number"
                        value={weight}
                        onChange={(e) => setWeight(e.target.value)}
                        placeholder="10"
                        min="0"
                        disabled={isLoading}
                      />
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="port">
                        {t('Port')} <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="port"
                        type="number"
                        value={port}
                        onChange={(e) => setPort(e.target.value)}
                        placeholder="443"
                        min="1"
                        max="65535"
                        disabled={isLoading}
                      />
                    </div>
                  </>
                )}

                <div className="space-y-2">
                  <Label htmlFor="comment">{t('Comment (optional)')}</Label>
                  <Textarea
                    id="comment"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder={t('Optional comment')}
                    disabled={isLoading}
                    rows={3}
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
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
        </form>
      </>
    </BaseDrawer>
  )
}
