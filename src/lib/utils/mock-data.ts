// Mock data for Appwrite Console UI

export interface Organization {
  $id: string
  name: string
  slug: string
  avatar?: string
  plan: 'free' | 'pro' | 'custom'
  members: number
}

export interface Team {
  $id: string
  name: string
  color: string
  members: number
  orgId: string
}

export interface Project {
  $id: string
  name: string
  teamId: string
  region: string
  createdAt: string
  icon?: string
  archived?: boolean
  /** Number of times the ping was received for this project. */
  pingCount?: number
  /** Last ping datetime in ISO 8601 format. */
  pingedAt?: string
}

export interface Database {
  $id: string
  name: string
  tables: number
  rows: number
}

export interface Collection {
  $id: string
  name: string
  databaseId: string
  rows: number
  columns: number
  indexes: number
  enabled?: boolean
}

export interface StorageBucket {
  $id: string
  name: string
  files: number
  size: number // in bytes
  maxSize: string
}

export interface Function {
  $id: string
  name: string
  runtime: string
  executions: number
  status: 'active' | 'inactive'
  avgDuration: number
}

export interface Site {
  $id: string
  name: string
  framework: 'next' | 'nuxt' | 'svelte' | 'astro' | 'react' | 'vue' | 'static'
  status: 'active' | 'building' | 'failed' | 'inactive'
  domains: string[]
  lastDeployment: string
  branch: string
  repository: string
  buildTime: number // in seconds
  visits: number
}

export interface User {
  $id: string
  name: string
  email: string
  avatar?: string
  status: 'verified' | 'unverified'
  createdAt: string
}

export interface ActivityEvent {
  $id: string
  type: 'create' | 'update' | 'delete' | 'execute' | 'upload' | 'login'
  resource: string
  resourceType: 'document' | 'file' | 'function' | 'user' | 'collection'
  timestamp: string
  userId: string
  userName: string
}

export interface UsageDataPoint {
  date: string
  requests: number
  bandwidth: number // in MB
  executions: number
}

// Billing-related interfaces
export interface Invoice {
  $id: string
  invoiceNumber: string
  dueDate: string
  paidDate?: string
  status: 'paid' | 'pending' | 'overdue' | 'failed'
  amount: number
  currency: string
  downloadUrl?: string
}

export interface PaymentMethod {
  $id: string
  type: 'card' | 'paypal' | 'bank'
  isPrimary: boolean
  last4?: string
  brand?: string
  expiryMonth?: number
  expiryYear?: number
  email?: string
  bankName?: string
  createdAt: string
}

export interface BillingAddress {
  $id: string
  name: string
  company?: string
  addressLine1: string
  addressLine2?: string
  city: string
  state?: string
  postalCode: string
  country: string
}

export interface TaxId {
  $id: string
  type: 'vat' | 'gst' | 'ein' | 'other'
  value: string
  country: string
  verified: boolean
}

export interface BillingAlert {
  $id: string
  threshold: number // percentage (e.g., 50, 75, 90, 100)
  enabled: boolean
}

// Org-level addon (like additional projects)
export interface OrgAddon {
  name: string
  quantity: number
  unitPrice: number
  total: number
}

// Project-specific addon charges
export interface ProjectAddon {
  name: string
  usage: string // e.g., "50 GB", "10K MAU"
  unitPrice: number
  total: number
}

// Project billing breakdown
export interface ProjectBillingBreakdown {
  projectId: string
  projectName: string
  addons: ProjectAddon[]
  total: number
}

export interface PlanDetails {
  name: string
  price: number
  billingCycle: 'monthly' | 'yearly'
  nextPaymentDate: string
  cycleStart: string
  cycleEnd: string
  basePrice: number
  orgAddons: OrgAddon[]
  projectBreakdowns: ProjectBillingBreakdown[]
  additionalCharges: {
    name: string
    quantity: number
    unitPrice: number
    total: number
  }[]
}

// Current user
export const currentConsoleUser = {
  $id: '507f1f77bcf86cd799439011',
  name: 'Alex Morgan',
  email: 'alex@appwrite.io',
  avatar:
    'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100&h=100&fit=crop&crop=face',
  role: 'Owner',
  memberSince: 'October 2025',
  accountStatus: 'Verified',
  accountId: '68dd2cc50002d18c9e42',
}

// Organizations
export const organizations: Organization[] = [
  {
    $id: '507f191e810c19729de860ea',
    name: 'Personal Projects',
    slug: 'personal-projects',
    plan: 'pro',
    members: 1,
  },
  {
    $id: '507f191e810c19729de860eb',
    name: 'Appwrite',
    slug: 'appwrite',
    avatar: 'https://appwrite.io/images/favicon.png',
    plan: 'custom',
    members: 45,
  },
  {
    $id: '507f191e810c19729de860ec',
    name: 'Open Runtimes',
    slug: 'open-runtimes',
    plan: 'pro',
    members: 12,
  },
  {
    $id: '507f191e810c19729de860ed',
    name: 'Side Projects',
    slug: 'side-projects',
    plan: 'free',
    members: 1,
  },
]

export const currentOrganization = organizations[0]

// Organization Members
export interface TeamMember {
  $id: string
  userName: string
  userEmail: string
  avatar?: string
  role:
    | 'owner'
    | 'admin'
    | 'member'
    | 'developer'
    | 'editor'
    | 'analyst'
    | 'billing'
  roles?: string[] // Full roles array from membership (for resending invitations)
  orgId: string
  joinedAt: string
  status?: 'pending' | 'active' // Membership status
  membershipId?: string // For pending invites, we need the membership ID to resend
  mfaEnabled?: boolean // Whether 2FA is enabled for this user
}

