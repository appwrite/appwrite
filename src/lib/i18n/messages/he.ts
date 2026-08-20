import { enCatalog, type EnCatalog } from './en'

export const heCatalog: EnCatalog = {
  ...enCatalog,
  app: {
    ...enCatalog.app,
    header: {
      ...enCatalog.app.header,
      centerSearchPlaceholder: 'חיפוש בדוקומנטציה...',
      marketingNav: {
        ...enCatalog.app.header.marketingNav,
        products: 'מוצרים',
        docs: 'דוקומנטציה',
        pricing: 'מחירים',
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
        assistant: 'Agent',
        upgrade: 'שדרוג',
        signIn: 'התחברו',
        signUp: 'הירשמו',
        signOut: 'התנתקות',
        backToOrganization: 'חזרה לארגון',
      },
      createMenu: {
        ...enCatalog.app.header.createMenu,
        newProject: 'פרויקט חדש',
        newOrganization: 'ארגון חדש',
        newAgent: 'סוכן חדש',
        buildSection: 'בניית המוצר',
        deploySection: 'פריסה',
        protectSection: 'הגנה',
        newDatabase: 'מסד נתונים חדש',
        newUser: 'משתמש חדש',
        newBucket: 'באקט חדש',
        newFunction: 'פונקציה חדשה',
        newMessage: 'הודעה חדשה',
        newFirewallRule: 'כלל Firewall חדש',
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
        createFirewallRules: 'אין לך הרשאה ליצור כללי Firewall.',
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
        // Temporary: remove once the old console is retired
        oldConsole: 'קונסול ישן',
        home: 'בית',
        docs: 'דוקומנטציה',
        changelog: 'יומן שינויים',
        admin: 'ניהול',
        cache: 'Cache',
        blocks: 'Blocks',
        generator: 'Generator',
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
        docs: 'דוקומנטציה',
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
          tanstackStart: 'TanStack Start',
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
          agent: 'Agent',
          hosting: 'אירוח',
          domains: 'דומיינים',
          network: 'רשת',
          firewall: 'Firewall',
        },
        learn: {
          ...enCatalog.app.footer.expanded.learn,
          blog: 'בלוג',
          docs: 'דוקומנטציה',
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
          affiliates: 'אפיליאייטס',
        },
        about: {
          ...enCatalog.app.footer.expanded.about,
          company: 'החברה',
          pricing: 'מחירים',
          careers: 'קריירה',
          contactUs: 'צור קשר',
          assets: 'נכסים',
          security: 'אבטחה',
        },
        compare: {
          ...enCatalog.app.footer.expanded.compare,
          vsSupabase: 'Appwrite מול Supabase', // pragma: allowlist secret
          vsFirebase: 'Appwrite מול Firebase', // pragma: allowlist secret
          vsNeon: 'Appwrite מול Neon', // pragma: allowlist secret
          vsVercel: 'Appwrite מול Vercel', // pragma: allowlist secret
          vsNetlify: 'Appwrite מול Netlify', // pragma: allowlist secret
          vsCloudinary: 'Appwrite מול Cloudinary', // pragma: allowlist secret
          vsAuth0: 'Appwrite מול Auth0', // pragma: allowlist secret
          nextjsHosting: 'אירוח Next.js',
          reactHosting: 'אירוח React',
          vueHosting: 'אירוח Vue.js',
          baas: 'Backend as a service (BaaS)',
        },
      },
    },
    sidebar: {
      ...enCatalog.app.sidebar,
      sections: {
        ...enCatalog.app.sidebar.sections,
        connect: 'חיבור',
        build: 'פיתוח',
        deploy: 'פריסה',
        observe: 'ניטור',
        protect: 'הגנה',
      },
      items: {
        ...enCatalog.app.sidebar.items,
        overview: 'לוח בקרה',
        apps: 'אפליקציות',
        apiKeys: 'מפתחות API',
        explorer: 'אקספלורר',
        auth: 'אימות',
        databases: 'מסדי נתונים',
        storage: 'אחסון',
        functions: 'פונקציות',
        messaging: 'הודעות',
        sites: 'אתרים',
        distribution: 'הפצה',
        activity: 'פעילות',
        realtime: 'Realtime',
        logs: 'לוגים',
        usage: 'שימוש',
        analytics: 'אנליטיקס',
        errors: 'שגיאות',
        firewall: 'חומת אש',
        advisor: 'יועץ',
        settings: 'הגדרות',
      },
      badges: {
        ...enCatalog.app.sidebar.badges,
        soon: 'בקרוב',
      },
      accessibility: {
        ...enCatalog.app.sidebar.accessibility,
        mainNavigation: 'ניווט ראשי',
        mobileNavigation: 'ניווט בנייד',
        closeNavigation: 'סגור ניווט',
        expandSidebar: 'הרחב סרגל צד',
        collapseSidebar: 'כווץ סרגל צד',
        comingSoonSuffix: '(בקרוב)',
      },
      onboarding: {
        ...enCatalog.app.sidebar.onboarding,
        getStarted: 'צעדים ראשונים',
        progress: 'התקדמות',
        loading: 'טוען...',
        of: 'מתוך',
        completed: 'הושלמו',
      },
    },
    nativeAppBar: {
      ...enCatalog.app.nativeAppBar,
      back: 'חזרה',
      forward: 'קדימה',
      history: 'היסטוריה',
      recentPages: 'עמודים אחרונים',
      noRecentPages: 'אין עמודים אחרונים',
      searchPlaceholder: 'חיפוש...',
    },
    consoleBanner: {
      ...enCatalog.app.consoleBanner,
      messagePrefix: 'הפכו רעיונות למוצרים פעילים. Vibe coding מופעל על ידי',
      dismiss: 'הסתר באנר',
    },
    debugMenu: enCatalog.app.debugMenu,
  },
  website: {
    ...enCatalog.website,
    home: {
      ...enCatalog.website.home,
      seoDescription:
        'Appwrite היא פלטפורמת פיתוח בקוד פתוח עם אימות, מסדי נתונים, אחסון, פונקציות, הודעות ואתרים. לבנות כמו צוות של מאות מפתחים.', // pragma: allowlist secret
      announcementNew: 'Breaking',
      announcementText: 'אנחנו נרגשים להכריז על Appwrite 2.0',
      heroTitleLineOne: 'לבנות מהר יותר,',
      heroTitleLineTwo: 'לצמוח רחוק מאי פעם',
      heroDescription:
        'Appwrite היא פלטפורמת קוד פתוח לבנייה ולהרחבה מהירה של אפליקציות, עם אימות, מסדי נתונים, אחסון, פונקציות, הודעות, Realtime ואירוח אתרים. הכל במקום אחד.', // pragma: allowlist secret
      heroPreviewWorkspace: 'Appwrite', // pragma: allowlist secret
      heroPreviewOrganization: 'Acme Corp',
      heroPreviewProject: 'פרויקט Appwrite ראשון', // pragma: allowlist secret
      heroImageAlt:
        'תצוגת הקונסול של Appwrite עם גרפי שימוש, אפליקציות ומפתחות API', // pragma: allowlist secret
      startProject: 'התחילו פרויקט',
      requestDemo: 'בקשו דמו',
      toolsHeading: 'מותאם לפריימוורקים, לשפות ולסוכני ה-AI שאתם אוהבים',
      aiDocsNavLabel: 'דוקומנטציית AI ו-MCP',
      productsHeadingLineOne: 'כל השירותים שאתם צריכים',
      productsHeadingLineTwo: 'בפלטפורמה אחת',
      productsDescription:
        'לבנות עם מוצרים מודולריים שמרגישים אחידים, מהאב-טיפוס הראשון ועד לקנה מידה מלא בפרודקשן.',
      securityHeading: 'אבטחה מובנית בכל שכבות הארכיטקטורה',
      securityDescription:
        'עם גישה שמתחילה מאבטחה, Appwrite עוזרת לשמור על המוצר ועל המשתמשים בטוחים כברירת מחדל, ומקלה על עמידה במדיניות מחמירה.', // pragma: allowlist secret
      aiDocLinks: {
        ...enCatalog.website.home.aiDocLinks,
        mcpServers: 'שרתי MCP',
        skills: 'Appwrite Skills', // pragma: allowlist secret
        aiArena: 'AI Arena',
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
          'אימות מאובטח של משתמשים עם אימייל, SMS, OAuth, סשנים אנונימיים ו-Magic URLs.',
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
    products: {
      ...enCatalog.website.products,
      pageLayout: {
        ...enCatalog.website.products.pageLayout,
        startBuilding: 'התחילו לבנות',
        viewDocs: 'צפו בדוקומנטציה',
        viewPricing: 'צפו במחירים',
      },
      productNames: {
        ...enCatalog.website.products.productNames,
        auth: 'אימות',
        databases: 'מסדי נתונים',
        storage: 'אחסון',
        functions: 'פונקציות',
        messaging: 'הודעות',
        sites: 'אתרים',
      },
      explore: {
        ...enCatalog.website.products.explore,
        title: 'גלו את Appwrite', // pragma: allowlist secret
        description:
          'שירותי Backend מודולריים שחולקים את אותו פרויקט, מודל הרשאות וקונסול.',
      },
      navigation: {
        ...enCatalog.website.products.navigation,
        triggerLabel: 'מוצרים',
        desktopTitle: 'מוצרי הפלטפורמה',
        desktopSubtitle: 'לבנות, לפרוס ולהתרחב על פלטפורמת Backend אחת',
        newLabel: 'חדש',
        categories: {
          ...enCatalog.website.products.navigation.categories,
          build: 'פיתוח',
          deploy: 'פריסה',
          protect: 'הגנה',
        },
        items: {
          ...enCatalog.website.products.navigation.items,
          authTagline: 'אימייל, OAuth, SMS, אימות דו-שלבי, צוותים וסשנים.',
          databasesTagline: 'TablesDB, DocumentsDB, VectorsDB, PostgreSQL, MySQL.',
          storageTagline: 'העלאה, עיבוד והגשה של קבצים דרך CDN.',
          functionsTagline: 'APIs, משימות Cron ומטפלי אירועים בקנה מידה רחב.',
          messagingTagline: 'אימייל, SMS ו-Push עם נושאים ויעדים.',
          sitesTagline: 'פריסות סטטיות, SSR ו-CSR מ-Git.',
          realtimeName: 'Realtime',
          realtimeTagline: 'אירועים חיים, ערוצים ונוכחות.',
          agentName: 'Agent',
          agentTagline: 'צ׳אט לבדיקת הפרויקט וביצוע פעולות מאושרות.',
          domainsName: 'Domains',
          domainsTagline: 'חיפוש, רכישה, העברה וניהול דומיינים.',
          firewallName: 'חומת אש',
          firewallTagline: 'כללי פרויקט לחסימה, הגבלת קצב והפניית תעבורה.',
          advisorName: 'יועץ',
          advisorTagline: 'תובנות אבטחה וביצועים.',
        },
      },
      tools: {
        ...enCatalog.website.products.tools,
        headingTitle: 'כלים שנבנו למפתחים ולסוכני AI',
        headingDescription:
          'API-first כברירת מחדל. השתמשו בקונסול, Realtime, SDKs, CLI, Terraform, MCP ו-Agent Skills על אותו פרויקט.',
        developerExperienceTitle: 'חוויית מפתחים וסוכני AI',
        developerExperienceFallbackCaption: 'אינטגרציה טיפוסית ב-TypeScript.',
        everythingApiTitle: 'הכל הוא API',
        everythingApiDescription:
          'REST, GraphQL ו-SDKs לכל שירות. אוטומציה ואינטגרציה ללא תלות בזרימות של קונסול בלבד.',
        consoleTitle: 'קונסול',
        consoleDescription:
          'ניהול כל השירותים בפרויקט מקונסול אחוד. גישת מקלדת מהירה ו-Command Center שומרים על קצב עבודה גבוה.',
        commandCenter: 'Command Center',
        realtimeTitle: 'Realtime',
        realtimeDescription:
          'הרשמה לאירועים חיים ותגובה לשינויים בזמן שהם קורים.',
        mcpTitle: 'MCP',
        mcpDescription:
          'חיבור סוכני AI לפרויקט, ל-APIs ולדוקומנטציה של Appwrite דרך שרתי MCP.', // pragma: allowlist secret
        terraformTitle: 'Terraform',
        terraformDescription:
          'ניהול תשתיות Appwrite כקוד עם הספק הרשמי.', // pragma: allowlist secret
        agentSkillsTitle: 'Agent Skills',
        agentSkillsDescription:
          'ספקו לסוכני AI ידע מדויק ל-SDK על שירותי Appwrite וה-APIs.', // pragma: allowlist secret
        sdksTitle: 'SDKs',
        sdksDescriptionPrefix:
          'SDKs לצד לקוח וצד שרת לפלטפורמות שהצוות שלכם כבר משתמש בהן.',
        sdksDescriptionSuffix:
          'SDKs רשמיים זמינים בסביבות Client ו-Server.',
        cliTitle: 'CLI',
        cliDescription:
          'פריסה של משאבים, ניהול פרויקטים ויצירת SDKs מוקלדים ישירות מהטרמינל.',
      },
    },
  },
}
