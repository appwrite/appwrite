import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { MarketingFeatureItem } from '@/components/pages/marketing/MarketingSections'
import {
  Activity,
  Building2,
  Cloud,
  Compass,
  GraduationCap,
  Headphones,
  Lock,
  Server,
  Shield,
  ShieldAlert,
  ShieldCheck,
  UserRoundCheck,
  Users,
  Zap,
} from 'lucide-react'

export const ENTERPRISE_FORM_ID = 'enterprise-contact-form'

export const enterpriseHero = {
  eyebrow: 'Enterprise',
  title: 'Appwrite for Enterprise',
  description:
    'Replace a patchwork of backend vendors with one platform. Enterprise teams reduce integration overhead, accelerate delivery, and scale with custom resources, dedicated support, and flexible deployment.',
} as const

export const enterprisePlatformSection = {
  title: 'One platform for your entire stack',
  description:
    'Reduce vendor sprawl and integration overhead. Appwrite unifies the backend services enterprise teams need to build, deploy, and protect modern applications without juggling multiple contracts or stitching vendors together.',
} as const

export const enterpriseSecuritySection = {
  eyebrow: 'Trust center',
  title: 'Security and compliance',
  description:
    'Compliance artifacts, agreements, and enterprise governance built for security reviews and procurement. Enterprise plans include access to SOC 2 reporting, data processing agreements, and advanced identity controls.',
} as const

export type EnterpriseComplianceFramework = {
  name: string
  summary?: string
}

export const enterpriseComplianceFrameworks: EnterpriseComplianceFramework[] = [
  {
    name: 'SOC 2 Type II',
    summary: 'Audited security controls',
  },
  {
    name: 'HIPAA',
    summary: 'Healthcare workloads',
  },
  {
    name: 'GDPR',
    summary: 'EU data processing',
  },
  {
    name: 'CCPA',
    summary: 'California privacy',
  },
]

export type EnterpriseSecurityControl = {
  title: string
  icon: 'shield' | 'lock' | 'server'
  items: string[]
}

export const enterpriseSecurityControls: EnterpriseSecurityControl[] = [
  {
    title: 'Compliance',
    icon: 'shield',
    items: ['SOC 2 Type II report', 'DPA', 'HIPAA-aligned controls'],
  },
  {
    title: 'Identity',
    icon: 'lock',
    items: ['SSO', 'Custom organization roles', 'Activity logs'],
  },
  {
    title: 'Protection',
    icon: 'server',
    items: ['Firewall and WAF', '90-day log retention', 'Custom backup policies'],
  },
]

export const enterpriseStats = [
  { value: '40M+', label: 'Developers worldwide' },
  { value: '120+', label: 'CDN locations' },
  { value: '24/7', label: 'Slack support' },
  { value: '99.99%', label: 'Uptime SLA' },
  { value: 'SOC 2', label: 'Type II certified' },
] as const

export const enterpriseValueProps: MarketingFeatureItem[] = [
  {
    title: 'All-in-one platform',
    description:
      'Auth, databases, storage, functions, messaging, hosting, and network security in one stack your teams already know how to operate.',
    icon: Building2,
  },
  {
    title: 'Faster time to market',
    description:
      'Ship production features without stitching together multiple vendors or maintaining custom backend infrastructure.',
    icon: Zap,
  },
  {
    title: 'Built for scale',
    description:
      'Custom bandwidth, storage, compute, and project limits tailored to your traffic, workloads, and organizational structure.',
    icon: Server,
  },
  {
    title: 'Enterprise support',
    description:
      'Dedicated success management, priority response times, and direct access to Appwrite engineers when you need them.',
    icon: Headphones,
  },
]

export const enterprisePlanCapabilities: MarketingFeatureItem[] = [
  {
    title: 'Uptime SLAs',
    description: 'Contractual availability commitments for mission-critical production workloads.',
    icon: Activity,
  },
  {
    title: 'Volume discounts',
    description: 'Pricing aligned to your usage profile across bandwidth, storage, and compute.',
    icon: Building2,
  },
  {
    title: 'Log drains',
    description: 'Stream execution and platform logs into your observability stack.',
    icon: Server,
  },
  {
    title: '90-day log retention',
    description: 'Extended retention for audits, incident response, and compliance workflows.',
    icon: Activity,
  },
  {
    title: 'Advanced observability',
    description: 'Deeper visibility into platform activity, performance, and operational health.',
    icon: Zap,
  },
  {
    title: 'Firewall',
    description: 'Appwrite Firewall with WAF capabilities to filter malicious traffic and protect applications at the edge.',
    icon: ShieldCheck,
  },
  {
    title: 'Premium DDoS protection',
    description: 'Enhanced network-level DDoS mitigation for high-traffic production workloads and mission-critical availability.',
    icon: ShieldAlert,
  },
  {
    title: 'SOC-2 and HIPAA',
    description: 'Compliance options for regulated industries and enterprise procurement requirements.',
    icon: Shield,
  },
  {
    title: 'Single Sign-On (SSO)',
    description: 'Centralized identity for console access with enterprise authentication policies.',
    icon: Lock,
  },
  {
    title: 'Activity logs',
    description: 'Track console actions across your organization for security and governance.',
    icon: Activity,
  },
  {
    title: 'Custom backup policies',
    description: 'Retention and recovery settings aligned to your disaster recovery requirements.',
    icon: Server,
  },
  {
    title: 'Custom organization roles',
    description: 'Fine-grained access control beyond standard owner and developer roles.',
    icon: Users,
  },
]