export const orgMembers: TeamMember[] = [
  {
    $id: '507f1f77bcf86cd799439012',
    name: 'Alex Morgan',
    email: 'alex@appwrite.io',
    avatar:
      'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100&h=100&fit=crop&crop=face',
    role: 'owner',
    orgId: '507f191e810c19729de860ea',
    joinedAt: '2023-01-15T10:00:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439013',
    name: 'Sarah Chen',
    email: 'sarah@example.com',
    avatar:
      'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100&h=100&fit=crop&crop=face',
    role: 'admin',
    orgId: '507f191e810c19729de860ea',
    joinedAt: '2023-03-22T14:30:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439014',
    name: 'Mike Johnson',
    email: 'mike@example.com',
    role: 'member',
    orgId: '507f191e810c19729de860ea',
    joinedAt: '2023-06-10T09:15:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439015',
    name: 'Emily Davis',
    email: 'emily@example.com',
    avatar:
      'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=100&h=100&fit=crop&crop=face',
    role: 'member',
    orgId: '507f191e810c19729de860ea',
    joinedAt: '2023-08-05T16:45:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439016',
    name: 'James Wilson',
    email: 'james@example.com',
    role: 'owner',
    orgId: '507f191e810c19729de860eb',
    joinedAt: '2022-11-01T08:00:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439017',
    name: 'Eldad Fux',
    email: 'eldad@appwrite.io',
    avatar:
      'https://images.unsplash.com/photo-1519345182560-3f2917c472ef?w=100&h=100&fit=crop&crop=face',
    role: 'owner',
    orgId: '507f191e810c19729de860eb',
    joinedAt: '2022-06-15T10:00:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439018',
    name: 'Christy Jacob',
    email: 'christy@appwrite.io',
    role: 'admin',
    orgId: '507f191e810c19729de860eb',
    joinedAt: '2022-08-20T14:30:00Z',
  },
  {
    $id: '507f1f77bcf86cd799439019',
    name: 'Torsten Dittmann',
    email: 'torsten@appwrite.io',
    avatar:
      'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=100&h=100&fit=crop&crop=face',
    role: 'admin',
    orgId: '507f191e810c19729de860eb',
    joinedAt: '2022-09-10T09:15:00Z',
  },
]

// Teams
export const teams: Team[] = [
  {
    $id: '507f1f77bcf86cd799439020',
    name: 'Personal Projects',
    color: 'from-orange-400 to-pink-500',
    members: 3,
    orgId: '507f191e810c19729de860ea',
  },
  {
    $id: '507f1f77bcf86cd799439021',
    name: 'Appwrite',
    color: 'from-pink-500 to-red-500',
    members: 12,
    orgId: '507f191e810c19729de860eb',
  },
  {
    $id: '507f1f77bcf86cd799439022',
    name: 'Open Runtimes',
    color: 'from-blue-400 to-violet-500',
    members: 8,
    orgId: '507f191e810c19729de860ec',
  },
]

export const currentTeam = teams[0]

// Projects
export const projects: Project[] = [
  // Personal Projects team
  {
    $id: '507f1f77bcf86cd799439030',
    name: 'Acme Dashboard',
    teamId: '507f1f77bcf86cd799439020',
    region: 'Frankfurt',
    createdAt: '2024-01-15T10:30:00Z',
    icon: 'A',
  },
  {
    $id: '507f1f77bcf86cd799439031',
    name: 'TravelBuddy',
    teamId: '507f1f77bcf86cd799439020',
    region: 'New York',
    createdAt: '2024-02-20T14:45:00Z',
    icon: 'T',
  },
  {
    $id: '507f1f77bcf86cd799439032',
    name: 'FitTrack Pro',
    teamId: '507f1f77bcf86cd799439020',
    region: 'Singapore',
    createdAt: '2024-03-10T09:15:00Z',
    icon: 'F',
  },
  {
    $id: '507f1f77bcf86cd799439033',
    name: 'ShopEase',
    teamId: '507f1f77bcf86cd799439020',
    region: 'Frankfurt',
    createdAt: '2024-03-12T11:20:00Z',
    icon: 'S',
  },
  {
    $id: '507f1f77bcf86cd799439034',
    name: 'TaskFlow',
    teamId: '507f1f77bcf86cd799439020',
    region: 'New York',
    createdAt: '2024-03-14T08:30:00Z',
    icon: 'T',
  },
  {
    $id: '507f1f77bcf86cd799439035',
    name: 'ChatConnect',
    teamId: '507f1f77bcf86cd799439020',
    region: 'Singapore',
    createdAt: '2024-03-15T16:45:00Z',
    icon: 'C',
  },
  // Appwrite team projects
  {
    $id: '507f1f77bcf86cd799439036',
    name: 'Console',
    teamId: '507f1f77bcf86cd799439021',
    region: 'Frankfurt',
    createdAt: '2023-06-10T08:00:00Z',
    icon: 'C',
  },
  {
    $id: '507f1f77bcf86cd799439037',
    name: 'Website',
    teamId: '507f1f77bcf86cd799439021',
    region: 'Frankfurt',
    createdAt: '2023-07-15T10:30:00Z',
    icon: 'W',
  },
  {
    $id: '507f1f77bcf86cd799439038',
    name: 'Cloud Functions',
    teamId: '507f1f77bcf86cd799439021',
    region: 'New York',
    createdAt: '2023-08-20T14:00:00Z',
    icon: 'F',
  },
  {
    $id: '507f1f77bcf86cd799439039',
    name: 'SDK Generator',
    teamId: '507f1f77bcf86cd799439021',
    region: 'Frankfurt',
    createdAt: '2023-09-05T09:15:00Z',
    icon: 'S',
  },
  {
    $id: '507f1f77bcf86cd79943903a',
    name: 'Documentation',
    teamId: '507f1f77bcf86cd799439021',
    region: 'Singapore',
    createdAt: '2023-10-12T11:45:00Z',
    icon: 'D',
  },
  // Open Runtimes team projects
  {
    $id: '507f1f77bcf86cd79943903b',
    name: 'Executor',
    teamId: '507f1f77bcf86cd799439022',
    region: 'Frankfurt',
    createdAt: '2023-11-01T08:30:00Z',
    icon: 'E',
  },
  {
    $id: '507f1f77bcf86cd79943903c',
    name: 'Proxy Service',
    teamId: '507f1f77bcf86cd799439022',
    region: 'New York',
    createdAt: '2023-11-15T10:00:00Z',
    icon: 'P',
  },
  {
    $id: '507f1f77bcf86cd79943903d',
    name: 'Runtime Engine',
    teamId: '507f1f77bcf86cd799439022',
    region: 'Singapore',
    createdAt: '2023-12-01T14:30:00Z',
    icon: 'R',
  },
  {
    $id: '507f1f77bcf86cd79943903e',
    name: 'Benchmarks',
    teamId: '507f1f77bcf86cd799439022',
    region: 'Frankfurt',
    createdAt: '2024-01-10T09:00:00Z',
    icon: 'B',
  },
]

