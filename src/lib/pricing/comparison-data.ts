import type { ComparisonTable } from './types'

export const comparisonTables: ComparisonTable[] = [
        {
            title: 'Resources',
            rows: [
                {
                    title: 'API bandwidth',
                    free: '5GB / month',
                    pro: '2TB / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional API bandwidth',
                    free: '-',
                    pro: '$15 per 100GB / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Storage',
                    free: '2GB',
                    pro: '150GB',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional storage',
                    free: '-',
                    pro: '$2.8 per 100GB ',
                    enterprise: 'Custom'
                },
                {
                    title: 'Executions',
                    free: '750K / month',
                    pro: '3.5M / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Execution logs',
                    free: '100',
                    pro: '1000',
                    enterprise: 'Custom',
                    info: 'Number of execution logs retained per function/site'
                }
            ]
        },
        {
            title: 'Platform',
            rows: [
                {
                    title: 'Number of projects',
                    free: '2 (Shared resources)',
                    pro: '1 (Dedicated resources)',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional projects',
                    free: '-',
                    pro: '$15',
                    enterprise: 'Custom'
                },
                {
                    title: 'Organization members',
                    free: '1',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Connected websites and apps',
                    free: '3 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'No Appwrite branding on emails',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Custom SMTP',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Webhooks',
                    free: '2 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Logs retention',
                    free: '1 hour',
                    pro: '7 days',
                    enterprise: 'Custom'
                },
                {
                    title: 'Budget caps and alerts',
                    free: 'Not needed',
                    pro: true,
                    enterprise: true
                }
            ]
        },
        {
            title: 'Auth',
            rows: [
                {
                    title: 'Users',
                    free: '75,000 monthly active users',
                    pro: '200,000 monthly active users',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional users',
                    free: '-',
                    pro: '$3 per 1,000 users',
                    enterprise: 'Custom'
                },
                {
                    title: 'Phone OTP',
                    free: '-',
                    pro: { text: 'View rates', href: '/docs/advanced/billing/phone-otp#rates' },
                    enterprise: 'Custom'
                },
                {
                    title: 'Teams',
                    free: '100 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'SSO',
                    free: '-',
                    pro: '-',
                    enterprise: 'Coming soon'
                }
            ]
        },
        {
            title: 'Databases',
            rows: [
                {
                    title: 'Databases',
                    free: '1 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Documents',
                    free: 'Unlimited',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Reads',
                    free: '500K / month',
                    pro: '1750K / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Writes',
                    free: '250K / month',
                    pro: '750K / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional reads',
                    free: '-',
                    pro: '$0.060 per 100k reads',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional writes',
                    free: '-',
                    pro: '$0.10 per 100k writes',
                    enterprise: 'Custom'
                },
                {
                    title: 'Backups',
                    free: '-',
                    pro: 'Daily',
                    enterprise: 'Custom'
                },
                {
                    title: 'Backups retention',
                    free: '-',
                    pro: '7 days retention',
                    enterprise: 'Custom'
                },
                {
                    title: 'Data encryption',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Bulk operations',
                    free: '100 rows / request',
                    pro: '1,000 rows / request',
                    enterprise: 'Custom'
                },
                {
                    title: 'Dedicated databases',
                    free: '-',
                    pro: 'Compute tiers from $10/mo',
                    enterprise: 'Custom',
                    info: 'Fixed monthly compute tiers with reserved CPU, memory, and connections. See Database pricing for tier details and add-ons.'
                }
            ]
        },
        {
            title: 'Storage',
            rows: [
                {
                    title: 'Buckets',
                    free: '1 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'File size limit',
                    free: '50MB',
                    pro: '5GB',
                    enterprise: 'Custom'
                },
                {
                    title: 'Image transformations',
                    free: '-',
                    pro: '100 origin images / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional transformations',
                    free: '-',
                    pro: '$5 per 1000 origin images',
                    enterprise: 'Custom'
                }
            ]
        },
        {
            title: 'Compute',
            rows: [
                {
                    title: 'Functions',
                    free: '2 per project',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Sites',
                    free: 'Unlimited',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Executions',
                    free: '750K / month',
                    pro: '3.5M / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'GB-hours',
                    free: '100 GB-hour / month',
                    pro: '1,000 GB-hour / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional GB-hours',
                    free: '-',
                    pro: '$0.06 per GB-hour',
                    enterprise: 'Custom'
                },
                {
                    title: 'Compute options',
                    free: '0.5 CPUs - 512MB RAM',
                    pro: 'Up to 4 CPUs - 4GB RAM',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional executions',
                    free: '-',
                    pro: '$2 per 1m',
                    enterprise: 'Custom'
                },
                {
                    title: 'Build duration',
                    info: 'Maximum time available for each Sites or Functions build',
                    free: '15 minutes',
                    pro: '45 minutes',
                    enterprise: 'Custom'
                },
                {
                    title: 'Express builds',
                    info: 'Dedicated priority queues for build jobs',
                    free: '-',
                    pro: true,
                    enterprise: true
                }
            ]
        },
        {
            title: 'Realtime',
            rows: [
                {
                    title: 'Concurrent connections',
                    free: '250',
                    pro: '500',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional connections',
                    free: '-',
                    pro: '$5 per 1,000',
                    enterprise: 'Custom'
                },
                {
                    title: 'Messages',
                    free: '2M',
                    pro: '6M',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional messages',
                    free: '-',
                    pro: '$2.50 per 1m',
                    enterprise: 'Custom'
                },
                {
                    title: 'Max message size',
                    free: '256 KB',
                    pro: '3 MB',
                    enterprise: 'Custom'
                }
            ]
        },
        {
            title: 'Messaging',
            rows: [
                {
                    title: 'Messages',
                    free: '1000 / month',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Topics',
                    free: '1',
                    pro: 'Unlimited',
                    enterprise: 'Unlimited'
                },
                {
                    title: 'Targets',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'In app notifications',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Chat',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Push notifications',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Email',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'SMS',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Discord',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'WhatsApp',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Slack',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Analytics',
                    free: '-',
                    pro: 'Coming soon',
                    enterprise: 'Coming soon'
                }
            ]
        },
        {
            title: 'Others',
            rows: [
                {
                    title: 'Screenshots',
                    free: '50 / month',
                    pro: '5,000 / month',
                    enterprise: 'Custom'
                },
                {
                    title: 'Additional screenshots',
                    free: '-',
                    pro: '$0.004 per screenshot',
                    enterprise: 'Custom'
                }
            ]
        },
        {
            title: 'Network',
            rows: [
                {
                    title: 'Content delivery network',
                    info: 'Available across all Appwrite services, with 200+ global PoP locations for low-latency delivery across Backend API, Serverless Functions, Storage files, and hosted websites.',
                    free: 'Basic',
                    pro: 'Advanced',
                    enterprise: 'Advanced'
                },
                {
                    title: 'Edge compute',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'DDoS mitigation',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Content compression',
                    info: 'Support for brotli, zstd, and gzip for text compression and webp for image compression',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'TLS encryption',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Firewall rules',
                    free: '2 per project',
                    pro: '50 per project',
                    enterprise: 'Custom'
                },
                {
                    title: 'Logs',
                    free: '-',
                    pro: '-',
                    enterprise: 'Coming soon'
                }
            ]
        },
        {
            title: 'Security',
            rows: [
                {
                    title: 'Organization roles',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'SOC-2, HIPAA, and BAA',
                    free: '-',
                    pro: '-',
                    enterprise: true
                },
                {
                    title: 'Custom organization roles',
                    free: '-',
                    pro: '-',
                    enterprise: 'Coming soon'
                },
                {
                    title: 'Activity logs',
                    free: '-',
                    pro: '-',
                    enterprise: 'Coming soon'
                }
            ]
        },
        {
            title: 'Support',
            rows: [
                {
                    title: 'Community',
                    free: true,
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'Email',
                    free: '-',
                    pro: true,
                    enterprise: true
                },
                {
                    title: 'SLA',
                    free: '-',
                    pro: '-',
                    enterprise: 'Custom'
                },
                {
                    title: 'Private Slack channel',
                    free: '-',
                    pro: '-',
                    enterprise: 'Custom'
                }
            ]
        }
    ]
