import { useState, useMemo } from 'react'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { Models } from '@appwrite.io/console'
import { Copy, Check } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'

interface VerifyDomainContentProps {
  rule: Models.ProxyRule
}

// Helper to get console variables (this would need to be fetched from console SDK)
// For now, we'll use placeholder values
const getConsoleVariables = () => {
  // In a real implementation, this would fetch from sdk.forConsole.console.getVariables()
  // For now, return defaults
  return {
    cname:
      '_APP_DOMAIN_TARGET_CNAME' in (globalThis as any)
        ? (globalThis as any)._APP_DOMAIN_TARGET_CNAME
        : 'appwrite.example.com',
    a:
      '_APP_DOMAIN_TARGET_A' in (globalThis as any)
        ? (globalThis as any)._APP_DOMAIN_TARGET_A
        : '1.2.3.4',
    aaaa:
      '_APP_DOMAIN_TARGET_AAAA' in (globalThis as any)
        ? (globalThis as any)._APP_DOMAIN_TARGET_AAAA
        : '2001:db8::1',
  }
}

export function VerifyDomainContent({ rule }: VerifyDomainContentProps) {
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState('cname')
  const vars = getConsoleVariables()
  const isCloud = useMemo(() => {
    try {
      return getBaseEndpoint().includes('cloud.appwrite.io')
    } catch {
      return false
    }
  }, [])

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success('Copied to clipboard')
    setTimeout(() => setCopiedField(null), 2000)
  }

  const dnsRecords = [
    {
      type: 'CNAME',
      name: rule.domain,
      value: vars.cname,
      ttl: 3600,
    },
  ]

  if (!isCloud && vars.a) {
    dnsRecords.push({
      type: 'A',
      name: rule.domain,
      value: vars.a,
      ttl: 3600,
    })
  }

  if (!isCloud && vars.aaaa) {
    dnsRecords.push({
      type: 'AAAA',
      name: rule.domain,
      value: vars.aaaa,
      ttl: 3600,
    })
  }

  return (
    <div className="space-y-4">
      {/* Domain card */}
      <div className="rounded-lg border border-border bg-card p-4">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium">{rule.domain}</p>
            <p className="text-[13px] text-muted-foreground">
              Add the following DNS records to verify ownership
            </p>
          </div>
          <Button variant="outline" size="sm">
            Change
          </Button>
        </div>
      </div>

      {/* Verification tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="grid w-full grid-cols-3">
          {vars.cname && <TabsTrigger value="cname">CNAME</TabsTrigger>}
          {isCloud && (
            <TabsTrigger value="nameservers">Nameservers</TabsTrigger>
          )}
          {!isCloud && vars.a && <TabsTrigger value="a">A</TabsTrigger>}
        </TabsList>

        {vars.cname && (
          <TabsContent value="cname" className="space-y-4">
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Type
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Name/Host
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Value/Target
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      TTL
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow>
                    <TableCell className="px-4 py-3">CNAME</TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {rule.domain}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {vars.cname}
                    </TableCell>
                    <TableCell className="px-4 py-3">3600</TableCell>
                    <TableCell className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleCopy(vars.cname, 'cname')}
                      >
                        {copiedField === 'cname' ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
            <p className="text-[13px] text-muted-foreground">
              Add a CNAME record pointing {rule.domain} to {vars.cname} in your
              DNS settings.
            </p>
          </TabsContent>
        )}

        {isCloud && (
          <TabsContent value="nameservers" className="space-y-4">
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Nameserver
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      ns1.appwrite.io
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleCopy('ns1.appwrite.io', 'ns1')}
                      >
                        {copiedField === 'ns1' ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      ns2.appwrite.io
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleCopy('ns2.appwrite.io', 'ns2')}
                      >
                        {copiedField === 'ns2' ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
            <p className="text-[13px] text-muted-foreground">
              Update your domain's nameservers to Appwrite's nameservers in your
              domain registrar settings.
            </p>
          </TabsContent>
        )}

        {!isCloud && vars.a && (
          <TabsContent value="a" className="space-y-4">
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Type
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Name/Host
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Value/Target
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      TTL
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow>
                    <TableCell className="px-4 py-3">A</TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {rule.domain}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {vars.a}
                    </TableCell>
                    <TableCell className="px-4 py-3">3600</TableCell>
                    <TableCell className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleCopy(vars.a, 'a')}
                      >
                        {copiedField === 'a' ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
            <p className="text-[13px] text-muted-foreground">
              Add an A record pointing {rule.domain} to {vars.a} in your DNS
              settings.
            </p>
          </TabsContent>
        )}
      </Tabs>
    </div>
  )
}
