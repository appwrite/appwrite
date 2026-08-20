import { enCatalog, type EnCatalog } from './en'

export const jaCatalog: EnCatalog = {
  ...enCatalog,
  app: {
    ...enCatalog.app,
    header: {
      ...enCatalog.app.header,
      centerSearchPlaceholder: 'ドキュメントを検索...',
      marketingNav: {
        ...enCatalog.app.header.marketingNav,
        products: 'プロダクト',
        docs: 'ドキュメント',
        pricing: '料金',
        enterprise: 'Enterprise',
        customers: '導入事例',
        blog: 'ブログ',
        changelog: '変更履歴',
        websiteNavigation: 'サイトナビゲーション',
        changelogNewUpdatesAria: '変更履歴、新着アップデート',
      },
      actions: {
        ...enCatalog.app.header.actions,
        openNavigation: 'ナビゲーションを開く',
        openWebsiteNavigation: 'サイトナビゲーションを開く',
        create: '作成',
        connect: '接続',
        assistant: 'Agent',
        upgrade: 'アップグレード',
        signIn: 'サインイン',
        signUp: 'サインアップ',
        signOut: 'サインアウト',
        backToOrganization: '組織に戻る',
      },
      createMenu: {
        ...enCatalog.app.header.createMenu,
        newProject: '新規プロジェクト',
        newOrganization: '新規組織',
        newAgent: '新規エージェント',
        buildSection: '構築',
        deploySection: 'デプロイ',
        protectSection: '保護',
        newDatabase: '新規データベース',
        newUser: '新規ユーザー',
        newBucket: '新規バケット',
        newFunction: '新規関数',
        newMessage: '新規メッセージ',
        newFirewallRule: '新規 Firewall ルール',
        newSite: '新規サイト',
      },
      permissions: {
        ...enCatalog.app.header.permissions,
        createProjects: 'プロジェクトを作成する権限がありません。',
        createDatabases: 'データベースを作成する権限がありません。',
        createUsers: 'ユーザーを作成する権限がありません。',
        createBuckets: 'バケットを作成する権限がありません。',
        createFunctions: '関数を作成する権限がありません。',
        createTopics: 'メッセージングトピックを作成する権限がありません。',
        createFirewallRules:
          'ファイアウォールルールを作成する権限がありません。',
        createSites: 'サイトを作成する権限がありません。',
      },
      accountMenu: {
        ...enCatalog.app.header.accountMenu,
        user: 'ユーザー',
        account: 'アカウント',
        projects: 'プロジェクト',
        domains: 'ドメイン',
        memberSince: '登録日',
        accountStatus: 'アカウントステータス',
        accountId: 'アカウント ID',
        copyAccountId: 'アカウント ID をコピー',
        copied: 'コピーしました',
        twoFactor: '2FA',
        active: '有効',
        inactive: '無効',
        enabled: '有効',
        disabled: '無効',
        console: 'コンソール',
        // Temporary: remove once the old console is retired
        oldConsole: '旧コンソール',
        home: 'ホーム',
        docs: 'ドキュメント',
        changelog: '変更履歴',
        admin: '管理',
        cache: 'Cache',
        blocks: 'Blocks',
        generator: 'Generator',
      },
      search: {
        ...enCatalog.app.header.search,
        compactPlaceholder: '検索...',
      },
    },
    footer: {
      ...enCatalog.app.footer,
      groups: {
        ...enCatalog.app.footer.groups,
        quickStarts: 'クイックスタート',
        products: 'プロダクト',
        learn: '学習',
        programs: 'プログラム',
        about: '会社情報',
        compare: '比較',
      },
      links: {
        ...enCatalog.app.footer.links,
        docs: 'ドキュメント',
        store: 'ストア',
        status: 'ステータス',
        cookieSettings: 'Cookie 設定',
        soc2: 'SOC 2 Type II 認証取得',
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
          web: 'Web',
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
          auth: '認証',
          databases: 'データベース',
          storage: 'ストレージ',
          functions: 'Functions',
          messaging: 'メッセージング',
          realtime: 'Realtime',
          agent: 'Agent',
          hosting: 'ホスティング',
          domains: 'Domains',
          network: 'Network',
          firewall: 'Firewall',
        },
        learn: {
          ...enCatalog.app.footer.expanded.learn,
          blog: 'ブログ',
          docs: 'ドキュメント',
          integrations: 'インテグレーション',
          community: 'コミュニティ',
          init: 'Init',
          threads: 'Threads',
          changelog: '変更履歴',
          roadmap: 'ロードマップ',
          sourceCode: 'ソースコード',
          arena: 'Arena',
          techNews: 'テックニュース',
        },
        programs: {
          ...enCatalog.app.footer.expanded.programs,
          startups: 'スタートアップ',
          education: '教育',
          partners: 'パートナー',
          enterprise: 'Enterprise',
          affiliates: 'アフィリエイト',
        },
        about: {
          ...enCatalog.app.footer.expanded.about,
          company: '会社',
          pricing: '料金',
          careers: '採用',
          contactUs: 'お問い合わせ',
          assets: 'アセット',
          security: 'セキュリティ',
        },
        compare: {
          ...enCatalog.app.footer.expanded.compare,
          vsSupabase: 'Appwrite vs. Supabase', // pragma: allowlist secret
          vsFirebase: 'Appwrite vs. Firebase', // pragma: allowlist secret
          vsNeon: 'Appwrite vs. Neon', // pragma: allowlist secret
          vsVercel: 'Appwrite vs. Vercel', // pragma: allowlist secret
          vsNetlify: 'Appwrite vs. Netlify', // pragma: allowlist secret
          vsCloudinary: 'Appwrite vs. Cloudinary', // pragma: allowlist secret
          vsAuth0: 'Appwrite vs. Auth0', // pragma: allowlist secret
          nextjsHosting: 'Next.js ホスティング',
          reactHosting: 'React ホスティング',
          vueHosting: 'Vue.js ホスティング',
          baas: 'Backend as a service (BaaS)',
        },
      },
    },
    sidebar: {
      ...enCatalog.app.sidebar,
      sections: {
        ...enCatalog.app.sidebar.sections,
        connect: '接続',
        build: '構築',
        deploy: 'デプロイ',
        observe: '監視',
        protect: '保護',
      },
      items: {
        ...enCatalog.app.sidebar.items,
        overview: '概要',
        apps: 'アプリ',
        apiKeys: 'API キー',
        explorer: 'Explorer',
        auth: '認証',
        databases: 'データベース',
        storage: 'ストレージ',
        functions: 'Functions',
        messaging: 'メッセージング',
        sites: 'サイト',
        distribution: 'Distribution',
        activity: 'アクティビティ',
        realtime: 'Realtime',
        logs: 'ログ',
        usage: '使用量',
        analytics: 'アナリティクス',
        errors: 'エラー',
        firewall: 'Firewall',
        advisor: 'Advisor',
        settings: '設定',
      },
      badges: {
        ...enCatalog.app.sidebar.badges,
        soon: '近日公開',
      },
      accessibility: {
        ...enCatalog.app.sidebar.accessibility,
        mainNavigation: 'メインナビゲーション',
        mobileNavigation: 'モバイルナビゲーション',
        closeNavigation: 'ナビゲーションを閉じる',
        expandSidebar: 'サイドバーを展開',
        collapseSidebar: 'サイドバーを折りたたむ',
        comingSoonSuffix: '(近日公開)',
      },
      onboarding: {
        ...enCatalog.app.sidebar.onboarding,
        getStarted: 'はじめる',
        progress: '進捗',
        loading: '読み込み中...',
        of: '/',
        completed: '完了',
      },
    },
    nativeAppBar: {
      ...enCatalog.app.nativeAppBar,
      back: '戻る',
      forward: '進む',
      history: '履歴',
      recentPages: '最近のページ',
      noRecentPages: '最近のページはありません',
      searchPlaceholder: '検索...',
    },
    consoleBanner: {
      ...enCatalog.app.consoleBanner,
      messagePrefix: 'アイデアを動くプロダクトに。Vibe coding powered by',
      dismiss: 'バナーを閉じる',
    },
    debugMenu: enCatalog.app.debugMenu,
  },
  website: {
    ...enCatalog.website,
    home: {
      ...enCatalog.website.home,
      seoDescription:
        'Appwrite は、認証、データベース、ストレージ、Functions、メッセージング、サイトを備えたオープンソースの開発者向けプラットフォームです。数百人規模のチームのように構築できます。', // pragma: allowlist secret
      announcementNew: 'Breaking',
      announcementText: 'Appwrite 2.0 の発表を嬉しく思います',
      heroTitleLineOne: 'より速く構築し、',
      heroTitleLineTwo: 'これまで以上にスケール',
      heroDescription:
        'Appwrite は、認証、データベース、ストレージ、Functions、メッセージング、Realtime、Web ホスティングを備えたオープンソースプラットフォームです。すべてが一つの場所に。', // pragma: allowlist secret
      heroPreviewWorkspace: 'Appwrite', // pragma: allowlist secret
      heroPreviewOrganization: 'Acme Corp',
      heroPreviewProject: '最初の Appwrite プロジェクト', // pragma: allowlist secret
      heroImageAlt:
        '使用量グラフ、アプリ、API キーを表示する Appwrite コンソールの概要', // pragma: allowlist secret
      startProject: 'プロジェクトを開始',
      requestDemo: 'デモを依頼',
      toolsHeading: 'お気に入りのフレームワーク、言語、エージェント向けに最適化',
      aiDocsNavLabel: 'AI と MCP ドキュメント',
      productsHeadingLineOne: '必要なサービスをすべて',
      productsHeadingLineTwo: '一つのプラットフォームに',
      productsDescription:
        '最初のプロトタイプから本番スケールまで、統一感のあるモジュラープロダクトで構築できます。',
      securityHeading: 'アーキテクチャのすべてのレイヤーに組み込まれたセキュリティ',
      securityDescription:
        'セキュリティファーストのアプローチにより、Appwrite はプロダクトとユーザーをデフォルトで安全に保ち、厳格なポリシーへの準拠を容易にします。', // pragma: allowlist secret
      aiDocLinks: {
        ...enCatalog.website.home.aiDocLinks,
        mcpServers: 'MCP サーバー',
        skills: 'Appwrite Skills', // pragma: allowlist secret
        aiArena: 'AI Arena',
      },
      securityItems: {
        ...enCatalog.website.home.securityItems,
        ddosTitle: 'DDoS 保護',
        ddosDescription:
          '分散型サービス拒否攻撃を自動的に検出し、軽減します。',
        encryptionTitle: '暗号化',
        encryptionDescription:
          '保存時および転送時の機密ワークロード向けに、組み込みのデータ暗号化を提供します。',
        abuseTitle: '不正利用の防止',
        abuseDescription:
          '組み込みのプラットフォーム保護機能で API を不正利用から守ります。',
        migrationsTitle: 'データ移行',
        migrationsDescription:
          'サードパーティから、または Cloud とセルフホスト環境間でデータを移行します。',
        gdprTitle: 'GDPR',
        gdprDescription:
          'GDPR 要件に対応するデータプライバシーのワークフローと保護機能をサポートします。',
        soc2Title: 'SOC 2',
        soc2Description:
          '高いセキュリティとプライバシー基準向けに設計されたインフラストラクチャ上で運用できます。',
        hipaaTitle: 'HIPAA',
        hipaaDescription:
          'セキュリティファーストのプロダクトコントロールで機密の健康データを保護します。',
        ccpaTitle: 'CCPA',
        ccpaDescription:
          '機密性の高いユーザーデータを保護するためのコントロールで構築できます。',
      },
      productBento: {
        ...enCatalog.website.home.productBento,
        authTitle: '認証',
        databasesTitle: 'データベース',
        storageTitle: 'ストレージ',
        functionsTitle: 'Functions',
        sitesTitle: 'サイト',
        messagingTitle: 'メッセージング',
        firewallTitle: 'Firewall',
        realtimeTitle: 'Realtime',
        authDescription:
          'メール、SMS、OAuth、匿名セッション、Magic URL でユーザーを安全に認証します。',
        databasesDescription:
          'Appwrite データベース、またはネイティブ PostgreSQL と MySQL でモデル化、クエリ、スケールし、ユースケースとチームのニーズに合わせられます。', // pragma: allowlist secret
        storageDescription:
          '圧縮、暗号化、画像変換、アクセス制御でファイルを保存します。',
        functionsDescription:
          '安全な分離ランタイムとイベント駆動の実行で Serverless 関数をデプロイします。',
        sitesDescription:
          'Git から静的、SSR、CSR フロントエンドをデプロイし、即時プレビューと Appwrite を組み合わせられます。', // pragma: allowlist secret
        messagingDescription:
          '統合メッセージングサービスでメール、SMS、プッシュ通知を送信します。',
        firewallDescription:
          'トラフィックルール、不正利用コントロール、エッジセキュリティでアプリを保護します。',
        realtimeDescription:
          'プロジェクト全体のイベントをリアルタイムで購読し、反応できます。',
        firewallNewLabel: '新着',
      },
    },
    products: {
      ...enCatalog.website.products,
      pageLayout: {
        ...enCatalog.website.products.pageLayout,
        startBuilding: '構築を開始',
        viewDocs: 'ドキュメントを見る',
        viewPricing: '料金を見る',
      },
      productNames: {
        ...enCatalog.website.products.productNames,
        auth: '認証',
        databases: 'データベース',
        storage: 'ストレージ',
        functions: 'Functions',
        messaging: 'メッセージング',
        sites: 'サイト',
      },
      explore: {
        ...enCatalog.website.products.explore,
        title: 'Appwrite を探索', // pragma: allowlist secret
        description:
          '同じプロジェクト、権限モデル、コンソールを共有するモジュラー Backend サービス。',
      },
      navigation: {
        ...enCatalog.website.products.navigation,
        triggerLabel: 'プロダクト',
        desktopTitle: 'プラットフォームプロダクト',
        desktopSubtitle: '一つの Backend プラットフォームで構築、デプロイ、スケール',
        newLabel: '新着',
        categories: {
          ...enCatalog.website.products.navigation.categories,
          build: '構築',
          deploy: 'デプロイ',
          protect: '保護',
        },
        items: {
          ...enCatalog.website.products.navigation.items,
          authTagline: 'メール、OAuth、SMS、MFA、チーム、セッション。',
          databasesTagline: 'TablesDB, DocumentsDB, VectorsDB, PostgreSQL, MySQL.',
          storageTagline: 'CDN でファイルをアップロード、変換、配信。',
          functionsTagline: '大規模な API、Cron ジョブ、イベントハンドラー。',
          messagingTagline: 'トピックとターゲットでメール、SMS、プッシュ。',
          sitesTagline: 'Git から静的、SSR、CSR デプロイ。',
          realtimeName: 'Realtime',
          realtimeTagline: 'ライブイベント、チャンネル、プレゼンス。',
          agentName: 'Agent',
          agentTagline: 'チャットでプロジェクトを確認し、承認済みの操作を実行。',
          domainsName: 'Domains',
          domainsTagline: 'ドメインの検索、購入、移管、管理。',
          firewallName: 'Firewall',
          firewallTagline: 'プロジェクトルールで拒否、レート制限、リダイレクト。',
          advisorName: 'Advisor',
          advisorTagline: 'セキュリティとパフォーマンスのインサイト。',
        },
      },
      tools: {
        ...enCatalog.website.products.tools,
        headingTitle: '開発者とエージェント向けに構築されたツール',
        headingDescription:
          'API ファースト設計。同じプロジェクトでコンソール、Realtime、SDK、CLI、Terraform、MCP、エージェントスキルを利用できます。',
        developerExperienceTitle: '開発者とエージェントの体験',
        developerExperienceFallbackCaption: 'TypeScript での典型的なインテグレーション。',
        everythingApiTitle: 'すべてが API',
        everythingApiDescription:
          'すべてのサービス向け REST、GraphQL、SDK。コンソール専用ワークフローに依存せず自動化と統合が可能。',
        consoleTitle: 'コンソール',
        consoleDescription:
          '統合コンソールからプロジェクト内のすべてのサービスを管理。最適化されたキーボード操作と Command Center で作業を高速化。',
        commandCenter: 'Command Center',
        realtimeTitle: 'Realtime',
        realtimeDescription:
          'ライブイベントを購読し、変更が起きた瞬間に反応できます。',
        mcpTitle: 'MCP',
        mcpDescription:
          'MCP サーバー経由で AI エージェントを Appwrite プロジェクト、API、ドキュメントに接続。', // pragma: allowlist secret
        terraformTitle: 'Terraform',
        terraformDescription:
          '公式プロバイダーで Appwrite インフラストラクチャをコードとして管理。', // pragma: allowlist secret
        agentSkillsTitle: 'Agent Skills',
        agentSkillsDescription:
          'Appwrite サービスと API について SDK 精度の知識を AI エージェントに提供。', // pragma: allowlist secret
        sdksTitle: 'SDKs',
        sdksDescriptionPrefix:
          'チームがすでに使用しているプラットフォーム向けのクライアントおよびサーバー SDK。',
        sdksDescriptionSuffix:
          '公式 SDK はクライアントおよびサーバーランタイムで利用可能。',
        cliTitle: 'CLI',
        cliDescription:
          'ターミナルからリソースをデプロイし、プロジェクトを管理し、型付き SDK を生成。',
      },
    },
  },
}