export const enterpriseDeploymentSection = {
  title: 'Cloud or self-hosted',
  description:
    'Run Enterprise on fully managed Appwrite Cloud or as a premium self-hosted edition in your environment.',
  sharedBenefitsTitle: 'Included with both Cloud and self-hosted Enterprise',
} as const

export const enterpriseDeploymentSharedBenefits: MarketingFeatureItem[] = [
  {
    title: '24/7 support',
    description: 'Round-the-clock Slack and email from our engineering team.',
    icon: Headphones,
  },
  {
    title: 'Customer success manager',
    description: 'Dedicated partner for onboarding and ongoing success.',
    icon: UserRoundCheck,
  },
  {
    title: 'Consultancy',
    description: 'Architecture and deployment guidance for your stack.',
    icon: Compass,
  },
  {
    title: 'Training',
    description: 'Hands-on sessions to onboard your developers.',
    icon: GraduationCap,
  },
]

export const enterpriseDeploymentOptions: MarketingFeatureItem[] = [
  {
    title: 'Appwrite Cloud',
    description:
      'Fully managed infrastructure with a plan customized to your needs: increased limits, predictable fixed pricing, uptime SLAs, and global CDN.',
    icon: Cloud,
  },
  {
    title: 'Self-hosted edition',
    description:
      'A premium, cloud-equal edition with advanced management tools. The same platform and tooling Appwrite uses to run Cloud at 500K projects scale, deployed in your environment.',
    icon: Server,
  },
]

export const enterpriseFormBullets = [
  'Custom bandwidth, storage, and compute limits',
  'Dedicated success manager and 24/7 Slack support',
  'Uptime SLAs and volume-based pricing',
  'SOC-2, HIPAA, SSO, and activity logs',
  'Custom Cloud limits with fixed pricing, or premium self-hosted with enterprise management tools',
] as const

export const enterpriseCompanySizeOptions = [
  { value: '1-10 employees', label: '1-10 employees' },
  { value: '11-50 employees', label: '11-50 employees' },
  { value: '51-200 employees', label: '51-200 employees' },
  { value: '201-500 employees', label: '201-500 employees' },
  { value: '501-1000 employees', label: '501-1000 employees' },
  { value: '1001-5000 employees', label: '1001-5000 employees' },
  { value: '5000+ employees', label: '5000+ employees' },
] as const

export const enterprisePreferredDeploymentOptions = [
  { value: 'Appwrite Cloud', label: 'Appwrite Cloud' },
  { value: 'Self-hosted edition', label: 'Self-hosted edition' },
  { value: 'Not sure yet', label: 'Not sure yet' },
] as const

export const enterpriseTimelineOptions = [
  { value: 'Exploring options', label: 'Exploring options' },
  { value: 'Evaluating vendors', label: 'Evaluating vendors' },
  { value: 'Ready to buy', label: 'Ready to buy' },
] as const

export const enterpriseFaqItems: MarketingFaqItem[] = [
  {
    question: 'Who is the Enterprise plan for?',
    answer:
      'Enterprise is designed for organizations with production workloads that need custom resource limits, premium support, compliance options, or deployment flexibility beyond the Pro plan.',
  },
  {
    question: 'How is Enterprise pricing determined?',
    answer:
      'Pricing is based on your usage profile, support requirements, and deployment model. Cloud Enterprise plans offer customized limits with predictable fixed pricing. Our team works with you to build a plan that matches your scale and procurement process.',
  },
  {
    question: 'Can we self-host Appwrite?',
    answer:
      'Yes. The Enterprise self-hosted edition is a premium, cloud-equal release with advanced management tools. It is the same platform Appwrite uses to run Cloud at 500K projects scale, deployed in your environment with dedicated support, SLAs, and compliance features.',
  },
  {
    question: 'What support is included?',
    answer:
      'Enterprise customers receive a dedicated success manager, 24/7 support on Slack, and priority response times. We also help with onboarding, architecture reviews, and ongoing optimization.',
  },
  {
    question: 'What compliance options are available?',
    answer:
      'Enterprise plans can include SOC-2 and HIPAA support, along with SSO, activity logs, and custom backup policies for security and governance workflows.',
  },
  {
    question: 'How do we get started?',
    answer:
      'Fill out the contact form on this page. Our sales team will review your use case and schedule a conversation to scope resources, support, and deployment options.',
  },
]