// Archived projects
export const archivedProjects: Project[] = [
  {
    $id: '507f1f77bcf86cd79943903f',
    name: 'Legacy API',
    teamId: '507f1f77bcf86cd799439020',
    region: 'Frankfurt',
    createdAt: '2022-05-10T08:00:00Z',
    icon: 'L',
    archived: true,
  },
  {
    $id: '507f1f77bcf86cd799439040',
    name: 'Old Dashboard',
    teamId: '507f1f77bcf86cd799439020',
    region: 'New York',
    createdAt: '2022-08-15T10:30:00Z',
    icon: 'O',
    archived: true,
  },
  {
    $id: '507f1f77bcf86cd799439041',
    name: 'Test Project',
    teamId: '507f1f77bcf86cd799439021',
    region: 'Singapore',
    createdAt: '2023-01-20T14:00:00Z',
    icon: 'T',
    archived: true,
  },
]

export const currentProject = projects[0]

// Databases
export const databases: Database[] = [
  {
    $id: '507f1f77bcf86cd799439050',
    name: 'Production',
    tables: 12,
    rows: 45892,
  },
  { $id: '507f1f77bcf86cd799439051', name: 'Staging', tables: 12, rows: 1250 },
  {
    $id: '507f1f77bcf86cd799439052',
    name: 'Analytics',
    tables: 5,
    rows: 128450,
  },
]

// Collections (Tables)
export const collections: Collection[] = [
  {
    $id: '507f1f77bcf86cd799439060',
    name: 'users',
    databaseId: '507f1f77bcf86cd799439050',
    rows: 12450,
    columns: 12,
    indexes: 3,
  },
  {
    $id: '507f1f77bcf86cd799439061',
    name: 'products',
    databaseId: '507f1f77bcf86cd799439050',
    rows: 3420,
    columns: 18,
    indexes: 5,
  },
  {
    $id: '507f1f77bcf86cd799439062',
    name: 'orders',
    databaseId: '507f1f77bcf86cd799439050',
    rows: 28750,
    columns: 16,
    indexes: 4,
  },
  {
    $id: '507f1f77bcf86cd799439063',
    name: 'reviews',
    databaseId: '507f1f77bcf86cd799439050',
    rows: 8920,
    columns: 10,
    indexes: 2,
  },
  {
    $id: '507f1f77bcf86cd799439064',
    name: 'categories',
    databaseId: '507f1f77bcf86cd799439050',
    rows: 156,
    columns: 10,
    indexes: 1,
  },
]

// Storage Buckets
export const buckets: StorageBucket[] = [
  {
    $id: '507f1f77bcf86cd799439070',
    name: 'Product Images',
    files: 3420,
    size: 2.4 * 1024 * 1024 * 1024,
    maxSize: '5 GB',
  },
  {
    $id: '507f1f77bcf86cd799439071',
    name: 'User Avatars',
    files: 12450,
    size: 890 * 1024 * 1024,
    maxSize: '2 GB',
  },
  {
    $id: '507f1f77bcf86cd799439072',
    name: 'Documents',
    files: 856,
    size: 1.2 * 1024 * 1024 * 1024,
    maxSize: '10 GB',
  },
]

// Functions
export const functions: Function[] = [
  {
    $id: '507f1f77bcf86cd799439080',
    name: 'send-notification',
    runtime: 'Node.js 18',
    executions: 45230,
    status: 'active',
    avgDuration: 245,
  },
  {
    $id: '507f1f77bcf86cd799439081',
    name: 'process-payment',
    runtime: 'Node.js 18',
    executions: 12890,
    status: 'active',
    avgDuration: 890,
  },
  {
    $id: '507f1f77bcf86cd799439082',
    name: 'generate-report',
    runtime: 'Python 3.11',
    executions: 890,
    status: 'active',
    avgDuration: 1250,
  },
  {
    $id: '507f1f77bcf86cd799439083',
    name: 'cleanup-old-data',
    runtime: 'Node.js 18',
    executions: 30,
    status: 'inactive',
    avgDuration: 3200,
  },
]

