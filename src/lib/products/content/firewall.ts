import type { ProductPageContent } from '@/lib/products/types'

export const firewallProductContent: ProductPageContent = {
  id: 'firewall',
  metaDescription:
    'Protect project APIs, Functions, and Sites with Appwrite Firewall. Create rules to deny, bypass, rate limit, redirect, or challenge matching traffic from the Console.',
  hero: {
    title: 'Control traffic before it reaches your app',
    description:
      'Define project rules that match the request properties you care about, then deny, bypass, rate limit, redirect, or challenge traffic before it hits your API, Functions, or Sites.',
  },
  faq: [
    {
      question: 'What can Firewall protect?',
      answer:
        'Firewall rules run on Appwrite Cloud before traffic reaches your project resources. Scope a rule to the project API, a specific Function, or a specific Site. Console traffic is never blocked, so you can keep managing rules even when deny or rate limit policies are active.',
      links: [
        { label: 'Firewall overview', href: '/docs/products/firewall' },
        { label: 'Resource scopes', href: '/docs/products/firewall/scopes' },
      ],
    },
    {
      question: 'Which actions can a rule take?',
      answer:
        'Each matching rule applies one action: Deny returns 403, Bypass allows the request and skips later rules, Rate limit throttles per client IP with a 429 when over quota, Redirect sends clients to another location with a 3xx status, and Challenge verifies suspicious clients before allowing them through. There is no separate Allow action. Use Bypass to allowlist traffic that should skip later deny, rate limit, or challenge rules.',
      links: [{ label: 'Actions', href: '/docs/products/firewall/actions' }],
    },
    {
      question: 'How do conditions and priority work together?',
      answer:
        'Every rule needs at least one condition. All conditions on a rule must match (AND). Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.',
      links: [
        { label: 'Conditions', href: '/docs/products/firewall/conditions' },
        { label: 'Priority', href: '/docs/products/firewall/priority' },
      ],
    },
    {
      question: 'Can I preview impact before enabling a rule?',
      answer:
        'Yes. While creating a rule, the Console estimates how many recent usage events would match your current conditions for the selected resource scope and date range. Use that preview to tighten filters before you enable the rule, then confirm outcomes in traffic overview.',
      links: [
        { label: 'Create a rule', href: '/docs/products/firewall/create' },
        { label: 'Monitor traffic', href: '/docs/products/firewall/monitor' },
      ],
    },
    {
      question: 'What does traffic overview show?',
      answer:
        'The Firewall page chart summarizes Passed request volume alongside Denied, Rate limited, Redirected, and Challenged series for the selected date range. Bypass matches and under-quota rate limit matches allow traffic without publishing a Firewall outcome metric. Use the overview with your rules list to verify policies after enablement.',
      links: [{ label: 'Monitor traffic', href: '/docs/products/firewall/monitor' }],
    },
    {
      question: 'Is Firewall available on every plan?',
      answer:
        'Firewall is available on Appwrite Cloud. Rule limits depend on your organization plan. Disabled rules still count toward plan limits but are not evaluated.',
      links: [
        { label: 'Plan limits', href: '/docs/products/firewall/rules#plan-limits' },
        { label: 'Pricing', href: '/pricing' },
      ],
    },
  ],
  cta: {
    title: 'Start protecting with Firewall',
    description:
      'Create your first deny, bypass, rate limit, redirect, or challenge rule from the Console and preview impact before you enable it.',
  },
}
