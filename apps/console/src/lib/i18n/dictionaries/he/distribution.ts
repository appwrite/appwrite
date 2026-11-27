export const heDistributionDictionary: Record<string, string> = {
  // Shared
  Distribution: 'Distribution',
  'Loading apps...': 'טוען אפליקציות...',
  'My app': 'האפליקציה שלי',
  'This distribution app does not exist or has been removed.':
    'אפליקציית הפצה זו אינה קיימת או שהוסרה.',
  'No builds yet': 'אין עדיין בניות',
  Track: 'מסלול',
  'Create the app': 'יצירת האפליקציה',
  'Build every platform': 'בנייה לכל פלטפורמה',
  'Each version gets its own build per platform, with status and duration tracked here.':
    'כל גרסה מקבלת Build משלה לכל פלטפורמה, והסטטוס ומשך הזמן מוצגים כאן.',
  'Submit to the stores': 'הגשה לחנויות',
  'Code signing': 'חתימת קוד',
  Signing: 'בחתימה',
  Expiring: 'יפוג בקרוב',
  Revoked: 'נשלל',
  // Statuses
  Validating: 'באימות',
  'In review': 'בבדיקה',
  Published: 'פורסם',
  Rejected: 'נדחה',
  Halted: 'הושהה',
  'Needs attention': 'דורש טיפול',
  Blocker: 'חוסם',
  'Manual action required': 'נדרשת פעולה ידנית',
  // Targets
  '4 vCPU, 8 GB RAM': '4 vCPU, 8 GB RAM',
  '8 vCPU, 16 GB RAM': '8 vCPU, 16 GB RAM',
  'A target holds the store identity and build settings for one platform of this app.':
    'יעד מכיל את פרטי החנות והגדרות ה-Build עבור פלטפורמה אחת של האפליקציה.',
  'Add an Android target to build this app and submit it to Google Play.':
    'הוסיפו יעד Android כדי לבנות את האפליקציה ולהגיש אותה ל-Google Play.',
  'Add an iOS target to build this app and submit it to the App Store.':
    'הוסיפו יעד iOS כדי לבנות את האפליקציה ולהגיש אותה ל-App Store.',
  'Add target': 'הוספת יעד',
  'Android target': 'יעד Android',
  'App Store app ID': 'מזהה האפליקציה ב-App Store',
  'Appwrite assigns the next build number to every build.':
    'Appwrite מקצה את מספר ה-Build הבא לכל Build.',
  'Automatic submission': 'הגשה אוטומטית',
  'Build configuration': 'תצורת Build',
  'Build timeout (seconds)': 'Timeout של Build (בשניות)',
  'Builds and submissions for this platform stop until you add the target again. This action cannot be undone.':
    'ה-Builds וההגשות של פלטפורמה זו ייעצרו עד שתוסיפו את היעד מחדש. לא ניתן לבטל פעולה זו.',
  'Builds use the build number from your project.':
    'ה-Builds משתמשים במספר ה-Build שמוגדר בפרויקט שלכם.',
  'Closed testing': 'בדיקה סגורה',
  'Failed to add target': 'הוספת היעד נכשלה',
  'Failed to remove target': 'הסרת היעד נכשלה',
  'Failed to update target': 'עדכון היעד נכשל',
  Flavor: 'Flavor',
  'Framework default': 'ברירת המחדל של ה-framework',
  'Gradle task': 'משימת Gradle',
  'How builds of this target are numbered and submitted.':
    'איך ה-Builds של היעד ממוספרים ומוגשים.',
  'Internal testing': 'בדיקה פנימית',
  'iOS target': 'יעד iOS',
  'Last build number:': 'מספר ה-Build האחרון:',
  'Manage connections': 'ניהול חיבורים',
  'Manage signing': 'ניהול חתימה',
  'No Android target': 'אין יעד Android',
  'No connections for this store yet.': 'עדיין אין חיבורים לחנות זו.',
  'No iOS target': 'אין יעד iOS',
  'No signing identities for this platform yet.':
    'עדיין אין זהויות חתימה לפלטפורמה זו.',
  'Open testing': 'בדיקה פתוחה',
  'Output path': 'נתיב פלט',
  'Override the framework defaults. Leave a field empty to use the default.':
    'עקיפת ברירות המחדל של ה-framework. השאירו שדה ריק כדי להשתמש בברירת המחדל.',
  'Remove target': 'הסרת יעד',
  'Resolved by preflight': 'נקבע בבדיקה המקדימה',
  'Resolved by preflight from the bundle ID.':
    'נקבע בבדיקה המקדימה לפי מזהה ה-Bundle.',
  Scheme: 'Scheme',
  'Signing identity': 'זהות חתימה',
  'Store and signing': 'חנות וחתימה',
  'Store connection': 'חיבור לחנות',
  'Submit every successful Git build of this target to the selected track.':
    'כל Build מוצלח מ-Git של היעד יוגש למסלול שנבחר.',
  'Target added': 'היעד נוסף',
  'Target removed': 'היעד הוסר',
  'Target updated': 'היעד עודכן',
  'TestFlight (external)': 'TestFlight (חיצוני)',
  'TestFlight (internal)': 'TestFlight (פנימי)',
  'Use 0 for the default timeout.':
    'הזינו 0 כדי להשתמש ב-Timeout של ברירת המחדל.',
  'Version strategy': 'אסטרטגיית גרסאות',
  'Where builds of this target are submitted and how they are signed.':
    'לאן ה-Builds של היעד מוגשים ואיך הם נחתמים.',
  Workspace: 'Workspace',
  'Xcode project': 'פרויקט Xcode',
  // App tabs
  Artifacts: 'ארטיפקטים',
  Releases: 'שחרורים',
  // Settings
  'Variables are passed to every build of this app. App variables override project variables with the same key. Keys cannot start with DISTRIBUTION_ or APPWRITE_.':
    'המשתנים מועברים לכל Build של האפליקציה. משתני אפליקציה גוברים על משתני פרויקט עם אותו מפתח. מפתחות לא יכולים להתחיל ב-DISTRIBUTION_ או ב-APPWRITE_.',
  'Permanently delete this app with its targets, variables and builds. Apps already published to the stores stay there. This action cannot be undone.':
    'מחיקה לצמיתות של האפליקציה, כולל היעדים, המשתנים וה-Builds שלה. אפליקציות שכבר פורסמו בחנויות יישארו שם. לא ניתן לבטל פעולה זו.',
  'Its targets, variables, builds and repository link are removed. This action cannot be undone.':
    'היעדים, המשתנים, ה-Builds והקישור למאגר יוסרו. לא ניתן לבטל פעולה זו.',
  'Enter app name': 'הזינו את שם האפליקציה',
  'Build on push': 'Build בכל push',
  'Build every target when a push matches your triggers. When off, start builds manually.':
    'בניית כל היעדים כש-push תואם לטריגרים. כשהאפשרות כבויה, מפעילים Builds ידנית.',
  'Report commit status': 'דיווח סטטוס commit',
  'Show building, passed or failed on each commit. Can be required in branch protection.':
    'הצגת בבנייה, עבר או נכשל בכל commit. אפשר לחייב זאת בהגנת Branch.',
  'Post build summaries': 'פרסום סיכומי Build',
  'Distribution does not comment on pull requests yet. This preference is kept for when it does.':
    'Distribution עדיין לא מגיב ב-pull requests. ההעדפה נשמרת לשימוש עתידי.',
  'Git automation': 'אוטומציית Git',
  'Choose what happens when you push to the connected repository.':
    'בחרו מה קורה כשאתם מבצעים push למאגר המחובר.',
  'Connected repository': 'מאגר מחובר',
  'Connect your app to a Git repository to build it when you push.':
    'חברו את האפליקציה למאגר Git כדי לבנות אותה בכל push.',
  'Repository details unavailable': 'פרטי המאגר אינם זמינים',
  'Pushes to this repository will stop building this app. Existing builds are kept.':
    'push למאגר הזה כבר לא יבנה את האפליקציה. ה-Builds הקיימים יישמרו.',
  "This app's Git installation can no longer reach its repository, so pushes can't build. Disconnect the repository, then connect it again through the installation that has it now.":
    'התקנת ה-Git של האפליקציה כבר לא מגיעה למאגר, ולכן push לא יכול לבנות. נתקו את המאגר וחברו אותו מחדש דרך ההתקנה שיש לה גישה אליו כעת.',
  "This app's repository details could not be loaded. Its Git connection is unchanged, but branch and root directory options are unavailable until access is restored.":
    'לא ניתן לטעון את פרטי המאגר של האפליקציה. חיבור ה-Git לא השתנה, אבל אפשרויות ה-Branch ותיקיית השורש לא זמינות עד שהגישה תשוחזר.',
  'Choose the directory containing your app code':
    'בחרו את התיקייה שמכילה את קוד האפליקציה',
  'App name updated': 'שם האפליקציה עודכן',
  'Failed to update app name': 'עדכון שם האפליקציה נכשל',
  'The name shown for this app in the Console.':
    'השם שמוצג לאפליקציה הזו ב-Console.',
  'Failed to update app status': 'עדכון סטטוס האפליקציה נכשל',
  'A disabled app ignores pushes and does not accept new builds.':
    'אפליקציה מושבתת מתעלמת מ-push ולא מקבלת Builds חדשים.',
  Branches: 'Branches',
  'Pushes to these branches start a build. When empty, only the production branch builds.':
    'push ל-Branches האלה מפעיל Build. כשהשדה ריק, רק Branch הפרודקשן נבנה.',
  'Only changes to these paths start a build. When empty, any change builds.':
    'רק שינויים בנתיבים האלה מפעילים Build. כשהשדה ריק, כל שינוי מפעיל Build.',
  'Pushing a matching tag starts a build. When empty, tags never build. GitHub and Gitea only.':
    'push של תגית תואמת מפעיל Build. כשהשדה ריק, תגיות לא מפעילות Build. נתמך ב-GitHub וב-Gitea בלבד.',
  'Limit which pushes build this app. Use glob patterns, and start a pattern with ! to exclude it.':
    'הגבילו אילו פעולות push בונות את האפליקציה. השתמשו בתבניות glob, והתחילו תבנית ב-! כדי להחריג אותה.',
  'Use letters, numbers and glob characters, and do not include and exclude the same pattern.':
    'השתמשו באותיות, ספרות ותווי glob, ואל תכללו ותחריגו את אותה תבנית.',

  // Builds
  'Add a target to the app to start builds.':
    'הוסיפו יעד לאפליקציה כדי להפעיל Builds.',
  'Back to builds': 'חזרה ל-Builds',
  'Build a target from a branch, tag or commit of the connected repository.':
    'בניית יעד מ-Branch, מתגית או מ-commit של המאגר המחובר.',
  'Build canceled': 'ה-Build בוטל',
  'Build deleted': 'ה-Build נמחק',
  'Build failed': 'ה-Build נכשל',
  'Build from': 'בנייה מתוך',
  'Build not found': 'ה-Build לא נמצא',
  'Build started': 'ה-Build התחיל',
  'Cancel the build before you delete it.': 'בטלו את ה-Build לפני שתמחקו אותו.',
  'Connect a repository to the app to start builds.':
    'חברו מאגר לאפליקציה כדי להפעיל Builds.',
  'Could not load this build': 'לא ניתן לטעון את ה-Build',
  'Create build': 'יצירת Build',
  'Delete build': 'מחיקת Build',
  'Each build compiles one target from the repository and signs it for its store.':
    'כל Build מהדר יעד אחד מהמאגר וחותם אותו עבור החנות שלו.',
  'Failed to delete build': 'מחיקת ה-Build נכשלה',
  'Failed to start build': 'הפעלת ה-Build נכשלה',
  'Fetching source': 'במשיכת קוד המקור',
  Inspecting: 'בניתוח',
  'Its logs are deleted, and so is its artifact when no submission uses it. This action cannot be undone.':
    'הלוגים שלו יימחקו, וכך גם הארטיפקט שלו אם אף הגשה לא משתמשת בו. לא ניתן לבטל פעולה זו.',
  'Leave empty to use the version set in your project.':
    'השאירו ריק כדי להשתמש בגרסה שמוגדרת בפרויקט שלכם.',
  'Load earlier logs': 'טעינת לוגים קודמים',
  'Loading builds...': 'טוען Builds...',
  'No version': 'ללא גרסה',
  'Showing the most recent logs.': 'מוצגים הלוגים האחרונים.',
  'Start a build from the connected repository, or push to a branch that matches your triggers.':
    'הפעילו Build מהמאגר המחובר, או בצעו push ל-Branch שתואם לטריגרים שלכם.',
  'The build stops and its logs are kept. Start a new build to try again.':
    'ה-Build ייעצר והלוגים שלו יישמרו. הפעילו Build חדש כדי לנסות שוב.',
  'This build does not exist or has been deleted.':
    'ה-Build הזה לא קיים או שנמחק.',
  'This build has no commit to build again.':
    'ל-Build הזה אין commit לבנייה חוזרת.',
  'This build has no logs.': 'ל-Build הזה אין לוגים.',
  'Failed to load logs': 'טעינת הלוגים נכשלה',
  'This target sets version codes manually.':
    'היעד הזה מגדיר קודי גרסה באופן ידני.',
  'Uploading to the store': 'בהעלאה לחנות',
  'Version code': 'קוד גרסה',
  'Version name': 'שם גרסה',
  builds: 'Builds',
  'Enter a whole number from 1 to 2,100,000,000.':
    'הזינו מספר שלם בין 1 ל-2,100,000,000.',
  'Failed to load builds': 'טעינת ה-Builds נכשלה',
  // Apps
  'A native Android project built with Gradle.':
    'פרויקט Android נייטיב שנבנה עם Gradle.',
  'A native iOS project built with Xcode.': 'פרויקט iOS נייטיב שנבנה עם Xcode.',
  'App created, but these targets were not added:':
    'האפליקציה נוצרה, אבל היעדים האלה לא נוספו:',
  'At least two parts separated by periods, each starting with a letter, as in your Gradle applicationId.':
    'לפחות שני חלקים שמופרדים בנקודות, וכל אחד מתחיל באות, כמו ב-applicationId של Gradle.',
  'Build Android and iOS releases from one place and submit them to Google Play and App Store Connect.':
    'בנו גרסאות Android ו-iOS ממקום אחד והגישו אותן ל-Google Play ול-App Store Connect.',
  'Build from a branch on every push or tag.': 'Build מ-Branch בכל push או תג.',
  'Build the app from a connected Git repository.':
    'בנו את האפליקציה ממאגר Git מחובר.',
  'Builds use the toolchain of this framework. Install and build commands can be changed per target later.':
    'ה-Builds משתמשים בכלי הבנייה של הפריימוורק הזה. אפשר לשנות את פקודות ההתקנה והבנייה לכל יעד בהמשך.',
  'Choose a framework': 'בחירת פריימוורק',
  'Choose platforms': 'בחירת פלטפורמות',
  'Choose the directory containing your app project':
    'בחרו את התיקייה שמכילה את פרויקט האפליקציה',
  'Connect a Git repository': 'חיבור מאגר Git',
  'Connect a repository': 'חיבור מאגר',
  'Each platform becomes a target with its own store identifier. Connections and signing are set on the target later.':
    'כל פלטפורמה הופכת ליעד עם מזהה חנות משלו. את החיבורים והחתימה מגדירים ביעד בהמשך.',
  'JavaScript or TypeScript with native iOS and Android builds.':
    'JavaScript או TypeScript עם Builds נייטיב ל-iOS ול-Android.',
  'Latest build': 'Build אחרון',
  'Letters, numbers, hyphens and periods, as registered in your Apple Developer account.':
    'אותיות, ספרות, מקפים ונקודות, כפי שנרשם בחשבון Apple Developer שלכם.',
  'Name your app': 'מתן שם לאפליקציה',
  'No apps found': 'לא נמצאו אפליקציות',
  'No repository': 'אין מאגר',
  'Not available for this framework': 'לא זמין בפריימוורק הזה',
  'One Dart codebase for iOS and Android.':
    'בסיס קוד Dart אחד ל-iOS ול-Android.',
  'Path to the app in the linked repository. Use the repository root (./) or the directory that holds the app project.':
    'הנתיב לאפליקציה במאגר המקושר. השתמשו בשורש המאגר (./) או בתיקייה שמכילה את פרויקט האפליקציה.',
  'Pick Flutter, React Native, native Android or native iOS, and the platforms you ship to.':
    'בחרו Flutter,‏ React Native,‏ Android נייטיב או iOS נייטיב, ואת הפלטפורמות שאליהן אתם מפיצים.',
  'Production branch of the app. Pushes to this branch start a build for every target.':
    'Branch הפרודקשן של האפליקציה. כל push ל-Branch הזה מפעיל Build לכל יעד.',
  'Pushes to the repository start builds. You can also upload builds yourself and connect a repository later.':
    'כל push למאגר מפעיל Build. אפשר גם להעלות Builds בעצמכם ולחבר מאגר בהמשך.',
  'Send builds to Google Play and App Store Connect, or submit them automatically.':
    'שלחו Builds ל-Google Play ול-App Store Connect, או הגישו אותם אוטומטית.',
  'Ship your apps to the stores': 'הפצת האפליקציות שלכם לחנויות',
  'The name is shown in the Console. Each store keeps its own listing name.':
    'השם מוצג ב-Console. לכל חנות יש שם משלה לדף האפליקציה.',
  'Try a different name or ID.': 'נסו שם או מזהה אחר.',
  'Upload builds later': 'העלאת Builds בהמשך',
  'Upload signed builds yourself. A repository can be connected in settings.':
    'העלו Builds חתומים בעצמכם. אפשר לחבר מאגר בהגדרות.',
  "You don't have permission to create apps.": 'אין לכם הרשאה ליצור אפליקציות.',
  // Artifacts
  'Upload artifact': 'העלאת ארטיפקט',
  'Binaries built by Appwrite or uploaded from your own pipeline. A ready artifact can be submitted to the stores from a release.':
    'קבצים בינאריים ש-Appwrite בנה או שהועלו מה-pipeline שלכם. ארטיפקט מוכן אפשר להגיש לחנויות מתוך שחרור.',
  'Failed to load artifacts': 'טעינת הארטיפקטים נכשלה',
  'No artifacts yet': 'אין עדיין ארטיפקטים',
  'Builds produce artifacts here. You can also upload an IPA or AAB built elsewhere.':
    'ה-Builds יוצרים כאן ארטיפקטים. אפשר גם להעלות IPA או AAB שנבנו במקום אחר.',
  artifacts: 'ארטיפקטים',
  'Only a ready artifact can be downloaded.': 'אפשר להוריד רק ארטיפקט מוכן.',
  Signed: 'חתום',
  Unsigned: 'לא חתום',
  'Upload an IPA for iOS or an AAB for Android. Appwrite inspects the binary and matches it to a target by its bundle ID or package name.':
    'העלו IPA ל-iOS או AAB ל-Android. Appwrite בודק את הקובץ הבינארי ומשייך אותו ליעד לפי ה-bundle ID או שם החבילה.',
  'Choose an .ipa or .aab file.': 'בחרו קובץ מסוג ipa או aab.',
  'The upload runs in the background while you keep working. Reloading the page stops it.':
    'ההעלאה ממשיכה ברקע בזמן שאתם ממשיכים לעבוד. רענון הדף עוצר אותה.',
  'This app has no targets yet. An artifact that matches no target is marked invalid.':
    'לאפליקציה הזו אין עדיין יעדים. ארטיפקט שלא תואם לאף יעד מסומן כלא תקין.',
  'Delete {name}? Its file is removed and it can no longer be submitted. This action cannot be undone.':
    'למחוק את {name}? הקובץ שלו יוסר ולא יהיה אפשר להגיש אותו יותר. לא ניתן לבטל פעולה זו.',
  'Artifact deleted': 'הארטיפקט נמחק',
  'Failed to delete artifact': 'מחיקת הארטיפקט נכשלה',
  'Delete artifact': 'מחיקת ארטיפקט',
  'Uploaded. Appwrite is inspecting the binary.':
    'הועלה. Appwrite בודק את הקובץ הבינארי.',
  'View artifacts': 'הצגת ארטיפקטים',
  // Connections
  "You don't have permission to manage store connections.":
    'אין לכם הרשאה לנהל חיבורים לחנויות.',
  'App Store Connect': 'App Store Connect',
  'Google Play': 'Google Play',
  'Create connection': 'יצירת חיבור',
  'Connection deleted': 'החיבור נמחק',
  'An app target still uses this connection. Choose another connection for the target, then try again.':
    'יעד של אפליקציה עדיין משתמש בחיבור הזה. בחרו חיבור אחר עבור היעד ונסו שוב.',
  'Failed to delete connection': 'מחיקת החיבור נכשלה',
  'Key ID': 'מזהה מפתח',
  'Issuer ID': 'מזהה מנפיק (Issuer ID)',
  'Service account': 'חשבון שירות',
  'Google Cloud project': 'פרויקט Google Cloud',
  'Search connections...': 'חיפוש חיבורים...',
  'Connect a store account': 'חיבור חשבון חנות',
  'Add an App Store Connect API key or a Google Play service account so Appwrite can upload builds and manage signing.':
    'הוסיפו מפתח API של App Store Connect או חשבון שירות של Google Play, כדי ש-Appwrite יוכל להעלות Builds ולנהל חתימה.',
  'Delete connection': 'מחיקת חיבור',
  'App Store Connect connected': 'App Store Connect חובר',
  'Connect App Store Connect': 'חיבור App Store Connect',
  'Appwrite uses an App Store Connect API key to upload builds and manage signing. It never asks for your Apple ID or password.':
    'Appwrite משתמש במפתח API של App Store Connect כדי להעלות Builds ולנהל חתימה. הוא לעולם לא יבקש את ה-Apple ID או את הסיסמה שלכם.',
  'Create a team key': 'יצירת מפתח צוות',
  'Add the key': 'הוספת המפתח',
  'The Account Holder must first request API access.':
    'בעל החשבון (Account Holder) צריך קודם לבקש גישה ל-API.',
  'Only the Account Holder can do this, once per team, in App Store Connect under Users and Access → Integrations.':
    'רק בעל החשבון יכול לעשות זאת, פעם אחת לכל צוות, ב-App Store Connect תחת Users and Access → Integrations.',
  'Generate a team key, not an individual key.': 'צרו מפתח צוות, לא מפתח אישי.',
  'Open Users and Access → Integrations → Team Keys and generate a key.':
    'פתחו את Users and Access → Integrations → Team Keys וצרו מפתח.',
  'Give the key the App Manager role.': 'הקצו למפתח את התפקיד App Manager.',
  'Choose Admin instead if Appwrite should create your certificates and provisioning profiles. A key role cannot be changed later.':
    'בחרו Admin במקום זאת אם Appwrite אמור ליצור את התעודות ואת פרופילי ה-provisioning שלכם. לא ניתן לשנות את התפקיד של מפתח בהמשך.',
  'Download the .p8 file and note the key ID and issuer ID.':
    'הורידו את קובץ ה-.p8 ורשמו את מזהה המפתח ואת מזהה המנפיק.',
  'Apple lets you download a key only once.':
    'Apple מאפשרת להוריד מפתח פעם אחת בלבד.',
  'Open App Store Connect': 'פתיחת App Store Connect',
  'Shown above the list of team keys.': 'מוצג מעל רשימת מפתחות הצוות.',
  'Private key (.p8)': 'מפתח פרטי (.p8)',
  'App Store Connect rejected the key.': 'App Store Connect דחה את המפתח.',
  'Google Play connected': 'Google Play חובר',
  'Connect Google Play': 'חיבור Google Play',
  'Appwrite uses a service account from your own Google Cloud project to upload bundles to Google Play.':
    'Appwrite משתמש בחשבון שירות מפרויקט Google Cloud שלכם כדי להעלות חבילות ל-Google Play.',
  'Create a service account': 'יצירת חשבון שירות',
  'Invite it to Play Console': 'הזמנה ל-Play Console',
  'Enable the Google Play Android Developer API.':
    'הפעילו את Google Play Android Developer API.',
  'Turn it on in the Google Cloud project that will own the service account.':
    'הפעילו אותו בפרויקט Google Cloud שבו ייווצר חשבון השירות.',
  'Create a service account.': 'צרו חשבון שירות.',
  'In IAM and admin → Service accounts, create an account. It needs no Google Cloud roles.':
    'ב-IAM and admin → Service accounts צרו חשבון. הוא לא צריך תפקידים ב-Google Cloud.',
  'Create a JSON key.': 'צרו מפתח JSON.',
  'Open the account, then Keys → Add key → Create new key, and choose JSON. Keep the downloaded file.':
    'פתחו את החשבון, ואז Keys → Add key → Create new key, ובחרו JSON. שמרו את הקובץ שהורד.',
  'Open the API library': 'פתיחת ספריית ה-API',
  'Open service accounts': 'פתיחת חשבונות השירות',
  'Invite the service account email.':
    'הזמינו את כתובת האימייל של חשבון השירות.',
  'In Play Console, open Users and permissions → Invite new users and enter the email of the service account.':
    'ב-Play Console פתחו את Users and permissions → Invite new users והזינו את כתובת האימייל של חשבון השירות.',
  'Grant access to your apps.': 'הענקת גישה לאפליקציות שלכם.',
  'Add the apps Appwrite will publish, with permission to release to the testing tracks and to production that you plan to use.':
    'הוסיפו את האפליקציות ש-Appwrite יפרסם, עם הרשאה לשחרר גרסאות למסלולי הבדיקה ולפרודקשן שבהם אתם מתכננים להשתמש.',
  'Send the invitation.': 'שלחו את ההזמנה.',
  'A service account accepts it straight away. Permission changes can take a few minutes to apply.':
    'חשבון שירות מקבל את ההזמנה מיד. שינויי הרשאות עשויים להימשך כמה דקות עד שייכנסו לתוקף.',
  'Open Play Console': 'פתיחת Play Console',
  'Service account key (JSON)': 'מפתח חשבון שירות (JSON)',
  'Google Play rejected the service account.':
    'Google Play דחה את חשבון השירות.',
  'Connection updated': 'החיבור עודכן',
  'Update connection': 'עדכון חיבור',
  'Rename the connection or replace its key. Appwrite checks a new key before saving it.':
    'שנו את שם החיבור או החליפו את המפתח שלו. Appwrite בודק מפתח חדש לפני שהוא נשמר.',
  'Rename the connection or replace its service account key. Appwrite checks a new key before saving it.':
    'שנו את שם החיבור או החליפו את מפתח חשבון השירות שלו. Appwrite בודק מפתח חדש לפני שהוא נשמר.',
  'Last check failed:': 'הבדיקה האחרונה נכשלה:',
  'New private key (.p8)': 'מפתח פרטי חדש (.p8)',
  'New service account key (JSON)': 'מפתח חשבון שירות חדש (JSON)',
  'Leave the key fields empty to keep the current key.':
    'השאירו את שדות המפתח ריקים כדי לשמור על המפתח הנוכחי.',
  'Leave this empty to keep the current key.':
    'השאירו ריק כדי לשמור על המפתח הנוכחי.',
  'The connection was not updated.': 'החיבור לא עודכן.',
  'Enter a name.': 'הזינו שם.',
  'Enter the key ID.': 'הזינו את מזהה המפתח.',
  'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.':
    'מזהה מפתח מורכב מ-10 אותיות גדולות וספרות, למשל 2X9R4HXF34.',
  'Enter the issuer ID. Individual keys have none, so use a team key.':
    'הזינו את מזהה המנפיק. למפתחות אישיים אין מזהה כזה, לכן השתמשו במפתח צוות.',
  'An issuer ID looks like 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1.':
    'מזהה מנפיק נראה כך: 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1.',
  'Add the .p8 private key.': 'הוסיפו את המפתח הפרטי (.p8).',
  'The .p8 file must contain a PEM private key.':
    'קובץ ה-.p8 חייב להכיל מפתח פרטי בפורמט PEM.',
  'Add the service account JSON key.': 'הוסיפו את מפתח ה-JSON של חשבון השירות.',
  'The key is not valid JSON.': 'המפתח אינו JSON תקין.',
  'This is not a service account key. Create a JSON key for a service account.':
    'זה אינו מפתח של חשבון שירות. צרו מפתח JSON עבור חשבון שירות.',
  'The key needs client_email and private_key. Download a new JSON key.':
    'המפתח חייב לכלול client_email ו-private_key. הורידו מפתח JSON חדש.',
  'The file could not be read. Try again.':
    'לא ניתן היה לקרוא את הקובץ. נסו שוב.',
  'Drop files here': 'גררו קבצים לכאן',
  'Drop a file here': 'גררו קובץ לכאן',
  'Add another file': 'הוספת קובץ נוסף',
  'Paste contents': 'הדבקת תוכן',
  // Releases
  releases: 'שחרורים',
  'A release is one version of this app. Submit it to each store from the release page.':
    'שחרור הוא גרסה אחת של האפליקציה. מגישים אותו לכל חנות מדף השחרור.',
  'Add a target to this app before submitting a release.':
    'הוסיפו יעד לאפליקציה לפני הגשת שחרור.',
  'Add locale': 'הוספת Locale',
  'Appwrite stops this submission and withdraws it from review. You can submit the release again later.':
    'Appwrite יעצור את ההגשה וימשוך אותה מהבדיקה. אפשר להגיש את השחרור שוב מאוחר יותר.',
  'Appwrite uploads the artifact to the store, then follows processing and review. Track the progress on this page.':
    'Appwrite מעלה את הארטיפקט לחנות ועוקב אחרי העיבוד והבדיקה. ההתקדמות מוצגת בדף הזה.',
  'Back to releases': 'חזרה לשחרורים',
  'Below 100% requests a staged rollout that you can raise, pause and resume. If the store applies a different percentage, you are warned after submitting.':
    'ערך מתחת ל-100% מבקש השקה מדורגת שאפשר להגדיל, להשהות ולחדש. אם החנות תחיל אחוז אחר, תוצג לכם אזהרה אחרי ההגשה.',
  'Bug fixes and performance improvements.': 'תיקוני באגים ושיפורי ביצועים.',
  'Builds for this release': 'Builds של השחרור',
  'Builds started for this release. Submit a ready build to a store from here.':
    'Builds שהופעלו עבור השחרור. אפשר להגיש מכאן Build מוכן לחנות.',
  'Cancel submission': 'ביטול ההגשה',
  'Change requested': 'השינוי התבקש',
  'Choose the percentage of Google Play users who receive this version. Use 100% to finish the staged rollout.':
    'בחרו את אחוז המשתמשים ב-Google Play שיקבלו את הגרסה. הזינו 100% כדי להשלים את ההשקה המדורגת.',
  'Could not load this release': 'לא ניתן לטעון את השחרור',
  'Create a release for the next version of this app, then submit its builds to the App Store and Google Play.':
    'צרו שחרור לגרסה הבאה של האפליקציה, ואז הגישו את ה-Builds שלו ל-App Store ול-Google Play.',
  'Create release': 'יצירת שחרור',
  'Delete release': 'מחיקת שחרור',
  'Delete release {version}? Its submission history is deleted with it. Versions already in the stores stay there. This action cannot be undone.':
    'למחוק את השחרור {version}? היסטוריית ההגשות שלו תימחק יחד איתו. גרסאות שכבר נמצאות בחנויות יישארו שם. לא ניתן לבטל פעולה זו.',
  'Each locale can have only one note.':
    'לכל Locale יכולה להיות הערה אחת בלבד.',
  'Each store target gets its own submissions. The status follows the store as it processes, reviews and releases the version.':
    'לכל יעד חנות יש הגשות משלו. הסטטוס מתעדכן לפי החנות בזמן שהיא מעבדת, בודקת ומשחררת את הגרסה.',
  'Enter a locale for every note.': 'הזינו Locale לכל הערה.',
  'Enter a whole number from 1 to 100.': 'הזינו מספר שלם בין 1 ל-100.',
  'Failed to create release': 'יצירת השחרור נכשלה',
  'Failed to create submission': 'יצירת ההגשה נכשלה',
  'Failed to delete release': 'מחיקת השחרור נכשלה',
  'Failed to load releases': 'טעינת השחרורים נכשלה',
  'Failed to update release': 'עדכון השחרור נכשל',
  'Failed to update the rollout': 'עדכון ההשקה נכשל',
  'Failed to update the submission': 'עדכון ההגשה נכשל',
  'Loading artifacts...': 'טוען ארטיפקטים...',
  Located: 'אותר',
  'No builds for this release yet.': 'אין עדיין Builds לשחרור הזה.',
  'No release notes.': 'אין הערות שחרור.',
  'No releases yet': 'אין עדיין שחרורים',
  'No submissions for this target yet.': 'אין עדיין הגשות ליעד הזה.',
  Notes: 'הערות',
  'Pause rollout': 'השהיית ההשקה',
  Processed: 'עבר עיבוד',
  'Published on': 'תאריך פרסום',
  'Release automatically': 'שחרור אוטומטי',
  'Release created': 'השחרור נוצר',
  'Release deleted': 'השחרור נמחק',
  'Release mode': 'אופן השחרור',
  'Release not found': 'השחרור לא נמצא',
  'Release notes': 'הערות שחרור',
  'Release the version as soon as Apple approves it. Turn this off to release it yourself from this page.':
    'הגרסה תשוחרר ברגע ש-Apple תאשר אותה. כבו את האפשרות כדי לשחרר אותה בעצמכם מהדף הזה.',
  'Release to the store': 'שחרור לחנות',
  'Release updated': 'השחרור עודכן',
  Released: 'שוחרר',
  'Remove notes': 'הסרת ההערות',
  'Resume rollout': 'חידוש ההשקה',
  'Reviewed on': 'תאריך הבדיקה',
  Rollout: 'השקה',
  'Rollout percentage': 'אחוז ההשקה',
  'Rollout updated': 'ההשקה עודכנה',
  'Search releases': 'חיפוש שחרורים',
  'Search releases...': 'חיפוש שחרורים...',
  Settled: 'הושלם',
  'Store releases cannot be rolled back. To stop a release, pause a Google Play staged rollout or an App Store phased release, or submit a previous artifact with a higher version code as a new submission.':
    'אי אפשר להחזיר לאחור שחרור בחנות. כדי לעצור שחרור, השהו השקה מדורגת ב-Google Play או שחרור הדרגתי ב-App Store, או הגישו ארטיפקט קודם עם קוד גרסה גבוה יותר כהגשה חדשה.',
  'Store status': 'סטטוס בחנות',
  'Submission created': 'ההגשה נוצרה',
  Submissions: 'הגשות',
  'Submissions already sent to a store keep the version and notes they were sent with.':
    'הגשות שכבר נשלחו לחנות שומרות על הגרסה ועל ההערות שאיתן נשלחו.',
  'Submit build': 'הגשת ה-Build',
  'Submit release': 'הגשת השחרור',
  'Submit to Google Play': 'הגשה ל-Google Play',
  'Submit to the App Store': 'הגשה ל-App Store',
  'Submitted on': 'תאריך ההגשה',
  'The store continues offering this version from the current rollout percentage.':
    'החנות תמשיך להציע את הגרסה מאחוז ההשקה הנוכחי.',
  'The store makes this approved version available to users. A released version cannot be withdrawn.':
    'החנות תהפוך את הגרסה המאושרת לזמינה למשתמשים. אי אפשר למשוך גרסה ששוחררה.',
  'The store stops offering this version to more users. Users who already updated keep it. You can resume the rollout later.':
    'החנות תפסיק להציע את הגרסה למשתמשים נוספים. משתמשים שכבר עדכנו יישארו איתה. אפשר לחדש את ההשקה מאוחר יותר.',
  'The version can be at most 64 characters.': 'הגרסה יכולה להכיל עד 64 תווים.',
  'The version users see in the stores, for example 1.2.0.':
    'הגרסה שמשתמשים רואים בחנויות, לדוגמה 1.2.0.',
  'This release does not exist or has been deleted.':
    'השחרור הזה אינו קיים או שנמחק.',
  'This target has no ready artifact. Build the release or upload a binary first.':
    'אין ליעד הזה ארטיפקט מוכן. בנו את השחרור או העלו קובץ בינארי קודם.',
  'Update release': 'עדכון שחרור',
  'Update rollout': 'עדכון ההשקה',
  Validated: 'אומת',
  'What changed in this version, per store locale. The stores show these notes to users.':
    'מה השתנה בגרסה, לפי Locale של החנות. החנויות מציגות את ההערות האלה למשתמשים.',
  "You don't have permission to create releases.":
    'אין לכם הרשאה ליצור שחרורים.',
  "You don't have permission to manage submissions.":
    'אין לכם הרשאה לנהל הגשות.',
  "You don't have permission to update releases.":
    'אין לכם הרשאה לעדכן שחרורים.',
  "You don't have permission to view submissions. Ask an organization owner for access.":
    'אין לכם הרשאה לצפות בהגשות. בקשו גישה מבעלי הארגון.',
  'Select an artifact': 'בחירת ארטיפקט',
  'The selected artifact is not ready for this target. Choose another artifact or wait for it to finish processing.':
    'הארטיפקט שנבחר אינו מוכן ליעד הזה. בחרו ארטיפקט אחר או המתינו לסיום העיבוד שלו.',
  'The store applied a {percent}% rollout instead of the requested {requested}%.':
    'החנות החילה השקה של {percent}% במקום {requested}% שהתבקשו.',
  "This build's artifact is not available for submission.":
    'הארטיפקט של ה-Build הזה אינו זמין להגשה.',
  "This build's artifact is still processing.":
    'הארטיפקט של ה-Build הזה עדיין בעיבוד.',
  "This build's target no longer exists.": 'היעד של ה-Build הזה כבר לא קיים.',
  // Signing
  "You don't have permission to manage signing identities.":
    'אין לכם הרשאה לנהל זהויות חתימה.',
  'Import iOS certificate': 'ייבוא תעודת iOS',
  'Import Android keystore': 'ייבוא keystore של Android',
  'Generate with Appwrite': 'יצירה עם Appwrite',
  'Add signing identity': 'הוספת זהות חתימה',
  'Signing identity deleted': 'זהות החתימה נמחקה',
  'An app target still uses this signing identity. Choose another one for the target, then try again.':
    'יעד של אפליקציה עדיין משתמש בזהות החתימה הזו. בחרו זהות אחרת עבור היעד ונסו שוב.',
  'Failed to delete signing identity': 'מחיקת זהות החתימה נכשלה',
  'No signing identities': 'אין זהויות חתימה',
  'Import your certificates and keystores, or let Appwrite generate and renew them for you.':
    'ייבאו את התעודות ואת קובצי ה-keystore שלכם, או תנו ל-Appwrite ליצור ולחדש אותם עבורכם.',
  Identifiers: 'מזהים',
  'Copy fingerprint': 'העתקת טביעת אצבע',
  'signing identities': 'זהויות חתימה',
  'Delete signing identity': 'מחיקת זהות חתימה',
  Imported: 'מיובא',
  Managed: 'מנוהל',
  'Generate signing identity': 'יצירת זהות חתימה',
  'Appwrite creates the signing material, keeps it encrypted, and renews what it can before it expires.':
    'Appwrite יוצר את חומרי החתימה, שומר אותם מוצפנים ומחדש את מה שאפשר לפני שתוקפו יפוג.',
  'Choose an App Store Connect connection.': 'בחרו חיבור App Store Connect.',
  'Add at least one bundle ID.': 'הוסיפו לפחות Bundle ID אחד.',
  'Signing identity generated': 'זהות החתימה נוצרה',
  'Appwrite creates an Apple Distribution certificate through your App Store Connect connection, then an App Store provisioning profile for each bundle ID. This needs a team key with the Admin role. Appwrite keeps one managed certificate per Apple team.':
    'Appwrite יוצר תעודת Apple Distribution דרך חיבור ה-App Store Connect שלכם, ולאחר מכן פרופיל provisioning של App Store לכל Bundle ID. לשם כך נדרש מפתח צוות עם התפקיד Admin. Appwrite מחזיק תעודה מנוהלת אחת לכל צוות Apple.',
  'Appwrite generates an RSA 2048 upload key with a certificate valid for 30 years. For a new app, the first bundle signed with it becomes the upload key in Google Play. For an existing app, download upload-certificate.pem afterwards and request an upload key reset in Play Console.':
    'Appwrite יוצר מפתח העלאה מסוג RSA 2048 עם תעודה בתוקף ל-30 שנה. באפליקציה חדשה, החבילה הראשונה שנחתמת בו קובעת אותו כמפתח ההעלאה ב-Google Play. באפליקציה קיימת, הורידו אחר כך את upload-certificate.pem ובקשו איפוס של מפתח ההעלאה ב-Play Console.',
  'Managed iOS signing needs an App Store Connect connection. Connect one first.':
    'חתימת iOS מנוהלת דורשת חיבור App Store Connect. חברו אחד קודם.',
  'Go to connections': 'מעבר לחיבורים',
  'App Store Connect connection': 'חיבור App Store Connect',
  'Choose a connection': 'בחירת חיבור',
  'Press Enter after each bundle ID. Include the IDs of app extensions.':
    'הקישו Enter אחרי כל Bundle ID. כללו גם את המזהים של הרחבות האפליקציה.',
  'The signing identity was not generated.': 'זהות החתימה לא נוצרה.',
  'Download signing files': 'הורדת קובצי חתימה',
  'Anyone with these files and passwords can sign apps as you. Store them somewhere safe, such as a password manager.':
    'כל מי שמחזיק בקבצים ובסיסמאות האלה יכול לחתום על אפליקציות בשמכם. שמרו אותם במקום בטוח, כמו מנהל סיסמאות.',
  'The signing files could not be loaded.':
    'לא ניתן היה לטעון את קובצי החתימה.',
  'Passwords are shown only in this dialog. Copy them before you close it.':
    'הסיסמאות מוצגות רק בחלון הזה. העתיקו אותן לפני שתסגרו אותו.',
  'Resetting the upload key of an existing app?':
    'מאפסים את מפתח ההעלאה של אפליקציה קיימת?',
  'In Play Console, open Protected with Play → Manage Play app signing → Request upload key reset, and upload upload-certificate.pem. Google holds the app signing key, so users keep receiving updates.':
    'ב-Play Console פתחו את Protected with Play → Manage Play app signing → Request upload key reset, והעלו את upload-certificate.pem. מפתח החתימה של האפליקציה נשמר אצל Google, כך שהמשתמשים ימשיכו לקבל עדכונים.',
  'Certificate password': 'סיסמת התעודה',
  'Keystore password': 'סיסמת ה-keystore',
  'Key password': 'סיסמת המפתח',
  'Key alias': 'כינוי המפתח (alias)',
  'Upload the keystore that holds your upload key, so Appwrite signs bundles the way Google Play expects. Files and passwords are encrypted before they are stored.':
    'העלו את ה-keystore שמכיל את מפתח ההעלאה שלכם, כדי ש-Appwrite יחתום על חבילות כפי ש-Google Play מצפה. הקבצים והסיסמאות מוצפנים לפני שהם נשמרים.',
  'Add the keystore file.': 'הוסיפו את קובץ ה-keystore.',
  'Enter the keystore password.': 'הזינו את סיסמת ה-keystore.',
  'Enter the key alias.': 'הזינו את כינוי המפתח.',
  'Signing identity imported': 'זהות החתימה יובאה',
  'Upload key': 'מפתח העלאה',
  Keystore: 'Keystore',
  'A PKCS#12 keystore is checked right away. A JKS keystore is checked by its first signing job.':
    'keystore מסוג PKCS#12 נבדק מיד. keystore מסוג JKS נבדק במשימת החתימה הראשונה שלו.',
  'Leave empty to use the keystore password. A PKCS#12 keystore always uses the keystore password.':
    'השאירו ריק כדי להשתמש בסיסמת ה-keystore. keystore מסוג PKCS#12 תמיד משתמש בסיסמת ה-keystore.',
  'The signing identity was not imported.': 'זהות החתימה לא יובאה.',
  'Import iOS signing identity': 'ייבוא זהות חתימה של iOS',
  'Upload an Apple Distribution certificate with its private key and the App Store provisioning profiles that include it. Files are encrypted before they are stored.':
    'העלו תעודת Apple Distribution עם המפתח הפרטי שלה ואת פרופילי ה-provisioning של App Store שכוללים אותה. הקבצים מוצפנים לפני שהם נשמרים.',
  'Add the .p12 certificate.': 'הוסיפו את תעודת ה-.p12.',
  'Add at least one App Store provisioning profile.':
    'הוסיפו לפחות פרופיל provisioning אחד של App Store.',
  'Certificate (.p12)': 'תעודה (.p12)',
  'In Keychain Access, export the Apple Distribution certificate together with its private key.':
    'ב-Keychain Access ייצאו את תעודת Apple Distribution יחד עם המפתח הפרטי שלה.',
  'Leave empty if the .p12 file has no password.':
    'השאירו ריק אם לקובץ ה-.p12 אין סיסמה.',
  'Provisioning profiles (.mobileprovision)':
    'פרופילי provisioning (.mobileprovision)',
  'Add one App Store profile for each bundle ID, including app extensions. Each profile must contain this certificate.':
    'הוסיפו פרופיל App Store אחד לכל Bundle ID, כולל הרחבות אפליקציה. כל פרופיל חייב לכלול את התעודה הזו.',
  // Overview
  'Checking…': 'בודקים…',
  'Run checks again': 'הרצת הבדיקות שוב',
  'Could not run the checks': 'לא ניתן היה להריץ את הבדיקות',
  'Ready to submit': 'מוכן להגשה',
  'Not ready to submit': 'לא מוכן להגשה',
  'Resolve the blockers below before submitting.':
    'פתרו את החוסמים שלמטה לפני ההגשה.',
  'Latest submission': 'הגשה אחרונה',
  'No submissions yet': 'אין עדיין הגשות',
  'View build': 'צפייה ב-Build',
  'View release': 'צפייה בשחרור',
  'Add an iOS or Android target to check whether this app is ready to submit to the stores.':
    'הוסיפו יעד iOS או Android כדי לבדוק אם האפליקציה מוכנה להגשה לחנויות.',
  'Go to targets': 'מעבר ליעדים',
  'Store tasks': 'משימות בחנויות',
  'Appwrite cannot do these for you. Complete them in each store before the first submission.':
    'Appwrite לא יכול לבצע את המשימות האלה בשבילכם. השלימו אותן בכל חנות לפני ההגשה הראשונה.',
  'Create the app record in App Store Connect with the bundle ID of the target.':
    'צרו את רשומת האפליקציה ב-App Store Connect עם ה-Bundle ID של היעד.',
  'Accept the latest agreements in App Store Connect.':
    'אשרו את ההסכמים העדכניים ב-App Store Connect.',
  'Complete the app privacy labels.': 'מלאו את תוויות הפרטיות של האפליקציה.',
  'Answer the export compliance questions.':
    'ענו על השאלות בנושא תאימות לתקנות ייצוא.',
  'Create the app in Play Console with the package name of the target.':
    'צרו את האפליקציה ב-Play Console עם שם החבילה של היעד.',
  'Accept the latest agreements in Play Console.':
    'אשרו את ההסכמים העדכניים ב-Play Console.',
  'Complete the data safety form.': 'מלאו את טופס בטיחות הנתונים.',
  'Upload the first build in Play Console yourself. Google Play accepts uploads from Appwrite after that.':
    'העלו בעצמכם את ה-Build הראשון ב-Play Console. אחרי זה Google Play מקבל העלאות מ-Appwrite.',
  'New personal developer accounts must run a closed test before releasing to production.':
    'בחשבונות מפתח אישיים חדשים צריך להריץ בדיקה סגורה לפני שחרור לפרודקשן.',
  // Review fixes: signing download
  'Show signing files': 'הצגת קובצי החתימה',
  'Every download is recorded in the audit log.':
    'כל הורדה נרשמת ביומן הביקורת.',
  'The file could not be saved.': 'לא ניתן היה לשמור את הקובץ.',
  // Review fixes: apps and settings
  'Could not load this app': 'לא ניתן לטעון את האפליקציה הזו',
  'App created, but the repository was not linked.':
    'האפליקציה נוצרה, אבל המאגר לא קושר.',
  'Connect it from the app settings.': 'חברו אותו מהגדרות האפליקציה.',
  'The server did not save these changes.': 'השרת לא שמר את השינויים האלה.',
  // Review fixes: credentials
  'Choose a file of type {extensions}.': 'בחרו קובץ מסוג {extensions}.',
  'Delete {name}? Appwrite stops using its credentials immediately. This action cannot be undone.':
    'למחוק את {name}? Appwrite יפסיק מיד להשתמש בפרטי הגישה של החיבור. לא ניתן לבטל פעולה זו.',
  'Delete {name}? Appwrite deletes its encrypted files. Download them first if you still need them. This action cannot be undone.':
    'למחוק את {name}? Appwrite ימחק את הקבצים המוצפנים שלה. אם אתם עדיין צריכים אותם, הורידו אותם קודם. לא ניתן לבטל פעולה זו.',
  'Failed to load signing identities': 'טעינת זהויות החתימה נכשלה',
  // Publish
  Publish: 'פרסום',
  'Publish app': 'פרסום האפליקציה',
  "You don't have permission to publish this app.":
    'אין לכם הרשאה לפרסם את האפליקציה הזו.',
  'Add a target to publish this app.': 'הוסיפו יעד כדי לפרסם את האפליקציה הזו.',
  'Choose the targets to publish and check that each one is ready for its store.':
    'בחרו את היעדים לפרסום ובדקו שכל אחד מהם מוכן לחנות שלו.',
  'Choose the release that the builds and submissions belong to.':
    'בחרו את השחרור שאליו שייכים ה-Builds וההגשות.',
  'Choose what to submit for each target.': 'בחרו מה להגיש לכל יעד.',
  'Review what happens for each target, then publish.':
    'בדקו מה יקרה בכל יעד, ואז פרסמו.',
  'Release automatically after approval': 'שחרור אוטומטי אחרי האישור',
  'This target has no store connection.': 'ליעד הזה אין חיבור לחנות.',
  'Run checks': 'הרצת בדיקות',
  'Running checks...': 'מריץ בדיקות...',
  'Select at least one target to publish.': 'בחרו לפחות יעד אחד לפרסום.',
  'Run the checks to continue.': 'הריצו את הבדיקות כדי להמשיך.',
  'Some checks could not run. Run them again to continue.':
    'חלק מהבדיקות לא רצו. הריצו אותן שוב כדי להמשיך.',
  'Resolve the blockers or deselect those targets to continue.':
    'פתרו את החוסמים או בטלו את הבחירה ביעדים האלה כדי להמשיך.',
  'Failed to run the checks': 'הרצת הבדיקות נכשלה',
  'Create a release': 'יצירת שחרור',
  'The version people see in the stores, for example 1.4.0.':
    'הגרסה שמשתמשים רואים בחנויות, למשל 1.4.0.',
  'What is new in this release': 'מה חדש בשחרור הזה',
  'Add to an open release': 'הוספה לשחרור פתוח',
  'This app has no draft or active releases.':
    'לאפליקציה הזו אין שחרורים בטיוטה או פעילים.',
  'Choose a release': 'בחרו שחרור',
  'Build from {branch}': 'Build מ-{branch}',
  'Build from Git': 'Build מ-Git',
  'Connect a repository in the app settings to build from Git.':
    'חברו מאגר בהגדרות האפליקציה כדי לבצע Build מ-Git.',
  'Use an artifact': 'שימוש בארטיפקט',
  'Appwrite builds the app for this release. Submit the build from the release page when it is ready.':
    'Appwrite יבצע Build לאפליקציה עבור השחרור הזה. הגישו את ה-Build מדף השחרור כשהוא מוכן.',
  'This target sets version codes manually. Use a number higher than the last upload.':
    'היעד הזה מגדיר קודי גרסה ידנית. השתמשו במספר גבוה מההעלאה האחרונה.',
  'No ready artifacts for this target. Upload one from the Artifacts tab.':
    'אין ליעד הזה ארטיפקטים מוכנים. העלו אחד מהלשונית ארטיפקטים.',
  'Choose an artifact': 'בחרו ארטיפקט',
  'After approval': 'אחרי האישור',
  'Wait for a manual release': 'המתנה לשחרור ידני',
  'Submit this build from the release page when it is ready.':
    'הגישו את ה-Build הזה מדף השחרור כשהוא מוכן.',
  'Starting build': 'מתחיל Build',
  Submitting: 'מגיש',
  Submitted: 'הוגש',
  'Failed to create the release': 'יצירת השחרור נכשלה',
  'Failed to publish this target': 'פרסום היעד נכשל',
  'Publishing started': 'הפרסום התחיל',
  // Review fixes: publish
  Artifact: 'ארטיפקט',
  'The checks use the track shown for each target. Choose the track for an artifact in the Source step.':
    'הבדיקות משתמשות במסלול שמוצג לכל יעד. בחרו את המסלול לארטיפקט בשלב מקור.',
  'The checks changed since you ran them. Review the targets to publish.':
    'תוצאות הבדיקות השתנו מאז שהרצתם אותן. בדקו את היעדים כדי לפרסם.',
  'Could not load the targets of this app.':
    'לא ניתן היה לטעון את היעדים של האפליקציה הזו.',
  'Set a production branch in the app settings to build from Git.':
    'הגדירו Branch פרודקשן בהגדרות האפליקציה כדי לבצע Build מ-Git.',
}