// Sites
export const sites: Site[] = [
  {
    $id: '507f1f77bcf86cd799439090',
    name: 'Marketing Website',
    framework: 'next',
    status: 'active',
    domains: ['www.acme.com', 'acme.com'],
    lastDeployment: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
    branch: 'main',
    repository: 'acme/marketing-site',
    buildTime: 45,
    visits: 125400,
  },
  {
    $id: '507f1f77bcf86cd799439091',
    name: 'Documentation',
    framework: 'astro',
    status: 'active',
    domains: ['docs.acme.com'],
    lastDeployment: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
    branch: 'main',
    repository: 'acme/docs',
    buildTime: 32,
    visits: 89200,
  },
  {
    $id: '507f1f77bcf86cd799439092',
    name: 'Customer Portal',
    framework: 'react',
    status: 'building',
    domains: ['portal.acme.com'],
    lastDeployment: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
    branch: 'feature/new-dashboard',
    repository: 'acme/customer-portal',
    buildTime: 0,
    visits: 45600,
  },
  {
    $id: '507f1f77bcf86cd799439093',
    name: 'Blog',
    framework: 'nuxt',
    status: 'active',
    domains: ['blog.acme.com'],
    lastDeployment: new Date(
      Date.now() - 3 * 24 * 60 * 60 * 1000,
    ).toISOString(),
    branch: 'main',
    repository: 'acme/blog',
    buildTime: 28,
    visits: 67800,
  },
  {
    $id: '507f1f77bcf86cd799439094',
    name: 'Landing Pages',
    framework: 'static',
    status: 'failed',
    domains: ['promo.acme.com'],
    lastDeployment: new Date(Date.now() - 1 * 60 * 60 * 1000).toISOString(),
    branch: 'main',
    repository: 'acme/landing-pages',
    buildTime: 0,
    visits: 23400,
  },
  {
    $id: '507f1f77bcf86cd799439095',
    name: 'Admin Dashboard',
    framework: 'svelte',
    status: 'inactive',
    domains: ['admin.acme.com'],
    lastDeployment: new Date(
      Date.now() - 14 * 24 * 60 * 60 * 1000,
    ).toISOString(),
    branch: 'main',
    repository: 'acme/admin',
    buildTime: 18,
    visits: 12300,
  },
]

// Users
export const users: User[] = [
  {
    $id: '507f1f77bcf86cd7994390a0',
    name: 'John Doe',
    email: 'john@example.com',
    status: 'verified',
    createdAt: '2024-01-20T10:30:00Z',
  },
  {
    $id: '507f1f77bcf86cd7994390a1',
    name: 'Sarah Wilson',
    email: 'sarah@example.com',
    status: 'verified',
    createdAt: '2024-02-15T14:45:00Z',
  },
  {
    $id: '507f1f77bcf86cd7994390a2',
    name: 'Mike Chen',
    email: 'mike@example.com',
    status: 'unverified',
    createdAt: '2024-03-01T09:15:00Z',
  },
  {
    $id: '507f1f77bcf86cd7994390a3',
    name: 'Emily Brown',
    email: 'emily@example.com',
    status: 'verified',
    createdAt: '2024-03-10T16:20:00Z',
  },
]

// Activity Feed
export const activityEvents: ActivityEvent[] = [
  {
    $id: '507f1f77bcf86cd7994390b0',
    type: 'create',
    resource: 'New order #28751',
    resourceType: 'document',
    timestamp: '2024-03-15T14:32:00Z',
    userId: '507f1f77bcf86cd7994390a0',
    userName: 'John Doe',
  },
  {
    $id: '507f1f77bcf86cd7994390b1',
    type: 'upload',
    resource: 'product-hero.jpg',
    resourceType: 'file',
    timestamp: '2024-03-15T14:28:00Z',
    userId: '507f1f77bcf86cd7994390a1',
    userName: 'Sarah Wilson',
  },
  {
    $id: '507f1f77bcf86cd7994390b2',
    type: 'execute',
    resource: 'send-notification',
    resourceType: 'function',
    timestamp: '2024-03-15T14:25:00Z',
    userId: '507f1f77bcf86cd7994390a0',
    userName: 'System',
  },
  {
    $id: '507f1f77bcf86cd7994390b3',
    type: 'update',
    resource: 'Product SKU-1234',
    resourceType: 'document',
    timestamp: '2024-03-15T14:20:00Z',
    userId: '507f1f77bcf86cd7994390a1',
    userName: 'Sarah Wilson',
  },
  {
    $id: '507f1f77bcf86cd7994390b4',
    type: 'login',
    resource: 'mike@example.com',
    resourceType: 'user',
    timestamp: '2024-03-15T14:15:00Z',
    userId: '507f1f77bcf86cd7994390a2',
    userName: 'Mike Chen',
  },
  {
    $id: '507f1f77bcf86cd7994390b5',
    type: 'delete',
    resource: 'old-banner.png',
    resourceType: 'file',
    timestamp: '2024-03-15T14:10:00Z',
    userId: '507f1f77bcf86cd7994390a3',
    userName: 'Emily Brown',
  },
  {
    $id: '507f1f77bcf86cd7994390b6',
    type: 'create',
    resource: 'reviews collection',
    resourceType: 'collection',
    timestamp: '2024-03-15T14:05:00Z',
    userId: '507f1f77bcf86cd7994390a0',
    userName: 'John Doe',
  },
  {
    $id: '507f1f77bcf86cd7994390b7',
    type: 'execute',
    resource: 'process-payment',
    resourceType: 'function',
    timestamp: '2024-03-15T14:00:00Z',
    userId: '507f1f77bcf86cd7994390a0',
    userName: 'System',
  },
]

