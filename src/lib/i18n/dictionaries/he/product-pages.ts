/**
 * Hebrew translations for product marketing pages (Auth, Databases, Storage, etc.).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heProductPagesDictionary: Record<string, string> = {
  '13+ runtimes, your stack': 'יותר מ-13 סביבות ריצה, הסטאק שלכם',
  'Add TOTP authenticator apps and recovery codes for sensitive accounts. Require MFA when users update credentials or access protected actions.':
    'הוסיפו אפליקציות אימות TOTP וקודי שחזור לחשבונות רגישים. חייבו MFA כשמשתמשים מעדכנים פרטי אימות או ניגשים לפעולות מוגנות.',
  'Add secure authentication to your app with email, OAuth, SMS, magic URLs, MFA, teams, presences, and session management.':
    'הוסיפו אימות מאובטח לאפליקציה שלכם עם אימייל, OAuth, SMS, Magic URL, MFA, צוותים, Presences וניהול סשנים.',
  'Appwrite DNS': 'Appwrite DNS',
  'Appwrite DNS docs': 'תיעוד Appwrite DNS',
  'Appwrite Network': 'Appwrite Network',
  'Appwrite Sites': 'Appwrite Sites',
  'Appwrite caches your package manager store between deployments, keyed automatically per function. pnpm, bun, npm, and yarn installs are faster on the next build with no extra configuration. If a cache restore fails, the build continues normally.':
    'Appwrite שומרת במטמון את מאגר מנהל החבילות בין פריסות, עם מפתח אוטומטי לכל פונקציה. התקנות pnpm, bun, npm ו-yarn מהירות יותר ב-Build הבא ללא הגדרות נוספות. אם שחזור המטמון נכשל, ה-Build ממשיך כרגיל.',
  'Appwrite hashes passwords with Argon2, including salting and adjustable work factors. From Auth Policies and Settings, set minimum length, character requirements, password history, dictionary checks, and rules that block personal data in passwords. Email policies can block disposable, aliased, or free-provider addresses at sign-up. Session settings control duration, limits per user, and cookie behavior for web apps.':
    'Appwrite מצפינה סיסמאות עם Argon2, כולל מלח וגורמי עבודה מתכווננים. ממדיניות והגדרות אימות אפשר להגדיר אורך מינימלי, דרישות תווים, היסטוריית סיסמאות, בדיקות מילון וכללים שחוסמים מידע אישי בסיסמאות. מדיניות אימייל יכולה לחסום כתובות חד-פעמיות, כינויים או ספקים חינמיים בהרשמה. הגדרות סשן שולטות במשך, במגבלות למשתמש ובהתנהגות עוגיות באפליקציות Web.',
  'Appwrite supports 13+ runtimes including Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, and Deno. Each runtime has multiple version tags so you can pin the environment that matches production.':
    'Appwrite תומכת ביותר מ-13 סביבות ריצה, כולל Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust ו-Deno. לכל סביבת ריצה יש תגי גרסה מרובים כדי שתוכלו לקבע את הסביבה שתואמת לפרודקשן.',
  'Appwrite supports both TablesDB and legacy Collections APIs. Docs cover migration paths and compatibility notes.':
    'Appwrite תומכת גם ב-TablesDB וגם ב-APIs הישנים של Collections. התיעוד מכסה מסלולי מיגרציה והערות תאימות.',
  'Are database backups included?': 'האם גיבויי מסדי נתונים כלולים?',
  'Are files private by default?': 'האם קבצים פרטיים כברירת מחדל?',
  'At-rest protection': 'הגנה במצב מנוחה',
  'Auth is included in every Appwrite deployment. Self-hosted installations use the same Auth APIs, SDKs, OAuth providers, policies, and session behavior as Appwrite Cloud. Configure auth methods, password rules, and security policies from the Console the same way.':
    'אימות כלול בכל פריסת Appwrite. התקנות self-hosted משתמשות באותם Auth APIs, SDKs, ספקי OAuth, מדיניות והתנהגות סשן כמו Appwrite Cloud. הגדירו שיטות אימות, כללי סיסמה ומדיניות אבטחה מהקונסולה באותו אופן.',
  'Auth overview': 'סקירת אימות',
  'Auth user delivery': 'משלוח למשתמשי אימות',
  'Backup features depend on your plan and database engine. Cloud plans include backup options for supported engines.':
    'יכולות גיבוי תלויות בתוכנית ובמנוע מסד הנתונים. תוכניות Cloud כוללות אפשרויות גיבוי למנועים נתמכים.',
  'Branch URLs': 'כתובות Branch',
  'Bring your own providers': 'הביאו ספקים משלכם',
  'Broadcast messaging': 'הודעות שידור',
  'Bucket encryption': 'הצפנת באקט',
  'Bucket settings docs': 'תיעוד הגדרות באקט',
  'Bucket-based file management at scale': 'ניהול קבצים מבוסס באקטים בקנה מידה',
  'Build and deploy faster': 'בנו ופרסו מהר יותר',
  'Build transforms visually in the Console, preview results live, and save presets for reuse across your team. Ship optimized images without writing transformation code.':
    'בנו טרנספורמציות ויזואלית בקונסולה, צפו בתוצאות בזמן אמת ושמרו פריסטים לשימוש חוזר בצוות. שלחו תמונות מותאמות בלי לכתוב קוד טרנספורמציה.',
  'Build workers restore a dependency cache at the start of each deployment, so package installs on unchanged lockfiles finish in seconds instead of minutes. Path filters and root directory settings let Turborepo monorepos skip builds when unrelated packages change. Deployment retention automatically deletes inactive deployments after a period you choose, so preview builds do not pile up and consume storage. You can also tune build and runtime CPU and memory in site settings when compilation or SSR needs more headroom.':
    'עובדי Build משחזרים מטמון תלויות בתחילת כל פריסה, כך שהתקנות חבילות על lockfiles ללא שינוי מסתיימות בשניות במקום בדקות. מסנני נתיב והגדרות תיקיית שורש מאפשרים ל-monorepos של Turborepo לדלג על Builds כשחבילות לא קשורות משתנות. שמירת פריסות מוחקת אוטומטית פריסות לא פעילות אחרי תקופה שתבחרו, כך ש-Builds לתצוגה מקדימה לא מצטברים וצורכים אחסון. אפשר גם לכוונן CPU וזיכרון של Build ו-runtime בהגדרות אתר כשקומפילציה או SSR דורשים יותר משאבים.',
  'Buy domains and manage DNS': 'רכישת דומיינים וניהול DNS',
  'CDN + TLS': 'CDN + TLS',
  'CDN delivery built in': 'משלוח CDN מובנה',
  'CDN docs': 'תיעוד CDN',
  'CDN overview': 'סקירת CDN',
  'Caching': 'מטמון',
  'Can Functions access other Appwrite services?': 'האם פונקציות יכולות לגשת לשירותי Appwrite אחרים?',
  'Can Functions respond to HTTP requests?': 'האם פונקציות יכולות להגיב לבקשות HTTP?',
  'Can I buy a domain and manage DNS in Appwrite?': 'האם אפשר לרכוש דומיין ולנהל DNS ב-Appwrite?',
  'Can I deploy without connecting Git?': 'האם אפשר לפרוס בלי לחבר Git?',
  'Can I develop Functions locally?': 'האם אפשר לפתח פונקציות מקומית?',
  'Can I encrypt files in Storage?': 'האם אפשר להצפין קבצים באחסון?',
  'Can I inspect site traffic and debug SSR output?': 'האם אפשר לבדוק תעבורת אתר ולנפות פלט SSR?',
  'Can I migrate from a legacy document database?': 'האם אפשר לעבור ממסד נתונים מסמכים ישן?',
  'Can I migrate users from another auth provider?': 'האם אפשר להעביר משתמשים מספק אימות אחר?',
  'Can I schedule messages and track delivery?': 'האם אפשר לתזמן הודעות ולעקוב אחרי משלוח?',
  'Can I send messages from Functions or my backend?': 'האם אפשר לשלוח הודעות מפונקציות או מה-backend שלי?',
  'Can I start from templates or quick-starts?': 'האם אפשר להתחיל מתבניות או Quick-starts?',
  'Can I transform images without storing multiple copies?': 'האם אפשר לשנות תמונות בלי לאחסן עותקים מרובים?',
  'Can I upload large files?': 'האם אפשר להעלות קבצים גדולים?',
  'Can I use Auth without building a custom login UI?': 'האם אפשר להשתמש באימות בלי לבנות ממשק התחברות מותאם?',
  'Can I use separate domains for staging and production?': 'האם אפשר להשתמש בדומיינים נפרדים ל-staging ולפרודקשן?',
  'Can Sites connect to my Appwrite backend?': 'האם אתרים יכולים להתחבר ל-backend של Appwrite?',
  'Channels in one API': 'ערוצים ב-API אחד',
  'Checking auth status': 'בדיקת סטטוס אימות',
  'Choosing a message type': 'בחירת סוג הודעה',
  'Code in Node, Bun, Python, Go, Rust, Dart, and more. Pin the version you ship with and deploy without relearning the platform.':
    'כתבו ב-Node, Bun, Python, Go, Rust, Dart ועוד. קבעו את הגרסה שאתם משחררים איתה ופרסו בלי ללמוד מחדש את הפלטפורמה.',
  'Compatible object access': 'גישה תואמת לאובייקטים',
  'Compose and delivery logs': 'הרכבה ולוגי משלוח',
  'Compose and schedule messages': 'הרכבה ותזמון הודעות',
  'Compression and image format optimization': 'דחיסה ואופטימיזציה של פורמט תמונה',
  'Connect Appwrite Storage to rclone, Terraform, and custom pipelines with a project-scoped HTTPS endpoint and SigV4-compatible signing. Copy credentials from the Console Connect tab and keep your existing S3 workflows.':
    'חברו את Appwrite Storage ל-rclone, Terraform וצינורות מותאמים עם Endpoint HTTPS מוגבל לפרויקט וחתימה תואמת SigV4. העתיקו פרטי גישה מלשונית Connect בקונסולה והמשיכו עם תהליכי S3 הקיימים שלכם.',
  'Connect a Git repository and ship on every push. Commits to your production branch build and auto-activate on your primary domain; other branches get preview links for org members to review before merge.':
    'חברו מאגר Git ושחררו בכל push. Commits ל-Branch הפרודקשן נבנים ומופעלים אוטומטית על הדומיין הראשי; Branches אחרים מקבלים קישורי תצוגה מקדימה לחברי הארגון לבדיקה לפני merge.',
  'Connect a repository and deploy on every push, the same workflow as Sites. Set a production branch that auto-activates successful builds, filter by branch or path with glob patterns, and review preview deployments from pull requests.':
    'חברו מאגר ופרסו בכל push, באותו תהליך כמו אתרים. הגדירו Branch פרודקשן שמפעיל אוטומטית Builds מוצלחים, סננו לפי Branch או נתיב עם תבניות glob, ובדקו פריסות תצוגה מקדימה מ-pull requests.',
  'Connect a repository in the Console, set a production branch and root directory, then deploy on every push. Branch and path filters use glob patterns, matching the Sites Git workflow.':
    'חברו מאגר בקונסולה, הגדירו Branch פרודקשן ותיקיית שורש, ואז פרסו בכל push. מסנני Branch ונתיב משתמשים בתבניות glob, כמו תהליך Git של אתרים.',
  'Connect hostnames to your site': 'חיבור שמות מארח לאתר',
  'Connect the email, SMS, and push providers you already use. Add credentials once in the Console, pick a vendor per channel, and route every message through the stack you operate.':
    'חברו את ספקי האימייל, SMS וה-push שאתם כבר משתמשים בהם. הוסיפו פרטי גישה פעם אחת בקונסולה, בחרו ספק לכל ערוץ ונתבו כל הודעה דרך הסטאק שאתם מפעילים.',
  'Create a team for each customer, organization, or workspace in your app. Invite members by email, assign roles, and scope databases, storage buckets, functions, and other resources with team-based permissions. Teams give you tenant isolation without building custom RBAC, and membership privacy settings let you control whether member lists are visible to other users.':
    'צרו צוות לכל לקוח, ארגון או workspace באפליקציה. הזמינו חברים באימייל, הקצו תפקידים והגדירו הרשאות למסדי נתונים, באקטים, פונקציות ומשאבים אחרים לפי צוות. צוותים נותנים בידוד נתונים בין לקוחות בלי לבנות RBAC מותאם, והגדרות פרטיות חברות מאפשרות לשלוט אם רשימות חברים גלויות למשתמשים אחרים.',
  'Create topics from the Console Topics tab and subscribe targets for newsletters, product announcements, and security alerts. Broadcast to every subscriber in a topic or combine topics with direct targets when you need finer control.':
    'צרו נושאים מלשונית Topics בקונסולה והירשמו יעדים לניוזלטרים, הכרזות מוצר והתראות אבטחה. שדרו לכל מנוי בנושא או שלבו נושאים עם יעדים ישירים כשצריך שליטה עדינה יותר.',
  'Database engines': 'מנועי מסדי נתונים',
  'Databases overview': 'סקירת מסדי נתונים',
  'Delivered on Appwrite Network': 'מסופק דרך Appwrite Network',
  'Delivery providers': 'ספקי משלוח',
  'Deploy from CLI': 'פריסה מ-CLI',
  'Deploy from Git': 'פריסה מ-Git',
  'Deploy from Git docs': 'תיעוד פריסה מ-Git',
  'Deploy from Git with auto-build on push': 'פריסה מ-Git עם Build אוטומטי ב-push',
  'Deploy from Git with preview URLs': 'פריסה מ-Git עם כתובות תצוגה מקדימה',
  'Deploy manually': 'פריסה ידנית',
  'Deploy serverless Functions with isolated runtimes, schedules, and event triggers. Build backends without managing servers.':
    'פרסו פונקציות Serverless עם סביבות ריצה מבודדות, לוחות זמנים וטריגרים של אירועים. בנו backends בלי לנהל שרתים.',
  'Deploy static, SSR, and CSR web apps with Appwrite Sites. Git-based deploys, preview URLs, instant rollbacks, custom domains, and Appwrite backends.':
    'פרסו אפליקציות Web סטטיות, SSR ו-CSR עם Appwrite Sites. פריסות מבוססות Git, כתובות תצוגה מקדימה, החזרות מיידיות, דומיינים מותאמים ו-backends של Appwrite.',
  'Deploy the way your team works': 'פרסו בדרך שהצוות שלכם עובד',
  'Deployment retention': 'שמירת פריסות',
  'Deployments docs': 'תיעוד פריסות',
  'Develop and run functions locally': 'פיתוח והרצת פונקציות מקומית',
  'Develop functions': 'פיתוח פונקציות',
  'Develop locally': 'פיתוח מקומי',
  'Do I need separate vendor integrations for email, SMS, and push?': 'האם צריך אינטגרציות נפרדות לספקים לאימייל, SMS ו-push?',
  'Does Auth support social login and linked identities?': 'האם אימות תומך ב-OAuth וזהויות מקושרות?',
  'Does Databases work with Auth permissions?': 'האם מסדי נתונים עובדים עם הרשאות אימות?',
  'Does Storage support file compression?': 'האם אחסון תומך בדחיסת קבצים?',
  'Does Storage support the S3 API?': 'האם אחסון תומך ב-S3 API?',
  'Domains docs': 'תיעוד דומיינים',
  'Draft email, SMS, and push from the Console Messages tab with channel-specific fields, topic and target selection, and delivery logs. Schedule sends for later or fire transactional flows such as OTP verification and account alerts from Functions or your backend.':
    'הכינו טיוטות אימייל, SMS ו-push מלשונית Messages בקונסולה עם שדות לפי ערוץ, בחירת נושא ויעד ולוגי משלוח. תזמנו שליחות למועד מאוחר יותר או הפעילו תהליכים טרנזקציוניים כמו אימות OTP והתראות חשבון מפונקציות או מה-backend שלכם.',
  'Each Auth user can have multiple targets registered to your project. Verified emails from email/password, magic URL, and email OTP sign-up create email targets automatically. Verified phone numbers from SMS OTP sign-up create SMS targets. Push targets are added from your client app after the user grants notification permission. Inspect and manage targets from the Targets tab on user detail in Auth.':
    'לכל משתמש אימות יכולים להיות מספר יעדים רשומים לפרויקט. אימיילים מאומתים מהרשמה עם אימייל/סיסמה, Magic URL ו-Email OTP יוצרים יעדי אימייל אוטומטית. מספרי טלפון מאומתים מהרשמה עם SMS OTP יוצרים יעדי SMS. יעדי push מתווספים מאפליקציית הלקוח אחרי שהמשתמש מאשר התראות. בדקו ונהלו יעדים מלשונית Targets בפרטי משתמש באימות.',
  'Each user can have email, phone, and push device targets registered to your project. Inspect and manage them from the Targets tab on user detail in Auth, then subscribe those targets to topics or address them directly in a message.':
    'לכל משתמש יכולים להיות יעדי אימייל, טלפון ומכשיר push רשומים לפרויקט. בדקו ונהלו אותם מלשונית Targets בפרטי משתמש באימות, ואז הירשמו את היעדים לנושאים או פנו אליהם ישירות בהודעה.',
  'Email and password login': 'התחברות באימייל וסיסמה',
  'Email: Resend, SendGrid, Mailgun, and SMTP. SMS: Twilio, Vonage, MSG91, Telesign, and Textmagic. Push: APNS and FCM. Configure multiple providers per channel and choose which one to use when sending. Discord and Slack chat integrations are coming soon.':
    'אימייל: Resend, SendGrid, Mailgun ו-SMTP. SMS: Twilio, Vonage, MSG91, Telesign ו-Textmagic. Push: APNS ו-FCM. הגדירו מספר ספקים לכל ערוץ ובחרו איזה להשתמש בשליחה. אינטגרציות צ\'אט ל-Discord ו-Slack בקרוב.',
  'Enable 30+ social providers from the Console Social providers tab. Users sign up in one click with GitHub, Google, Apple, and the identity providers your audience already uses.':
    'הפעילו יותר מ-30 ספקי OAuth מלשונית ספקי OAuth בקונסולה. משתמשים נרשמים בלחיצה אחת עם GitHub, Google, Apple וספקי הזהות שהקהל שלכם כבר משתמש בהם.',
  'Enable MFA in Auth settings, then let users enroll an authenticator app (TOTP) and download recovery codes. MFA adds a second step after the primary sign-in method. Require it for sensitive actions such as updating credentials or accessing protected resources. Users who lose their device can sign in with a recovery code instead of the TOTP.':
    'הפעילו MFA בהגדרות אימות, ואז אפשרו למשתמשים לרשום אפליקציית אימות (TOTP) ולהוריד קודי שחזור. MFA מוסיף שלב שני אחרי שיטת האימות הראשית. חייבו אותו לפעולות רגישות כמו עדכון פרטי אימות או גישה למשאבים מוגנים. משתמשים שאיבדו את המכשיר יכולים להתחבר עם קוד שחזור במקום TOTP.',
  'Enable Magic URL, Email OTP, and Phone SMS from Auth settings in the Console. Magic URL sends a one-click sign-in link to the user\'s email. Email OTP delivers a time-limited code they enter in your app. Phone SMS verifies users through text messages without a password. You can offer passwordless methods alongside email and password, or disable password login entirely for a password-free experience.':
    'הפעילו Magic URL, Email OTP ו-Phone SMS מהגדרות אימות בקונסולה. Magic URL שולח קישור התחברות בלחיצה אחת לאימייל של המשתמש. Email OTP מספק קוד מוגבל בזמן שהם מזינים באפליקציה. Phone SMS מאמת משתמשים בהודעות SMS בלי סיסמה. אפשר להציע שיטות ללא סיסמה לצד אימייל וסיסמה, או לבטל התחברות בסיסמה לחוויה ללא סיסמה.',
  'Encryption at rest': 'הצפנה במצב מנוחה',
  'Event triggers and scheduled executions': 'טריגרים של אירועים והרצות מתוזמנות',
  'Every function gets a generated URL and optional custom domain for sync HTTP APIs and webhooks. Pass user sessions with the x-appwrite-user-jwt header so your function respects Auth permissions inside Server SDKs.':
    'לכל פונקציה יש URL שנוצר אוטומטית ודומיין מותאם אופציונלי ל-HTTP APIs סינכרוניים ו-Webhooks. העבירו סשנים של משתמשים עם ה-header x-appwrite-user-jwt כדי שהפונקציה תכבד הרשאות אימות בתוך Server SDKs.',
  'Every run creates an execution you can inspect in the Console. Review status, trigger, method, path, and duration in the executions table, then open details for logs, errors, and headers. Request and response bodies are not stored by default. Use log() and error() for the audit trail you need.':
    'כל הרצה יוצרת execution שאפשר לבדוק בקונסולה. עברו על סטטוס, טריגר, שיטה, נתיב ומשך בטבלת ההרצות, ואז פתחו פרטים ללוגים, שגיאות ו-headers. גופי בקשה ותגובה לא נשמרים כברירת מחדל. השתמשו ב-log() ו-error() לעקבות הביקורת שאתם צריכים.',
  'Every site runs on Appwrite Network. Auth, Databases, and Storage stay in your project region while pages and assets reach users from the edge.':
    'כל אתר רץ על Appwrite Network. אימות, מסדי נתונים ואחסון נשארים באזור הפרויקט שלכם, בעוד דפים ונכסים מגיעים למשתמשים מהקצה.',
  'Every storage file and transformed preview is served through Appwrite CDN with 120+ edge locations worldwide. Transformed images are cached in your project region first, so repeat requests skip re-processing and reach users faster.':
    'כל קובץ אחסון ותצוגה מקדימה מותאמת מסופקים דרך Appwrite CDN עם יותר מ-120 מיקומי קצה ברחבי העולם. תמונות מותאמות נשמרות במטמון באזור הפרויקט קודם, כך שבקשות חוזרות מדלגות על עיבוד מחדש ומגיעות למשתמשים מהר יותר.',
  'Execute functions': 'הרצת פונקציות',
  'Execute functions docs': 'תיעוד הרצת פונקציות',
  'Executions and observability': 'הרצות ו-Observability',
  'Executions docs': 'תיעוד הרצות',
  'Fast builds with built-in dependency cache': 'Builds מהירים עם מטמון תלויות מובנה',
  'File tokens are secrets attached to a file that authorize preview, view, or download without session cookies. Create tokens from the Console or Server SDK, set an optional expiry, and share the URL with anyone. This avoids third-party cookie issues in embedded or cross-domain apps.':
    'טוקני קובץ הם סודות המצורפים לקובץ שמאשרים תצוגה מקדימה, צפייה או הורדה בלי עוגיות סשן. צרו טוקנים מהקונסולה או מ-Server SDK, הגדירו תפוגה אופציונלית ושתפו את ה-URL עם כל אחד. זה מונע בעיות עוגיות צד שלישי באפליקציות מוטמעות או cross-domain.',
  'File tokens docs': 'תיעוד טוקני קובץ',
  'File tokens for expiring public links': 'טוקני קובץ לקישורים ציבוריים עם תפוגה',
  'Fine-tune Auth from the Console Policies and Settings tabs. Set session length and limits, password strength and history, email signup rules, membership privacy, and which auth methods are enabled for your project.':
    'כווננו אימות מלשוניות Policies והגדרות בקונסולה. הגדירו אורך סשן ומגבלות, חוזק סיסמה והיסטוריה, כללי הרשמה באימייל, פרטיות חברות ואילו שיטות אימות מופעלות לפרויקט.',
  'Framework presets': 'פריסטים של frameworks',
  'Framework quick-starts and templates': 'Quick-starts ותבניות של frameworks',
  'Frameworks': 'Frameworks',
  'Function domains': 'דומיינים של פונקציות',
  'Function domains docs': 'תיעוד דומיינים של פונקציות',
  'Function templates catalog': 'קטלוג תבניות פונקציות',
  'Granular permissions at bucket and file level': 'הרשאות מפורטות ברמת באקט וקובץ',
  'HTTP endpoints via domains': 'Endpoints של HTTP דרך דומיינים',
  'Host static sites, SPAs, and PWAs alongside server-rendered apps. Choose the rendering mode that fits your framework, from Vite and Astro to Next.js 16, Nuxt, SvelteKit, and TanStack Start.':
    'אחסנו אתרים סטטיים, SPAs ו-PWAs לצד אפליקציות מרונדרות בשרת. בחרו את מצב הרינדור שמתאים ל-framework שלכם, מ-Vite ו-Astro ועד Next.js 16, Nuxt, SvelteKit ו-TanStack Start.',
  'How are passwords, sessions, and security policies configured?': 'איך מגדירים סיסמאות, סשנים ומדיניות אבטחה?',
  'How are targets linked to Auth users?': 'איך יעדים מקושרים למשתמשי אימות?',
  'How do I add multi-factor authentication?': 'איך מוסיפים אימות רב-שלבי?',
  'How do I debug function executions?': 'איך מנפים הרצות פונקציות?',
  'How do I deploy from Git?': 'איך פורסים מ-Git?',
  'How do file tokens work for public sharing?': 'איך טוקני קובץ עובדים לשיתוף ציבורי?',
  'How do instant rollbacks work?': 'איך החזרות מיידיות עובדות?',
  'How do preview deployments work?': 'איך פריסות תצוגה מקדימה עובדות?',
  'How do teams and multi-tenancy work?': 'איך צוותים תומכים בריבוי לקוחות?',
  'How do topics and targets work together?': 'איך נושאים ויעדים עובדים יחד?',
  'How does Appwrite Sites keep builds and deployments efficient?': 'איך Appwrite Sites שומרת על Builds ופריסות יעילים?',
  'How does Auth work with self-hosted Appwrite?': 'איך אימות עובד עם Appwrite self-hosted?',
  'How does passwordless sign-in work?': 'איך התחברות ללא סיסמה עובדת?',
  'How does the build cache work?': 'איך מטמון ה-Build עובד?',
  'How is Storage different from Databases?': 'מה ההבדל בין אחסון למסדי נתונים?',
  'Image transform wizard and presets': 'אשף טרנספורמציית תמונה ופריסטים',
  'Image transforms docs': 'תיעוד טרנספורמציות תמונה',
  'In-console query editor': 'עורך שאילתות בקונסולה',
  'Instant rollbacks': 'החזרות מיידיות',
  'Instant rollbacks change which ready deployment is served to visitors. They do not delete, modify, or rebuild your code, so recovery is near-instant with zero downtime. Open your site Overview in the Console, click Instant Rollback, and promote a previous deployment.':
    'החזרות מיידיות משנות איזו פריסה מוכנה מוגשת למבקרים. הן לא מוחקות, משנות או בונות מחדש את הקוד, כך שההתאוששות כמעט מיידית ללא זמן השבתה. פתחו את סקירת האתר בקונסולה, לחצו על Instant Rollback וקדמו פריסה קודמת.',
  'Instant rollbacks docs': 'תיעוד החזרות מיידיות',
  'Integrate Storage with Auth users, teams, and roles. Set bucket-wide defaults and per-file rules from the Console Security tab so the right people can read, create, update, or delete files.':
    'שלבו אחסון עם משתמשי אימות, צוותים ותפקידים. הגדירו ברירות מחדל לכל באקט וכללים לפי קובץ מלשונית Security בקונסולה, כדי שהאנשים הנכונים יוכלו לקרוא, ליצור, לעדכן או למחוק קבצים.',
  'Is CDN delivery included with Storage?': 'האם משלוח CDN כלול באחסון?',
  'Language runtimes': 'סביבות ריצה',
  'Local development docs': 'תיעוד פיתוח מקומי',
  'Local-first development': 'פיתוח מקומי קודם',
  'Logs docs': 'תיעוד לוגים',
  'Logs, traffic, and usage insights': 'לוגים, תעבורה ותובנות שימוש',
  'Lower storage costs, cut bandwidth, and speed up page loads without a separate media pipeline. Enable gzip or zstd per bucket to compress uploads automatically, then serve WebP, AVIF, and other modern formats from the preview endpoint. Keep one original upload and optimize file size every time you deliver it.':
    'הפחיתו עלויות אחסון, חתכו רוחב פס והאיצו טעינת דפים בלי צינור מדיה נפרד. הפעילו gzip או zstd לכל באקט כדי לדחוס העלאות אוטומטית, ואז הגישו WebP, AVIF ופורמטים מודרניים אחרים מ-Endpoint התצוגה המקדימה. שמרו העלאה מקורית אחת ואופטמו גודל קובץ בכל משלוח.',
  'MFA docs': 'תיעוד MFA',
  'Magic URL docs': 'תיעוד Magic URL',
  'Memberships & roles': 'חברויות ותפקידים',
  'Messages docs': 'תיעוד הודעות',
  'Messaging overview': 'סקירת הודעות',
  'Model each customer or workspace as a team with memberships, invites, and roles. Scope databases, storage, and other resources to the right tenant from the Console Users and Teams tabs, without building custom RBAC.':
    'מדלו כל לקוח או workspace כצוות עם חברויות, הזמנות ותפקידים. הגדירו הרשאות למסדי נתונים, אחסון ומשאבים אחרים ללקוח או לארגון הנכון מלשוניות Users ו-Teams בקונסולה, בלי לבנות RBAC מותאם.',
  'Multi-tenancy': 'Multi-tenancy',
  'Multi-tenancy docs': 'תיעוד Multi-tenancy',
  'Multi-tenancy with teams and roles': 'ריבוי לקוחות עם צוותים ותפקידים',
  'No. You connect your own provider credentials once in the Console, then send on every channel through one Messaging API and SDK. Pick a vendor per channel (Resend for email, Twilio for SMS, FCM for push, and so on) without maintaining three separate integrations or delivery logs.':
    'לא. מחברים פרטי גישה לספקים שלכם פעם אחת בקונסולה, ואז שולחים בכל ערוץ דרך Messaging API ו-SDK אחד. בחרו ספק לכל ערוץ (Resend לאימייל, Twilio ל-SMS, FCM ל-push וכן הלאה) בלי לתחזק שלוש אינטגרציות נפרדות או לוגי משלוח.',
  'OAuth 2 and social login': 'OAuth 2 וספקי OAuth',
  'OAuth providers': 'ספקי OAuth',
  'OAuth2': 'OAuth2',
  'OAuth2 docs': 'תיעוד OAuth2',
  'On-the-fly delivery': 'משלוח בזמן אמת',
  'On-the-fly image transformations': 'טרנספורמציות תמונה בזמן אמת',
  'Open the Executions tab in the Console to review status, trigger, method, path, and duration for each run. Execution details include logs, errors, and headers. Request and response bodies are not logged by default for privacy. Use log() and error() in your handler for the output you want to retain.':
    'פתחו את לשונית Executions בקונסולה כדי לעבור על סטטוס, טריגר, שיטה, נתיב ומשך לכל הרצה. פרטי ההרצה כוללים לוגים, שגיאות ו-headers. גופי בקשה ותגובה לא נרשמים כברירת מחדל לפרטיות. השתמשו ב-log() ו-error() ב-handler שלכם לפלט שאתם רוצים לשמור.',
  'Organize uploads in isolated buckets with upload, download, list, and delete APIs. Browse files in the Console with search, pagination, and bulk operations.':
    'ארגנו העלאות בבאקטים מבודדים עם APIs להעלאה, הורדה, רשימה ומחיקה. עיינו בקבצים בקונסולה עם חיפוש, עימוד ופעולות מרובות.',
  'Password hashing': 'הצפנת סיסמאות',
  'Passwordless login': 'התחברות ללא סיסמה',
  'Permission-aware tables': 'טבלאות מודעות להרשאות',
  'Permissions docs': 'תיעוד הרשאות',
  'Platform event hooks': 'Hooks של אירועי פלטפורמה',
  'Point production at your live deployment, give staging branches their own domain, or configure redirects. Every site also gets a generated .appwrite.network URL for instant sharing.':
    'כוונו פרודקשן לפריסה החיה, תנו ל-Branches של staging דומיין משלהם, או הגדירו הפניות. לכל אתר יש גם URL שנוצר ב-.appwrite.network לשיתוף מיידי.',
  'Presence for team collaboration': 'Presence לשיתוף פעולה בצוות',
  'Presences': 'Presences',
  'Presences show who is active right now: online, away, typing, or viewing a page or channel. Upsert records with status and metadata, then subscribe over Realtime for live updates. Use them for team rosters, shared doc viewers, chat typing indicators, and support queue availability.':
    'Presences מציגים מי פעיל כרגע: מחובר, לא זמין, מקליד או צופה בדף או בערוץ. עדכנו רשומות עם סטטוס ומטא-דאטה, ואז הירשמו דרך Realtime לעדכונים חיים. השתמשו בהם לרשימות צוות, צופים במסמכים משותפים, אינדיקטורי הקלדה בצ\'אט וזמינות בתור תמיכה.',
  'Previews': 'תצוגות מקדימות',
  'Providers docs': 'תיעוד ספקים',
  'Purchase domains in Appwrite and manage records with Appwrite DNS from the Console. TLS is issued automatically when you connect a hostname.':
    'רכשו דומיינים ב-Appwrite ונהלו רשומות עם Appwrite DNS מהקונסולה. TLS מונפק אוטומטית כשמחברים שם מארח.',
  'Push and SMS work well for time-sensitive alerts users see within minutes. Email suits rich HTML content like receipts, newsletters, and promotions. SMS reaches phones even without internet. Push drives re-engagement with deep links back into your app. Most production apps combine all three depending on urgency and content.':
    'Push ו-SMS מתאימים להתראות דחופות שמשתמשים רואים תוך דקות. אימייל מתאים לתוכן HTML עשיר כמו קבלות, ניוזלטרים ומבצעים. SMS מגיע לטלפונים גם בלי אינטרנט. Push מחזיר מעורבות עם deep links חזרה לאפליקציה. רוב אפליקציות הפרודקשן משלבות את שלושת הערוצים לפי דחיפות ותוכן.',
  'Push to deploy': 'Push לפריסה',
  'Quick start': 'התחלה מהירה',
  'Recovery policies': 'מדיניות שחזור',
  'Rendering': 'רינדור',
  'Rendering docs': 'תיעוד רינדור',
  'Repository deploys': 'פריסות ממאגר',
  'Run functions asynchronously on platform events or on a cron schedule. Sync HTTP calls and SDK executions with async disabled return responses immediately but cap at 30 seconds. Events, cron jobs, and queued executions run in the background with your configured timeout, up to 15 minutes.':
    'הריצו פונקציות באופן אסינכרוני על אירועי פלטפורמה או בלוח זמנים של Cron. קריאות HTTP סינכרוניות והרצות SDK עם async מבוטל מחזירות תגובות מיד אבל מוגבלות ל-30 שניות. אירועים, משימות Cron והרצות בתור רצים ברקע עם ה-Timeout שהגדרתם, עד 15 דקות.',
  'Runtimes': 'סביבות ריצה',
  'Runtimes docs': 'תיעוד סביבות ריצה',
  'S3-compatible object access': 'גישה לאובייקטים תואמת S3',
  'SSR authentication': 'אימות SSR',
  'SSR docs': 'תיעוד SSR',
  'SSR hosting': 'אירוח SSR',
  'Schedule triggers': 'טריגרים מתוזמנים',
  'Security policies you control': 'מדיניות אבטחה בשליטתכם',
  'Self-hosting': 'Self-hosting',
  'Send SMS messages': 'שליחת הודעות SMS',
  'Send email messages': 'שליחת הודעות אימייל',
  'Send email, SMS, and push notifications with Appwrite Messaging. Topics, targets, providers, and scheduling in one API.':
    'שלחו הודעות אימייל, SMS והתראות push עם Appwrite Messaging. נושאים, יעדים, ספקים ותזמון ב-API אחד.',
  'Send on every channel from one Messaging service and SDK. Use createEmail, createSms, and createPush for transactional mail, OTP codes, and mobile alerts without wiring three separate vendor integrations.':
    'שלחו בכל ערוץ משירות Messaging ו-SDK אחד. השתמשו ב-createEmail, createSms ו-createPush לדואר טרנזקציונלי, קודי OTP והתראות מובייל בלי לחבר שלוש אינטגרציות ספקים נפרדות.',
  'Send push notifications': 'שליחת התראות push',
  'Share files with token-based preview, view, and download URLs that work without session cookies. Set an expiry date or keep links open-ended for external viewers.':
    'שתפו קבצים עם כתובות תצוגה מקדימה, צפייה והורדה מבוססות טוקן שעובדות בלי עוגיות סשן. הגדירו תאריך תפוגה או השאירו קישורים פתוחים לצופים חיצוניים.',
  'Should I use TablesDB or PostgreSQL?': 'האם להשתמש ב-TablesDB או ב-PostgreSQL?',
  'Show who is online, on the same page, or typing in team chat. Presences sync status and metadata over Realtime so you can add collaboration cues to shared docs, dashboards, and support tools without building sockets.':
    'הציגו מי מחובר, באותו דף או מקליד בצ\'אט צוותי. Presences מסנכרנים סטטוס ומטא-דאטה דרך Realtime כדי שתוכלו להוסיף רמזי שיתוף פעולה למסמכים משותפים, דשבורדים וכלי תמיכה בלי לבנות sockets.',
  'Sign-in methods': 'שיטות התחברות',
  'Site domains': 'דומיינים של אתרים',
  'Site logs': 'לוגים של אתרים',
  'Sites overview': 'סקירת אתרים',
  'Sites run on Appwrite Network with global CDN delivery, DDoS protection, Web Application Firewall (WAF), and TLS encryption. SSR workloads can execute closer to users at the edge while Auth, Databases, Storage, and other project services stay in your selected region.':
    'אתרים רצים על Appwrite Network עם משלוח CDN גלובלי, הגנת DDoS, Web Application Firewall (WAF) והצפנת TLS. עומסי SSR יכולים לרוץ קרוב יותר למשתמשים בקצה, בעוד אימות, מסדי נתונים, אחסון ושירותי פרויקט אחרים נשארים באזור שבחרתם.',
  'Sites supports popular frameworks including Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, Remix, Angular, React, and more. Static hosting works with any framework that outputs HTML assets; SSR is available for supported server-rendered stacks. See the frameworks page for build settings per preset.':
    'אתרים תומכים ב-frameworks פופולריים כולל Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, Remix, Angular, React ועוד. אירוח סטטי עובד עם כל framework שמפיק נכסי HTML; SSR זמין לסטאקים נתמכים עם רינדור בשרת. ראו את דף ה-frameworks להגדרות Build לכל פריסט.',
  'Spend less time waiting on builds and more time shipping updates. Cached dependencies speed up repeat deploys, path filters help monorepos skip unnecessary rebuilds, and deployment retention automatically removes old inactive deployments to save storage. Tune build and runtime CPU and memory when compilation or SSR needs more headroom.':
    'השקיעו פחות זמן בהמתנה ל-Builds ויותר זמן בשחרור עדכונים. תלויות במטמון מאיצות פריסות חוזרות, מסנני נתיב עוזרים ל-monorepos לדלג על בניות מחדש מיותרות, ושמירת פריסות מסירה אוטומטית פריסות ישנות לא פעילות כדי לחסוך אחסון. כווננו CPU וזיכרון של Build ו-runtime כשקומפילציה או SSR דורשים יותר משאבים.',
  'Start from official quick-starts or pick a template in the create wizard. Filter by framework and use case, connect GitHub, and deploy with build settings already tuned for Appwrite.':
    'התחילו מ-Quick-starts רשמיים או בחרו תבנית באשף היצירה. סננו לפי framework ומקרה שימוש, חברו GitHub ופרסו עם הגדרות Build שכבר מותאמות ל-Appwrite.',
  'Start from the Console Templates tab with pre-built integrations for Stripe payments, OpenAI prompts, search sync, Discord bots, and more. Filter by use case or runtime and skip boilerplate when wiring new backend jobs.':
    'התחילו מלשונית Templates בקונסולה עם אינטגרציות מוכנות לתשלומי Stripe, פרומפטים של OpenAI, סנכרון חיפוש, בוטים של Discord ועוד. סננו לפי מקרה שימוש או סביבת ריצה ודלגו על boilerplate כשמחברים משימות backend חדשות.',
  'Static and SPA hosting serves pre-built assets at the edge with fast cold starts. SSR runs your framework on each request, which suits dynamic or user-specific pages and gives you runtime access to environment variables. Many frameworks support both modes in the same app.':
    'אירוח סטטי ו-SPA מגיש נכסים מוכנים מראש בקצה עם cold starts מהירים. SSR מריץ את ה-framework שלכם בכל בקשה, מה שמתאים לדפים דינמיים או ספציפיים למשתמש ונותן גישה ל-runtime למשתני סביבה. frameworks רבים תומכים בשני המצבים באותה אפליקציה.',
  'Static hosting': 'אירוח סטטי',
  'Storage docs': 'תיעוד אחסון',
  'Storage is for binary files like images, videos, and PDFs. Databases store structured rows and fields. Most apps use both together: Storage for assets and Databases for metadata and relationships.':
    'אחסון מיועד לקבצים בינאריים כמו תמונות, סרטונים ו-PDFs. מסדי נתונים מאחסנים שורות ושדות מובנים. רוב האפליקציות משתמשות בשניהם יחד: אחסון לנכסים ומסדי נתונים למטא-דאטה ויחסים.',
  'Storage overview': 'סקירת אחסון',
  'Storage permissions': 'הרשאות אחסון',
  'Store and query structured data with TablesDB, native PostgreSQL, and MySQL. Permissions, relationships, vector search, and backups included.':
    'אחסנו ושאילתו נתונים מובנים עם TablesDB, PostgreSQL מקורי ו-MySQL. הרשאות, יחסים, חיפוש וקטורי וגיבויים כלולים.',
  'Store, manage, and deliver files with Appwrite Storage. Built-in CDN, regional caching, S3-compatible access, compression, encryption, on-the-fly transforms, file tokens, and secure downloads.':
    'אחסנו, נהלו וספקו קבצים עם Appwrite Storage. CDN מובנה, מטמון אזורי, גישה תואמת S3, דחיסה, הצפנה, טרנספורמציות בזמן אמת, טוקני קובץ והורדות מאובטחות.',
  'Switch the active deployment with zero downtime and no rebuild. Pick any previous ready deployment from Overview in the Console and promote it in one click when you need to recover fast.':
    'החליפו את הפריסה הפעילה ללא זמן השבתה ובלי Build מחדש. בחרו כל פריסה מוכנה קודמת מסקירה בקונסולה וקדמו אותה בלחיצה אחת כשצריך להתאושש מהר.',
  'Sync executions run over HTTP domains or the SDK with async set to false. Appwrite waits for your function and returns the response, with a 30 second hard limit. Async executions are queued for events, cron schedules, and SDK calls with async set to true. They run in the background and use your function timeout, up to 15 minutes.':
    'הרצות סינכרוניות רצות דרך דומייני HTTP או ה-SDK עם async מוגדר ל-false. Appwrite ממתינה לפונקציה ומחזירה את התגובה, עם מגבלה קשיחה של 30 שניות. הרצות אסינכרוניות נכנסות לתור לאירועים, לוחות זמנים של Cron וקריאות SDK עם async מוגדר ל-true. הן רצות ברקע ומשתמשות ב-Timeout של הפונקציה, עד 15 דקות.',
  'TablesDB is fastest to integrate with Appwrite SDKs and permissions. Choose PostgreSQL when you need advanced SQL, extensions like pgvector, or an existing SQL toolchain.':
    'TablesDB הכי מהיר לשילוב עם Appwrite SDKs והרשאות. בחרו PostgreSQL כשצריכים SQL מתקדם, הרחבות כמו pgvector או toolchain SQL קיים.',
  'Targets are the ways a user can be reached: email addresses, phone numbers, and push device tokens. Subscribe targets to a topic to broadcast the same message to every subscriber, or address specific users and targets when you need private, one-to-one delivery. Topics fit newsletters and announcements; sensitive content like chat should go to individual targets.':
    'יעדים הם הדרכים שאפשר להגיע למשתמש: כתובות אימייל, מספרי טלפון וטוקני מכשיר push. הירשמו יעדים לנושא כדי לשדר את אותה הודעה לכל מנוי, או פנו למשתמשים ויעדים ספציפיים כשצריך משלוח פרטי אחד-לאחד. נושאים מתאימים לניוזלטרים והכרזות; תוכן רגיש כמו צ\'אט צריך ללכת ליעדים בודדים.',
  'Targets docs': 'תיעוד יעדים',
  'Targets linked to Auth users': 'יעדים מקושרים למשתמשי אימות',
  'Team collaboration': 'שיתוף פעולה בצוות',
  'Team invites': 'הזמנות לצוות',
  'Templates docs': 'תיעוד תבניות',
  'Topics docs': 'תיעוד נושאים',
  'Topics for group and broadcast messaging': 'נושאים להודעות קבוצתיות ושידור',
  'Track requests, bandwidth, builds, and compute over time with breakdowns by path, asset type, or region. Inspect individual requests with status, headers, and SSR console output in the same view.':
    'עקבו אחרי בקשות, רוחב פס, Builds וחישוב לאורך זמן עם פירוט לפי נתיב, סוג נכס או אזור. בדקו בקשות בודדות עם סטטוס, headers ופלט קונסולת SSR באותה תצוגה.',
  'Turn on Magic URL, Email OTP, and Phone SMS from Auth settings. Ship secure sign-in without storing or resetting passwords.':
    'הפעילו Magic URL, Email OTP ו-Phone SMS מהגדרות אימות. שחררו התחברות מאובטחת בלי לאחסן או לאפס סיסמאות.',
  'Turn on bucket encryption from Settings so new uploads are stored encrypted at rest. If files are exposed, encrypted objects stay unreadable without your project keys.':
    'הפעילו הצפנת באקט מהגדרות כדי שהעלאות חדשות יאוחסנו מוצפנות במצב מנוחה. אם קבצים נחשפים, אובייקטים מוצפנים נשארים לא קריאים בלי מפתחות הפרויקט.',
  'Two-factor auth': 'אימות דו-שלבי',
  'Unified email, SMS, and push API': 'API מאוחד לאימייל, SMS ו-push',
  'Upload and download': 'העלאה והורדה',
  'Upload and download docs': 'תיעוד העלאה והורדה',
  'Use Git for automatic builds on push, the Appwrite CLI in CI, or a manual tarball upload from the Console. Every path runs through the same build pipeline, logs, domains, and rollbacks.':
    'השתמשו ב-Git ל-Builds אוטומטיים ב-push, ב-Appwrite CLI ב-CI, או בהעלאת tarball ידנית מהקונסולה. כל מסלול עובר דרך אותו צינור Build, לוגים, דומיינים והחזרות.',
  'Use the Appwrite CLI and Docker to run functions on localhost with hot reload. Test with production-style headers, impersonate users, and deploy when you are ready.':
    'השתמשו ב-Appwrite CLI ו-Docker להריץ פונקציות על localhost עם hot reload. בדקו עם headers בסגנון פרודקשן, התחזו למשתמשים ופרסו כשאתם מוכנים.',
  'Use the preview endpoint to resize, crop, convert format, set quality, add borders, and rotate images on demand. No pre-processing pipeline or duplicate files.':
    'השתמשו ב-Endpoint התצוגה המקדימה לשנות גודל, לחתוך, להמיר פורמט, להגדיר איכות, להוסיף מסגרות ולסובב תמונות לפי דרישה. בלי צינור עיבוד מוקדם או קבצים כפולים.',
  'Verify sessions from Next.js, Nuxt, SvelteKit, and other server-rendered apps. Issue session cookies from your backend with dedicated guides and tutorials.':
    'אמתו סשנים מ-Next.js, Nuxt, SvelteKit ואפליקציות מרונדרות בשרת אחרות. הנפיקו עוגיות סשן מה-backend שלכם עם מדריכים ומדריכים ייעודיים.',
  'What edge network and security features are included?': 'אילו יכולות רשת קצה ואבטחה כלולות?',
  'What is the difference between static and SSR hosting?': 'מה ההבדל בין אירוח סטטי ל-SSR?',
  'What is the difference between sync and async execution?': 'מה ההבדל בין הרצה סינכרונית לאסינכרונית?',
  'What team collaboration features can presences power?': 'אילו יכולות שיתוף פעולה בצוות Presences יכולים להפעיל?',
  'When should I use email, SMS, or push?': 'מתי כדאי להשתמש באימייל, SMS או push?',
  'When you push to a branch other than your production branch, Appwrite builds a deployment but does not activate it on your primary domain. Instead, a preview URL is generated for org members to review. Pull requests can also receive preview links and optional PR comments unless silent mode is enabled.':
    'כשעושים push ל-Branch שאינו Branch הפרודקשן, Appwrite בונה פריסה אבל לא מפעילה אותה על הדומיין הראשי. במקום זאת נוצר URL לתצוגה מקדימה לחברי הארגון לבדיקה. Pull requests יכולים גם לקבל קישורי תצוגה מקדימה והערות PR אופציונליות, אלא אם מצב שקט מופעל.',
  'Which delivery providers are supported?': 'אילו ספקי משלוח נתמכים?',
  'Which frameworks does Sites support?': 'אילו frameworks אתרים תומכים?',
  'Which languages do Functions support?': 'אילו שפות פונקציות תומכות?',
  'Yes. Add multiple domain rules on a site: point one hostname to the active production deployment, map another to a specific Git branch for staging, or configure redirects. Branch and commit preview URLs are also generated automatically for Git deployments.':
    'כן. הוסיפו מספר כללי דומיין לאתר: כוונו שם מארח אחד לפריסת הפרודקשן הפעילה, מפו אחר ל-Branch Git ספציפי ל-staging, או הגדירו הפניות. כתובות תצוגה מקדימה ל-Branch ו-commit נוצרות גם אוטומטית לפריסות Git.',
  'Yes. Appwrite Auth is API-first, so you keep full control of the UI in your app and call the Account SDK for sign-up, login, sessions, and MFA. Quick starts cover React, Next.js, Vue, SvelteKit, Flutter, and other platforms. For server-rendered apps, verify sessions on your backend and issue HTTP-only cookies using the SSR guides.':
    'כן. Appwrite Auth הוא API-first, כך שאתם שומרים שליטה מלאה על ה-UI באפליקציה וקוראים ל-Account SDK להרשמה, התחברות, סשנים ו-MFA. Quick starts מכסים React, Next.js, Vue, SvelteKit, Flutter ופלטפורמות אחרות. לאפליקציות מרונדרות בשרת, אמתו סשנים ב-backend והנפיקו עוגיות HTTP-only עם מדריכי SSR.',
  'Yes. Appwrite Storage exposes a project-scoped HTTPS endpoint with SigV4-compatible signing. Copy the endpoint, access key, and secret from the Connect tab in your project to attach buckets to rclone, Terraform, or other S3 tooling without rebuilding upload pipelines.':
    'כן. Appwrite Storage חושף Endpoint HTTPS מוגבל לפרויקט עם חתימה תואמת SigV4. העתיקו את ה-Endpoint, מפתח הגישה והסוד מלשונית Connect בפרויקט כדי לחבר באקטים ל-rclone, Terraform או כלי S3 אחרים בלי לבנות מחדש צינורות העלאה.',
  'Yes. Appwrite supports OAuth 2.0 with 30+ providers, including GitHub, Google, Apple, Discord, and Microsoft. Enable providers in the Console under Auth > Social providers, add your OAuth credentials and redirect URI, then start the flow from the Account SDK. Each OAuth sign-in creates an identity linked to the user account, so one person can connect multiple providers without duplicate accounts.':
    'כן. Appwrite תומכת ב-OAuth 2.0 עם יותר מ-30 ספקים, כולל GitHub, Google, Apple, Discord ו-Microsoft. הפעילו ספקים בקונסולה תחת אימות > ספקי OAuth, הוסיפו פרטי OAuth ו-redirect URI, ואז התחילו את התהליך מ-Account SDK. כל התחברות OAuth יוצרת זהות מקושרת לחשבון המשתמש, כך שאדם אחד יכול לחבר מספר ספקים בלי חשבונות כפולים.',
  'Yes. Browse templates from Sites > Templates in the Console and filter by framework or use case. The create wizard walks you through GitHub setup, production branch, environment variables, and domain configuration. Official quick-starts cover Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, and more.':
    'כן. עיינו בתבניות מ-Sites > Templates בקונסולה וסננו לפי framework או מקרה שימוש. אשף היצירה מלווה אתכם בהגדרת GitHub, Branch פרודקשן, משתני סביבה והגדרת דומיין. Quick-starts רשמיים מכסים Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start ועוד.',
  'Yes. Buckets and files have no permissions by default, so access is denied until you grant read, create, update, or delete to users, teams, or roles. Enable file security on a bucket to set per-file permissions on top of bucket defaults.':
    'כן. לבאקטים וקבצים אין הרשאות כברירת מחדל, כך שהגישה נדחית עד שמעניקים קריאה, יצירה, עדכון או מחיקה למשתמשים, צוותים או תפקידים. הפעילו אבטחת קבצים על באקט כדי להגדיר הרשאות לפי קובץ מעל ברירות המחדל של הבאקט.',
  'Yes. Compose email, SMS, and push from the Console Messages tab or call createEmail, createSms, and createPush from the Server SDK. Send immediately, save as a draft, or pass scheduledAt for later delivery. Every message appears in the Messages tab with status (draft, scheduled, processing, failed, or success) and delivery timestamps.':
    'כן. הכינו אימייל, SMS ו-push מלשונית Messages בקונסולה או קראו ל-createEmail, createSms ו-createPush מ-Server SDK. שלחו מיד, שמרו כטיוטה או העבירו scheduledAt למשלוח מאוחר יותר. כל הודעה מופיעה בלשונית Messages עם סטטוס (טיוטה, מתוזמן, בעיבוד, נכשל או הצלחה) וחותמות זמן משלוח.',
  'Yes. Enable gzip or zstd compression per bucket from Settings. Compression applies to new uploads and helps reduce storage and bandwidth costs. Files larger than 20 MB skip compression even when enabled.':
    'כן. הפעילו דחיסת gzip או zstd לכל באקט מהגדרות. הדחיסה חלה על העלאות חדשות ועוזרת להפחית עלויות אחסון ורוחב פס. קבצים גדולים מ-20 MB מדלגים על דחיסה גם כשהיא מופעלת.',
  'Yes. Every function gets a generated domain and you can add custom domains on Appwrite Cloud. Pass x-appwrite-user-jwt to authenticate users and respect Auth permissions inside your function.':
    'כן. לכל פונקציה יש דומיין שנוצר אוטומטית ואפשר להוסיף דומיינים מותאמים ב-Appwrite Cloud. העבירו x-appwrite-user-jwt לאימות משתמשים ולכיבוד הרשאות אימות בתוך הפונקציה.',
  'Yes. Functions receive a dynamic API key and run with project context. Configure scopes in Settings, then call Databases, Storage, Messaging, Auth, and other APIs from server SDKs inside your handler.':
    'כן. פונקציות מקבלות מפתח API דינמי ורצות בהקשר פרויקט. הגדירו scopes בהגדרות, ואז קראו למסדי נתונים, אחסון, הודעות, אימות ו-APIs אחרים מ-server SDKs בתוך ה-handler.',
  'Yes. Import users through the Console or the Users API with the Server SDK. For email and password accounts, create users with plain-text passwords or import existing password hashes when your provider uses a supported algorithm: Argon2, bcrypt, scrypt, scrypt-modified (Firebase), SHA, MD5, or PHPass. New passwords are stored with Argon2. Hashes imported from other algorithms are upgraded to Argon2 after the user\'s first successful sign-in.':
    'כן. ייבאו משתמשים דרך הקונסולה או Users API עם Server SDK. לחשבונות אימייל וסיסמה, צרו משתמשים עם סיסמאות בטקסט רגיל או ייבאו hashes סיסמה קיימים כשהספק משתמש באלגוריתם נתמך: Argon2, bcrypt, scrypt, scrypt-modified (Firebase), SHA, MD5 או PHPass. סיסמאות חדשות נשמרות עם Argon2. Hashes שיובאו מאלגוריתמים אחרים משודרגים ל-Argon2 אחרי ההתחברות המוצלחת הראשונה של המשתמש.',
  'Yes. Purchase domains from your organization Domains tab and manage records with Appwrite DNS in the same Console. Connect the domain to a site for automatic TLS, or use the generated .appwrite.network URL while you set up DNS. Apex domains can delegate to Appwrite nameservers; subdomains use CNAME records. Sites traffic is delivered through Appwrite Network with CDN, DDoS protection, and WAF.':
    'כן. רכשו דומיינים מלשונית Domains בארגון ונהלו רשומות עם Appwrite DNS באותה קונסולה. חברו את הדומיין לאתר ל-TLS אוטומטי, או השתמשו ב-URL שנוצר ב-.appwrite.network בזמן שמגדירים DNS. דומייני apex יכולים להאציל ל-Appwrite nameservers; תת-דומיינים משתמשים ברשומות CNAME. תעבורת אתרים מסופקת דרך Appwrite Network עם CDN, הגנת DDoS ו-WAF.',
  'Yes. Push deployments with the Appwrite CLI from CI or your machine, or upload a .tar.gz archive from the Console for manual deploys. Git remains the recommended path for automatic builds on push and branch previews, but every deploy method uses the same build pipeline and settings.':
    'כן. דחפו פריסות עם Appwrite CLI מ-CI או מהמחשב, או העלו ארכיון .tar.gz מהקונסולה לפריסות ידניות. Git נשאר המסלול המומלץ ל-Builds אוטומטיים ב-push ותצוגות מקדימות של Branches, אבל כל שיטת פריסה משתמשת באותו צינור Build והגדרות.',
  'Yes. Request transformations through the preview endpoint to resize, crop, convert format, and adjust quality on the fly. Keep one original upload and let Appwrite generate variants on demand.':
    'כן. בקשו טרנספורמציות דרך Endpoint התצוגה המקדימה לשנות גודל, לחתוך, להמיר פורמט ולכוונן איכות בזמן אמת. שמרו העלאה מקורית אחת ותנו ל-Appwrite ליצור וריאנטים לפי דרישה.',
  'Yes. Row and table permissions can reference users, teams, and roles from Appwrite Auth.':
    'כן. הרשאות שורה וטבלה יכולות להתייחס למשתמשים, צוותים ותפקידים מ-Appwrite Auth.',
  'Yes. Sites deploy in the same project as Auth, Databases, Storage, Functions, and Messaging. Use environment variables for API keys and endpoints, then call Appwrite SDKs from your frontend or SSR routes without managing separate infrastructure.':
    'כן. אתרים נפרסים באותו פרויקט כמו אימות, מסדי נתונים, אחסון, פונקציות והודעות. השתמשו במשתני סביבה למפתחות API ו-Endpoints, ואז קראו ל-Appwrite SDKs מה-frontend או מנתיבי SSR בלי לנהל תשתית נפרדת.',
  'Yes. Storage files and transformed previews are served through Appwrite CDN with 120+ edge locations. Transformed images are cached in your project region, so repeat requests skip re-processing before reaching the edge.':
    'כן. קבצי אחסון ותצוגות מקדימות מותאמות מסופקים דרך Appwrite CDN עם יותר מ-120 מיקומי קצה. תמונות מותאמות נשמרות במטמון באזור הפרויקט, כך שבקשות חוזרות מדלגות על עיבוד מחדש לפני הגעה לקצה.',
  'Yes. Storage supports chunked uploads for large files through the SDKs and Console. Configure maximum file size per bucket and use resumable uploads when transferring big assets.':
    'כן. אחסון תומך בהעלאות מקוטעות לקבצים גדולים דרך ה-SDKs והקונסולה. הגדירו גודל קובץ מקסימלי לכל באקט והשתמשו בהעלאות הניתנות לחידוש בהעברת נכסים גדולים.',
  'Yes. The Appwrite CLI runs your function in Docker on localhost with hot reload, the same runtime image as production, and optional user impersonation for Auth-aware testing.':
    'כן. Appwrite CLI מריץ את הפונקציה ב-Docker על localhost עם hot reload, אותה תמונת runtime כמו בפרודקשן, והתחזות משתמש אופציונלית לבדיקות מודעות לאימות.',
  'Yes. Turn on encryption per bucket from Settings so new files are stored encrypted at rest. If files are leaked, encrypted objects cannot be read without your keys. Files larger than 20 MB skip encryption even when enabled.':
    'כן. הפעילו הצפנה לכל באקט מהגדרות כדי שקבצים חדשים יאוחסנו מוצפנים במצב מנוחה. אם קבצים דולפים, אובייקטים מוצפנים לא ניתנים לקריאה בלי המפתחות שלכם. קבצים גדולים מ-20 MB מדלגים על הצפנה גם כשהיא מופעלת.',
  'Yes. Usage charts show requests, bandwidth, builds, and compute over selectable ranges, with breakdowns to see where traffic comes from. The Logs tab records every request with status code, method, path, and duration. Open a log entry for request and response headers. For SSR sites, console.log and console.error output appears in response logs.':
    'כן. תרשימי שימוש מציגים בקשות, רוחב פס, Builds וחישוב בטווחים לבחירה, עם פירוטים לראות מאיפה מגיעה התעבורה. לשונית לוגים רושמת כל בקשה עם קוד סטטוס, שיטה, נתיב ומשך. פתחו רשומת לוג ל-headers של בקשה ותגובה. לאתרי SSR, פלט של console.log ו-console.error מופיע בלוגי תגובה.',
  'Yes. Use the Server SDK from Functions, your API server, or any trusted backend with a project API key. This is the standard pattern for transactional flows such as OTP verification, password reset, order receipts, and inventory alerts triggered by platform events or custom logic.':
    'כן. השתמשו ב-Server SDK מפונקציות, שרת ה-API שלכם או כל backend מהימן עם מפתח API לפרויקט. זה התבנית הסטנדרטית לתהליכים טרנזקציוניים כמו אימות OTP, איפוס סיסמה, קבלות הזמנה והתראות מלאי שמופעלות על ידי אירועי פלטפורמה או לוגיקה מותאמת.',
  'gzip and zstd buckets': 'באקטים עם gzip ו-zstd',
}
