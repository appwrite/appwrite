import { FileText, Shield } from '@/lib/icons'
import { Button } from '@/components/ui/button'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { SOC2_SETTINGS_KEYWORDS } from '@/lib/settings-search/org-settings-cards'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { useT } from '@/lib/i18n/translate'
import { useParams } from '@tanstack/react-router'
import { BaaSettingsCard } from './_components/BaaSettingsCard'

const COMPANY_NAME = import.meta.env.VITE_COMPANY_NAME || 'Appwrite'
const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL || CONTACT_ENTERPRISE_URL
const LEGAL_EMAIL = import.meta.env.VITE_LEGAL_EMAIL || 'legal@appwrite.io'

export function ComplianceTab() {
  const t = useT()
  const params = useParams({ strict: false })
  const organizationId = params.orgId as string | undefined

  const cards: SettingsCardItem[] = [
    {
      id: 'dpa',
      search: {
        title: 'Data processing agreement (DPA)',
        keywords: ['dpa', 'gdpr', 'legal', 'data processing'],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Data processing agreement (DPA)')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                'A DPA is a legally binding document that outlines how',
              )}{' '}
              {COMPANY_NAME}{' '}
              {t(
                "processes personal data on your behalf. It's required for GDPR compliance when handling EU residents' data.",
              )}
            </p>
            <div className="flex items-start gap-3 mt-3">
              <FileText className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Download the DPA, review it with your legal team, sign it, and send a copy to',
                )}{' '}
                <span className="font-medium text-foreground">{LEGAL_EMAIL}</span>
                .{' '}
                {t(
                  "We'll countersign and return a fully executed copy within 5 business days.",
                )}
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                window.open('/legal/dpa.pdf', '_blank', 'noopener,noreferrer')
              }}
            >
              {t('Download DPA')}
            </Button>
          </div>
        </div>
      ),
    },
    {
      id: 'baa',
      search: {
        title: 'Business associate agreement (BAA)',
        keywords: ['baa', 'hipaa', 'phi', 'healthcare'],
      },
      node: organizationId ? (
        <BaaSettingsCard organizationId={organizationId} />
      ) : null,
    },
    {
      id: 'soc2',
      search: {
        title: 'SOC 2 type II report',
        description:
          'SOC 2 Type II auditing standard for security, availability, and confidentiality. Enterprise plans.',
        keywords: [...SOC2_SETTINGS_KEYWORDS],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <div className="flex items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('SOC 2 type II report')}
              </h3>
              <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                {t('Enterprise')}
              </span>
            </div>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                "SOC 2 Type II is an auditing standard that verifies a service provider's security controls over an extended period. It demonstrates that",
              )}{' '}
              {COMPANY_NAME}{' '}
              {t(
                'maintains rigorous security practices for data protection, availability, and confidentiality.',
              )}
            </p>
            <div className="flex items-start gap-3 mt-3">
              <Shield className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
              <p className="text-[13px] text-muted-foreground">
                <span className="font-medium text-foreground">
                  {t('Why it matters:')}
                </span>{' '}
                {t(
                  'Many enterprise customers and regulated industries require SOC 2 compliance from their vendors. Access to our SOC 2 report is available on Enterprise plans.',
                )}
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
              {t('Contact sales')}
            </Button>
          </div>
        </div>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