// Usage data for charts (last 7 days)
export const usageData: UsageDataPoint[] = [
  { date: 'Mar 9', requests: 12450, bandwidth: 245, executions: 890 },
  { date: 'Mar 10', requests: 15230, bandwidth: 312, executions: 1020 },
  { date: 'Mar 11', requests: 14100, bandwidth: 289, executions: 945 },
  { date: 'Mar 12', requests: 18920, bandwidth: 398, executions: 1250 },
  { date: 'Mar 13', requests: 16780, bandwidth: 356, executions: 1180 },
  { date: 'Mar 14', requests: 21450, bandwidth: 445, executions: 1420 },
  { date: 'Mar 15', requests: 19200, bandwidth: 402, executions: 1350 },
]

// Dashboard stats
export const dashboardStats = {
  totalRequests: 118130,
  requestsChange: 12.5,
  totalBandwidth: 2.45, // GB
  bandwidthChange: 8.3,
  totalDocuments: 175542,
  documentsChange: 5.2,
  totalStorage: 4.49, // GB
  storageChange: 3.1,
  totalUsers: 12450,
  usersChange: 15.8,
  totalExecutions: 8055,
  executionsChange: 22.4,
  totalGbHours: 156.8, // GB-hours
  gbHoursChange: 18.2,
}

// Billing-related interfaces
export interface Coupon {
  $id: string
  code: string
  total: number
  remaining: number
  currency: string
  expiresAt: string
  appliedAt: string
}

// Billing mock data
export const planDetails: PlanDetails = {
  name: 'Pro',
  price: 15,
  billingCycle: 'monthly',
  nextPaymentDate: new Date(
    Date.now() + 15 * 24 * 60 * 60 * 1000,
  ).toISOString(),
  cycleStart: new Date(Date.now() - 15 * 24 * 60 * 60 * 1000).toISOString(),
  cycleEnd: new Date(Date.now() + 15 * 24 * 60 * 60 * 1000).toISOString(),
  basePrice: 15,
  orgAddons: [
    { name: 'Additional projects', quantity: 3, unitPrice: 15, total: 45 },
    { name: 'Additional members', quantity: 2, unitPrice: 15, total: 30 },
  ],
  projectBreakdowns: [
    {
      projectId: '507f1f77bcf86cd799439030',
      projectName: 'Acme Dashboard',
      addons: [
        { name: 'Bandwidth', usage: '150 GB', unitPrice: 0.1, total: 15 },
        { name: 'MAU', usage: '25K users', unitPrice: 0.02, total: 10 },
        {
          name: 'Function executions',
          usage: '500K',
          unitPrice: 0.5,
          total: 12.5,
        },
      ],
      total: 37.5,
    },
    {
      projectId: '507f1f77bcf86cd799439031',
      projectName: 'TravelBuddy',
      addons: [
        { name: 'Bandwidth', usage: '80 GB', unitPrice: 0.1, total: 8 },
        { name: 'Storage', usage: '50 GB', unitPrice: 0.25, total: 12.5 },
      ],
      total: 20.5,
    },
    {
      projectId: '507f1f77bcf86cd799439032',
      projectName: 'FitTrack Pro',
      addons: [{ name: 'MAU', usage: '15K users', unitPrice: 0.02, total: 6 }],
      total: 6,
    },
    {
      projectId: '507f1f77bcf86cd799439033',
      projectName: 'ShopEase',
      addons: [],
      total: 0,
    },
  ],
  additionalCharges: [
    { name: 'Additional members', quantity: 3, unitPrice: 15, total: 45 },
    { name: 'Additional compute', quantity: 50, unitPrice: 0.5, total: 25 },
    { name: 'Additional bandwidth', quantity: 100, unitPrice: 0.1, total: 10 },
    { name: 'Additional storage', quantity: 20, unitPrice: 0.25, total: 5 },
  ],
}

export const invoices: Invoice[] = [
  {
    $id: '507f1f77bcf86cd7994390c0',
    invoiceNumber: 'INV-2024-0315',
    dueDate: new Date(Date.now() - 15 * 24 * 60 * 60 * 1000).toISOString(),
    paidDate: new Date(Date.now() - 15 * 24 * 60 * 60 * 1000).toISOString(),
    status: 'paid',
    amount: 95,
    currency: 'USD',
    downloadUrl: '#',
  },
  {
    $id: '507f1f77bcf86cd7994390c1',
    invoiceNumber: 'INV-2024-0215',
    dueDate: new Date(Date.now() - 45 * 24 * 60 * 60 * 1000).toISOString(),
    paidDate: new Date(Date.now() - 44 * 24 * 60 * 60 * 1000).toISOString(),
    status: 'paid',
    amount: 85,
    currency: 'USD',
    downloadUrl: '#',
  },
  {
    $id: '507f1f77bcf86cd7994390c2',
    invoiceNumber: 'INV-2024-0115',
    dueDate: new Date(Date.now() - 75 * 24 * 60 * 60 * 1000).toISOString(),
    paidDate: new Date(Date.now() - 75 * 24 * 60 * 60 * 1000).toISOString(),
    status: 'paid',
    amount: 75,
    currency: 'USD',
    downloadUrl: '#',
  },
  {
    $id: '507f1f77bcf86cd7994390c3',
    invoiceNumber: 'INV-2023-1215',
    dueDate: new Date(Date.now() - 105 * 24 * 60 * 60 * 1000).toISOString(),
    paidDate: new Date(Date.now() - 105 * 24 * 60 * 60 * 1000).toISOString(),
    status: 'paid',
    amount: 65,
    currency: 'USD',
    downloadUrl: '#',
  },
  {
    $id: '507f1f77bcf86cd7994390c4',
    invoiceNumber: 'INV-2023-1115',
    dueDate: new Date(Date.now() - 135 * 24 * 60 * 60 * 1000).toISOString(),
    paidDate: new Date(Date.now() - 134 * 24 * 60 * 60 * 1000).toISOString(),
    status: 'paid',
    amount: 55,
    currency: 'USD',
    downloadUrl: '#',
  },
]

