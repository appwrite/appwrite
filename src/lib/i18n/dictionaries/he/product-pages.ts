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
  'Sites run on Appwrite Network with global CDN delivery, DDoS protection, Firewall, and TLS encryption. SSR workloads can execute closer to users at the edge while Auth, Databases, Storage, and other project services stay in your selected region.':
    'אתרים רצים על Appwrite Network עם משלוח CDN גלובלי, הגנת DDoS, Firewall והצפנת TLS. עומסי SSR יכולים לרוץ קרוב יותר למשתמשים בקצה, בעוד אימות, מסדי נתונים, אחסון ושירותי פרויקט אחרים נשארים באזור שבחרתם.',
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
  'Yes. Purchase domains from your organization Domains tab and manage records with Appwrite DNS in the same Console. Connect the domain to a site for automatic TLS, or use the generated .appwrite.network URL while you set up DNS. Apex domains can delegate to Appwrite nameservers; subdomains use CNAME records. Sites traffic is delivered through Appwrite Network with CDN, DDoS protection, and Firewall.':
    'כן. רכשו דומיינים מלשונית Domains בארגון ונהלו רשומות עם Appwrite DNS באותה קונסולה. חברו את הדומיין לאתר ל-TLS אוטומטי, או השתמשו ב-URL שנוצר ב-.appwrite.network בזמן שמגדירים DNS. דומייני apex יכולים להאציל ל-Appwrite nameservers; תת-דומיינים משתמשים ברשומות CNAME. תעבורת אתרים מסופקת דרך Appwrite Network עם CDN, הגנת DDoS ו-Firewall.',
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
  '2 filters': '2 מסננים',
  '3 rules': '3 כללים',
  'Add read replicas on dedicated databases to absorb query load and improve failover resilience. Enable high availability when replica count is greater than zero, then tune sync mode and failover from Replication settings.':
    'הוסיפו read replicas במסדי נתונים ייעודיים כדי לספוג עומס שאילתות ולשפר חוסן failover. High availability מופעל כשמספר ה-replicas גדול מאפס, ואז כוונו מצב סנכרון ו-failover מהגדרות Replication.',
  'Appwrite Databases include five engines in two categories. Appwrite DBs are TablesDB for relational-style tables and columns, DocumentsDB for flexible JSON documents, and VectorsDB for embeddings and similarity search. Native DBs are managed PostgreSQL and MySQL for teams that need full SQL compatibility, extensions, and portable schemas.':
    'Appwrite Databases כוללים חמישה מנועים בשתי קטגוריות. Appwrite DBs הם TablesDB לטבלאות ועמודות בסגנון יחסי, DocumentsDB למסמכי JSON גמישים, ו-VectorsDB ל-embeddings ולחיפוש דמיון. Native DBs הם PostgreSQL ו-MySQL מנוהלים לצוותים שצריכים תאימות SQL מלאה, הרחבות וסכמות ניידות.',
  'Appwrite DBs': 'Appwrite DBs',
  'Appwrite DBs cover tables, documents, and vectors. Native DBs bring managed PostgreSQL and MySQL when you need full SQL control. Pick the model that matches your data, then operate every engine from the same Console and project.':
    'Appwrite DBs מכסים טבלאות, מסמכים ווקטורים. Native DBs מביאים PostgreSQL ו-MySQL מנוהלים כשצריך שליטת SQL מלאה. בחרו את המודל שמתאים לנתונים, ואז הפעילו כל מנוע מאותה קונסולה ואותו פרויקט.',
  'Appwrite still supports legacy Collections APIs alongside TablesDB and DocumentsDB. Docs cover migration paths, compatibility notes, and how to move schemas and documents without disrupting clients.':
    'Appwrite עדיין תומכת ב-APIs הישנים של Collections לצד TablesDB ו-DocumentsDB. התיעוד מכסה מסלולי מיגרציה, הערות תאימות, ואיך להעביר סכמות ומסמכים בלי לשבש לקוחות.',
  'Are backups and PITR included?': 'האם גיבויים ו-PITR כלולים?',
  'Auth linked': 'מקושר לאימות',
  'Automate encrypted hot backups with policies, or create a manual backup when you need a snapshot now. Enable PITR on dedicated databases to restore to a specific moment after accidental deletes, failed migrations, or bad writes.':
    'הגדירו גיבויי hot מוצפנים עם מדיניות, או צרו גיבוי ידני כשצריך snapshot עכשיו. הפעילו PITR במסדי נתונים ייעודיים כדי לשחזר לרגע מסוים אחרי מחיקות בטעות, מיגרציות שנכשלו או כתיבות שגויות.',
  'Backups and point-in-time recovery': 'גיבויים ו-point-in-time recovery',
  'Backups docs': 'תיעוד גיבויים',
  'Can I query, relate, and bulk-update data from the SDKs?':
    'האם אפשר לשאול, לקשר ולעדכן נתונים בכמות גדולה דרך ה-SDKs?',
  'Choose how this database is provisioned.': 'בחרו איך מסד הנתונים הזה מסופק.',
  'Choose TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs, Console workflows, and Auth-aware permissions out of the box. Choose PostgreSQL or MySQL when you need advanced SQL, existing ORM tooling, extensions such as pgvector, or to run schemas you already operate elsewhere.':
    'בחרו TablesDB, DocumentsDB או VectorsDB כשאתם רוצים Appwrite SDKs, תהליכי קונסולה והרשאות מודעות לאימות מהקופסה. בחרו PostgreSQL או MySQL כשצריך SQL מתקדם, כלי ORM קיימים, הרחבות כמו pgvector, או להריץ סכמות שאתם כבר מפעילים במקום אחר.',
  'Commit multi-step writes atomically.': 'בצעו כתיבות מרובות שלבים באופן אטומי.',
  'Compute model': 'מודל Compute',
  'Compute models': 'מודלי Compute',
  'Connect with standard SQL clients, ORMs, and the in-console query editor. Keep portable schemas, use the extensions your stack needs, and manage roles, connections, and backups alongside your Appwrite project.':
    'התחברו עם לקוחות SQL סטנדרטיים, ORMs ועורך השאילתות בקונסולה. שמרו על סכמות ניידות, השתמשו בהרחבות שהסטאק שלכם צריך, ונהלו תפקידים, חיבורים וגיבויים לצד פרויקט Appwrite.',
  'Connections, schemas, and backups': 'חיבורים, סכמות וגיבויים',
  'Create a database, choose your engine and compute model, and query your first data in minutes.':
    'צרו מסד נתונים, בחרו מנוע ומודל Compute, ושאלו את הנתונים הראשונים שלכם תוך דקות.',
  'Databases docs': 'תיעוד מסדי נתונים',
  'Databases for every data model': 'מסדי נתונים לכל מודל נתונים',
  'Do Appwrite DBs integrate with Auth permissions?': 'האם Appwrite DBs משתלבים עם הרשאות אימות?',
  'Embeddings and similarity search for semantic retrieval and AI features.':
    'Embeddings וחיפוש דמיון לשליפה סמנטית וליכולות AI.',
  'Extensions, roles, SQL editor': 'הרחבות, תפקידים ועורך SQL',
  'Familiar MySQL compatibility for common relational apps and migrations.':
    'תאימות MySQL מוכרת לאפליקציות יחסיות נפוצות ולמיגרציות.',
  'Filter, order, and paginate from the SDKs and Console. Model related data with relationships, run multi-step writes in transactions, and use bulk operations when you need to update many rows or documents at once.':
    'סננו, מיינו ופגנו מה-SDKs ומהקונסולה. מדלו נתונים קשורים עם relationships, הריצו כתיבות מרובות שלבים ב-transactions, והשתמשו בפעולות bulk כשצריך לעדכן הרבה שורות או מסמכים בבת אחת.',
  'Five engines, two categories': 'חמישה מנועים, שתי קטגוריות',
  'Five engines for tables, documents, vectors, and native SQL':
    'חמישה מנועים לטבלאות, מסמכים, וקטורים ו-SQL מקורי',
  'Flexible JSON documents with filters and full-text search for evolving schemas.':
    'מסמכי JSON גמישים עם מסננים וחיפוש טקסט מלא לסכמות שמשתנות.',
  'Full SQL, extensions, and portable schemas for relational workloads and existing tooling.':
    'SQL מלא, הרחבות וסכמות ניידות לעומסים יחסיים ולכלים קיימים.',
  'HA enabled': 'HA פעיל',
  'Hot backups with zero downtime and fast recovery.': 'גיבויי hot ללא downtime ועם שחזור מהיר.',
  'How do replication and high availability work?': 'איך עובדים replication ו-high availability?',
  'Ideal for prototypes': 'אידיאלי לפרוטוטיפים',
  'Instant provisioning': 'הקצאה מיידית',
  'Isolated compute with replicas, HA, and PITR.': 'Compute מבודד עם replicas, HA ו-PITR.',
  'Isolated resources': 'משאבים מבודדים',
  'Legacy collections': 'Collections ישנים',
  'Legacy documents': 'מסמכים ישנים',
  'Link related tables without custom joins.': 'קשרו טבלאות קשורות בלי joins מותאמים.',
  'Native DBs': 'Native DBs',
  'Native SQL for PostgreSQL and MySQL': 'SQL מקורי ל-PostgreSQL ו-MySQL',
  'On dedicated databases you can add read replicas to scale query traffic and improve failover resilience. High availability is enabled when replica count is greater than zero. Configure sync mode and failover from the Console Replication settings for supported engines.':
    'במסדי נתונים ייעודיים אפשר להוסיף read replicas כדי להרחיב תעבורת שאילתות ולשפר חוסן failover. High availability מופעל כשמספר ה-replicas גדול מאפס. הגדירו מצב סנכרון ו-failover מהגדרות Replication בקונסולה למנועים נתמכים.',
  'Permissions wired to Auth': 'הרשאות שמחוברות לאימות',
  'Pick the engine that fits your workload, then scale it the same way. Appwrite Databases cover structured tables, documents, vectors, and native SQL, with serverless or dedicated compute, replication, backups, and point-in-time recovery.':
    'בחרו את המנוע שמתאים לעומס שלכם, ואז הגדילו אותו באותה דרך. Appwrite Databases מכסים טבלאות מובנות, מסמכים, וקטורים ו-SQL מקורי, עם Compute Serverless או ייעודי, replication, גיבויים ו-point-in-time recovery.',
  'Queries docs': 'תיעוד שאילתות',
  'Queries, relationships, and transactions': 'שאילתות, relationships ו-transactions',
  'Read replicas & HA': 'Read replicas ו-HA',
  'Relational-style tables, columns, and indexes for structured data and complex queries.':
    'טבלאות, עמודות ואינדקסים בסגנון יחסי לנתונים מובנים ולשאילתות מורכבות.',
  'Relationships': 'Relationships',
  'Replication and high availability': 'Replication ו-high availability',
  'Restore to a specific moment beyond the latest backup.': 'שחזרו לרגע מסוים מעבר לגיבוי האחרון.',
  'Row-level permissions': 'הרשאות ברמת שורה',
  'Scale query traffic and improve failover resilience.': 'הרחיבו תעבורת שאילתות ושפרו חוסן failover.',
  'Scope access with Auth users, teams, and roles.': 'הגדירו גישה עם משתמשי אימות, צוותים ותפקידים.',
  'Scope TablesDB and DocumentsDB access with users, teams, and roles from Appwrite Auth. Set rules at the table, collection, row, or document level so each tenant only sees their data.':
    'הגדירו גישה ל-TablesDB ו-DocumentsDB עם משתמשים, צוותים ותפקידים מ-Appwrite Auth. הגדירו כללים ברמת טבלה, collection, שורה או מסמך כדי שכל לקוח יראה רק את הנתונים שלו.',
  'Serverless databases run on a shared pool and are the fastest way to start. Billing is usage-based: there is no fixed compute fee, and you pay for storage plus reads and writes against your plan quota (then overage). Dedicated databases provision isolated compute for predictable performance, higher connection limits, and production options such as read replicas, high availability, and point-in-time recovery. Billing is a fixed monthly compute tier per database (from $10/mo), with reads and writes included in the tier. HA replicas and PITR are optional add-ons, and extra storage or bandwidth is billed as overage. You pick a specification when you create the database and can upgrade later.':
    'מסדי נתונים Serverless רצים על מאגר משותף והם הדרך המהירה ביותר להתחיל. החיוב מבוסס שימוש: אין דמי Compute קבועים, ומשלמים על אחסון ועל קריאות וכתיבות לפי מכסת התוכנית (ואחר כך overage). מסדי נתונים ייעודיים מקצים Compute מבודד לביצועים צפויים, מגבלות חיבור גבוהות יותר ואפשרויות פרודקשן כמו read replicas, high availability ו-point-in-time recovery. החיוב הוא שכבת Compute חודשית קבועה לכל מסד נתונים (מ-$10 לחודש), עם קריאות וכתיבות כלולות בשכבה. HA replicas ו-PITR הם add-ons אופציונליים, ואחסון או רוחב פס עודפים מחויבים כ-overage. בוחרים specification ביצירה ואפשר לשדרג אחר כך.',
  'Serverless or dedicated compute': 'Compute Serverless או ייעודי',
  'Shared compute pool': 'מאגר Compute משותף',
  'Shared pool. Fast to create, no capacity planning.': 'מאגר משותף. יצירה מהירה, בלי תכנון קיבולת.',
  'Start on a shared serverless pool when you want speed and simplicity. Move to dedicated specifications when you need isolated resources, higher connection limits, and production options like replicas and PITR. Choose at create time or upgrade later.':
    'התחילו במאגר Serverless משותף כשאתם רוצים מהירות ופשטות. עברו ל-specifications ייעודיים כשצריך משאבים מבודדים, מגבלות חיבור גבוהות יותר ואפשרויות פרודקשן כמו replicas ו-PITR. בחרו ביצירה או שדרגו אחר כך.',
  'Store and query data with TablesDB, DocumentsDB, VectorsDB, PostgreSQL, and MySQL. Choose serverless or dedicated, with replication, backups, and PITR.':
    'שמרו ושאלו נתונים עם TablesDB, DocumentsDB, VectorsDB, PostgreSQL ו-MySQL. בחרו Serverless או ייעודי, עם replication, גיבויים ו-PITR.',
  'Transactions': 'Transactions',
  'What database engines does Appwrite offer?': 'אילו מנועי מסדי נתונים Appwrite מציעה?',
  'What is the difference between serverless and dedicated databases?':
    'מה ההבדל בין מסדי נתונים Serverless לייעודיים?',
  'When should I use Appwrite DBs vs native PostgreSQL or MySQL?':
    'מתי להשתמש ב-Appwrite DBs מול PostgreSQL או MySQL מקוריים?',
  'Yes on Appwrite Cloud for supported plans and engines. Create automated backup policies or run manual backups from the Backups tab. Point-in-time recovery (PITR) on dedicated databases lets you restore to a specific moment beyond the latest scheduled backup, which helps after accidental deletes, failed migrations, or bad writes.':
    'כן ב-Appwrite Cloud לתוכניות ולמנועים נתמכים. צרו מדיניות גיבוי אוטומטית או הריצו גיבויים ידניים מלשונית Backups. Point-in-time recovery (PITR) במסדי נתונים ייעודיים מאפשר לשחזר לרגע מסוים מעבר לגיבוי המתוזמן האחרון, וזה עוזר אחרי מחיקות בטעות, מיגרציות שנכשלו או כתיבות שגויות.',
  'Yes. Appwrite DBs support filters, ordering, pagination, relationships, transactions, bulk operations, and geo queries through the SDKs and Console. Native PostgreSQL and MySQL databases support full SQL from the in-console editor and your existing SQL clients.':
    'כן. Appwrite DBs תומכים במסננים, מיון, pagination, relationships, transactions, פעולות bulk ושאילתות geo דרך ה-SDKs והקונסולה. מסדי PostgreSQL ו-MySQL מקוריים תומכים ב-SQL מלא מעורך הקונסולה ומלקוחות SQL קיימים.',
  'Yes. TablesDB and DocumentsDB permissions can reference users, teams, and roles from Appwrite Auth at the table, collection, row, and document level. Scope data per customer or workspace without building custom access control.':
    'כן. הרשאות TablesDB ו-DocumentsDB יכולות להפנות למשתמשים, צוותים ותפקידים מ-Appwrite Auth ברמת טבלה, collection, שורה ומסמך. הגדירו נתונים לפי לקוח או workspace בלי לבנות בקרת גישה מותאמת.',
  'Accepts writes and serves as the source of truth for replicas.':
    'מקבל כתיבות ומשמש כמקור האמת ל-replicas.',
  'Active Record and Data Mapper patterns for TypeScript.':
    'תבניות Active Record ו-Data Mapper ל-TypeScript.',
  'Connect with standard SQL clients and the in-console query editor. Keep portable schemas, use the extensions your stack needs, and manage roles, connections, and backups alongside your Appwrite project.':
    'התחברו עם לקוחות SQL סטנדרטיים ועורך השאילתות בקונסולה. שמרו על סכמות ניידות, השתמשו בהרחבות שהסטאק שלכם צריך, ונהלו תפקידים, חיבורים וגיבויים לצד פרויקט Appwrite.',
  'Dedicated databases run behind a connection pooler with a primary for writes and read replicas for query scale and failover. Choose asynchronous, synchronous, or quorum sync mode, then promote a replica when you need to move write traffic.':
    'מסדי נתונים ייעודיים רצים מאחורי מאגר חיבורים עם primary לכתיבות ו-read replicas להרחבת שאילתות ול-failover. בחרו מצב סנכרון Asynchronous, Synchronous או Quorum, ואז קדמו replica כשצריך להעביר תעבורת כתיבה.',
  'Lightweight TypeScript ORM with SQL-like query builder.':
    'ORM קל משקל ל-TypeScript עם בונה שאילתות דמוי SQL.',
  'Official PostgreSQL CLI for ad-hoc SQL and schema exploration.':
    'CLI רשמי של PostgreSQL ל-SQL נקודתי ולחקירת סכמות.',
  'On serverless databases, replication and high availability are abstracted and managed by the platform, so you do not configure replicas or sync mode yourself. On dedicated databases, traffic can enter through a connection pooler such as PgDog or ProxySQL. A primary instance accepts reads and writes, and read replicas scale query traffic and improve failover resilience. High availability is enabled when replica count is greater than zero. Choose asynchronous, synchronous, or quorum sync mode, and promote a replica from the Console when you need to move write traffic.':
    'במסדי נתונים Serverless, replication ו-high availability מופשטים ומנוהלים על ידי הפלטפורמה, כך שאין צורך להגדיר replicas או מצב סנכרון בעצמכם. במסדי נתונים ייעודיים תעבורה יכולה להיכנס דרך מאגר חיבורים כמו PgDog או ProxySQL. מופע primary מקבל קריאה וכתיבה, ו-read replicas מרחיבים תעבורת שאילתות ומשפרים חוסן failover. High availability מופעל כשמספר ה-replicas גדול מאפס. בחרו מצב סנכרון Asynchronous, Synchronous או Quorum, וקדמו replica מהקונסולה כשצריך להעביר תעבורת כתיבה.',
  'Promise-based ORM for Node.js with multi-dialect support.':
    'ORM מבוסס Promise ל-Node.js עם תמיכה במספר דיאלקטים.',
  'Proxy, primary, and read replicas for high availability.':
    'Proxy, primary ו-read replicas ל-high availability.',
  'Python SQL toolkit and ORM for expressive queries.':
    'ערכת כלים ו-ORM של SQL ל-Python לשאילתות אקספרסיביות.',
  'Routes client connections and pools traffic to the cluster.': 'מנתב חיבורי לקוח ומאגד תעבורה לאשכול.',
  'Scale query traffic and stand ready for failover promotion.':
    'מרחיבים תעבורת שאילתות ומוכנים לקידום ב-failover.',
  'Type-safe schema and client for Node.js and TypeScript.': 'סכמה ולקוח type-safe ל-Node.js ול-TypeScript.',
  'Use the same connection strings with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your SQL toolchain. Copy ready-made snippets from the Console Connect tab and keep shipping with the stack your team already knows.':
    'השתמשו באותם מחרוזות חיבור עם Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql ושאר כלי ה-SQL שלכם. העתיקו snippets מוכנים מלשונית Connect בקונסולה והמשיכו לשחרר עם הסטאק שהצוות כבר מכיר.',
  'Works with your ORM and toolstack': 'עובד עם ה-ORM והסטאק שלכם',

  // Firewall product page
  '24h ago': 'לפני 24 שעות',
  '5 attributes': '5 מאפיינים',
  'A specific Function execution endpoint.': 'Endpoint להרצת Function ספציפית.',
  'A specific Site deployment hostname.': 'Hostname של פריסת Site ספציפית.',
  'Actions docs': 'תיעוד פעולות',
  'All conditions must match (AND).': 'כל התנאים חייבים להתאים (AND).',
  'Allow the request and skip later Firewall rules.':
    'אפשרו את הבקשה ודלגו על כללי Firewall הבאים.',
  'Apply policies to the project API, a specific Function, or a specific Site. Keep production APIs locked down while preview sites and health checks stay reachable.':
    'החילו מדיניות על ה-API של הפרויקט, על Function ספציפית או על Site ספציפי. שמרו על APIs של פרודקשן נעולים בזמן שאתרי תצוגה מקדימה ובדיקות תקינות נשארים נגישים.',
  'Can I preview impact before enabling a rule?': 'האם אפשר לצפות בהשפעה לפני הפעלת כלל?',
  'Choose where the rule evaluates matching traffic.':
    'בחרו איפה הכלל מעריך תעבורה תואמת.',
  'Condition matching': 'התאמת תנאים',
  'Conditions docs': 'תיעוד תנאים',
  'Continue': 'המשך',
  'Control traffic before it reaches your app': 'שלטו בתעבורה לפני שהיא מגיעה לאפליקציה',
  'Create your first deny, bypass, rate limit, or redirect rule from the Console and preview impact before you enable it.':
    'צרו את כלל הדחייה, העקיפה, מגבלת הקצב או ההפניה הראשון מהקונסולה וצפו בהשפעה לפני ההפעלה.',
  'Define project rules that match requests by IP, path, method, country, or user agent, then deny, bypass, rate limit, or redirect them before they hit your API, Functions, or Sites.':
    'הגדירו כללי פרויקט שמתאימים בקשות לפי IP, נתיב, method, מדינה או user agent, ואז דחו, עקפו, הגבילו קצב או הפנו אותן לפני שהן מגיעות ל-API, ל-Functions או ל-Sites.',
  'Deny account mutations': 'דחיית שינויי account',
  'Deny, bypass, rate limit, or redirect': 'דחייה, עקיפה, מגבלת קצב או הפניה',
  'Each matching rule applies one action: Deny returns 403, Bypass allows the request and skips later rules, Rate limit throttles per client IP with a 429 when over quota, and Redirect sends clients to another location with a 3xx status. There is no separate Allow action. Use Bypass to allowlist traffic that should skip later deny or rate limit rules.':
    'כל כלל תואם מחיל פעולה אחת: Deny מחזיר 403, Bypass מאפשר את הבקשה ומדלג על כללים הבאים, Rate limit מגביל לפי IP של לקוח עם 429 מעל המכסה, ו-Redirect שולח לקוחות ליעד אחר עם סטטוס 3xx. אין פעולת Allow נפרדת. השתמשו ב-Bypass כדי לאפשר תעבורה שצריכה לדלג על כללי דחייה או מגבלת קצב מאוחרים יותר.',
  'Every rule applies one action when conditions match. Deny abusive traffic with 403, bypass trusted clients past later rules, throttle per IP with rate limits, or redirect to maintenance and migration URLs.':
    'כל כלל מחיל פעולה אחת כשהתנאים מתאימים. דחו תעבורה פוגענית עם 403, עקפו לקוחות מהימנים מעבר לכללים הבאים, הגבילו לפי IP עם מגבלות קצב, או הפנו לכתובות תחזוקה ומיגרציה.',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Conditions can filter on IP, request path, HTTP method, country, or user agent. Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    'כל כלל דורש לפחות תנאי אחד. כל התנאים בכלל חייבים להתאים (AND). תנאים יכולים לסנן לפי IP, נתיב בקשה, HTTP method, מדינה או user agent. כללים מוערכים לפי עדיפות (מספרים נמוכים קודם). הכלל המופעל הראשון שמתאים קובע את התוצאה ומפסיק את ההערכה.',
  'Filter by IP address, request path, HTTP method, country, or user agent. Combine conditions with AND so a rule only fires when every filter matches.':
    'סננו לפי כתובת IP, נתיב בקשה, HTTP method, מדינה או user agent. שלבו תנאים עם AND כדי שכלל יופעל רק כשכל המסננים מתאימים.',
  'First match': 'התאמה ראשונה',
  'Firewall is available on Appwrite Cloud. Rule limits depend on your organization plan. Disabled rules still count toward plan limits but are not evaluated.':
    'Firewall זמין ב-Appwrite Cloud. מגבלות כללים תלויות בתוכנית הארגון. כללים מושבתים עדיין נספרים במגבלות התוכנית אבל לא מוערכים.',
  'Firewall overview': 'סקירת Firewall',
  'Firewall rules run on Appwrite Cloud before traffic reaches your project resources. Scope a rule to the project API, a specific Function, or a specific Site. Console traffic is never blocked, so you can keep managing rules even when deny or rate limit policies are active.':
    'כללי Firewall רצים ב-Appwrite Cloud לפני שהתעבורה מגיעה למשאבי הפרויקט. הגדירו היקף לכלל ל-API של הפרויקט, ל-Function ספציפית או ל-Site ספציפי. תעבורת הקונסולה אף פעם לא נחסמת, כך שתוכלו להמשיך לנהל כללים גם כשמדיניות דחייה או מגבלת קצב פעילה.',
  'Functions and Sites scopes': 'היקפי Functions ו-Sites',
  'How do conditions and priority work together?': 'איך תנאים ועדיפות עובדים יחד?',
  'Impact before enable': 'השפעה לפני הפעלה',
  'Impact preview docs': 'תיעוד תצוגת השפעה',
  'Is Firewall available on every plan?': 'האם Firewall זמין בכל תוכנית?',
  'Last 24 hours': '24 השעות האחרונות',
  'Lower numbers evaluate first. The first match stops the chain.':
    'מספרים נמוכים מוערכים קודם. ההתאמה הראשונה עוצרת את השרשרת.',
  'Lower priority numbers evaluate first. Place bypass allowlists ahead of broader deny or rate limit rules so trusted traffic skips the rest of the chain.':
    'מספרי עדיפות נמוכים מוערכים קודם. הציבו רשימות עקיפה לפני כללי דחייה או מגבלת קצב רחבים יותר כדי שתעבורה מהימנה תדלג על שאר השרשרת.',
  'Match on the request attributes that matter': 'התאימו לפי מאפייני הבקשה שחשובים',
  'Matched': 'תואמות',
  'Matched requests over time': 'בקשות תואמות לאורך זמן',
  'Monitor denied, limited, and redirected traffic': 'עקבו אחרי תעבורה שנדחתה, הוגבלה או הופנתה',
  'Monitor docs': 'תיעוד ניטור',
  'Now': 'עכשיו',
  'Office IP allowlist': 'רשימת IP משרדי',
  'One action per matching rule. Evaluation stops at the first match.':
    'פעולה אחת לכל כלל תואם. ההערכה נעצרת בהתאמה הראשונה.',
  'Passed': 'עברו',
  'Path starts with /v1/account': 'הנתיב מתחיל ב-/v1/account',
  'Plan limits': 'מגבלות תוכנית',
  'Preview impact before you enable': 'צפו בהשפעה לפני ההפעלה',
  'Priority decides the first match': 'עדיפות קובעת את ההתאמה הראשונה',
  'Priority docs': 'תיעוד עדיפות',
  'Project REST and GraphQL endpoints.': 'Endpoints של REST ו-GraphQL בפרויקט.',
  'Protect project APIs, Functions, and Sites with Appwrite Firewall. Create rules to deny, bypass, rate limit, or redirect matching traffic from the Console.':
    'הגנו על APIs, Functions ו-Sites של הפרויקט עם Appwrite Firewall. צרו כללים לדחייה, עקיפה, מגבלת קצב או הפניה של תעבורה תואמת מהקונסולה.',
  'Rate limit public API': 'מגבלת קצב ל-API ציבורי',
  'Rate limited': 'הוגבלו בקצב',
  'Recent requests that would match these conditions.':
    'בקשות אחרונות שהיו מתאימות לתנאים האלה.',
  'Reject matching requests before they reach your project.':
    'דחו בקשות תואמות לפני שהן מגיעות לפרויקט.',
  'Resource scope': 'היקף משאב',
  'Resource scopes': 'היקפי משאבים',
  'Rule actions': 'פעולות כלל',
  'Rule conditions': 'תנאי כלל',
  'Rule priority': 'עדיפות כלל',
  'Rules': 'כללים',
  'Scope rules to API, Functions, or Sites': 'הגדירו היקף כללים ל-API, Functions או Sites',
  'Scopes docs': 'תיעוד היקפים',
  'Send matching clients to another location with a 3xx status.':
    'שלחו לקוחות תואמים ליעד אחר עם סטטוס 3xx.',
  'Skipped': 'דולגו',
  'Start protecting with Firewall': 'התחילו להגן עם Firewall',
  'The Firewall page chart summarizes Passed request volume alongside Denied, Rate limited, Redirected, and Challenged series for the selected date range. Bypass matches and under-quota rate limit matches allow traffic without publishing a Firewall outcome metric. Use the overview with your rules list to verify policies after enablement.':
    'התרשים בעמוד Firewall מסכם נפח בקשות Passed לצד סדרות Denied, Rate limited, Redirected ו-Challenged לטווח התאריכים שנבחר. התאמות Bypass והתאמות מגבלת קצב מתחת למכסה מאפשרות תעבורה בלי לפרסם מדד תוצאה של Firewall. השתמשו בסקירה יחד עם רשימת הכללים כדי לאמת מדיניות אחרי הפעלה.',
  'Throttle matching requests that exceed a per-IP quota.':
    'הגבילו בקשות תואמות שחורגות ממכסה לפי IP.',
  'Track request volume alongside denied, rate-limited, redirected, and challenged outcomes on the Firewall page. Confirm policies after enablement without leaving the Console.':
    'עקבו אחרי נפח בקשות לצד תוצאות דחייה, מגבלת קצב, הפניה ו-challenge בעמוד Firewall. אשרו מדיניות אחרי הפעלה בלי לעזוב את הקונסולה.',
  'What can Firewall protect?': 'מה Firewall יכול להגן?',
  'What does traffic overview show?': 'מה סקירת התעבורה מציגה?',
  'Which actions can a rule take?': 'אילו פעולות כלל יכול לבצע?',
  'While creating a rule, estimate how many recent requests would match your conditions for the selected scope and date range. Tighten filters before traffic is affected.':
    'בזמן יצירת כלל, העריכו כמה בקשות אחרונות היו מתאימות לתנאים שלכם להיקף ולטווח התאריכים שנבחרו. הדקו מסננים לפני שהתעבורה מושפעת.',
  'Yes. While creating a rule, the Console estimates how many recent usage events would match your current conditions for the selected resource scope and date range. Use that preview to tighten filters before you enable the rule, then confirm outcomes in traffic overview.':
    'כן. בזמן יצירת כלל, הקונסולה מעריכה כמה אירועי שימוש אחרונים היו מתאימים לתנאים הנוכחיים להיקף המשאב ולטווח התאריכים שנבחרו. השתמשו בתצוגה המקדימה כדי להדק מסננים לפני הפעלת הכלל, ואז אשרו תוצאות בסקירת התעבורה.',

  // Firewall product page updates
  '10+': '10+',
  'Act on automated traffic with score-based conditions.':
    'פעלו על תעבורה אוטומטית עם תנאים מבוססי ציון.',
  'ASN': 'ASN',
  'Bot score': 'ציון בוט',
  'Combine attributes with AND so a rule only fires when every filter matches.':
    'שלבו מאפיינים עם AND כדי שכלל יופעל רק כשכל המסננים מתאימים.',
  'Create your first deny, bypass, rate limit, redirect, or challenge rule from the Console and preview impact before you enable it.':
    'צרו את כלל הדחייה, העקיפה, מגבלת הקצב, ההפניה או ה-Challenge הראשון מהקונסולה וצפו בהשפעה לפני ההפעלה.',
  'Define project rules that match rich request attributes, then deny, bypass, rate limit, redirect, or challenge traffic before it hits your API, Functions, or Sites.':
    'הגדירו כללי פרויקט שמתאימים מאפייני בקשה עשירים, ואז דחו, עקפו, הגבילו קצב, הפנו או אתגרו תעבורה לפני שהיא מגיעה ל-API, ל-Functions או ל-Sites.',
  'Deny, bypass, rate limit, redirect, or challenge':
    'דחייה, עקיפה, מגבלת קצב, הפניה או Challenge',
  'Each matching rule applies one action: Deny returns 403, Bypass allows the request and skips later rules, Rate limit throttles per client IP with a 429 when over quota, Redirect sends clients to another location with a 3xx status, and Challenge verifies suspicious clients before allowing them through. There is no separate Allow action. Use Bypass to allowlist traffic that should skip later deny, rate limit, or challenge rules.':
    'כל כלל תואם מחיל פעולה אחת: Deny מחזיר 403, Bypass מאפשר את הבקשה ומדלג על כללים הבאים, Rate limit מגביל לפי IP של לקוח עם 429 מעל המכסה, Redirect שולח לקוחות ליעד אחר עם סטטוס 3xx, ו-Challenge מאמת לקוחות חשודים לפני שמאפשר להם להמשיך. אין פעולת Allow נפרדת. השתמשו ב-Bypass כדי לאפשר תעבורה שצריכה לדלג על כללי דחייה, מגבלת קצב או Challenge מאוחרים יותר.',
  'Every rule applies one action when conditions match. Deny abusive traffic, bypass trusted clients, throttle per IP, redirect to maintenance URLs, or challenge suspicious requests before they continue.':
    'כל כלל מחיל פעולה אחת כשהתנאים מתאימים. דחו תעבורה פוגענית, עקפו לקוחות מהימנים, הגבילו לפי IP, הפנו לכתובות תחזוקה, או אתגרו בקשות חשודות לפני שהן ממשיכות.',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Conditions can filter on IP, request path, HTTP method, country, user agent, ASN, headers, query parameters, TLS fingerprints, bot score, and more. Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    'כל כלל דורש לפחות תנאי אחד. כל התנאים בכלל חייבים להתאים (AND). תנאים יכולים לסנן לפי IP, נתיב בקשה, HTTP method, מדינה, user agent, ASN, headers, פרמטרי query, טביעות TLS, ציון בוט ועוד. כללים מוערכים לפי עדיפות (מספרים נמוכים קודם). הכלל המופעל הראשון שמתאים קובע את התוצאה ומפסיק את ההערכה.',
  'Filter by IP, path, method, country, user agent, ASN, headers, query parameters, TLS fingerprints, bot score, and more. Combine conditions with AND so a rule only fires when every filter matches.':
    'סננו לפי IP, נתיב, method, מדינה, user agent, ASN, headers, פרמטרי query, טביעות TLS, ציון בוט ועוד. שלבו תנאים עם AND כדי שכלל יופעל רק כשכל המסננים מתאימים.',
  'Filter on query keys and values without changing path rules.':
    'סננו לפי מפתחות וערכי query בלי לשנות כללי נתיב.',
  'Geo allow or deny by resolved ISO country code.':
    'אפשרו או דחו לפי קוד מדינה ISO שזוהה.',
  'Header': 'Header',
  'Identify clients by TLS fingerprint when IPs rotate.':
    'זהו לקוחות לפי טביעת TLS כשכתובות IP מתחלפות.',
  'JA4 fingerprint': 'טביעת JA4',
  'Lower priority numbers evaluate first. Place bypass allowlists ahead of broader deny, rate limit, or challenge rules so trusted traffic skips the rest of the chain.':
    'מספרי עדיפות נמוכים מוערכים קודם. הציבו רשימות עקיפה לפני כללי דחייה, מגבלת קצב או Challenge רחבים יותר כדי שתעבורה מהימנה תדלג על שאר השרשרת.',
  'Match exact client IPs for allowlists and denylists.':
    'התאימו כתובות IP מדויקות לרשימות היתר וחסימה.',
  'Match on rich request attributes': 'התאימו לפי מאפייני בקשה עשירים',
  'Match request headers for tokens, clients, or custom signals.':
    'התאימו headers של בקשה לטוקנים, לקוחות או אותות מותאמים.',
  'Match traffic by autonomous system for hosting and ISP ranges.':
    'התאימו תעבורה לפי מערכת אוטונומית לטווחי hosting ו-ISP.',
  'Monitor denied, limited, redirected, and challenged traffic':
    'עקבו אחרי תעבורה שנדחתה, הוגבלה, הופנתה או עברה Challenge',
  'Present a challenge before allowing suspicious clients through.':
    'הציגו Challenge לפני שמאפשרים ללקוחות חשודים להמשיך.',
  'Protect prefixes like /v1/account or sensitive routes.':
    'הגנו על קידומות כמו /v1/account או נתיבים רגישים.',
  'Protect project APIs, Functions, and Sites with Appwrite Firewall. Create rules to deny, bypass, rate limit, redirect, or challenge matching traffic from the Console.':
    'הגנו על APIs, Functions ו-Sites של הפרויקט עם Appwrite Firewall. צרו כללים לדחייה, עקיפה, מגבלת קצב, הפניה או Challenge של תעבורה תואמת מהקונסולה.',
  'Query parameter': 'פרמטר query',
  'Restrict mutating methods such as POST, PUT, and DELETE.':
    'הגבילו מתודות משנות כמו POST, PUT ו-DELETE.',
  'See how many recent requests would match before you enable a rule.':
    'ראו כמה בקשות אחרונות היו מתאימות לפני שתפעילו כלל.',
  'Filter bots, scripts, monitors, and known clients.':
    'סננו בוטים, סקריפטים, מוניטורים ולקוחות מוכרים.',

  'Build rules from the request properties that matter to your app. Combine conditions so a rule only fires when every filter matches.':
    'בנו כללים ממאפייני הבקשה שחשובים לאפליקציה שלכם. שלבו תנאים כדי שכלל יופעל רק כשכל המסננים מתאימים.',
  'Define project rules that match the request properties you care about, then deny, bypass, rate limit, redirect, or challenge traffic before it hits your API, Functions, or Sites.':
    'הגדירו כללי פרויקט שמתאימים למאפייני הבקשה שחשובים לכם, ואז דחו, עקפו, הגבילו קצב, הפנו או אתגרו תעבורה לפני שהיא מגיעה ל-API, ל-Functions או ל-Sites.',
  'Every condition on a rule must match before the action runs.':
    'כל תנאי בכלל חייב להתאים לפני שהפעולה רצה.',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    'כל כלל דורש לפחות תנאי אחד. כל התנאים בכלל חייבים להתאים (AND). כללים מוערכים לפי עדיפות (מספרים נמוכים קודם). הכלל המופעל הראשון שמתאים קובע את התוצאה ומפסיק את ההערכה.',
  'Match on request properties such as identity, location, path, and client signals.':
    'התאימו לפי מאפייני בקשה כמו זהות, מיקום, נתיב ואותות לקוח.',
  'Estimate how much recent traffic a draft rule would affect, then refine conditions before you turn it on.':
    'העריכו כמה תעבורה אחרונה כלל טיוטה ישפיע עליה, ואז הדקו תנאים לפני ההפעלה.',
  'See how much recent traffic a draft rule would affect.':
    'ראו כמה תעבורה אחרונה כלל טיוטה ישפיע עליה.',
  'Incoming': 'נכנסות',
  'Your app': 'האפליקציה שלכם',
}



