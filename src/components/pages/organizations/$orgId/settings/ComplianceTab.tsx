import { FileText, Shield, ShieldCheck } from '@/lib/icons'
import { Button } from '@/components/ui/button'

const COMPANY_NAME = import.meta.env.VITE_COMPANY_NAME || 'Appwrite'
const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'
const LEGAL_EMAIL = import.meta.env.VITE_LEGAL_EMAIL || 'legal@appwrite.io'

export function ComplianceTab() {
  return (
    <div className="space-y-6">
      {/* DPA - Data Processing Agreement */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Data Processing Agreement (DPA)
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            A DPA is a legally binding document that outlines how {COMPANY_NAME}{' '}
            processes personal data on your behalf. It's required for GDPR
            compliance when handling EU residents' data.
          </p>
          <div className="flex items-start gap-3 mt-3">
            <FileText className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
            <p className="text-[13px] text-muted-foreground">
              Download the DPA, review it with your legal team, sign it, and
              send a copy to{' '}
              <span className="font-medium text-foreground">
                {LEGAL_EMAIL}
              </span>
              . We'll countersign and return a fully executed copy within 5
              business days.
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => {
              window.open(
                '/legal/dpa.pdf',
                '_blank',
                'noopener,noreferrer',
              )
            }}
          >
            Download DPA
          </Button>
        </div>
      </div>

      {/* BAA - Business Associate Agreement */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Business Associate Agreement (BAA)
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            A BAA is required under HIPAA when a service provider handles
            Protected Health Information (PHI) on behalf of a covered entity. If
            your application processes, stores, or transmits health-related data
            of US patients, you'll need a BAA in place.
          </p>
          <div className="flex items-start gap-3 mt-3">
            <ShieldCheck className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
            <p className="text-[13px] text-muted-foreground">
              <span className="font-medium text-foreground">
                Who needs this:
              </span>{' '}
              Healthcare providers, health plans, healthcare clearinghouses, and
              their business associates building HIPAA-compliant applications.
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => {
              window.open(CONTACT_SALES_URL, '_blank', 'noopener,noreferrer')
            }}
          >
            Contact Sales
          </Button>
        </div>
      </div>

      {/* SOC-2 Compliance */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              SOC 2 Type II Report
            </h3>
            <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
              Enterprise
            </span>
          </div>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            SOC 2 Type II is an auditing standard that verifies a service
            provider's security controls over an extended period. It
            demonstrates that {COMPANY_NAME} maintains rigorous security
            practices for data protection, availability, and confidentiality.
          </p>
          <div className="flex items-start gap-3 mt-3">
            <Shield className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
            <p className="text-[13px] text-muted-foreground">
              <span className="font-medium text-foreground">Why it matters:</span>{' '}
              Many enterprise customers and regulated industries require SOC 2
              compliance from their vendors. Access to our SOC 2 report is
              available on Enterprise plans.
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => {
              window.open(CONTACT_SALES_URL, '_blank', 'noopener,noreferrer')
            }}
          >
            Contact Sales
          </Button>
        </div>
      </div>
    </div>
  )
}
