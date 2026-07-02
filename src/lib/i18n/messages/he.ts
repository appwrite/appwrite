import { enCatalog, type EnCatalog } from './en'

export const heCatalog: EnCatalog = {
  ...enCatalog,
  app: {
    ...enCatalog.app,
    header: {
      ...enCatalog.app.header,
      centerSearchPlaceholder: 'חיפוש בתיעוד',
      marketingNav: {
        ...enCatalog.app.header.marketingNav,
        products: 'מוצרים',
        docs: 'תיעוד',
        pricing: 'תמחור',
        enterprise: 'אנטרפרייז',
        customers: 'לקוחות',
        blog: 'בלוג',
        changelog: 'יומן שינויים',
        websiteNavigation: 'ניווט האתר',
        changelogNewUpdatesAria: 'יומן שינויים, עדכונים חדשים',
      },
      actions: {
        ...enCatalog.app.header.actions,
        openNavigation: 'פתח ניווט',
        openWebsiteNavigation: 'פתח ניווט אתר',
        create: 'יצירה',
        connect: 'חיבור',
        assistant: 'עוזר',
        upgrade: 'שדרוג',
        signIn: 'התחברות',
        signUp: 'הרשמה',
        signOut: 'התנתקות',
        backToOrganization: 'חזרה לארגון',
      },
      createMenu: {
        ...enCatalog.app.header.createMenu,
        newProject: 'פרויקט חדש',
        newOrganization: 'ארגון חדש',
        buildSection: 'בניית המוצר',
        deploySection: 'פריסה',
        newDatabase: 'מסד נתונים חדש',
        newUser: 'משתמש חדש',
        newBucket: 'באקט חדש',
        newFunction: 'פונקציה חדשה',
        newMessage: 'הודעה חדשה',
        newSite: 'אתר חדש',
      },
      permissions: {
        ...enCatalog.app.header.permissions,
        createProjects: 'אין לך הרשאה ליצור פרויקטים.',
        createDatabases: 'אין לך הרשאה ליצור מסדי נתונים.',
        createUsers: 'אין לך הרשאה ליצור משתמשים.',
        createBuckets: 'אין לך הרשאה ליצור באקטים.',
        createFunctions: 'אין לך הרשאה ליצור פונקציות.',
        createTopics: 'אין לך הרשאה ליצור נושאי הודעות.',
        createSites: 'אין לך הרשאה ליצור אתרים.',
      },
      accountMenu: {
        ...enCatalog.app.header.accountMenu,
        user: 'משתמש',
        account: 'חשבון',
        projects: 'פרויקטים',
        domains: 'דומיינים',
        memberSince: 'חבר מאז',
        accountStatus: 'סטטוס חשבון',
        accountId: 'מזהה חשבון',
        copyAccountId: 'העתק מזהה חשבון',
        copied: 'הועתק',
        twoFactor: 'אימות דו-שלבי',
        active: 'פעיל',
        inactive: 'לא פעיל',
        enabled: 'מופעל',
        disabled: 'כבוי',
        console: 'קונסול',
        home: 'בית',
        docs: 'תיעוד',
        changelog: 'יומן שינויים',
        admin: 'ניהול',
        cache: 'מטמון',
        blocks: 'חסימות',
      },
      search: {
        ...enCatalog.app.header.search,
        compactPlaceholder: 'חיפוש...',
      },
    },
    footer: {
      ...enCatalog.app.footer,
      groups: {
        ...enCatalog.app.footer.groups,
        quickStarts: 'התחלות מהירות',
        products: 'מוצרים',
        learn: 'לימוד',
        programs: 'תוכניות',
        about: 'אודות',
        compare: 'השוואה',
      },
      links: {
        ...enCatalog.app.footer.links,
        docs: 'תיעוד',
        store: 'חנות',
        status: 'סטטוס',
        cookieSettings: 'הגדרות עוגיות',
        soc2: 'תאימות SOC 2 Type II',
      },
      social: {
        ...enCatalog.app.footer.social,
        github: 'GitHub',
        x: 'X',
        youtube: 'YouTube',
        linkedIn: 'LinkedIn',
        instagram: 'Instagram',
        discord: 'Discord',
        dailyDevSquad: 'daily.dev Squad',
      },
      expanded: {
        ...enCatalog.app.footer.expanded,
        quickStarts: {
          ...enCatalog.app.footer.expanded.quickStarts,
          web: 'ווב',
          nextjs: 'Next.js',
          react: 'React',
          vue: 'Vue.js',
          nuxt: 'Nuxt',
          svelteKit: 'SvelteKit',
          refine: 'Refine',
          angular: 'Angular',
          reactNative: 'React Native',
          flutter: 'Flutter',
          apple: 'Apple',
          android: 'Android',
          qwik: 'Qwik',
          astro: 'Astro',
          solid: 'Solid',
        },
        products: {
          ...enCatalog.app.footer.expanded.products,
          auth: 'אימות',
          databases: 'מסדי נתונים',
          storage: 'אחסון',
          functions: 'פונקציות',
          messaging: 'הודעות',
          realtime: 'Realtime',
          hosting: 'אירוח',
          network: 'רשת',
        },
        learn: {
          ...enCatalog.app.footer.expanded.learn,
          blog: 'בלוג',
          docs: 'תיעוד',
          integrations: 'אינטגרציות',
          community: 'קהילה',
          init: 'Init',
          threads: 'Threads',
          changelog: 'יומן שינויים',
          roadmap: 'מפת דרכים',
          sourceCode: 'קוד מקור',
          arena: 'Arena',
          techNews: 'חדשות טכנולוגיה',
        },
        programs: {
          ...enCatalog.app.footer.expanded.programs,
          startups: 'סטארטאפים',
          education: 'חינוך',
          partners: 'שותפים',
          enterprise: 'אנטרפרייז',
        },
        about: {
          ...enCatalog.app.footer.expanded.about,
          company: 'החברה',
          pricing: 'תמחור',
          careers: 'קריירה',
          contactUs: 'צור קשר',
          assets: 'נכסים',
          security: 'אבטחה',
        },
        compare: {
          ...enCatalog.app.footer.expanded.compare,
          baas: 'Backend as a service (BaaS)',
        },
      },
    },
    debugMenu: {
      ...enCatalog.app.debugMenu,
      language: {
        ...enCatalog.app.debugMenu.language,
        label: 'שפה',
        autoLabel: 'אוטומטי (דפדפן)',
        autoDescription: 'השתמש בשפת הדפדפן.',
        englishLabel: 'אנגלית',
        englishDescription: 'הצג ממשק באנגלית.',
        hebrewLabel: 'עברית',
        hebrewDescription: 'הצג ממשק בעברית ובכיוון RTL.',
        activeAuto: 'אוטומטי',
        activeEnglish: 'אנגלית',
        activeHebrew: 'עברית (RTL)',
      },
    },
  },
  website: {
    ...enCatalog.website,
    home: {
      ...enCatalog.website.home,
      seoDescription:
        'Appwrite היא פלטפורמת פיתוח בקוד פתוח עם אימות, מסדי נתונים, אחסון, פונקציות, הודעות ואתרים. לבנות כמו צוות של מאות אנשים.', // pragma: allowlist secret
      announcementNew: 'חדש',
      announcementText: 'מכריזים על Presences API',
      heroTitle: 'לבנות מהר יותר ולגדול יותר מאי פעם',
      heroDescription:
        'Appwrite היא פלטפורמת קוד פתוח לבנייה ולהתרחבות מהירה יותר של אפליקציות, עם אימות, מסדי נתונים, אחסון, פונקציות, הודעות, Realtime ואירוח אתרים. הכל במקום אחד.', // pragma: allowlist secret
      heroPreviewWorkspace: 'Appwrite', // pragma: allowlist secret
      heroPreviewOrganization: 'אקמי קורפ',
      heroPreviewProject: 'פרויקט Appwrite ראשון', // pragma: allowlist secret
      heroImageAlt:
        'תצוגת הקונסול של Appwrite עם גרפי שימוש, אפליקציות ומפתחות API', // pragma: allowlist secret
      startProject: 'התחלת פרויקט',
      requestDemo: 'בקשת דמו',
      toolsHeading: 'מותאם לפריימוורקים, לשפות ולסוכני ה-AI שאתם אוהבים',
      aiDocsNavLabel: 'תיעוד AI ו-MCP',
      productsHeadingLineOne: 'כל השירותים שאתם צריכים',
      productsHeadingLineTwo: 'בפלטפורמה אחת',
      productsDescription:
        'לבנות עם מוצרים מודולריים שמרגישים אחידים מהאב-טיפוס הראשון ועד לסקייל בפרודקשן.',
      securityHeading: 'אבטחה מובנית בכל שכבות הארכיטקטורה',
      securityDescription:
        'עם גישה שמתחילה מאבטחה, Appwrite עוזרת לשמור על המוצר ועל המשתמשים בטוחים כברירת מחדל, ומקלה על עמידה במדיניות מחמירה.', // pragma: allowlist secret
      aiDocLinks: {
        ...enCatalog.website.home.aiDocLinks,
        mcpServers: 'שרתי MCP',
        skills: 'יכולות Appwrite', // pragma: allowlist secret
        aiArena: 'זירת AI',
      },
      securityItems: {
        ...enCatalog.website.home.securityItems,
        ddosTitle: 'הגנת DDoS',
        ddosDescription:
          'זיהוי והפחתה אוטומטיים של מתקפות מניעת שירות מבוזרות.',
        encryptionTitle: 'הצפנה',
        encryptionDescription:
          'הצפנת נתונים מובנית עבור עומסי עבודה רגישים במנוחה ובתעבורה.',
        abuseTitle: 'הגנה מפני ניצול לרעה',
        abuseDescription:
          'הגנה על ה-API שלכם מפני ניצול לרעה באמצעות מנגנוני פלטפורמה מובנים.',
        migrationsTitle: 'העברת נתונים',
        migrationsDescription:
          'העברת נתונים מצדדים שלישיים או בין סביבות Cloud ו-Self-hosted.',
        gdprTitle: 'GDPR',
        gdprDescription:
          'תמיכה בתהליכי פרטיות נתונים ובאמצעי הגנה לדרישות GDPR.',
        soc2Title: 'SOC 2',
        soc2Description:
          'עבודה על גבי תשתית שתוכננה לסטנדרטים גבוהים של אבטחה ופרטיות.',
        hipaaTitle: 'HIPAA',
        hipaaDescription:
          'הגנה על נתוני בריאות רגישים עם בקרות מוצר שמבוססות אבטחה.',
        ccpaTitle: 'CCPA',
        ccpaDescription:
          'בנייה עם בקרות שעוזרות להגן על נתונים רגישים של משתמשים.',
      },
      productBento: {
        ...enCatalog.website.home.productBento,
        authTitle: 'אימות',
        databasesTitle: 'מסדי נתונים',
        storageTitle: 'אחסון',
        functionsTitle: 'פונקציות',
        sitesTitle: 'אתרים',
        messagingTitle: 'הודעות',
        firewallTitle: 'חומת אש',
        realtimeTitle: 'Realtime',
        authDescription:
          'אימות מאובטח של משתמשים עם אימייל, SMS, OAuth, סשנים אנונימיים וקישורי קסם.',
        databasesDescription:
          'מודלים, שאילתות וסקייל עם מסדי הנתונים של Appwrite או עם PostgreSQL ו-MySQL ייעודיים, כדי להתאים לתרחיש העבודה ולצורכי הצוות.', // pragma: allowlist secret
        storageDescription:
          'אחסון קבצים עם דחיסה, הצפנה, המרות תמונה ובקרת גישה.',
        functionsDescription:
          'פריסת פונקציות Serverless עם סביבות ריצה מבודדות ומאובטחות והפעלה מבוססת אירועים.',
        sitesDescription:
          'פריסת פרונטאנד סטטי, SSR ו-CSR מ-Git עם תצוגות מקדימות מיידיות ו-Appwrite מאחוריהם.', // pragma: allowlist secret
        messagingDescription:
          'שליחת אימייל, SMS והתראות Push דרך שירות הודעות אחוד.',
        firewallDescription:
          'הגנה על אפליקציות עם כללי תעבורה, בקרות ניצול לרעה ואבטחת קצה לכל פרויקט.',
        realtimeDescription:
          'הרשמה ותגובה לאירועים ברחבי הפרויקט בזמן אמת.',
        firewallNewLabel: 'חדש',
      },
    },
  },
}
