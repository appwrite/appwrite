import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  Building2,
  Cloud,
  Headphones,
  Lock,
  Server,
  Shield,
  Users,
  Zap,
} from 'lucide-react'
import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { MarketingFeatureItem } from '@/components/pages/marketing/MarketingSections'

export const ENTERPRISE_FORM_ID = 'enterprise-contact-form'

export const enterpriseHero = {
  eyebrow: 'Enterprise',
  title: 'Appwrite for Enterprise',
  description:
    'Enterprise teams partner with Appwrite to give developers an all-in-one development platform. Reduce backend complexity, accelerate delivery, and scale with dedicated support, custom resources, and enterprise-grade security.',
} as const

export const enterpriseStats = [
  { value: '40M+', label: 'Developers reached' },
  { value: '120+', label: 'CDN points of presence' },
  { value: '24/7', label: 'Enterprise support on Slack' },
  { value: '99.99%', label: 'Uptime SLA available' },
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
    title: 'Success manager',
    description: 'A dedicated partner for onboarding, architecture reviews, and ongoing optimization.',
    icon: Users,
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
    title: 'Bring your own Cloud',
    description: 'Run Appwrite in your cloud account with enterprise controls and support.',
    icon: Cloud,
  },
  {
    title: 'SOC-2, HIPAA, and BAA',
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

export const enterpriseDeploymentOptions: MarketingFeatureItem[] = [
  {
    title: 'Appwrite Cloud',
    description:
      'Managed infrastructure with global CDN, automatic scaling, and the fastest path to production.',
    icon: Cloud,
  },
  {
    title: 'Bring your own Cloud',
    description:
      'Deploy in your AWS, GCP, or Azure environment while Appwrite provides enterprise support and guidance.',
    icon: Building2,
  },
  {
    title: 'Self-hosted enterprise',
    description:
      'Advanced self-hosting options for teams that need full infrastructure control with dedicated support.',
    icon: Server,
  },
]

export const enterpriseSecurityHighlights: MarketingFeatureItem[] = [
  {
    title: 'Compliance ready',
    description: 'SOC-2, HIPAA, and BAA options to support security reviews and procurement.',
    icon: Shield,
  },
  {
    title: 'Identity and access',
    description: 'SSO, custom organization roles, and activity logs for enterprise governance.',
    icon: Lock,
  },
  {
    title: 'Network protection',
    description: 'Firewall, WAF, and edge capabilities for applications exposed to the internet.',
    icon: Server,
  },
]

export const enterpriseFormBullets = [
  'Custom bandwidth, storage, and compute limits',
  'Dedicated success manager and 24/7 Slack support',
  'Uptime SLAs and volume-based pricing',
  'SOC-2, HIPAA, BAA, SSO, and activity logs',
  'Bring your own Cloud and advanced self-hosting options',
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

export const enterpriseFaqItems: MarketingFaqItem[] = [
  {
    question: 'Who is the Enterprise plan for?',
    answer:
      'Enterprise is designed for organizations with production workloads that need custom resource limits, premium support, compliance options, or deployment flexibility beyond the Pro plan.',
  },
  {
    question: 'How is Enterprise pricing determined?',
    answer:
      'Pricing is based on your usage profile, support requirements, and deployment model. Our team works with you to build a plan that matches your scale and procurement process.',
  },
  {
    question: 'Can we deploy in our own cloud?',
    answer:
      'Yes. Bring your own Cloud and advanced self-hosting options are available for teams that need infrastructure control while retaining enterprise support from Appwrite.',
  },
  {
    question: 'What support is included?',
    answer:
      'Enterprise customers receive a dedicated success manager, 24/7 support on Slack, and priority response times. We also help with onboarding, architecture reviews, and ongoing optimization.',
  },
  {
    question: 'What compliance options are available?',
    answer:
      'Enterprise plans can include SOC-2, HIPAA, and BAA support, along with SSO, activity logs, and custom backup policies for security and governance workflows.',
  },
  {
    question: 'How do we get started?',
    answer:
      'Fill out the contact form on this page. Our sales team will review your use case and schedule a conversation to scope resources, support, and deployment options.',
  },
]