export const paymentMethods: PaymentMethod[] = [
  {
    $id: '507f1f77bcf86cd7994390d0',
    type: 'card',
    isPrimary: true,
    last4: '4242',
    brand: 'Visa',
    expiryMonth: 12,
    expiryYear: 2026,
    createdAt: '2023-06-15T10:00:00Z',
  },
  {
    $id: '507f1f77bcf86cd7994390d1',
    type: 'card',
    isPrimary: false,
    last4: '5555',
    brand: 'Mastercard',
    expiryMonth: 8,
    expiryYear: 2025,
    createdAt: '2024-01-20T14:30:00Z',
  },
]

export const billingAddress: BillingAddress = {
  $id: '507f1f77bcf86cd7994390e0',
  name: 'Alex Morgan',
  company: 'Acme Inc.',
  addressLine1: '123 Innovation Drive',
  addressLine2: 'Suite 400',
  city: 'San Francisco',
  state: 'CA',
  postalCode: '94105',
  country: 'United States',
}

export const taxId: TaxId = {
  $id: '507f1f77bcf86cd7994390e1',
  type: 'ein',
  value: '12-3456789',
  country: 'United States',
  verified: true,
}

export const billingAlerts: BillingAlert[] = [
  { $id: '507f1f77bcf86cd7994390e2', threshold: 50, enabled: true },
  { $id: '507f1f77bcf86cd7994390e3', threshold: 75, enabled: true },
  { $id: '507f1f77bcf86cd7994390e4', threshold: 90, enabled: false },
]

export const availableCredits = {
  amount: 125.5,
  currency: 'USD',
  expiresAt: new Date(Date.now() + 90 * 24 * 60 * 60 * 1000).toISOString(),
}

