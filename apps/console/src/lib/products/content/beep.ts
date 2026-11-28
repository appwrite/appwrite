import {
  BEEP_APP_STORE_URL,
  BEEP_GOOGLE_PLAY_URL,
} from '@/lib/notifications/beep-links'
import type { ProductPageContent } from '@/lib/products/types'

/**
 * Beep product page copy. Beep is Cloud only and free. Pushes cover monitor
 * alerts (usage, budget, uptime checks) and changelog entries; there are no
 * certificate, deployment, or billing pushes. Critical alerts ring through
 * silent mode on Android only until the iOS entitlement ships.
 */
export const beepProductContent: ProductPageContent = {
  id: 'beep',
  metaTitle: 'Beep: the Appwrite Cloud app for your phone',
  metaDescription:
    'Appwrite Beep is the free Appwrite Cloud app for iPhone, iPad, and Android. Get a push when a monitor fires, acknowledge it for your team, page the next person in line, and check usage across every project.',
  hero: {
    title: 'Your projects, in your pocket',
    description:
      'Appwrite Beep is the Appwrite Cloud app for iPhone, iPad, and Android. Get a push the moment a monitor fires, acknowledge it for your team, and check usage across every project. You choose what reaches you.',
    stats: [
      { value: '$0', label: 'On the App Store and Google Play' },
      { value: 'Every org', label: 'All your organizations and projects' },
      { value: 'From the push', label: 'Acknowledge, snooze, or mute' },
      { value: 'Snooze', label: '30 minutes to 24 hours' },
      { value: 'Critical', label: 'Rings through silent mode on Android' },
    ],
  },
  apps: {
    appStore: BEEP_APP_STORE_URL,
    googlePlay: BEEP_GOOGLE_PLAY_URL,
  },
  faq: [
    {
      question: 'What is Appwrite Beep?',
      answer:
        'Beep is the Appwrite Cloud app for your phone. It pushes alerts from your monitors as they fire and resolve, lets you acknowledge, snooze, and escalate them, and shows usage, Appwrite Cloud status, and the changelog for every organization and project you belong to.',
    },
    {
      question: 'Is Beep free?',
      answer:
        'Yes. Beep is free on the App Store and Google Play, with no ads or in-app purchases. Sign in with your Appwrite Cloud account. The monitors that send alerts follow your organization’s plan.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Which notifications reach my phone?',
      answer:
        'Alerts from usage monitors, budget thresholds, and HTTP, DNS, TCP, and SMTP endpoint checks when they fire, and when they resolve if you want, plus a push when Appwrite publishes a changelog entry. Appwrite Cloud incidents show in the Updates tab.',
      links: [{ label: 'Appwrite Monitors', href: '/products/monitors' }],
    },
    {
      question: 'Who gets a push when a monitor fires?',
      answer:
        'The organization roles the monitor notifies, Owners by default, on every phone where they are signed in to Beep. With an escalation, each step notifies its roles and people in turn until someone acknowledges the alert or it resolves. The same people also get an email and a Console notification.',
    },
    {
      question: 'Can I stop notifications without missing alerts?',
      answer:
        'Yes. Snooze one organization or all of them for 30 minutes, 1 hour, 4 hours, or 24 hours, turn off organizations and projects you don’t watch, or mute a single monitor. Snoozed alerts still fire and still show in Beep, the Console, and email. Muting a monitor also stops its emails to you.',
    },
    {
      question: 'Do critical alerts ring through silent mode?',
      answer:
        'On Android, yes. Monitors your team sets to Critical ring at alarm volume, even on silent, and in Do Not Disturb once you let Beep’s critical alerts override it. On iPhone, critical monitors arrive as normal notifications that follow your silent switch and Focus for now.',
    },
    {
      question: 'Can I create monitors in Beep?',
      answer:
        'Monitors are created in the Console. In Beep you can acknowledge alerts, adjust a usage monitor’s threshold, pause or resume a monitor, and create and assign escalations.',
    },
    {
      question: 'Does Beep work with self-hosted Appwrite?',
      answer: 'No. Beep signs in to Appwrite Cloud only.',
    },
  ],
  cta: {
    title: 'Put your projects in your pocket',
    description:
      'Download Beep, sign in with your Appwrite account, and choose what reaches you.',
  },
}
