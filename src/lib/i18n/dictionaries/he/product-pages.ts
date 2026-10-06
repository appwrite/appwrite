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
  'Appwrite Domains docs': 'תיעוד Appwrite Domains',
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
  'AutoGravity does not crop this file. It returns a focal point that the preview crop should keep visible.':
    'AutoGravity לא חותכת את הקובץ הזה. היא מחזירה נקודת מוקד שחיתוך ה-preview צריך להשאיר גלויה.',
  'AutoGravity focal point': 'נקודת מוקד של AutoGravity',
  'Automatic crop gravity': 'מוקד חיתוך אוטומטי',
  'Face bounding box, if confidence is high': 'תיבת פנים, אם רמת הביטחון גבוהה',
  'It asks AutoGravity to pick a crop focus. YuNet runs first and uses a face bounding box when confidence is high enough. Otherwise U²-Net saliency finds the strongest connected region and returns its weighted centroid as a normalized (x, y) point. The preview endpoint crops around that point. Fixed gravity values still work as before.':
    'זה מבקש מ-AutoGravity לבחור מוקד חיתוך. YuNet רץ קודם ומשתמש בתיבת פנים כשהביטחון מספיק גבוה. אחרת סאליינס של U²-Net מוצא את האזור המחובר החזק ביותר ומחזיר את מרכז המשקל שלו כנקודה מנורמלת (x, y). ה-preview חותך סביב הנקודה הזו. ערכי gravity קבועים ממשיכים לעבוד כמו קודם.',
  'Normalized focal point for the crop': 'נקודת מוקד מנורמלת לחיתוך',
  'Pass gravity=auto on file preview and Appwrite picks a focal point from the image. YuNet looks for a face first. If none is confident enough, U²-Net saliency finds the strongest subject. The service returns a normalized (x, y) coordinate. Existing values like center and top-right stay unchanged.':
    'העבירו gravity=auto ב-preview של הקובץ, ו-Appwrite בוחרת נקודת מוקד מהתמונה. YuNet מחפש פנים קודם. אם אין זיהוי מספיק בטוח, סאליינס של U²-Net מוצא את הנושא הבולט ביותר. השירות מחזיר קואורדינטה מנורמלת (x, y). ערכים קיימים כמו center ו-top-right נשארים ללא שינוי.',
  'Saliency map, then strongest connected region': 'מפת סאליינס, ואז האזור המחובר החזק ביותר',
  'Square crop using gravity=auto, subject kept in view': 'חיתוך ריבועי עם gravity=auto, הנושא נשאר בתמונה',
  'Square crop using gravity=center, mostly empty field': 'חיתוך ריבועי עם gravity=center, בעיקר שדה ריק',
  'The middle of the frame is empty grass.': 'אמצע הפריים הוא דשא ריק.',
  'The same 400×400 request keeps the subject.': 'אותה בקשת 400×400 משאירה את הנושא.',
  'Keeps the subject.': 'משאיר את הנושא.',
  'Misses the subject.': 'מפספס את הנושא.',
  'What does gravity=auto do on image previews?': 'מה gravity=auto עושה בתצוגות מקדימות של תמונות?',
  'Wide source photograph with the subject on the left': 'צילום מקור רחב עם הנושא בצד שמאל',
  'YuNet looks for a face, then U²-Net saliency. AutoGravity returns a normalized (x, y) point. The uploaded file is not cropped.':
    'YuNet מחפש פנים, ואז סאליינס של U²-Net. AutoGravity מחזירה נקודה מנורמלת (x, y). הקובץ שהועלה לא נחתך.',
  'YuNet, then U²-Net. File is not cropped.': 'YuNet, ואז U²-Net. הקובץ לא נחתך.',
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
  'Connect GitHub or Origin and ship on every push. Commits to your production branch build and auto-activate on your primary domain; other branches get preview links for org members to review before merge.':
    'חברו GitHub או Origin ושחררו בכל push. Commits ל-Branch הפרודקשן נבנים ומופעלים אוטומטית על הדומיין הראשי; Branches אחרים מקבלים קישורי תצוגה מקדימה לחברי הארגון לבדיקה לפני merge.',
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
  'Email: Resend, SendGrid, Mailgun, Amazon SES, and SMTP. SMS: Twilio, Vonage, MSG91, Telesign, and Textmagic. Push: APNS and FCM. Configure multiple providers per channel and choose which one to use when sending. Discord and Slack chat integrations are coming soon.':
    'אימייל: Resend, SendGrid, Mailgun, Amazon SES ו-SMTP. SMS: Twilio, Vonage, MSG91, Telesign ו-Textmagic. Push: APNS ו-FCM. הגדירו מספר ספקים לכל ערוץ ובחרו איזה להשתמש בשליחה. אינטגרציות צ\'אט ל-Discord ו-Slack בקרוב.',
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
  'Start from official quick-starts or pick a template in the create wizard. Filter by framework and use case, connect GitHub or Origin, and deploy with build settings already tuned for Appwrite.':
    'התחילו מ-Quick-starts רשמיים או בחרו תבנית באשף היצירה. סננו לפי framework ומקרה שימוש, חברו GitHub או Origin ופרסו עם הגדרות Build שכבר מותאמות ל-Appwrite.',
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
  'Use the preview endpoint to resize, crop, convert format, set quality, add borders, and rotate from a single upload. Pass gravity=auto and YuNet looks for a face first. If none is confident enough, U²-Net saliency returns a normalized (x, y) crop focus. Fixed gravity values still work.':
    'השתמשו ב-preview endpoint לשינוי גודל, חיתוך, המרת פורמט, איכות, מסגרות וסיבוב מהעלאה אחת. העבירו gravity=auto ו-YuNet מחפש פנים קודם. אם אין זיהוי מספיק בטוח, סאליינס של U²-Net מחזיר מוקד חיתוך מנורמל (x, y). ערכי gravity קבועים ממשיכים לעבוד.',
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
  'Yes. Browse templates from Sites > Templates in the Console and filter by framework or use case. The create wizard walks you through GitHub or Origin setup, production branch, environment variables, and domain configuration. Official quick-starts cover Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, and more.':
    'כן. עיינו בתבניות מ-Sites > Templates בקונסולה וסננו לפי framework או מקרה שימוש. אשף היצירה מלווה אתכם בהגדרת GitHub או Origin, Branch פרודקשן, משתני סביבה והגדרת דומיין. Quick-starts רשמיים מכסים Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start ועוד.',
  'Yes. Buckets and files have no permissions by default, so access is denied until you grant read, create, update, or delete to users, teams, or roles. Enable file security on a bucket to set per-file permissions on top of bucket defaults.':
    'כן. לבאקטים וקבצים אין הרשאות כברירת מחדל, כך שהגישה נדחית עד שמעניקים קריאה, יצירה, עדכון או מחיקה למשתמשים, צוותים או תפקידים. הפעילו אבטחת קבצים על באקט כדי להגדיר הרשאות לפי קובץ מעל ברירות המחדל של הבאקט.',
  'Yes. Compose email, SMS, and push from the Console Messages tab or call createEmail, createSms, and createPush from the Server SDK. Send immediately, save as a draft, or pass scheduledAt for later delivery. Every message appears in the Messages tab with status (draft, scheduled, processing, failed, or success) and delivery timestamps.':
    'כן. הכינו אימייל, SMS ו-push מלשונית Messages בקונסולה או קראו ל-createEmail, createSms ו-createPush מ-Server SDK. שלחו מיד, שמרו כטיוטה או העבירו scheduledAt למשלוח מאוחר יותר. כל הודעה מופיעה בלשונית Messages עם סטטוס (טיוטה, מתוזמן, בעיבוד, נכשל או הצלחה) וחותמות זמן משלוח.',
  'Yes. Enable gzip or zstd compression per bucket from Settings. Compression applies to new uploads and helps reduce storage and bandwidth costs. Files larger than 20 MB skip compression even when enabled.':
    'כן. הפעילו דחיסת gzip או zstd לכל באקט מהגדרות. הדחיסה חלה על העלאות חדשות ועוזרת להפחית עלויות אחסון ורוחב פס. קבצים גדולים מ-20 MB מדלגים על דחיסה גם כשהיא מופעלת.',
  'Yes. Every function gets a generated domain and you can add custom domains on Appwrite Cloud. Pass x-appwrite-user-jwt to authenticate users and respect Auth permissions inside your function.':
    'כן. לכל פונקציה יש דומיין שנוצר אוטומטית ואפשר להוסיף דומיינים מותאמים ב-Appwrite Cloud. העבירו x-appwrite-user-jwt לאימות משתמשים ולכיבוד הרשאות אימות בתוך הפונקציה.',
  'Yes. Functions receive an ephemeral API key and run with project context. Configure scopes in Settings, then call Databases, Storage, Messaging, Auth, and other APIs from server SDKs inside your handler.':
    'כן. פונקציות מקבלות מפתח API זמני ורצות בהקשר פרויקט. הגדירו scopes בהגדרות, ואז קראו למסדי נתונים, אחסון, הודעות, אימות ו-APIs אחרים מ-server SDKs בתוך ה-handler.',
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
  'Does Appwrite offer managed PostgreSQL?': 'האם Appwrite מציעה PostgreSQL מנוהל?',
  'Managed PostgreSQL and databases for every model':
    'PostgreSQL מנוהל ומסדי נתונים לכל מודל',
  'Managed PostgreSQL and MySQL': 'PostgreSQL ו-MySQL מנוהלים',
  'Managed PostgreSQL hosting and app databases':
    'אירוח PostgreSQL מנוהל ומסדי נתוני אפליקציה',
  'Managed PostgreSQL hosting with full SQL, pgvector, and portable schemas for Prisma, Drizzle, and existing tooling.':
    'אירוח PostgreSQL מנוהל עם SQL מלא, pgvector וסכמות ניידות ל-Prisma, Drizzle ולכלים קיימים.',
  'Managed PostgreSQL hosting with pgvector, plus TablesDB, DocumentsDB, and VectorsDB. Dedicated compute, backups, replicas, and PITR.':
    'אירוח PostgreSQL מנוהל עם pgvector, לצד TablesDB, DocumentsDB ו-VectorsDB. Compute ייעודי, גיבויים, replicas ו-PITR.',
  'PostgreSQL docs': 'תיעוד PostgreSQL',
  'PostgreSQL quick start': 'Quick start ל-PostgreSQL',
  'Dedicated managed PostgreSQL and MySQL engines you connect to with standard SQL clients.':
    'מנועי PostgreSQL ו-MySQL מנוהלים ייעודיים שמתחברים אליהם עם לקוחות SQL סטנדרטיים.',
  'Hosted PostgreSQL and MySQL with standard clients and the in-console SQL editor. Use pgvector, PostGIS, portable schemas, and the ORMs you already run.':
    'PostgreSQL ו-MySQL מתארחים עם לקוחות סטנדרטיים ועורך SQL בקונסולה. השתמשו ב-pgvector, PostGIS, סכמות ניידות וב-ORMs שכבר רצים אצלכם.',
  'Run managed PostgreSQL next to tables, documents, and vectors. Connect with Prisma or psql, install pgvector, and scale dedicated compute with backups, replicas, and PITR.':
    'הריצו PostgreSQL מנוהל לצד טבלאות, מסמכים ווקטורים. התחברו עם Prisma או psql, התקינו pgvector, והגדילו Compute ייעודי עם גיבויים, replicas ו-PITR.',
  'Yes. Native PostgreSQL databases are dedicated, managed PostgreSQL instances in your project region. You connect with psql, Prisma, Drizzle, or any PostgreSQL driver over TLS. Appwrite provisions compute, backups, replicas, a connection pooler, and point-in-time recovery. PostgreSQL 18 is the default, with 17 also supported.':
    'כן. מסדי PostgreSQL מקוריים הם מופעי PostgreSQL מנוהלים ייעודיים באזור הפרויקט שלכם. מתחברים עם psql, Prisma, Drizzle או כל דרייבר PostgreSQL מעל TLS. Appwrite מקצה Compute, גיבויים, replicas, מאגר חיבורים ו-point-in-time recovery. PostgreSQL 18 הוא ברירת המחדל, וגם 17 נתמך.',
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
  'Managed PostgreSQL': 'PostgreSQL מנוהל',
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
  '3 online': '3 מחוברים',
  'Event': 'אירוע',
  'Your order has shipped': 'ההזמנה שלכם נשלחה',
  'Track your delivery in the app.': 'עקבו אחרי המשלוח באפליקציה.',
  'Welcome to Acme': 'ברוכים הבאים ל-Acme',
  'Cloning repository': 'שכפול המאגר',
  'Building': 'בבנייה',
  'Deploying': 'בפריסה',
  'Deny': 'חסימה',
  'Bypass': 'עקיפה',
  'Runtime': 'סביבת ריצה',
  'Endpoint': 'Endpoint',
  'Denied': 'נחסמו',
  'Redirected': 'הופנו מחדש',
  'Draft': 'טיוטה',
  'Edge SSR': 'SSR בקצה',
  'US East': 'מזרח ארה"ב',
  'EU West': 'מערב אירופה',
  'AP South': 'דרום אסיה-פסיפיק',
  'Deploy from CI or your terminal with the Appwrite CLI and appwrite.config.json.':
    'פרסו מ-CI או מהטרמינל עם Appwrite CLI ו-appwrite.config.json.',

  // Managed PostgreSQL product page
  'Managed PostgreSQL hosting': 'אחסון PostgreSQL מנוהל',
  'Managed PostgreSQL hosting on Appwrite. Every database is provisioned for your project with a TLS hostname, connection pooling, extensions like pgvector, backups, PITR, replicas, and branches.':
    'אחסון PostgreSQL מנוהל ב-Appwrite. כל מסד נתונים מוקם עבור הפרויקט שלכם עם hostname מאובטח ב-TLS, connection pooling, הרחבות כמו pgvector, גיבויים, PITR, רפליקות ו-Branches.',
  'Raw Postgres, fully managed': 'Postgres גולמי, מנוהל לחלוטין',
  'Appwrite runs the engine and you keep the SQL: connect with psql or any driver, bring your own ORM and migrations, and use the full PostgreSQL feature set with no Appwrite layer in between. Each database is provisioned in your project region with its own hostname, credentials, and TLS.':
    'Appwrite מריצה את המנוע ואתם שומרים על ה-SQL: התחברו עם psql או עם כל driver, הביאו ORM ומיגרציות משלכם והשתמשו בכל יכולות PostgreSQL בלי שכבה של Appwrite באמצע. כל מסד נתונים מוקם באזור הפרויקט שלכם עם hostname, פרטי גישה ו-TLS משלו.',
  'PostgreSQL 18 by default': 'PostgreSQL 18 כברירת מחדל',
  '6 regions': '6 אזורים',
  'Every Appwrite Cloud region': 'כל אזור של Appwrite Cloud',
  'High availability maximum': 'מקסימום לזמינות גבוהה',
  'Restore to any moment': 'שחזור לכל רגע',
  'Smallest specification': 'המפרט הקטן ביותר',
  'Start building on managed PostgreSQL': 'התחילו לבנות על PostgreSQL מנוהל',
  'Create a database in your project region, copy the connection string, and run your first query in minutes.':
    'צרו מסד נתונים באזור הפרויקט שלכם, העתיקו את מחרוזת החיבור והריצו את השאילתה הראשונה שלכם בתוך דקות.',

  // Managed PostgreSQL FAQ
  'How is managed PostgreSQL different from TablesDB, DocumentsDB, and VectorsDB?':
    'במה PostgreSQL מנוהל שונה מ-TablesDB, מ-DocumentsDB ומ-VectorsDB?',
  'A managed PostgreSQL database is the raw engine, provisioned for your project with its own compute, storage, networking, and credentials. You talk to it over the PostgreSQL wire protocol instead of an Appwrite SDK, so your schema, migrations, roles, and queries are standard PostgreSQL. Use TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs, platform permissions, and serverless scaling for app data instead.':
    'מסד נתונים מנוהל של PostgreSQL הוא המנוע עצמו, שמוקם עבור הפרויקט שלכם עם מחשוב, אחסון, רשת ופרטי גישה משלו. אתם מדברים איתו דרך פרוטוקול התקשורת של PostgreSQL ולא דרך SDK של Appwrite, ולכן הסכימה, המיגרציות, התפקידים והשאילתות שלכם הם PostgreSQL רגיל. השתמשו ב-TablesDB, ב-DocumentsDB או ב-VectorsDB כשאתם רוצים SDKs של Appwrite, הרשאות פלטפורמה וקנה מידה serverless לנתוני האפליקציה.',
  'TablesDB docs': 'תיעוד TablesDB',
  'DocumentsDB docs': 'תיעוד DocumentsDB',
  'VectorsDB docs': 'תיעוד VectorsDB',
  'Which PostgreSQL versions can I run, and can I upgrade later?':
    'אילו גרסאות PostgreSQL אפשר להריץ, והאם אפשר לשדרג בהמשך?',
  'New databases run PostgreSQL 18 by default, and you can create one on PostgreSQL 17 by passing a version at create time. The version can be upgraded later and upgrades run online: a second instance is provisioned on the new version, data streams over with logical replication, and traffic cuts over once replication catches up. Reads continue throughout, but the old instance is fenced read-only at the cutover and client connections are closed, so your application has to reconnect. Check that your installed extensions support the target version first.':
    'מסדי נתונים חדשים רצים על PostgreSQL 18 כברירת מחדל, ואפשר ליצור מסד על PostgreSQL 17 על ידי העברת גרסה בזמן היצירה. אפשר לשדרג את הגרסה בהמשך, והשדרוגים מתבצעים בזמן ריצה: instance שני מוקם בגרסה החדשה, הנתונים זורמים אליו עם logical replication, והתעבורה עוברת אליו ברגע שהרפליקציה מדביקה את הפער. הקריאות ממשיכות כל העת, אבל ה-instance הישן נחסם לקריאה בלבד ברגע המעבר וחיבורי הלקוח נסגרים, כך שהאפליקציה שלכם צריכה להתחבר מחדש. בדקו קודם שההרחבות המותקנות שלכם תומכות בגרסת היעד.',
  'Maintenance docs': 'תיעוד תחזוקה',
  'Which regions can I run a database in?': 'באילו אזורים אפשר להריץ מסד נתונים?',
  'Managed PostgreSQL is available in every Appwrite Cloud region: Frankfurt, New York, San Francisco, Singapore, Sydney, and Toronto. A database takes the region of the project that owns it, so there is no per-database region selector. Create your project close to your users first, then create the database. Each database gets a hostname in the form db-<hash>.<region>.appwrite.center and the data does not leave the region.':
    'PostgreSQL מנוהל זמין בכל אזור של Appwrite Cloud: פרנקפורט, ניו יורק, סן פרנסיסקו, סינגפור, סידני וטורונטו. מסד נתונים מקבל את האזור של הפרויקט שמחזיק אותו, ולכן אין בחירת אזור לכל מסד נתונים בנפרד. צרו קודם את הפרויקט שלכם קרוב למשתמשים, ואז צרו את מסד הנתונים. כל מסד נתונים מקבל hostname בצורה db-<hash>.<region>.appwrite.center והנתונים לא יוצאים מהאזור.',
  'Cloud regions': 'אזורי Cloud',
  'How do specifications and pricing work?': 'איך מפרטים ותמחור עובדים?',
  'Each database runs against one compute specification that sets its CPU, memory, included storage, bandwidth, and connection limit. There are twelve tiers, from 1 core and 1 GB at $10 per month up to 32 cores and 256 GB, billed monthly and pro-rated by the hour the database is running. Storage and bandwidth beyond the tier allowance are billed as overage, and optional features are add-ons: each high availability replica costs the tier price again, and point-in-time recovery adds 20%. Managed databases need a paid plan with a payment method on the organization, and your plan decides which tiers you can pick.':
    'כל מסד נתונים רץ על מפרט מחשוב אחד שקובע את ה-CPU, הזיכרון, האחסון הכלול, רוחב הפס ומגבלת החיבורים שלו. יש שתים עשרה דרגות, מליבה אחת ו-1 GB ב-$10 לחודש ועד 32 ליבות ו-256 GB, בחיוב חודשי ומחולק יחסית לפי השעות שבהן מסד הנתונים פעל. אחסון ורוחב פס מעל ההקצאה של הדרגה מחויבים כעודף, ויכולות אופציונליות הן תוספים: כל רפליקה של זמינות גבוהה עולה שוב את מחיר הדרגה, ושחזור לנקודת זמן מוסיף 20%. מסדי נתונים מנוהלים דורשים תוכנית בתשלום עם אמצעי תשלום בארגון, והתוכנית שלכם קובעת אילו דרגות אפשר לבחור.',
  'Pricing': 'תמחור',
  'Scaling docs': 'תיעוד שינוי קנה מידה',
  'How many connections can I open, and when do I need the pooler?':
    'כמה חיבורים אפשר לפתוח, ומתי צריך את ה-Pooler?',
  'The connection limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers can exhaust that limit quickly. Point runtime traffic at the pooler on port 6432, same hostname and credentials, and many short-lived clients share a small pool of server connections. Keep migrations, schema changes, and long administrative sessions on the direct port 5432.':
    'מגבלת החיבורים נקבעת לפי המפרט שלכם, מ-100 בדרגה הקטנה ביותר ועד תקרת פלטפורמה של 10,000. PostgreSQL מקצה תהליך backend לכל חיבור, ולכן פונקציות serverless ושרתי אפליקציה שמתרחבים אופקית יכולים למצות את המגבלה הזו במהירות. הפנו את תעבורת זמן הריצה ל-Pooler בפורט 6432, אותו hostname ואותם פרטי גישה, והרבה לקוחות קצרי מועד יחלקו מאגר קטן של חיבורי שרת. השאירו מיגרציות, שינויי סכימה וסשנים אדמיניסטרטיביים ארוכים על הפורט הישיר 5432.',
  'Connection pooling docs': 'תיעוד Connection pooling',
  'Connections docs': 'תיעוד חיבורים',
  'Which extensions can I install?': 'אילו הרחבות אפשר להתקין?',
  'The available list comes from what the engine reports for your PostgreSQL version, minus extensions Appwrite does not offer, so it reflects what this database can actually install. Common picks include pgvector for embeddings, PostGIS for geospatial data, pg_trgm for fuzzy search, pgcrypto, hstore, citext, and ltree. Installs and uninstalls are free, up to 50 extensions per database, and they are managed from the Console or with a Server SDK and an API key. Uninstalling always cascades, so anything that depends on the extension is dropped with it.':
    'הרשימה הזמינה מבוססת על מה שהמנוע מדווח עבור גרסת PostgreSQL שלכם, פחות הרחבות ש-Appwrite לא מציעה, ולכן היא משקפת מה מסד הנתונים הזה יכול להתקין בפועל. בחירות נפוצות כוללות pgvector ל-embeddings, PostGIS לנתונים גיאוגרפיים, pg_trgm לחיפוש מקורב, pgcrypto, hstore, citext ו-ltree. התקנות והסרות הן בחינם, עד 50 הרחבות לכל מסד נתונים, והן מנוהלות מהקונסולה או עם Server SDK ו-API key. הסרה תמיד מתפשטת, ולכן כל מה שתלוי בהרחבה נמחק יחד איתה.',
  'Extensions docs': 'תיעוד הרחבות',
  'What is the difference between backups and point-in-time recovery?':
    'מה ההבדל בין גיבויים לשחזור לנקודת זמן?',
  'Backups are snapshots on a schedule. Every database is provisioned with a default policy, backups are stored off the instance, and you can add policies with their own cron schedule and retention or take a manual backup before a risky change. Point-in-time recovery is an add-on that continuously archives the write-ahead log, so you can restore to any moment inside a window of 1 to 35 days, for example the second before a bad migration. Both restores are in place: the database is unavailable while it restores and anything written after the target is discarded.':
    'גיבויים הם snapshots לפי לוח זמנים. כל מסד נתונים מוקם עם מדיניות ברירת מחדל, הגיבויים נשמרים מחוץ ל-instance, ואפשר להוסיף מדיניות עם לוח זמנים cron ותקופת שמירה משלה או לבצע גיבוי ידני לפני שינוי מסוכן. שחזור לנקודת זמן הוא תוסף שמארכב באופן רציף את ה-write-ahead log, כך שתוכלו לשחזר לכל רגע בתוך חלון של 1 עד 35 ימים, למשל השנייה שלפני מיגרציה שגויה. שני סוגי השחזור מתבצעים במקום: מסד הנתונים אינו זמין בזמן השחזור וכל מה שנכתב אחרי נקודת היעד נמחק.',
  'How does high availability and failover work?':
    'איך זמינות גבוהה ו-failover עובדים?',
  'High availability adds up to five streaming replicas next to the primary, each a full copy on its own compute. Pick asynchronous replication for the fastest writes, or synchronous or quorum when you cannot lose acknowledged writes. Appwrite health-checks the primary continuously, and when it stops responding the most caught-up replica is promoted, the hostname is repointed, and the old primary is rebuilt as a replica. Your application reconnects to the same host and port, and a driver pool with retries usually recovers on its own.':
    'זמינות גבוהה מוסיפה עד חמש רפליקות streaming לצד ה-primary, כל אחת עותק מלא על מחשוב משלה. בחרו רפליקציה asynchronous לכתיבות המהירות ביותר, או synchronous או quorum כשאסור לאבד כתיבות שאושרו. Appwrite בודקת את תקינות ה-primary באופן רציף, וכשהוא מפסיק להגיב הרפליקה המעודכנת ביותר מקודמת, ה-hostname מופנה אליה, וה-primary הישן נבנה מחדש כרפליקה. האפליקציה שלכם מתחברת שוב לאותו host ואותו פורט, ו-pool של driver עם ניסיונות חוזרים בדרך כלל מתאושש לבד.',
  'High availability docs': 'תיעוד זמינות גבוהה',
  'What are branches good for?': 'למה Branches טובים?',
  'A branch is a short-lived, isolated copy of your database created from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Use a branch to rehearse a destructive migration, reproduce a bug, run heavy analytical queries, or give every pull request its own database. Branches diverge from the parent and never merge back, and every branch has a TTL: 24 hours by default and 7 days at most.':
    'Branch הוא עותק מבודד וקצר מועד של מסד הנתונים שלכם, שנוצר מ-snapshot של האחסון, עם endpoint משל עצמו ועם פרטי הגישה של מסד האב. מסד האב לא מוקפא ולא עוצר כתיבות. השתמשו ב-Branch כדי לתרגל מיגרציה הרסנית, לשחזר באג, להריץ שאילתות אנליטיות כבדות או לתת לכל pull request מסד נתונים משלו. Branches מתפצלים ממסד האב ואף פעם לא ממוזגים חזרה, ולכל Branch יש TTL: 24 שעות כברירת מחדל ועד 7 ימים.',
  'Branches docs': 'תיעוד Branches',
  'What does Appwrite manage, and what do I still own?':
    'מה Appwrite מנהלת, ועל מה אתם עדיין אחראים?',
  'Appwrite manages the database container, storage, networking, TLS, backups, replication, security patches, and engine upgrades, and gives you a weekly maintenance window, pause and resume, and live metrics. You own everything above the wire protocol: schema, migrations, indexes, roles and grants, queries, which extensions to install, and the IP allowlist. There is nothing Appwrite-specific in your application code.':
    'Appwrite מנהלת את קונטיינר מסד הנתונים, האחסון, הרשת, TLS, הגיבויים, הרפליקציה, עדכוני האבטחה ושדרוגי המנוע, ונותנת לכם חלון תחזוקה שבועי, השהיה והמשך, ומטריקות חיות. אתם אחראים על כל מה שמעל פרוטוקול התקשורת: סכימה, מיגרציות, אינדקסים, תפקידים והרשאות, שאילתות, אילו הרחבות להתקין ורשימת ה-IP המותרים. אין שום דבר ייחודי ל-Appwrite בקוד האפליקציה שלכם.',
  'Network security docs': 'תיעוד אבטחת רשת',
  'Can I migrate an existing PostgreSQL database in?':
    'האם אפשר לייבא מסד נתונים PostgreSQL קיים?',
  'Yes, with the standard PostgreSQL tooling you already use. Create the database, copy its connection string, and run your dump and restore against the direct port 5432, because tools like pg_dump expect one session for the whole run and can misbehave in transaction mode. Then point your application runtime at the pooler on port 6432. Any client that speaks the PostgreSQL wire protocol works, including pgAdmin, DataGrip, and your migration tool of choice.':
    'כן, עם כלי PostgreSQL הרגילים שאתם כבר משתמשים בהם. צרו את מסד הנתונים, העתיקו את מחרוזת החיבור שלו והריצו את ה-dump וה-restore מול הפורט הישיר 5432, כי כלים כמו pg_dump מצפים לסשן אחד לכל ההרצה ויכולים להתנהג לא כשורה במצב transaction. אחר כך הפנו את זמן הריצה של האפליקציה ל-Pooler בפורט 6432. כל לקוח שמדבר את פרוטוקול התקשורת של PostgreSQL עובד, כולל pgAdmin, DataGrip וכלי המיגרציה שבחרתם.',

  // Managed PostgreSQL feature sections
  'A PostgreSQL endpoint, nothing else in the way':
    'Endpoint של PostgreSQL, בלי שום דבר בדרך',
  'Every database gets its own hostname on port 5432, TLS by default, and an admin role that owns it. Connect with psql, any driver, or any ORM: Prisma, Drizzle, Kysely, Laravel, Django, Rails. The Console credentials dialog hands you a ready-made DSN, .env, Prisma, Drizzle, or psql snippet, and you can rotate the primary password whenever your policy says so.':
    'כל מסד נתונים מקבל hostname משלו בפורט 5432, TLS כברירת מחדל ותפקיד admin שהוא הבעלים שלו. התחברו עם psql, עם כל driver או עם כל ORM: Prisma, Drizzle, Kysely, Laravel, Django, Rails. דיאלוג פרטי הגישה בקונסולה נותן לכם קטע קוד מוכן של DSN, .env, Prisma, Drizzle או psql, ואפשר להחליף את הסיסמה הראשית בכל פעם שהמדיניות שלכם דורשת זאת.',
  'Quick start docs': 'תיעוד התחלה מהירה',
  'Connection pooling on port 6432': 'Connection pooling בפורט 6432',
  'PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers exhaust a specification fast. The pooler runs next to your database on the same hostname with the same credentials and TLS: switch the port to 6432 and many short-lived clients share a small pool of server connections. Transaction mode is the default, session mode keeps prepared statements and LISTEN/NOTIFY, and reads route to replicas automatically once high availability is on.':
    'PostgreSQL מקצה תהליך backend לכל חיבור, ולכן פונקציות serverless ושרתי אפליקציה שמתרחבים אופקית ממצים מפרט במהירות. ה-Pooler רץ לצד מסד הנתונים שלכם על אותו hostname עם אותם פרטי גישה ואותו TLS: החליפו את הפורט ל-6432 והרבה לקוחות קצרי מועד יחלקו מאגר קטן של חיבורי שרת. מצב transaction הוא ברירת המחדל, מצב session שומר prepared statements ו-LISTEN/NOTIFY, וקריאות מנותבות לרפליקות אוטומטית ברגע שזמינות גבוהה מופעלת.',
  'pgvector, PostGIS, and the rest at no extra cost':
    'pgvector, PostGIS והשאר בלי תוספת עלות',
  'The extension catalog follows your PostgreSQL version, so you install embeddings, geospatial types, fuzzy search, and crypto helpers the same way you would on your own server. Installs and uninstalls are free, up to 50 extensions per database, managed from the Console or with a Server SDK and an API key.':
    'קטלוג ההרחבות עוקב אחרי גרסת PostgreSQL שלכם, ולכן אתם מתקינים embeddings, סוגים גיאוגרפיים, חיפוש מקורב וכלי הצפנה בדיוק כמו שהייתם עושים בשרת שלכם. התקנות והסרות הן בחינם, עד 50 הרחבות לכל מסד נתונים, בניהול מהקונסולה או עם Server SDK ו-API key.',
  'Snapshot branches in minutes': 'Branches מ-snapshot בתוך דקות',
  'A branch is an isolated copy of your database taken from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Rehearse a destructive migration, reproduce a bug, or give every pull request its own database. Branches diverge from the parent and never merge back, so they expire: 24 hours by default, 7 days at most.':
    'Branch הוא עותק מבודד של מסד הנתונים שלכם שנלקח מ-snapshot של האחסון, עם endpoint משל עצמו ועם פרטי הגישה של מסד האב. מסד האב לא מוקפא ולא עוצר כתיבות. תרגלו מיגרציה הרסנית, שחזרו באג או תנו לכל pull request מסד נתונים משלו. Branches מתפצלים ממסד האב ואף פעם לא ממוזגים חזרה, ולכן תוקפם פג: 24 שעות כברירת מחדל, ועד 7 ימים.',
  'Backups off the instance, restores on demand':
    'גיבויים מחוץ ל-instance, שחזור לפי דרישה',
  'Every database is provisioned with a backup policy, and backups are stored off the database instance. Add policies with their own cron schedule and retention, take a manual backup before a risky change, and restore when something goes wrong. Enable point-in-time recovery as an add-on to archive the write-ahead log continuously and restore to any moment in a window of up to 35 days.':
    'כל מסד נתונים מוקם עם מדיניות גיבוי, והגיבויים נשמרים מחוץ ל-instance של מסד הנתונים. הוסיפו מדיניות עם לוח זמנים cron ותקופת שמירה משלה, בצעו גיבוי ידני לפני שינוי מסוכן ושחזרו כשמשהו משתבש. הפעילו שחזור לנקודת זמן כתוסף כדי לארכב את ה-write-ahead log באופן רציף ולשחזר לכל רגע בחלון של עד 35 ימים.',
  'Replicas with automatic failover': 'רפליקות עם failover אוטומטי',
  'Add up to five streaming replicas next to the primary and pick how safe your writes should be: asynchronous for the fastest commits, synchronous or quorum when you cannot lose an acknowledged write. When the primary stops responding, Appwrite promotes the most caught-up replica and repoints the hostname, so your application reconnects to the same host. With read/write splitting on the pooler, those replicas serve your read traffic too.':
    'הוסיפו עד חמש רפליקות streaming לצד ה-primary ובחרו כמה בטוחות הכתיבות שלכם צריכות להיות: asynchronous ל-commits המהירים ביותר, synchronous או quorum כשאסור לאבד כתיבה שאושרה. כשה-primary מפסיק להגיב, Appwrite מקדמת את הרפליקה המעודכנת ביותר ומפנה אליה את ה-hostname, כך שהאפליקציה שלכם מתחברת שוב לאותו host. עם הפרדת קריאה/כתיבה ב-Pooler, הרפליקות האלה משרתות גם את תעבורת הקריאה שלכם.',
  'Live metrics and online resizing': 'מטריקות חיות ושינוי גודל בזמן ריצה',
  'The Monitor tab tracks compute, connections, storage, and workload without anything to install: CPU, memory, queries per second, cache hit ratio, disk growth, and the largest tables. Inspect live sessions from the connections list, then cancel a query or terminate an idle-in-transaction session that is holding locks. When the metrics say it is time, resize compute online and let storage grow on its own.':
    'לשונית Monitor עוקבת אחרי מחשוב, חיבורים, אחסון ועומס עבודה בלי להתקין כלום: CPU, זיכרון, שאילתות בשנייה, יחס פגיעות מטמון, גידול דיסק והטבלאות הגדולות ביותר. בדקו סשנים חיים מרשימת החיבורים, ואז בטלו שאילתה או סיימו סשן במצב idle in transaction שמחזיק מנעולים. כשהמטריקות אומרות שהגיע הזמן, שנו את גודל המחשוב בזמן ריצה ותנו לאחסון לגדול לבד.',
  'Monitoring docs': 'תיעוד ניטור',

  // Managed PostgreSQL visuals
  'Your toolchain': 'כלי העבודה שלכם',
  'Rotatable': 'ניתן להחלפה',
  'TLS by default': 'TLS כברירת מחדל',
  'Rotate password': 'החלפת סיסמה',
  'Console or API': 'קונסולה או API',
  'Same credentials, any client': 'אותם פרטי גישה, כל לקוח',
  'Pool': 'מאגר',
  'Same credentials and TLS': 'אותם פרטי גישה ואותו TLS',
  'Writes and locked reads': 'כתיבות וקריאות עם נעילה',
  'SELECT traffic': 'תעבורת SELECT',
  'Transaction mode by default. Read/write splitting turns on with high availability, so SELECT traffic reaches replicas.':
    'מצב transaction כברירת מחדל. הפרדת קריאה/כתיבה נדלקת עם זמינות גבוהה, כך שתעבורת SELECT מגיעה לרפליקות.',
  'Search and AI': 'חיפוש ו-AI',
  'Geospatial': 'נתונים גיאוגרפיים',
  'Security and data types': 'אבטחה וסוגי נתונים',
  'Vector data type and similarity search for embeddings, next to your relational data.':
    'סוג נתונים וקטורי וחיפוש דמיון ל-embeddings, לצד הנתונים הרלציוניים שלכם.',
  'Trigram matching for fuzzy string search, typo tolerance, and similarity ranking.':
    'התאמת trigram לחיפוש מחרוזות מקורב, סובלנות לשגיאות כתיב ודירוג דמיון.',
  'Spatial types, indexes, and functions for geographic objects and radius queries.':
    'סוגים, אינדקסים ופונקציות מרחביים לאובייקטים גיאוגרפיים ולשאילתות רדיוס.',
  'Represent and query hierarchical, tree-like data such as categories and org charts.':
    'ייצוג ותשאול של נתונים היררכיים בצורת עץ, כמו קטגוריות ותרשימי ארגון.',
  'Cryptographic functions for hashing and encrypting values inside the database.':
    'פונקציות הצפנה ל-hashing ולהצפנת ערכים בתוך מסד הנתונים.',
  'Case-insensitive text, plus hstore for key/value columns and uuid-ossp for UUIDs.':
    'טקסט שאינו תלוי רישיות, וגם hstore לעמודות מפתח/ערך ו-uuid-ossp ל-UUIDs.',
  'Free to install and uninstall': 'התקנה והסרה בחינם',
  'Up to 50 extensions per database': 'עד 50 הרחבות לכל מסד נתונים',
  'Server SDK and API key, or the Console': 'Server SDK ו-API key, או הקונסולה',
  'Install from a Server SDK': 'התקנה מ-Server SDK',
  'Parent database': 'מסד נתונים אב',
  'No write pause, no freeze': 'בלי עצירת כתיבות, בלי הקפאה',
  'Preview environment': 'סביבת תצוגה מקדימה',
  'Rehearse an ALTER': 'תרגול ALTER',
  'Snapshot copy': 'עותק מ-snapshot',
  'Parent credentials reused': 'פרטי הגישה של מסד האב בשימוש חוזר',
  'Branches diverge and never merge back':
    'Branches מתפצלים ואף פעם לא ממוזגים חזרה',
  'Own schedule and retention per policy':
    'לוח זמנים ותקופת שמירה משלה לכל מדיניות',
  'Daily policy': 'מדיניות יומית',
  'Kept until removed': 'נשמר עד להסרה',
  'WAL archived continuously': 'WAL מאורכב באופן רציף',
  'Window up to 35 days': 'חלון של עד 35 ימים',
  'Stored off the instance': 'נשמר מחוץ ל-instance',
  'Restore in place when you need it': 'שחזור במקום כשצריך',
  'Next to promote': 'הבאה לקידום',
  'Streaming to 3 replicas': 'Streaming ל-3 רפליקות',
  'Automatic failover': 'failover אוטומטי',
  'Same hostname, same port': 'אותו hostname, אותו פורט',
  'Replication mode': 'מצב רפליקציה',
  'Fastest commits': 'ה-commits המהירים ביותר',
  'One confirmation': 'אישור אחד',
  'Majority confirms': 'רוב מאשר',
  'Queries / sec': 'שאילתות / שנייה',
  'Active connections': 'חיבורים פעילים',
  'Cancel or terminate': 'ביטול או סיום',
  'idle': 'סרק',
  'idle in transaction': 'סרק בטרנזקציה',
  'Resize compute': 'שינוי גודל מחשוב',
  'Online, no dump and restore': 'בזמן ריצה, בלי dump ו-restore',
  'Grows past 85% usage': 'גדל כשהשימוש עובר 85%',

  // Managed PostgreSQL, shortened hero and CTA copy
  'Appwrite runs the engine and you keep the SQL. Connect with psql or any driver, bring your own ORM and migrations, and get the full PostgreSQL feature set with nothing in between.':
    'Appwrite מריצה את המנוע ואתם שומרים על ה-SQL. התחברו עם psql או עם כל driver, הביאו ORM ומיגרציות משלכם וקבלו את כל יכולות PostgreSQL בלי שום דבר באמצע.',
  'Create a database in your project region, copy the connection string, and run your first query.':
    'צרו מסד נתונים באזור הפרויקט שלכם, העתיקו את מחרוזת החיבור והריצו את השאילתה הראשונה שלכם.',

  // Managed PostgreSQL, shortened FAQ answers
  'A managed PostgreSQL database is the raw engine with its own compute, storage, and credentials. You talk to it over the PostgreSQL wire protocol instead of an Appwrite SDK, so schema, migrations, roles, and queries are standard PostgreSQL. Use TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs and platform permissions for app data instead.':
    'מסד נתונים מנוהל של PostgreSQL הוא המנוע עצמו, עם מחשוב, אחסון ופרטי גישה משלו. אתם מדברים איתו דרך פרוטוקול התקשורת של PostgreSQL ולא דרך SDK של Appwrite, ולכן הסכימה, המיגרציות, התפקידים והשאילתות הם PostgreSQL רגיל. השתמשו ב-TablesDB, ב-DocumentsDB או ב-VectorsDB כשאתם רוצים SDKs של Appwrite והרשאות פלטפורמה לנתוני האפליקציה.',
  'New databases run PostgreSQL 18 by default, and you can pick PostgreSQL 17 at create time. Upgrades run online: a second instance is provisioned on the new version, data streams over with logical replication, and traffic cuts over once it catches up. Connections are closed at the cutover, so your application has to reconnect.':
    'מסדי נתונים חדשים רצים על PostgreSQL 18 כברירת מחדל, ואפשר לבחור PostgreSQL 17 בזמן היצירה. השדרוגים מתבצעים בזמן ריצה: instance שני מוקם בגרסה החדשה, הנתונים זורמים אליו עם logical replication, והתעבורה עוברת אליו ברגע שהוא מדביק את הפער. החיבורים נסגרים ברגע המעבר, ולכן האפליקציה שלכם צריכה להתחבר מחדש.',
  'Every Appwrite Cloud region: Frankfurt, New York, San Francisco, Singapore, Sydney, and Toronto. A database takes the region of the project that owns it, so create your project close to your users first. Each database gets a hostname in the form db-<hash>.<region>.appwrite.center and the data does not leave the region.':
    'כל אזור של Appwrite Cloud: פרנקפורט, ניו יורק, סן פרנסיסקו, סינגפור, סידני וטורונטו. מסד נתונים מקבל את האזור של הפרויקט שמחזיק אותו, ולכן צרו קודם את הפרויקט שלכם קרוב למשתמשים. כל מסד נתונים מקבל hostname בצורה db-<hash>.<region>.appwrite.center והנתונים לא יוצאים מהאזור.',
  'PostgreSQL uses the same dedicated compute tiers as pricing: reserved CPU, memory, and connections, from $10/mo per database. Reads and writes are included in the tier. High availability replicas are +50% of base per replica, and point-in-time recovery is +20% of base. Extra storage and bandwidth are usage-based overage. Managed databases need a paid plan.':
    'PostgreSQL משתמש באותן דרגות מחשוב ייעודי כמו בתמחור: מעבד, זיכרון וחיבורים שמורים, החל מ-$10/חודש לכל מסד נתונים. קריאות וכתיבות כלולות בדרגה. רפליקת זמינות גבוהה מחויבת ב-+50% מהבסיס לכל רפליקה, ושחזור לנקודת זמן ב-+20% מהבסיס. אחסון ורוחב פס נוספים הם חריגה לפי שימוש. מסדי נתונים מנוהלים דורשים תוכנית בתשלום.',
  'The limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. Since PostgreSQL spends a backend process per connection, point runtime traffic at the pooler on port 6432, where many short-lived clients share a small pool. Keep migrations and long administrative sessions on the direct port 5432.':
    'המגבלה נקבעת לפי המפרט שלכם, מ-100 בדרגה הקטנה ביותר ועד תקרת פלטפורמה של 10,000. מכיוון ש-PostgreSQL מקצה תהליך backend לכל חיבור, הפנו את תעבורת זמן הריצה ל-Pooler בפורט 6432, שם הרבה לקוחות קצרי מועד חולקים מאגר קטן. השאירו מיגרציות וסשנים אדמיניסטרטיביים ארוכים על הפורט הישיר 5432.',
  'The list comes from what the engine reports for your PostgreSQL version, so it reflects what this database can actually install: pgvector for embeddings, PostGIS for geospatial data, pg_trgm for fuzzy search, pgcrypto, hstore, citext, and ltree. Installs are free, up to 50 per database. Uninstalling always cascades, so anything depending on the extension is dropped with it.':
    'הרשימה מבוססת על מה שהמנוע מדווח עבור גרסת PostgreSQL שלכם, ולכן היא משקפת מה מסד הנתונים הזה יכול להתקין בפועל: pgvector ל-embeddings, PostGIS לנתונים גיאוגרפיים, pg_trgm לחיפוש מקורב, pgcrypto, hstore, citext ו-ltree. ההתקנות הן בחינם, עד 50 לכל מסד נתונים. הסרה תמיד מתפשטת, ולכן כל מה שתלוי בהרחבה נמחק יחד איתה.',
  'Backups are snapshots on a schedule, stored off the instance, with your own cron and retention on top of the default policy. Point-in-time recovery is an add-on that archives the write-ahead log continuously, so you can restore to any moment in a window of 1 to 35 days. Both restore in place, so anything written after the target is discarded.':
    'גיבויים הם snapshots לפי לוח זמנים, שנשמרים מחוץ ל-instance, עם cron ותקופת שמירה משלכם מעל מדיניות ברירת המחדל. שחזור לנקודת זמן הוא תוסף שמארכב את ה-write-ahead log באופן רציף, כך שתוכלו לשחזר לכל רגע בחלון של 1 עד 35 ימים. שני הסוגים משחזרים במקום, ולכן כל מה שנכתב אחרי נקודת היעד נמחק.',
  'High availability adds up to five streaming replicas, each a full copy on its own compute. Pick asynchronous replication for the fastest writes, or synchronous or quorum when you cannot lose acknowledged writes. When the primary stops responding, the most caught-up replica is promoted and the hostname repointed, so a driver pool with retries usually recovers on its own.':
    'זמינות גבוהה מוסיפה עד חמש רפליקות streaming, כל אחת עותק מלא על מחשוב משלה. בחרו רפליקציה asynchronous לכתיבות המהירות ביותר, או synchronous או quorum כשאסור לאבד כתיבות שאושרו. כשה-primary מפסיק להגיב, הרפליקה המעודכנת ביותר מקודמת וה-hostname מופנה אליה, כך ש-pool של driver עם ניסיונות חוזרים בדרך כלל מתאושש לבד.',
  'A branch is a short-lived copy created from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Rehearse a destructive migration, reproduce a bug, or give every pull request its own database. Branches never merge back and expire after 24 hours by default, 7 days at most.':
    'Branch הוא עותק קצר מועד שנוצר מ-snapshot של האחסון, עם endpoint משל עצמו ועם פרטי הגישה של מסד האב. מסד האב לא מוקפא ולא עוצר כתיבות. תרגלו מיגרציה הרסנית, שחזרו באג או תנו לכל pull request מסד נתונים משלו. Branches אף פעם לא ממוזגים חזרה ותוקפם פג אחרי 24 שעות כברירת מחדל, ועד 7 ימים.',
  'Appwrite manages the container, storage, networking, TLS, backups, replication, security patches, and engine upgrades. You own everything above the wire protocol: schema, migrations, indexes, roles and grants, queries, extensions, and the IP allowlist. There is nothing Appwrite-specific in your application code.':
    'Appwrite מנהלת את הקונטיינר, האחסון, הרשת, TLS, הגיבויים, הרפליקציה, עדכוני האבטחה ושדרוגי המנוע. אתם אחראים על כל מה שמעל פרוטוקול התקשורת: סכימה, מיגרציות, אינדקסים, תפקידים והרשאות, שאילתות, הרחבות ורשימת ה-IP המותרים. אין שום דבר ייחודי ל-Appwrite בקוד האפליקציה שלכם.',
  'Yes, with the standard PostgreSQL tooling you already use. Run your dump and restore against the direct port 5432, because tools like pg_dump expect one session for the whole run, then point your application runtime at the pooler on port 6432. Any client that speaks the wire protocol works, including pgAdmin and DataGrip.':
    'כן, עם כלי PostgreSQL הרגילים שאתם כבר משתמשים בהם. הריצו את ה-dump וה-restore מול הפורט הישיר 5432, כי כלים כמו pg_dump מצפים לסשן אחד לכל ההרצה, ואז הפנו את זמן הריצה של האפליקציה ל-Pooler בפורט 6432. כל לקוח שמדבר את פרוטוקול התקשורת עובד, כולל pgAdmin ו-DataGrip.',

  // Managed PostgreSQL, shortened feature descriptions
  'Every database gets its own hostname on port 5432, TLS, and an admin role that owns it. Connect with psql, any driver, or any ORM, and copy a ready-made DSN, .env, or Prisma snippet from the Console.':
    'כל מסד נתונים מקבל hostname משלו בפורט 5432, TLS ותפקיד admin שהוא הבעלים שלו. התחברו עם psql, עם כל driver או עם כל ORM, והעתיקו מהקונסולה קטע קוד מוכן של DSN, .env או Prisma.',
  'PostgreSQL spends a backend process per connection, so serverless functions exhaust a specification fast. Switch to port 6432, same hostname and credentials, and short-lived clients share a small pool.':
    'PostgreSQL מקצה תהליך backend לכל חיבור, ולכן פונקציות serverless ממצות מפרט במהירות. החליפו לפורט 6432, אותו hostname ואותם פרטי גישה, ולקוחות קצרי מועד יחלקו מאגר קטן.',
  'The catalog follows your PostgreSQL version, so you install embeddings, geospatial types, and fuzzy search the same way you would on your own server. Free to install, up to 50 per database.':
    'הקטלוג עוקב אחרי גרסת PostgreSQL שלכם, ולכן אתם מתקינים embeddings, סוגים גיאוגרפיים וחיפוש מקורב בדיוק כמו שהייתם עושים בשרת שלכם. ההתקנה בחינם, עד 50 לכל מסד נתונים.',
  'A branch is an isolated copy taken from a storage snapshot, with its own endpoint and the parent credentials. The parent never pauses writes. Rehearse a migration or give every pull request a database.':
    'Branch הוא עותק מבודד שנלקח מ-snapshot של האחסון, עם endpoint משל עצמו ועם פרטי הגישה של מסד האב. מסד האב אף פעם לא עוצר כתיבות. תרגלו מיגרציה או תנו לכל pull request מסד נתונים.',
  'Every database ships with a backup policy and backups are stored off the instance. Add your own schedule and retention, or enable point-in-time recovery to restore to any moment in the last 35 days.':
    'כל מסד נתונים מגיע עם מדיניות גיבוי, והגיבויים נשמרים מחוץ ל-instance. הוסיפו לוח זמנים ותקופת שמירה משלכם, או הפעילו שחזור לנקודת זמן כדי לשחזר לכל רגע ב-35 הימים האחרונים.',
  'Add up to five streaming replicas and pick how safe writes should be: asynchronous, synchronous, or quorum. If the primary stops responding, the most caught-up replica is promoted and the hostname repointed.':
    'הוסיפו עד חמש רפליקות streaming ובחרו כמה בטוחות הכתיבות צריכות להיות: asynchronous, synchronous או quorum. אם ה-primary מפסיק להגיב, הרפליקה המעודכנת ביותר מקודמת וה-hostname מופנה אליה.',
  'The Monitor tab tracks CPU, memory, queries per second, cache hit ratio, and disk growth with nothing to install. Inspect live sessions, cancel a query, then resize compute online when it is time.':
    'לשונית Monitor עוקבת אחרי CPU, זיכרון, שאילתות בשנייה, יחס פגיעות מטמון וגידול דיסק, בלי להתקין כלום. בדקו סשנים חיים, בטלו שאילתה, ואז שנו את גודל המחשוב בזמן ריצה כשמגיע הזמן.',

  // Realtime product page
  'Live data over one WebSocket': 'נתונים חיים על WebSocket אחד',
  'Subscribe to Appwrite events over one WebSocket connection. Realtime brings type-safe channels, server-side query filters, live presence, and permission-aware events to every Appwrite service.':
    'הירשמו לאירועי Appwrite דרך חיבור WebSocket אחד. Realtime מביא ערוצים בטוחי טיפוסים, מסנני שאילתות בצד השרת, Presence חי ואירועים שמודעים להרשאות לכל שירות של Appwrite.',
  'Subscribe to events from any Appwrite service and receive changes within milliseconds instead of polling for them. A single connection carries every subscription, filters events server-side, and only delivers what the signed-in user is allowed to read.':
    'הירשמו לאירועים מכל שירות של Appwrite וקבלו שינויים בתוך אלפיות שנייה במקום לתשאל אותם. חיבור אחד נושא את כל ההרשמות, מסנן אירועים בצד השרת ומוסר רק את מה שהמשתמש המחובר מורשה לקרוא.',
  '1 socket': 'סוקט אחד',
  'Shared by all subscriptions': 'משותף לכל ההרשמות',
  'Milliseconds': 'אלפיות שנייה',
  'From write to subscriber': 'מהכתיבה ועד הנרשם',
  'Across every service': 'בכל השירותים',
  'Filtered server-side': 'מסונן בצד השרת',
  'Live online status': 'סטטוס מחוברים בזמן אמת',
  'Start building with Realtime': 'התחילו לבנות עם Realtime',
  'Subscribe to your first channel and watch rows, files, and presence updates arrive as they happen.':
    'הירשמו לערוץ הראשון שלכם וראו עדכוני שורות, קבצים ו-Presence מגיעים ברגע שהם קורים.',

  // Realtime FAQ
  'What is Appwrite Realtime?': 'מה זה Appwrite Realtime?',
  'Realtime is a third protocol for talking to Appwrite, alongside REST and GraphQL. Instead of requesting new data over HTTP, you subscribe once and the server pushes new data to every connected client over a WebSocket as soon as it changes. Subscriptions cover events from all of Appwrite services, not just databases.':
    'Realtime הוא פרוטוקול שלישי לתקשורת עם Appwrite, לצד REST ו-GraphQL. במקום לבקש נתונים חדשים דרך HTTP, אתם נרשמים פעם אחת והשרת דוחף נתונים חדשים לכל לקוח מחובר דרך WebSocket ברגע שהם משתנים. ההרשמות מכסות אירועים מכל שירותי Appwrite, לא רק ממסדי נתונים.',
  'Realtime overview': 'סקירת Realtime',
  'How many WebSocket connections does my app open?':
    'כמה חיבורי WebSocket האפליקציה שלי פותחת?',
  'Client SDKs use a single WebSocket per Realtime client for all subscriptions. Adding one with subscribe(), replacing its channels or queries with update(), and dropping it with unsubscribe() all apply on the existing socket where supported, so there is no full reconnect. The connection closes when you call realtime.disconnect().':
    'Client SDKs משתמשים ב-WebSocket אחד לכל לקוח Realtime עבור כל ההרשמות. הוספה עם subscribe(), החלפת הערוצים או השאילתות שלה עם update() והסרה עם unsubscribe() חלות כולן על הסוקט הקיים כשיש תמיכה, כך שאין חיבור מחדש מלא. החיבור נסגר כשאתם קוראים ל-realtime.disconnect().',
  'Subscribe docs': 'תיעוד הרשמה',
  'Which resources can I subscribe to?': 'לאילו משאבים אפשר להירשם?',
  'Channels cover account events, rows, files, teams, memberships, executions, functions, and presences. The Channel helper class builds the channel string for you with a fluent API, so you can target one row or every row in a table. Leave an ID blank to subscribe with a wildcard, and append .create(), .update(), or .delete() to narrow the stream to a single event type.':
    'ערוצים מכסים אירועי חשבון, שורות, קבצים, צוותים, חברויות, הרצות, פונקציות ו-Presences. מחלקת העזר Channel בונה בשבילכם את מחרוזת הערוץ עם API שוטף, כך שתוכלו לכוון לשורה אחת או לכל השורות בטבלה. השאירו מזהה ריק כדי להירשם עם תו כללי, והוסיפו .create(), .update() או .delete() כדי לצמצם את הזרם לסוג אירוע אחד.',
  'Channels docs': 'תיעוד ערוצים',
  'Events reference': 'מדריך האירועים',
  'Can I filter events before they reach my callback?':
    'האם אפשר לסנן אירועים לפני שהם מגיעים ל-callback שלי?',
  'Yes. Pass queries as a third parameter when you subscribe and Appwrite filters events server-side, so your callback only runs for updates that match. Realtime supports Query.equal, Query.notEqual, the greater than and less than comparisons, Query.isNull, Query.isNotNull, Query.and, and Query.or.':
    'כן. העבירו שאילתות כפרמטר שלישי כשאתם נרשמים ו-Appwrite מסננת את האירועים בצד השרת, כך שה-callback שלכם רץ רק עבור עדכונים שמתאימים. Realtime תומך ב-Query.equal, ב-Query.notEqual, בהשוואות גדול מ- וקטן מ-, ב-Query.isNull, ב-Query.isNotNull, ב-Query.and וב-Query.or.',
  'Realtime queries docs': 'תיעוד שאילתות Realtime',
  'What does a Realtime message look like?': 'איך נראית הודעת Realtime?',
  'Every message carries four properties: events (the Appwrite events that triggered the update), channels (the channels that can receive it), timestamp (an ISO 8601 time in UTC from the server), and payload (the same data as the matching response model). Branch on the event names in events to decide how to update your UI.':
    'כל הודעה נושאת ארבעה מאפיינים: events (אירועי Appwrite שהפעילו את העדכון), channels (הערוצים שיכולים לקבל אותה), timestamp (זמן ISO 8601 ב-UTC מהשרת) ו-payload (אותם נתונים כמו מודל התשובה המתאים). פצלו לפי שמות האירועים ב-events כדי להחליט איך לעדכן את ה-UI שלכם.',
  'Payload docs': 'תיעוד Payload',
  'Can a user receive updates for data they cannot read?':
    'האם משתמש יכול לקבל עדכונים על נתונים שאינו יכול לקרוא?',
  'No. Every subscription is secured by the same permissions system used by rows, files, and presences, so a user only receives updates for resources they have permission to access. Granting read to Role.any() is what makes a resource stream to any client, including visitors who are not signed in.':
    'לא. כל הרשמה מאובטחת באותה מערכת הרשאות שמשמשת שורות, קבצים ו-Presences, ולכן משתמש מקבל עדכונים רק על משאבים שיש לו הרשאה לגשת אליהם. מתן הרשאת קריאה ל-Role.any() הוא מה שגורם למשאב לזרום לכל לקוח, כולל מבקרים שאינם מחוברים.',
  'Realtime authentication docs': 'תיעוד אימות Realtime',
  'What happens when the user signs in or out?':
    'מה קורה כשהמשתמש מתחבר או מתנתק?',
  'Realtime authenticates with the session that existed when the subscription was created. If you authenticate after subscribing, that subscription will not receive updates for the new user, so create the session first. When a user signs out and another signs in, call realtime.disconnect() and subscribe again for the new session.':
    'Realtime מאמת עם הסשן שהיה קיים כשההרשמה נוצרה. אם אתם מאמתים אחרי ההרשמה, ההרשמה הזו לא תקבל עדכונים עבור המשתמש החדש, ולכן צרו קודם את הסשן. כשמשתמש מתנתק ואחר מתחבר, קראו ל-realtime.disconnect() והירשמו מחדש עבור הסשן החדש.',
  'How does presence work?': 'איך Presence עובד?',
  'A presence is a short-lived record tied to a user, with a userId, a free-form status string, an optional metadata object, and an expiresAt timestamp. It is durable, so you can list presences at any time to see who is here, and live, so every change fires upsert, update, and delete events on the presences channels. Keep a record alive with a heartbeat, or use realtime.upsertPresence() so it is removed when the connection closes.':
    'Presence היא רשומה קצרת מועד שמשויכת למשתמש, עם userId, מחרוזת status חופשית, אובייקט metadata אופציונלי וחותמת זמן expiresAt. היא נשמרת, ולכן אפשר לרשום את ה-Presences בכל רגע ולראות מי נמצא כאן, והיא חיה, ולכן כל שינוי מפעיל אירועי upsert, update ו-delete בערוצי ה-presences. שמרו רשומה בחיים עם heartbeat, או השתמשו ב-realtime.upsertPresence() כדי שהיא תוסר כשהחיבור נסגר.',
  'Auth presences': 'Presences באימות',
  'Can I use Realtime from a Server SDK with an API key?':
    'האם אפשר להשתמש ב-Realtime מ-Server SDK עם API key?',
  'Not today. Realtime subscriptions are a client SDK feature and are not offered for Server SDKs with an API key. Presence records are the exception: they are also a regular HTTP resource, so server code can write them with an API key that has the presences.write scope and clients will see the change live.':
    'לא כרגע. הרשמות Realtime הן יכולת של client SDK ואינן מוצעות ל-Server SDKs עם API key. רשומות Presence הן החריג: הן גם משאב HTTP רגיל, ולכן קוד שרת יכול לכתוב אותן עם API key שיש לו את ה-scope presences.write, והלקוחות יראו את השינוי בזמן אמת.',
  'Can I point the SDK at a custom WebSocket endpoint?':
    'האם אפשר להפנות את ה-SDK ל-endpoint WebSocket מותאם?',
  'Yes. The SDK derives the Realtime endpoint from your Appwrite endpoint, which is wss://<REGION>.cloud.appwrite.io/v1/realtime by default. If you run Appwrite behind a custom proxy and moved the Realtime route, call setEndpointRealtime on the client with your own value.':
    'כן. ה-SDK גוזר את ה-endpoint של Realtime מה-endpoint של Appwrite שלכם, שהוא wss://<REGION>.cloud.appwrite.io/v1/realtime כברירת מחדל. אם אתם מריצים את Appwrite מאחורי proxy מותאם והזזתם את הנתיב של Realtime, קראו ל-setEndpointRealtime בלקוח עם הערך שלכם.',
  'Custom endpoint docs': 'תיעוד Endpoint מותאם',

  // Realtime feature sections
  'One connection, many subscriptions': 'חיבור אחד, הרשמות רבות',
  'Create a Realtime client once and every subscription shares a single WebSocket. Add one with subscribe(), replace its channels or queries with update(), and drop it with unsubscribe() without reconnecting the client. Call disconnect() when you want to close everything at once, like on sign out or app teardown.':
    'צרו לקוח Realtime פעם אחת וכל ההרשמות חולקות WebSocket אחד. הוסיפו הרשמה עם subscribe(), החליפו את הערוצים או השאילתות שלה עם update() והסירו אותה עם unsubscribe() בלי לחבר מחדש את הלקוח. קראו ל-disconnect() כשאתם רוצים לסגור הכול בבת אחת, למשל בהתנתקות או בסגירת האפליקציה.',
  'Type-safe channels for every service': 'ערוצים בטוחי טיפוסים לכל שירות',
  'Channels decide which resources you listen to, and the Channel helper builds them with a fluent API instead of hand-written strings. Target account events, rows, files, teams, memberships, executions, functions, and presences. Leave an ID blank to subscribe with a wildcard, or append .create(), .update(), or .delete() to narrow the stream.':
    'ערוצים קובעים לאילו משאבים אתם מאזינים, ומחלקת העזר Channel בונה אותם עם API שוטף במקום מחרוזות שנכתבות ביד. כוונו לאירועי חשבון, שורות, קבצים, צוותים, חברויות, הרצות, פונקציות ו-Presences. השאירו מזהה ריק כדי להירשם עם תו כללי, או הוסיפו .create(), .update() או .delete() כדי לצמצם את הזרם.',
  'Filter events before they reach you': 'סננו אירועים לפני שהם מגיעים אליכם',
  'Pass queries when you subscribe and Appwrite filters events server-side, so your callback only runs for updates that match. The methods are the ones you already use for lists: equal, notEqual, the greater than and less than comparisons, isNull, isNotNull, and the and and or combinators.':
    'העבירו שאילתות כשאתם נרשמים ו-Appwrite מסננת את האירועים בצד השרת, כך שה-callback שלכם רץ רק עבור עדכונים שמתאימים. המתודות הן אותן מתודות שאתם כבר משתמשים בהן לרשימות: equal, notEqual, השוואות גדול מ- וקטן מ-, isNull, isNotNull והמשלבים and ו-or.',
  'Presence that is durable and live': 'Presence שנשמר וגם חי',
  'A presence record carries a userId, a free-form status, an optional metadata object, and an expiresAt timestamp. List presences at any time to see who is here right now, and subscribe to the presences channels for upsert, update, and delete events in milliseconds. Use it for online dots, typing indicators, and who is viewing a document.':
    'רשומת Presence נושאת userId, status חופשי, אובייקט metadata אופציונלי וחותמת זמן expiresAt. רשמו את ה-Presences בכל רגע כדי לראות מי נמצא כאן עכשיו, והירשמו לערוצי ה-presences כדי לקבל אירועי upsert, update ו-delete באלפיות שנייה. השתמשו בזה לנקודות חיווי של מחוברים, לחיווי הקלדה ולמי שצופה במסמך.',
  'A predictable payload on every event': 'Payload צפוי בכל אירוע',
  'Each message carries the events that triggered it, the channels that can receive it, an ISO 8601 timestamp from the server, and a payload that matches the response model of the resource. Branch on the event names to tell a create from an update or a delete, then apply the payload straight to your state.':
    'כל הודעה נושאת את האירועים שהפעילו אותה, את הערוצים שיכולים לקבל אותה, חותמת זמן ISO 8601 מהשרת ו-payload שתואם למודל התשובה של המשאב. פצלו לפי שמות האירועים כדי להבדיל בין יצירה, עדכון ומחיקה, ואז החילו את ה-payload ישירות על ה-state שלכם.',
  'Permission-aware subscriptions': 'הרשמות שמודעות להרשאות',
  'Subscriptions are secured by the same permissions system as the rest of Appwrite, so a user only receives updates for resources they can read. Granting read to Role.any() is what opens a stream to every client. Realtime uses the session that existed when you subscribed, so disconnect and subscribe again when the session changes.':
    'ההרשמות מאובטחות באותה מערכת הרשאות כמו שאר Appwrite, ולכן משתמש מקבל עדכונים רק על משאבים שהוא יכול לקרוא. מתן הרשאת קריאה ל-Role.any() הוא מה שפותח זרם לכל לקוח. Realtime משתמש בסשן שהיה קיים כשנרשמתם, ולכן התנתקו והירשמו שוב כשהסשן משתנה.',

  // Realtime visuals
  'Appwrite events': 'אירועי Appwrite',
  '1 WebSocket': 'WebSocket אחד',
  'Every subscription shares this connection.': 'כל ההרשמות חולקות את החיבור הזה.',
  'Web app': 'אפליקציית Web',
  'New row in orders': 'שורה חדשה ב-orders',
  'Mobile app': 'אפליקציה למובייל',
  'paige@example.com is editing': 'paige@example.com עורך כרגע',
  'Live attendees': 'משתתפים מחוברים',
  'Server-side filters': 'מסננים בצד השרת',
  'Clients only receive what they can read.':
    'לקוחות מקבלים רק את מה שהם יכולים לקרוא.',
  'Realtime client': 'לקוח Realtime',
  'Four subscriptions, one connection': 'ארבע הרשמות, חיבור אחד',
  'Add a subscription': 'הוספת הרשמה',
  'subscribed': 'רשום',
  'Swap channels, no reconnect': 'החלפת ערוצים, בלי חיבור מחדש',
  'Drops every subscription': 'מסיר כל ההרשמות',
  'Account and teams': 'חשבון וצוותים',
  'Every account event for the signed-in user, from a new session to a name change.':
    'כל אירוע חשבון של המשתמש המחובר, מסשן חדש ועד שינוי שם.',
  'Create, update, and delete events on any team.':
    'אירועי יצירה, עדכון ומחיקה של כל צוות.',
  'Create, update, and delete events on any membership.':
    'אירועי יצירה, עדכון ומחיקה של כל חברות.',
  'Any create, update, or delete event on rows in a single table.':
    'כל אירוע יצירה, עדכון או מחיקה של שורות בטבלה אחת.',
  'Update and delete events for one specific row.':
    'אירועי עדכון ומחיקה של שורה אחת ספציפית.',
  'Any row event across the project.': 'כל אירוע שורה בכל הפרויקט.',
  'Files and functions': 'קבצים ופונקציות',
  'Update and delete events on any file in one bucket.':
    'אירועי עדכון ומחיקה של כל קובץ בבאקט אחד.',
  'Any update to a function execution.': 'כל עדכון של הרצת פונקציה.',
  'Every execution event for one function.': 'כל אירוע הרצה של פונקציה אחת.',
  'Upsert, update, and delete events on any presence the subscriber can read.':
    'אירועי upsert, עדכון ומחיקה של כל Presence שהנרשם יכול לקרוא.',
  'Upsert, update, and delete events on a single presence record.':
    'אירועי upsert, עדכון ומחיקה של רשומת Presence אחת.',
  'Narrow a presence stream to one event type with a filter.':
    'צמצמו זרם Presence לסוג אירוע אחד עם מסנן.',
  'Leave an ID blank and the helper subscribes with a wildcard.':
    'השאירו מזהה ריק ומחלקת העזר תירשם עם תו כללי.',
  'Event filters': 'מסנני אירועים',
  'Events on the channel': 'אירועים בערוץ',
  'match': 'תואם',
  'dropped': 'הושמט',
  'Filtered on the server': 'מסונן בשרת',
  'Your callback': 'ה-callback שלכם',
  '2 of 3 delivered': '2 מתוך 3 נמסרו',
  'Non-matching events never reach the client.':
    'אירועים שאינם מתאימים לא מגיעים ללקוח.',
  'Every message carries': 'כל הודעה נושאת',
  'The Appwrite events that triggered this update.':
    'אירועי Appwrite שהפעילו את העדכון הזה.',
  'Every channel that can receive this message.':
    'כל ערוץ שיכול לקבל את ההודעה הזו.',
  'ISO 8601 time in UTC, from the server.': 'זמן ISO 8601 ב-UTC, מהשרת.',
  'The same data as the resource response model.':
    'אותם נתונים כמו מודל התשובה של המשאב.',
  'Branch on the event name': 'פיצול לפי שם האירוע',
  'Who is here': 'מי נמצא כאן',
  'editing': 'בעריכה',
  'away': 'לא זמין',
  'List the active set at any time, or follow it live.':
    'רשמו את הקבוצה הפעילה בכל רגע, או עקבו אחריה בזמן אמת.',
  'Presence record': 'רשומת Presence',
  'Slides on heartbeat': 'נדחה קדימה בכל heartbeat',
  'typing': 'מקליד',
  'Member of team acme': 'חבר בצוות acme',
  'Event delivered': 'האירוע נמסר',
  'Not a member of team acme': 'אינו חבר בצוות acme',
  'Nothing delivered': 'לא נמסר דבר',
  'Disconnect and subscribe again when the session changes.':
    'התנתקו והירשמו שוב כשהסשן משתנה.',
  'One WebSocket, filtered server-side.': 'WebSocket אחד, מסונן בצד השרת.',

  // Realtime, shortened hero, FAQ, and feature copy
  'Subscribe to events from any Appwrite service and get changes in milliseconds instead of polling. One connection carries every subscription and only delivers what the user is allowed to read.':
    'הירשמו לאירועים מכל שירות של Appwrite וקבלו שינויים תוך אלפיות שנייה במקום לתשאל. חיבור אחד נושא את כל ההרשמות ומוסר רק את מה שהמשתמש מורשה לקרוא.',
  'A presence is a short-lived record tied to a user, with a userId, a free-form status, optional metadata, and an expiresAt timestamp. It is durable, so you can list presences at any time, and live, so every change fires upsert, update, and delete events. Keep a record alive with a heartbeat, or use realtime.upsertPresence() so it is removed when the connection closes.':
    'Presence היא רשומה קצרת מועד שמשויכת למשתמש, עם userId, status חופשי, metadata אופציונלי וחותמת זמן expiresAt. היא נשמרת, ולכן אפשר לרשום את ה-Presences בכל רגע, והיא חיה, ולכן כל שינוי מפעיל אירועי upsert, update ו-delete. שמרו רשומה בחיים עם heartbeat, או השתמשו ב-realtime.upsertPresence() כדי שהיא תוסר כשהחיבור נסגר.',
  'Create a Realtime client once and every subscription shares a single WebSocket. Add one with subscribe(), swap its channels or queries with update(), and drop it with unsubscribe(), all without reconnecting.':
    'צרו לקוח Realtime פעם אחת וכל ההרשמות חולקות WebSocket אחד. הוסיפו הרשמה עם subscribe(), החליפו את הערוצים או השאילתות שלה עם update() והסירו אותה עם unsubscribe(), הכול בלי להתחבר מחדש.',
  'The Channel helper builds channel strings with a fluent API instead of hand-written text. Target rows, files, teams, executions, and presences. Leave an ID blank for a wildcard, or append .create() to narrow the stream.':
    'מחלקת העזר Channel בונה מחרוזות ערוץ עם API שוטף במקום טקסט שנכתב ביד. כוונו לשורות, קבצים, צוותים, הרצות ו-Presences. השאירו מזהה ריק לתו כללי, או הוסיפו .create() כדי לצמצם את הזרם.',
  'Pass queries when you subscribe and Appwrite filters events on the server, so your callback only runs for updates that match. The methods are the ones you already use for lists, from equal to isNull and or.':
    'העבירו שאילתות כשאתם נרשמים ו-Appwrite מסננת את האירועים בשרת, כך שה-callback שלכם רץ רק עבור עדכונים שמתאימים. המתודות הן אותן מתודות שאתם כבר משתמשים בהן לרשימות, מ-equal ועד isNull ו-or.',
  'A presence record carries a user, a free-form status, optional metadata, and an expiry. List presences to see who is here, and subscribe for upsert, update, and delete events in milliseconds.':
    'רשומת Presence נושאת משתמש, status חופשי, metadata אופציונלי ומועד תפוגה. רשמו את ה-Presences כדי לראות מי נמצא כאן, והירשמו כדי לקבל אירועי upsert, update ו-delete באלפיות שנייה.',
  'Every message carries the events that triggered it, the channels that can receive it, a server timestamp, and a payload matching the resource response model. Branch on the event names, then apply the payload.':
    'כל הודעה נושאת את האירועים שהפעילו אותה, את הערוצים שיכולים לקבל אותה, חותמת זמן מהשרת ו-payload שתואם למודל התשובה של המשאב. פצלו לפי שמות האירועים, ואז החילו את ה-payload.',
  'Subscriptions use the same permissions as the rest of Appwrite, so a user only receives updates for resources they can read. Realtime uses the session you had when you subscribed, so reconnect when it changes.':
    'ההרשמות משתמשות באותן הרשאות כמו שאר Appwrite, ולכן משתמש מקבל עדכונים רק על משאבים שהוא יכול לקרוא. Realtime משתמש בסשן שהיה לכם כשנרשמתם, ולכן התחברו מחדש כשהוא משתנה.',
  'Realtime WebSocket API': 'Realtime WebSocket API',
  'Presence': 'Presence',
  'Pooler': 'Pooler',
  'REST API': 'REST API',
  'GraphQL API': 'GraphQL API',
  'HTTP': 'HTTP',
  'v18': 'v18',
  'Everything in Appwrite is realtime': 'כל דבר ב-Appwrite הוא Realtime',
  'Rows, files, function executions, sessions, teams, and presence all emit events on the same WebSocket. Subscribe once, get changes in milliseconds instead of polling, and only ever receive what the user can read.':
    'שורות, קבצים, הרצות פונקציות, סשנים, צוותים ו-Presence כולם מפיקים אירועים על אותו WebSocket. הירשמו פעם אחת, קבלו שינויים באלפיות שנייה במקום לתשאל, וקבלו רק את מה שהמשתמש יכול לקרוא.',
  'Every service': 'כל שירות',
  'Not just databases': 'לא רק מסדי נתונים',
  'Every service publishes channels: account and sessions, teams and memberships, rows, files, function executions, and presences. The Channel helper class builds the channel string for you with a fluent API, so you can target one row or every row in a table. Leave an ID blank to subscribe with a wildcard, and append .create(), .update(), or .delete() to narrow the stream to a single event type.':
    'כל שירות מפרסם ערוצים: חשבון וסשנים, צוותים וחברויות, שורות, קבצים, הרצות פונקציות ו-Presences. מחלקת העזר Channel בונה בשבילכם את מחרוזת הערוץ עם API שוטף, כך שתוכלו לכוון לשורה אחת או לכל השורות בטבלה. השאירו מזהה ריק כדי להירשם עם תו כללי, והוסיפו .create(), .update() או .delete() כדי לצמצם את הזרם לסוג אירוע אחד.',
  'Subscribe to your first channel and watch rows, files, executions, and presence updates arrive as they happen.':
    'הירשמו לערוץ הראשון שלכם וראו עדכוני שורות, קבצים, הרצות ו-Presence מגיעים ברגע שהם קורים.',
  'Every service publishes to a channel': 'כל שירות מפרסם לערוץ',
  'Databases, Storage, Functions, Auth, teams, and presence all stream on the same socket. The Channel helper builds the string with a fluent API, so leave an ID blank for a wildcard or append .create() to narrow the stream.':
    'מסדי נתונים, אחסון, פונקציות, אימות, צוותים ו-Presence כולם זורמים על אותו סוקט. מחלקת העזר Channel בונה את המחרוזת עם API שוטף, כך שאפשר להשאיר מזהה ריק לתו כללי או להוסיף .create() כדי לצמצם את הזרם.',
  'Auth, teams, and presence': 'אימות, צוותים ו-Presence',
  'Built-in connection pooling': 'Connection pooling מובנה',
  'PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers exhaust a specification fast. Point runtime traffic at the pooler, same hostname and credentials, and many clients share a small pool of server connections.':
    'PostgreSQL מקצה תהליך backend לכל חיבור, ולכן פונקציות serverless ושרתי אפליקציה שמתרחבים אופקית ממצים מפרט במהירות. הפנו את תעבורת זמן הריצה ל-Pooler, אותו hostname ואותם פרטי גישה, והרבה לקוחות יחלקו מאגר קטן של חיבורי שרת.',
  'The limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. Since PostgreSQL spends a backend process per connection, point runtime traffic at the pooler, where many clients share a small pool of server connections. Keep migrations and long administrative sessions on a direct connection.':
    'המגבלה נקבעת לפי המפרט שלכם, מ-100 בדרגה הקטנה ביותר ועד תקרת פלטפורמה של 10,000. מכיוון ש-PostgreSQL מקצה תהליך backend לכל חיבור, הפנו את תעבורת זמן הריצה ל-Pooler, שם הרבה לקוחות חולקים מאגר קטן של חיבורי שרת. השאירו מיגרציות וסשנים אדמיניסטרטיביים ארוכים על חיבור ישיר.',
  'Yes, with the standard PostgreSQL tooling you already use. Run your dump and restore over a direct connection, because tools like pg_dump expect one session for the whole run, then point your application runtime at the pooler. Any client that speaks the wire protocol works, including pgAdmin and DataGrip.':
    'כן, עם כלי PostgreSQL הרגילים שאתם כבר משתמשים בהם. הריצו את ה-dump וה-restore על חיבור ישיר, כי כלים כמו pg_dump מצפים לסשן אחד לכל ההרצה, ואז הפנו את זמן הריצה של האפליקציה ל-Pooler. כל לקוח שמדבר את פרוטוקול התקשורת עובד, כולל pgAdmin ו-DataGrip.',
  'A full extension catalog, at no extra cost':
    'קטלוג הרחבות מלא, בלי תוספת עלות',
  'Each database lists every extension its PostgreSQL version can install, from pgvector and PostGIS to pg_trgm, pgcrypto, and many more. Free to install, up to 50 per database.':
    'כל מסד נתונים מציג את כל ההרחבות שגרסת PostgreSQL שלו יכולה להתקין, מ-pgvector ו-PostGIS ועד pg_trgm, pgcrypto ועוד רבות. ההתקנה בחינם, עד 50 לכל מסד נתונים.',
  'The engine reports every extension this PostgreSQL version can install, so the catalog is the full set available to this database, not a short list. That includes pgvector, PostGIS, pg_trgm, pgcrypto, and many more. Installs are free, up to 50 per database. Uninstalling always cascades, so anything depending on the extension is dropped with it.':
    'המנוע מדווח על כל הרחבה שגרסת PostgreSQL הזו יכולה להתקין, ולכן הקטלוג הוא הסט המלא הזמין למסד הנתונים הזה, לא רשימה קצרה. זה כולל pgvector, PostGIS, pg_trgm, pgcrypto ועוד רבות. ההתקנות בחינם, עד 50 לכל מסד נתונים. הסרה תמיד עושה cascade, כך שכל מה שתלוי בהרחבה נמחק יחד איתה.',
  'Case-insensitive text type, so comparisons ignore letter case.':
    'טיפוס טקסט שאינו רגיש לאותיות גדולות וקטנות, כך שהשוואות מתעלמות מרישיות.',
  'A sample of the catalog. Your database lists every extension it can install, including hstore, uuid-ossp, and many more.':
    'מדגם מהקטלוג. מסד הנתונים שלכם מציג את כל ההרחבות שאפשר להתקין, כולל hstore, uuid-ossp ועוד רבות.',
  'Compute credits on every Pro plan': 'קרדיטים כלולים בכל תוכנית Pro',
  Clients: 'לקוחות',
  'Specifications and pricing': 'מפרטים ותמחור',
  'Specifications docs': 'תיעוד מפרטים',
  'Use the same hostname and credentials with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your PostgreSQL toolchain. Copy DSN, .env, and ORM snippets from the Console Credentials tab, or follow integration guides in the docs.':
    'השתמשו באותו hostname ובאותם credentials עם Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql ושאר כלי ה-PostgreSQL שלכם. העתיקו DSN, .env וקטעי ORM מהלשונית Credentials בקונסול, או עקבו אחר מדריכי האינטגרציה בתיעוד.',
}



