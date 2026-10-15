/**
 * Hebrew translations for paid campaign landing pages (/secret/*).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heCampaignsDictionary: Record<string, string> = {
  // Meta
  'The secret Supabase and Firebase are hiding from you':
    'הסוד ש-Supabase ו-Firebase מסתירות מכם',
  'The secret Supabase is hiding from you': 'הסוד ש-Supabase מסתירה מכם',
  'The secret Firebase is hiding from you': 'הסוד ש-Firebase מסתירה מכם',
  'The fine print on databases, functions, hosting, and billing, with links to their own docs. Then meet the open-source cloud that does it all, and make your own call.':
    'האותיות הקטנות על מסדי נתונים, פונקציות, אירוח וחיוב, עם קישורים לתיעוד שלהן עצמן. אחר כך הכירו את הענן בקוד פתוח שעושה את כל זה, והחליטו בעצמכם.',
  'The fine print on Supabase databases, functions, hosting, and pricing, with links to its own docs. Then meet the open-source cloud that does more, and make your own call.':
    'האותיות הקטנות על מסדי הנתונים, הפונקציות, האירוח והתמחור של Supabase, עם קישורים לתיעוד שלה עצמה. אחר כך הכירו את הענן בקוד פתוח שעושה יותר, והחליטו בעצמכם.',
  'The fine print on Firebase billing, functions, databases, and lock-in, with links to its own docs. Then meet the open-source cloud you can own, and make your own call.':
    'האותיות הקטנות על החיוב, הפונקציות, מסדי הנתונים ונעילת הספק של Firebase, עם קישורים לתיעוד שלה עצמה. אחר כך הכירו את הענן בקוד פתוח שיכול להיות באמת שלכם, והחליטו בעצמכם.',
  'Read the fine print, then make your own call.':
    'קראו את האותיות הקטנות, ואז תחליטו בעצמכם.',

  // Hero
  'The secret {suspects} are hiding from you': '{suspects} מסתירות מכם סוד',
  'The secret {suspects} is hiding from you': '{suspects} מסתירה מכם סוד',
  'Case file, declassified': 'תיק חקירה, הותר לפרסום',
  "It's not a conspiracy. It's the fine print. We're Appwrite, the open-source cloud, and we went through their docs and pricing so you don't have to. Every claim links to its source. You make the call.":
    'זו לא קונספירציה. אלה האותיות הקטנות. אנחנו Appwrite, הענן בקוד פתוח, ועברנו על התיעוד והתמחור שלהן כדי שאתם לא תצטרכו. כל טענה מקושרת למקור שלה. ההחלטה בידיים שלכם.',
  'Open the case file': 'פתחו את תיק החקירה',
  'Try Appwrite for free': 'נסו את Appwrite בחינם',
  'Free plan available': 'יש תוכנית חינמית',
  pricing: 'תמחור',
  'Fine print': 'אותיות קטנות',
  'Case file': 'תיק חקירה',

  // Case file
  'The case file': 'תיק החקירה',
  'Six things in the fine print': 'שישה דברים באותיות הקטנות',
  'Nothing here is a leak. It is all public, just easy to miss. Scroll to lift the black marker, and see how Appwrite answers each one.':
    'שום דבר כאן אינו הדלפה. הכול פומבי, רק קל לפספס. גללו כדי להסיר את הטוש השחור, וראו איך Appwrite עונה על כל אחד מהם.',
  Exhibit: 'מוצג',
  Exhibits: 'מוצגים',
  Declassified: 'הותר לפרסום',
  'Evidence index': 'אינדקס ראיות',
  'Every finding links to their own docs or pricing page.':
    'כל ממצא מקושר לתיעוד או לדף התמחור שלהן עצמן.',
  'See how Appwrite does it': 'ראו איך Appwrite עושה את זה',
  'Seen enough?': 'ראיתם מספיק?',

  // Topics
  'Lock-in': 'נעילת ספק',
  Roadmap: 'מפת דרכים',

  // Exhibit headlines
  'Pick a database. Just one.': 'בחרו מסד נתונים. רק אחד.',
  'Your code speaks their language.': 'הקוד שלכם מדבר בשפה שלהן.',
  'Your frontend lives somewhere else.': 'ה-frontend שלכם גר במקום אחר.',
  'Reaching your users takes another vendor.':
    'כדי להגיע למשתמשים שלכם צריך עוד ספק.',
  'Leaving is harder than joining.': 'לעזוב קשה יותר מלהצטרף.',
  'The fine print on your bill.': 'האותיות הקטנות בחשבון שלכם.',
  'Self-hosting is the lite version.': 'האירוח העצמי הוא גרסת לייט.',
  'You can never take it with you.': 'לעולם לא תוכלו לקחת את זה איתכם.',
  'The cap is not really a cap.': 'התקרה היא לא באמת תקרה.',
  'Writing backend code needs a billing account.':
    'כדי לכתוב קוד backend צריך חשבון חיוב.',
  'SQL is a separate product.': 'SQL הוא מוצר נפרד.',
  'Products come with an expiry date.': 'למוצרים יש תאריך תפוגה.',
  'Security takes a language of its own.': 'אבטחה דורשת שפה משלה.',

  // Findings
  'PostgreSQL, and only PostgreSQL': 'PostgreSQL, ורק PostgreSQL',
  'Every project is a single Postgres database. Documents and vectors have to fit inside it.':
    'כל פרויקט הוא מסד נתונים יחיד של Postgres. מסמכים ווקטורים חייבים להיכנס לתוכו.',
  'Documents, unless you add Cloud SQL': 'מסמכים, אלא אם תוסיפו Cloud SQL',
  'Firestore is NoSQL. Relational data means SQL Connect, a separate Cloud SQL instance from $9.37/mo after a 3-month trial.':
    'Firestore היא NoSQL. נתונים רלציוניים פירושם SQL Connect, מופע Cloud SQL נפרד שמתחיל ב-$9.37 לחודש אחרי תקופת ניסיון של 3 חודשים.',
  'TypeScript, and nothing else': 'TypeScript, ותו לא',
  'Edge Functions run TypeScript on a Deno runtime. Python, Go, and the rest live somewhere else.':
    'Edge Functions מריצות TypeScript על סביבת ריצה של Deno. Python, Go וכל השאר צריכים לרוץ במקום אחר.',
  'No functions without Blaze': 'אין פונקציות בלי Blaze',
  'Cloud Functions are not available on the no-cost Spark plan. You need pay-as-you-go Blaze, and you get Node.js or Python.':
    'Cloud Functions לא זמינות בתוכנית Spark החינמית. צריך את תוכנית Blaze בתשלום לפי שימוש, ומקבלים Node.js או Python.',
  'No web hosting': 'אין אירוח אתרים',
  'Supabase runs your backend only. Your frontend goes on Vercel, Netlify, or another host, with another bill.':
    'Supabase מריצה רק את ה-backend שלכם. ה-frontend עובר ל-Vercel, ל-Netlify או לשירות אירוח אחר, עם חשבון נוסף.',
  'SSR hosting needs Blaze': 'אירוח SSR דורש Blaze',
  'Static Hosting works on the free plan, but App Hosting for SSR frameworks requires the Blaze plan.':
    'Static Hosting עובד בתוכנית החינמית, אבל App Hosting לפריימוורקים של SSR דורש את תוכנית Blaze.',
  'No messaging API': 'אין API להודעות',
  'Auth emails and sign-in codes are covered. Product email, SMS, and push need another service.':
    'אימיילים של אימות וקודי התחברות מכוסים. אימיילים של המוצר, SMS ו-push דורשים שירות נוסף.',
  'Push, and only push': 'Push, ורק push',
  'Cloud Messaging sends push notifications. Email and SMS to your users need another provider.':
    'Cloud Messaging שולח התראות push. אימייל ו-SMS למשתמשים שלכם דורשים ספק נוסף.',
  'Self-hosting gets you one project': 'אירוח עצמי נותן לכם פרויקט אחד',
  'Self-hosted Supabase is community supported, runs a single project, and leaves out branching, managed backups, and PITR.':
    'Supabase באירוח עצמי נתמך על ידי הקהילה, מריץ פרויקט יחיד ולא כולל branching, גיבויים מנוהלים ו-PITR.',
  'Closed source, Google Cloud only': 'קוד סגור, רק ב-Google Cloud',
  'Every Firebase project is a Google Cloud project. There is no way to run it on your own servers.':
    'כל פרויקט Firebase הוא פרויקט Google Cloud. אין דרך להריץ אותו על השרתים שלכם.',
  '250GB of egress on Pro': '250GB של תעבורה יוצאת ב-Pro',
  'Pro includes 100K monthly active users and 250GB of egress, then $3.25 per 1,000 users and $0.09 per GB.':
    'Pro כוללת 100K משתמשים פעילים חודשיים ו-250GB של תעבורה יוצאת, ואחר כך $3.25 לכל 1,000 משתמשים ו-$0.09 לכל GB.',
  'Spend caps skip your database': 'תקרות ההוצאה מדלגות על מסד הנתונים',
  'Spend caps are in Preview, cover four services, and leave out Firestore, Storage, and Auth. Google says they are not hard caps.':
    'תקרות ההוצאה נמצאות ב-Preview, מכסות ארבעה שירותים ולא כוללות את Firestore, Storage ו-Auth. Google עצמה אומרת שאלה לא תקרות קשיחות.',
  'Shutdown dates on the calendar': 'תאריכי סגירה כבר ביומן',
  'Dynamic Links shut down in August 2025. Firebase Studio sunsets on March 22, 2027, and Extensions on March 31, 2027.':
    'Dynamic Links נסגרו באוגוסט 2025. Firebase Studio נסגר ב-22 במרץ 2027, ו-Extensions ב-31 במרץ 2027.',
  'A rules language of its own': 'שפת כללים משלה',
  'Firestore and Storage access lives in Security Rules, a separate language you write, test, and deploy.':
    'הגישה ל-Firestore ול-Storage מוגדרת ב-Security Rules, שפה נפרדת שצריך לכתוב, לבדוק ולפרוס.',

  // Appwrite answers
  'Five database models in one project':
    'חמישה מודלים של מסדי נתונים בפרויקט אחד',
  'TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.':
    'TablesDB על compute מסוג serverless או ייעודי, ועוד DocumentsDB, VectorsDB, PostgreSQL ו-MySQL על compute ייעודי.',
  '13+ runtimes, on the Free plan too': 'יותר מ-13 סביבות ריצה, גם בתוכנית Free',
  'Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, Deno, and more, with 750K executions a month for free.':
    'Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, Deno ועוד, עם 750K הרצות בחודש בחינם.',
  'Sites, next to your backend': 'Sites, ממש ליד ה-backend שלכם',
  'Static and SSR hosting with Git deploys and previews on every plan, Free included.':
    'אירוח סטטי ו-SSR עם פריסות מ-Git ותצוגות מקדימות בכל תוכנית, כולל Free.',
  'Email, SMS, and push from one API': 'אימייל, SMS ו-push מ-API אחד',
  'Appwrite Messaging connects 12 providers, with topics, targeting, and scheduling built in.':
    'Appwrite Messaging מתחברת ל-12 ספקים, עם topics, טירגוט ותזמון מובנים.',
  'Open source, run it anywhere': 'קוד פתוח, שרץ בכל מקום',
  'Self-host with one Docker command and run many projects with the same APIs, SDKs, and Console as Appwrite Cloud.':
    'אירוח עצמי בפקודת Docker אחת, והרצה של פרויקטים רבים עם אותם APIs, SDKs וקונסולה כמו ב-Appwrite Cloud.',
  '2TB bandwidth and one budget cap': 'רוחב פס של 2TB ותקרת תקציב אחת',
  'Pro is $25/mo with 200K monthly active users, 2TB bandwidth, and an organization-wide budget cap.':
    'Pro עולה $25 לחודש, עם 200K משתמשים פעילים חודשיים, רוחב פס של 2TB ותקרת תקציב לכל הארגון.',
  'The code is yours to keep': 'הקוד נשאר שלכם',
  'Appwrite is open source, so the backend you build on stays yours to run on Appwrite Cloud or your own servers.':
    'Appwrite היא קוד פתוח, כך שה-backend שאתם בונים עליו נשאר שלכם, להריץ ב-Appwrite Cloud או על השרתים שלכם.',
  'Permissions you can read': 'הרשאות שאפשר לקרוא',
  'Role strings on tables, rows, buckets, and files, set from the Console or SDK and enforced on every API.':
    'מחרוזות תפקיד על טבלאות, שורות, buckets וקבצים, שמוגדרות מהקונסולה או מה-SDK ונאכפות בכל API.',
  'Twice the users, eight times the bandwidth':
    'פי שניים משתמשים, פי שמונה רוחב פס',
  'Appwrite Pro is also $25/mo, with 200K monthly active users, 2TB bandwidth, and $3 per 1,000 extra users.':
    'גם Appwrite Pro עולה $25 לחודש, עם 200K משתמשים פעילים חודשיים, רוחב פס של 2TB ו-$3 לכל 1,000 משתמשים נוספים.',
  'One budget cap for your whole organization': 'תקרת תקציב אחת לכל הארגון',
  'On Pro, a single cap limits automatic scaling across all your projects, with email warnings before you reach it.':
    'ב-Pro, תקרה אחת מגבילה את הגדילה האוטומטית בכל הפרויקטים שלכם, עם התראות באימייל לפני שמגיעים אליה.',

  // Reveal
  'The secret is out': 'הסוד נחשף',
  'You never had to pick between them':
    'אף פעם לא הייתם צריכים לבחור ביניהן',
  'Appwrite is the open-source cloud that brings it all together: auth, five database models, storage, functions in 13+ runtimes, realtime, messaging, and web hosting. Run it on Appwrite Cloud or on your own servers.':
    'Appwrite היא הענן בקוד פתוח שמחבר את הכול יחד: אימות, חמישה מודלים של מסדי נתונים, אחסון, פונקציות ביותר מ-13 סביבות ריצה, Realtime, הודעות ואירוח אתרים. הריצו אותה ב-Appwrite Cloud או על השרתים שלכם.',
  'Keep Postgres. Get everything else': 'השאירו את Postgres. קבלו את כל השאר',
  'Appwrite runs managed PostgreSQL next to four more database models, plus auth, storage, functions in 13+ runtimes, realtime, messaging, and web hosting. All open source, on Appwrite Cloud or your own servers.':
    'Appwrite מריצה PostgreSQL מנוהל לצד ארבעה מודלים נוספים של מסדי נתונים, ועוד אימות, אחסון, פונקציות ביותר מ-13 סביבות ריצה, Realtime, הודעות ואירוח אתרים. הכול בקוד פתוח, ב-Appwrite Cloud או על השרתים שלכם.',
  'An all-in-one cloud you can actually own':
    'ענן הכול-באחד שבאמת יכול להיות שלכם',
  'Appwrite gives you the all-in-one experience, with auth, databases, storage, functions, realtime, messaging, and hosting, except it is open source and runs on Appwrite Cloud or your own servers.':
    'Appwrite נותנת לכם את חוויית הכול-באחד, עם אימות, מסדי נתונים, אחסון, פונקציות, Realtime, הודעות ואירוח, רק שהיא בקוד פתוח ורצה ב-Appwrite Cloud או על השרתים שלכם.',
  'Products in one platform': 'מוצרים בפלטפורמה אחת',
  PostgreSQL: 'PostgreSQL',

  // Verdict
  'Your call': 'ההחלטה שלכם',
  'Line them up side by side': 'השוו אותן זו לצד זו',
  'The short version. The full comparisons cover every product, price, and source.':
    'הגרסה המקוצרת. ההשוואות המלאות מכסות כל מוצר, מחיר ומקור.',
  'Read the full Supabase comparison': 'קראו את ההשוואה המלאה ל-Supabase',
  'Read the full Firebase comparison': 'קראו את ההשוואה המלאה ל-Firebase',
  'Self-host many projects': 'אירוח עצמי של פרויקטים רבים',
  'No self-hosting': 'אין אירוח עצמי',
  'SQL through Cloud SQL, billed separately': 'SQL דרך Cloud SQL, בחיוב נפרד',
  'Free plan included': 'כולל תוכנית Free',
  TypeScript: 'TypeScript',
  'Deno runtime': 'סביבת ריצה של Deno',
  'Blaze plan only': 'בתוכנית Blaze בלבד',
  'SSR requires Blaze': 'SSR דורש Blaze',
  'Push only': 'push בלבד',
  'Spend cap on Pro': 'תקרת הוצאות ב-Pro',
  'Preview, four services': 'Preview, ארבעה שירותים',
  '200K MAU, 2TB bandwidth': '200K MAU, רוחב פס של 2TB',
  '100K MAU, 250GB egress': '100K MAU, תעבורה יוצאת של 250GB',
  'Blaze, billed by usage': 'Blaze, חיוב לפי שימוש',
  'In fairness, they might still be the right call if':
    'למען ההגינות, הן עדיין עשויות להיות הבחירה הנכונה אם',

  // Switching and close
  Switching: 'מעבר',
  'Bring your project to Appwrite': 'העבירו את הפרויקט שלכם ל-Appwrite',
  'The Migrations tool in the Console moves your users, data, and files in the background, and migration usage does not count toward your Appwrite Cloud bill.':
    'כלי המיגרציות בקונסולה מעביר את המשתמשים, הנתונים והקבצים שלכם ברקע, והשימוש במהלך המיגרציה לא נספר בחשבון Appwrite Cloud שלכם.',
  'Firestore collections': 'אוספי Firestore',
  'Storage files': 'קבצי Storage',
  'Bring over users, Firestore collections, and Storage files. Existing passwords keep working.':
    'העבירו משתמשים, אוספי Firestore וקבצי Storage. הסיסמאות הקיימות ממשיכות לעבוד.',
  'Your verdict': 'פסק הדין שלכם',
  'Case closed? That part is up to you': 'התיק נסגר? זה כבר תלוי בכם',
  'Create a free Appwrite project, poke around the Console, and make the call yourself.':
    'צרו פרויקט Appwrite בחינם, חטטו בקונסולה והחליטו בעצמכם.',
  'See Appwrite pricing': 'צפו בתמחור של Appwrite',}
