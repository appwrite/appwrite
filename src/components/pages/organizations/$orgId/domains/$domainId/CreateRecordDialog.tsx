import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim() || !value.trim()) return

    onCreate({
      type,
      name: name.trim(),
      value: value.trim(),
      ttl: parseInt(ttl) || DEFAULT_TTL,
      priority: type === 'MX' || type === 'SRV' ? parseInt(priority) || undefined : undefined,
      weight: type === 'SRV' ? parseInt(weight) || undefined : undefined,
      port: type === 'SRV' ? parseInt(port) || undefined : undefined,
      comment: comment.trim() || undefined,
    })
  }

  const requiresPriority = type === 'MX' || type === 'SRV'
  const requiresSRVFields = type === 'SRV'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0 max-h-[90vh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add DNS Record</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Create a new DNS record for your domain.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="type">
                Type <span className="text-destructive">*</span>
              </Label>
              <Select value={type} onValueChange={setType} disabled={isLoading}>
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
            </div>

            <div className="space-y-2">
              <Label htmlFor="name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="@ or subdomain"
                disabled={isLoading}
                autoFocus
              />
              <p className="text-[12px] text-muted-foreground">
                Use @ for the root domain, or enter a subdomain (e.g., www, mail)
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="value">
                Value <span className="text-destructive">*</span>
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
                Time to live in seconds (default: 3600)
              </p>
            </div>

            {requiresPriority && (
              <div className="space-y-2">
                <Label htmlFor="priority">
                  Priority <span className="text-destructive">*</span>
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
                    ? 'Lower numbers have higher priority'
                    : 'Priority for SRV record'}
                </p>
              </div>
            )}

            {requiresSRVFields && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="weight">
                    Weight <span className="text-destructive">*</span>
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
                    Port <span className="text-destructive">*</span>
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
              <Label htmlFor="comment">Comment (optional)</Label>
              <Input
                id="comment"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Optional comment"
                disabled={isLoading}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim() || !value.trim()}>
              Add Record
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