export const coupons: Coupon[] = [
  {
    $id: '507f1f77bcf86cd7994390f0',
    code: 'WELCOME50',
    total: 50,
    remaining: 25.5,
    currency: 'USD',
    expiresAt: new Date(Date.now() + 60 * 24 * 60 * 60 * 1000).toISOString(),
    appliedAt: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    $id: '507f1f77bcf86cd7994390f1',
    code: 'PROMO2024',
    total: 100,
    remaining: 100,
    currency: 'USD',
    expiresAt: new Date(Date.now() + 120 * 24 * 60 * 60 * 1000).toISOString(),
    appliedAt: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    $id: '507f1f77bcf86cd7994390f2',
    code: 'DEVCONF23',
    total: 25,
    remaining: 0,
    currency: 'USD',
    expiresAt: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString(),
    appliedAt: new Date(Date.now() - 60 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    $id: '507f1f77bcf86cd7994390f3',
    code: 'STARTUP100',
    total: 100,
    remaining: 75,
    currency: 'USD',
    expiresAt: new Date(Date.now() + 180 * 24 * 60 * 60 * 1000).toISOString(),
    appliedAt: new Date(Date.now() - 14 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    $id: '507f1f77bcf86cd7994390f4',
    code: 'PARTNER25',
    total: 25,
    remaining: 10,
    currency: 'USD',
    expiresAt: new Date(Date.now() + 45 * 24 * 60 * 60 * 1000).toISOString(),
    appliedAt: new Date(Date.now() - 45 * 24 * 60 * 60 * 1000).toISOString(),
  },
]

export const budgetCap = {
  enabled: false,
  limit: 100,
  currency: 'USD',
}

// Firewall-related interfaces
export interface FirewallRule {
  $id: string
  name: string
  description?: string
  action: 'block' | 'allow' | 'challenge'
  priority: number
  enabled: boolean
  conditions: {
    ipAddress?: string
    userAgent?: string
    path?: string
    method?: string
    country?: string
  }
  rateLimit?: {
    enabled: boolean
    requests: number
    window: number // seconds
  }
  createdAt: string
  updatedAt: string
  stats: {
    totalRequests: number
    blocked: number
    allowed: number
    challenged: number
    lastTriggered: string | null
  }
}

export interface FirewallAnalytics {
  totalRequests: number
  totalBlocked: number
  totalAllowed: number
  totalChallenged: number
  requestsChange: number
  blockedChange: number
  allowedChange: number
  blockRateChange: number
  timeSeries: Array<{
    timestamp: string
    requests: number
    blocked: number
    allowed: number
    challenged: number
  }>
  topBlockedIPs: Array<{
    ip: string
    count: number
  }>
  topBlockedCountries: Array<{
    country: string
    count: number
  }>
}

export interface FirewallLog {
  $id: string
  timestamp: string
  action: 'block' | 'allow' | 'challenge'
  ipAddress: string
  path?: string
  method?: string
  statusCode?: number
  ruleId?: string
  ruleName?: string
  country?: string
  userAgent?: string
}

// Firewall mock data
export const mockFirewallRules: FirewallRule[] = [
  {
    $id: 'rule_001',
    name: 'Block suspicious IPs',
    description: 'Blocks requests from known malicious IP addresses',
    action: 'block',
    priority: 100,
    enabled: true,
    conditions: {
      ipAddress: '192.168.1.100,10.0.0.50',
    },
    createdAt: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString(),
    updatedAt: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString(),
    stats: {
      totalRequests: 12450,
      blocked: 12450,
      allowed: 0,
      challenged: 0,
      lastTriggered: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
    },
  },
  {
    $id: 'rule_002',
    name: 'Rate limit API endpoints',
    description: 'Limits API requests to prevent abuse',
    action: 'challenge',
    priority: 90,
    enabled: true,
    conditions: {
      path: '/api/*',
    },
    rateLimit: {
      enabled: true,
      requests: 100,
      window: 60,
    },
    createdAt: new Date(Date.now() - 5 * 24 * 60 * 60 * 1000).toISOString(),
    updatedAt: new Date(Date.now() - 1 * 24 * 60 * 60 * 1000).toISOString(),
    stats: {
      totalRequests: 45230,
      blocked: 0,
      allowed: 39800,
      challenged: 5430,
      lastTriggered: new Date(Date.now() - 2 * 60 * 1000).toISOString(),
    },
  },
  {
    $id: 'rule_003',
    name: 'Block specific countries',
    description: 'Blocks requests from restricted countries',
    action: 'block',
    priority: 80,
    enabled: true,
    conditions: {
      country: 'CN,RU',
    },
    createdAt: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString(),
    updatedAt: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString(),
    stats: {
      totalRequests: 8920,
      blocked: 8920,
      allowed: 0,
      challenged: 0,
      lastTriggered: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
    },
  },
  {
    $id: 'rule_004',
    name: 'Allow admin panel',
    description: 'Allows access to admin panel from specific IPs',
    action: 'allow',
    priority: 70,
    enabled: true,
    conditions: {
      path: '/admin/*',
      ipAddress: '203.0.113.0/24',
    },
    createdAt: new Date(Date.now() - 10 * 24 * 60 * 60 * 1000).toISOString(),
    updatedAt: new Date(Date.now() - 4 * 24 * 60 * 60 * 1000).toISOString(),
    stats: {
      totalRequests: 3420,
      blocked: 0,
      allowed: 3420,
      challenged: 0,
      lastTriggered: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
    },
  },
  {
    $id: 'rule_005',
    name: 'Block malicious user agents',
    description: 'Blocks requests with suspicious user agents',
    action: 'block',
    priority: 60,
    enabled: false,
    conditions: {
      userAgent: '*bot*,*crawler*,*scraper*',
    },
    createdAt: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString(),
    updatedAt: new Date(Date.now() - 1 * 24 * 60 * 60 * 1000).toISOString(),
    stats: {
      totalRequests: 0,
      blocked: 0,
      allowed: 0,
      challenged: 0,
      lastTriggered: null,
    },
  },
]

// Generate time series data for the last 24 hours
const generateTimeSeries = () => {
  const now = Date.now()
  const hours = 24
  const points = []

  for (let i = hours; i >= 0; i--) {
    const timestamp = new Date(now - i * 60 * 60 * 1000).toISOString()
    const baseRequests = 100 + Math.random() * 200
    const blocked = Math.floor(baseRequests * (0.1 + Math.random() * 0.2))
    const challenged = Math.floor(baseRequests * (0.05 + Math.random() * 0.1))
    const allowed = Math.floor(baseRequests - blocked - challenged)

    points.push({
      timestamp,
      requests: Math.floor(baseRequests),
      blocked,
      allowed,
      challenged,
    })
  }

  return points
}

export const mockFirewallAnalytics: FirewallAnalytics = {
  totalRequests: 245680,
  totalBlocked: 34250,
  totalAllowed: 198430,
  totalChallenged: 13000,
  requestsChange: 12.5,
  blockedChange: 8.3,
  allowedChange: 15.2,
  blockRateChange: -3.2,
  timeSeries: generateTimeSeries(),
  topBlockedIPs: [
    { ip: '192.168.1.100', count: 12450 },
    { ip: '10.0.0.50', count: 8920 },
    { ip: '203.0.113.45', count: 5430 },
    { ip: '198.51.100.10', count: 3420 },
    { ip: '203.0.113.100', count: 2100 },
  ],
  topBlockedCountries: [
    { country: 'China', count: 15230 },
    { country: 'Russia', count: 8920 },
    { country: 'United States', count: 5430 },
    { country: 'Germany', count: 3420 },
    { country: 'France', count: 2100 },
  ],
}

export const mockFirewallLogs: FirewallLog[] = [
  {
    $id: 'log_001',
    timestamp: new Date(Date.now() - 2 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '192.168.1.100',
    path: '/api/users',
    method: 'GET',
    statusCode: 403,
    ruleId: 'rule_001',
    ruleName: 'Block suspicious IPs',
    country: 'United States',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
  },
  {
    $id: 'log_002',
    timestamp: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
    action: 'challenge',
    ipAddress: '203.0.113.45',
    path: '/api/products',
    method: 'GET',
    statusCode: 429,
    ruleId: 'rule_002',
    ruleName: 'Rate limit API endpoints',
    country: 'Germany',
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
  },
  {
    $id: 'log_003',
    timestamp: new Date(Date.now() - 8 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '10.0.0.50',
    path: '/api/orders',
    method: 'POST',
    statusCode: 403,
    ruleId: 'rule_001',
    ruleName: 'Block suspicious IPs',
    country: 'United States',
    userAgent: 'curl/7.68.0',
  },
  {
    $id: 'log_004',
    timestamp: new Date(Date.now() - 12 * 60 * 1000).toISOString(),
    action: 'allow',
    ipAddress: '203.0.113.10',
    path: '/admin/dashboard',
    method: 'GET',
    statusCode: 200,
    ruleId: 'rule_004',
    ruleName: 'Allow admin panel',
    country: 'United States',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
  },
  {
    $id: 'log_005',
    timestamp: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '198.51.100.10',
    path: '/api/auth/login',
    method: 'POST',
    statusCode: 403,
    ruleId: 'rule_003',
    ruleName: 'Block specific countries',
    country: 'China',
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
  },
  {
    $id: 'log_006',
    timestamp: new Date(Date.now() - 20 * 60 * 1000).toISOString(),
    action: 'challenge',
    ipAddress: '203.0.113.100',
    path: '/api/users',
    method: 'GET',
    statusCode: 429,
    ruleId: 'rule_002',
    ruleName: 'Rate limit API endpoints',
    country: 'France',
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
  },
  {
    $id: 'log_007',
    timestamp: new Date(Date.now() - 25 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '192.168.1.200',
    path: '/api/products',
    method: 'GET',
    statusCode: 403,
    ruleId: 'rule_001',
    ruleName: 'Block suspicious IPs',
    country: 'United States',
    userAgent: 'python-requests/2.28.0',
  },
  {
    $id: 'log_008',
    timestamp: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
    action: 'allow',
    ipAddress: '203.0.113.20',
    path: '/api/health',
    method: 'GET',
    statusCode: 200,
    country: 'United States',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
  },
  {
    $id: 'log_009',
    timestamp: new Date(Date.now() - 35 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '198.51.100.20',
    path: '/api/orders',
    method: 'GET',
    statusCode: 403,
    ruleId: 'rule_003',
    ruleName: 'Block specific countries',
    country: 'Russia',
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
  },
  {
    $id: 'log_010',
    timestamp: new Date(Date.now() - 40 * 60 * 1000).toISOString(),
    action: 'challenge',
    ipAddress: '203.0.113.50',
    path: '/api/users',
    method: 'GET',
    statusCode: 429,
    ruleId: 'rule_002',
    ruleName: 'Rate limit API endpoints',
    country: 'Germany',
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
  },
  {
    $id: 'log_011',
    timestamp: new Date(Date.now() - 45 * 60 * 1000).toISOString(),
    action: 'allow',
    ipAddress: '203.0.113.15',
    path: '/admin/settings',
    method: 'GET',
    statusCode: 200,
    ruleId: 'rule_004',
    ruleName: 'Allow admin panel',
    country: 'United States',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
  },
  {
    $id: 'log_012',
    timestamp: new Date(Date.now() - 50 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '192.168.1.150',
    path: '/api/auth/register',
    method: 'POST',
    statusCode: 403,
    ruleId: 'rule_001',
    ruleName: 'Block suspicious IPs',
    country: 'United States',
    userAgent: 'PostmanRuntime/7.29.0',
  },
  {
    $id: 'log_013',
    timestamp: new Date(Date.now() - 55 * 60 * 1000).toISOString(),
    action: 'block',
    ipAddress: '198.51.100.30',
    path: '/api/products',
    method: 'GET',
    statusCode: 403,
    ruleId: 'rule_003',
    ruleName: 'Block specific countries',
    country: 'China',
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
  },
  {
    $id: 'log_014',
    timestamp: new Date(Date.now() - 60 * 60 * 1000).toISOString(),
    action: 'challenge',
    ipAddress: '203.0.113.75',
    path: '/api/orders',
    method: 'GET',
    statusCode: 429,
    ruleId: 'rule_002',
    ruleName: 'Rate limit API endpoints',
    country: 'United States',
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
  },
  {
    $id: 'log_015',
    timestamp: new Date(Date.now() - 65 * 60 * 1000).toISOString(),
    action: 'allow',
    ipAddress: '203.0.113.25',
    path: '/api/health',
    method: 'GET',
    statusCode: 200,
    country: 'United States',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
  },
]

// Navigation items
export const navItems = [
  { id: 'overview', label: 'Overview', icon: 'LayoutDashboard' },
  { id: 'databases', label: 'Databases', icon: 'Database' },
  { id: 'auth', label: 'Auth', icon: 'Users' },
  { id: 'storage', label: 'Storage', icon: 'Folder' },
  { id: 'functions', label: 'Functions', icon: 'Zap' },
  { id: 'messaging', label: 'Messaging', icon: 'MessageSquare' },
  { id: 'realtime', label: 'Realtime', icon: 'Radio' },
] as const

export const settingsNavItems = [
  { id: 'settings', label: 'Settings', icon: 'Settings' },
  { id: 'usage', label: 'Usage', icon: 'BarChart3' },
  { id: 'webhooks', label: 'Webhooks', icon: 'Webhook' },
  { id: 'api-keys', label: 'API Keys', icon: 'Key' },
] as const

// Helper functions
export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1000
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

export function formatNumber(num: number): string {
  if (num >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  }
  if (num >= 1000) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return num.toString()
}

export function getRelativeTime(timestamp: string): string {
  const now = new Date()
  const then = new Date(timestamp)
  const diffMs = now.getTime() - then.getTime()
  const diffMins = Math.floor(diffMs / 60000)

  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`

  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`

  const diffDays = Math.floor(diffHours / 24)
  return `${diffDays}d ago`
}
