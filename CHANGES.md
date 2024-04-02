# Version 1.5.4
## What's Changed
### Fixes

* Fix function build command by @abnegate in https://github.com/appwrite/appwrite/pull/7813

# Version 1.5.3
## What's Changed
### Fixes

* Fix Attribute not found when migrating users collection in [#7782](https://github.com/appwrite/appwrite/pull/7782)
* Fix git deployments in [#7780](https://github.com/appwrite/appwrite/pull/7780)
* Allow wildcards for url validation like OAuth2 success in [#7791](https://github.com/appwrite/appwrite/pull/7791)

# Version 1.5.2
## What's Changed
* Fix stats migration by @abnegate in https://github.com/appwrite/appwrite/pull/7760
* Fix index migrations by @abnegate in https://github.com/appwrite/appwrite/pull/7769
* Fix Flutter/Dart SDKs by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7765
* Fix push notifications with no image by @abnegate in https://github.com/appwrite/appwrite/pull/7771
* Fix Python SDK by @abnegate in https://github.com/appwrite/appwrite/pull/7770
* Fix Android SDK deployment by @abnegate in https://github.com/appwrite/appwrite/pull/7770


**Full Changelog**: https://github.com/appwrite/appwrite/compare/1.5.1...1.5.2

# Version 1.5.1
## What's Changed
* fix: usage containers by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7757

**Full Changelog**: https://github.com/appwrite/appwrite/compare/1.5.0...1.5.

# Version 1.5.0
## What's Changed
### New features
- SSR support added. You can now handle sessions on your server app. [Learn more in docs](https://appwrite.io/docs/products/auth/server-side-rendering)
- 2FA support is now added for Appwrite Auth and for Console users. [Learn about adding 2FA to your app](https://appwrite.io/docs/products/auth/2fa) [Learn about 2FA on Console](https://appwrite.io/docs/advanced/security/2fa)
- Appwrite Messaging added. You can now send emails, SMS messages, and push notifications. [Learn more in docs](https://appwrite.io/docs/products/messaging)
- Appwrite now has enums for all config strings for OAuth, messaging adaptors, and more. [Learn more in the docs](https://appwrite.io/docs/sdks)
- New runtime versions for Dart, Bun, Ruby, Node, Deno, Python, PHP, Kotlin, Java, and Swift. [Learn more in docs](https://appwrite.io/docs/products/functions/runtimes)
- Create custom login flows with custom sessions and tokens. [Learn more in docs](https://appwrite.io/docs/products/auth/custom-token)

### Upgrading
- Appwrite Cloud is not yet updated to 1.5.x, expect an announcement in the upcoming weeks. If you lock your Appwrite SDK version, this update is not breaking.
- Follow the [self-hosted docs](https://appwrite.io/docs/advanced/self-hosting/update) to update your self-hosted Appwrite.
- Update your SDKs to the latest versions. The API is backwards compatible, using old SDKs will not break existing apps, but you will not have access to new features.
### Full changes
* Sync 1.5.x by @abnegate in https://github.com/appwrite/appwrite/pull/6030
* Sync master into 1.5.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/6092
* add collections to config file and messaging scopes to config file by @fanatic75 in https://github.com/appwrite/appwrite/pull/5930
* Sync 1.4.x to 1.5.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/6233
* Feat add messaging response models by @fanatic75 in https://github.com/appwrite/appwrite/pull/5951
* Feat messages event config by @fanatic75 in https://github.com/appwrite/appwrite/pull/5986
* Sync main 1.5.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/6514
* Sync 1.5.x with 1.4.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/6875
* Feat provider controllers by @fanatic75 in https://github.com/appwrite/appwrite/pull/6023
* Feat topics controller by @fanatic75 in https://github.com/appwrite/appwrite/pull/6032
* Feat sms push controllers by @fanatic75 in https://github.com/appwrite/appwrite/pull/6950
* Sync 1.4.x to 1.5.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/6965
* Providers from attribute by @fanatic75 in https://github.com/appwrite/appwrite/pull/6991
* made review changes by @fanatic75 in https://github.com/appwrite/appwrite/pull/7010
* removes provider from topics by @fanatic75 in https://github.com/appwrite/appwrite/pull/7014
* review changes by @fanatic75 in https://github.com/appwrite/appwrite/pull/7048
* Event label messaging by @fanatic75 in https://github.com/appwrite/appwrite/pull/7058
* Feat logs messaging api by @fanatic75 in https://github.com/appwrite/appwrite/pull/7069
* Chore sync main 1.5.x by @fanatic75 in https://github.com/appwrite/appwrite/pull/7115
* Chore sync main by @abnegate in https://github.com/appwrite/appwrite/pull/7120
* Add XDebug by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7082
* More review changes by @fanatic75 in https://github.com/appwrite/appwrite/pull/7129
* Feat target provider type by @fanatic75 in https://github.com/appwrite/appwrite/pull/7132
* removes internal provider by @fanatic75 in https://github.com/appwrite/appwrite/pull/7151
* Feat account target by @fanatic75 in https://github.com/appwrite/appwrite/pull/7152
* 1.4.x by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7149
* adds user name in subscriber response model by @fanatic75 in https://github.com/appwrite/appwrite/pull/7177
* feat: add migration stats task by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7187
* migrates enum attribute size to 255 by @fanatic75 in https://github.com/appwrite/appwrite/pull/7183
* changes TextMagic to Textmagic in all places and uses email validator by @fanatic75 in https://github.com/appwrite/appwrite/pull/7188
* chore: upgrade console to 3.2.9 by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7189
* makes provider creation fields optional and adds target info in subsc… by @fanatic75 in https://github.com/appwrite/appwrite/pull/7195
* Chore update sdks by @abnegate in https://github.com/appwrite/appwrite/pull/7180
* adds target when creating user via server endpoint by @fanatic75 in https://github.com/appwrite/appwrite/pull/7217
* Feat add message provider type by @abnegate in https://github.com/appwrite/appwrite/pull/7219
* feat: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7225
* Implement Job based hamster by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7216
* bump: console version by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7238
* chore: update console version by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7240
* misc changes by @fanatic75 in https://github.com/appwrite/appwrite/pull/7227
* Update appwrite base image by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7241
* Revert "Update appwrite base image" by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7256
* feat: upgrade console to 3.2.15 by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7258
* mail support string as attachment by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7261
* fix redis issue by encoding content by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7262
* Fix cookie issue by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7260
* support mail template override by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7251
* feat: project usage custom date range by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7266
* feat: usage breakdown by project by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7270
* provide retention time as queue server resource by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7272
* fix: remove expired cookie by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7275
* PEA-15 Refactor Deletes and maintenance worker by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7273
* Refactor usage execution trigger by @shimonewman in https://github.com/appwrite/appwrite/pull/7274
* Fix max array size 1 by @abnegate in https://github.com/appwrite/appwrite/pull/7287
* chore: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7291
* Fix SMS import by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7293
* rename stats collection by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7301
* fix deletes worker by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7310
* Sync main with 1.4.x by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7312
* combining network inbound by @shimonewman in https://github.com/appwrite/appwrite/pull/7298
* chore: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7313
* Update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7314
* chore: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7315
* chore: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7316
* chore: update console by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7319
* chore: update hamster script by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7320
* Refactor usage worker sn by @shimonewman in https://github.com/appwrite/appwrite/pull/7326
* usageHook tweaks by @shimonewman in https://github.com/appwrite/appwrite/pull/7330
* Refactor inf metric calc by @shimonewman in https://github.com/appwrite/appwrite/pull/7339
* Fix user last activity not updating by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7292
* Fix user identity attaching to wrong user by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7280
* fix for file extension not supported by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7349
* Bump console to version 3.3.14 by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7358
* Bump console to version 3.3.15 by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7359
* Update tr.json by @fanksin in https://github.com/appwrite/appwrite/pull/7276
* Improve large file handling by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7351
* PEA-38 compression constants by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7366
* Fix permission issue with chunk upload by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7328
* 1.4.x by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7317
* Sync with main by @shimonewman in https://github.com/appwrite/appwrite/pull/7374
* Feat: Max password length by @Meldiron in https://github.com/appwrite/appwrite/pull/7376
* feat: console hostname env variable by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7360
* Sync main by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7384
* Add `_APP_CONSOLE_HOSTNAMES` env var to allow more hostnames to the console project by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7377
* Fix app console hostnames check by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7385
* Add General E2E tests to CI pipeline by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7386
* Fix app console hostnames check on refactor usage sn by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7387
* executor: pass build timeout to runtimes by @iMacHumphries in https://github.com/appwrite/appwrite/pull/7350
* Create an enum for Message status by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7380
* [Feat]: Zoho OAuth Provider by @UtkarshAhuja2003 in https://github.com/appwrite/appwrite/pull/7365
* Messaging uniform logic fixes by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7397
* Webhook attempts PR suggestions by @Meldiron in https://github.com/appwrite/appwrite/pull/7402
* fix: escape html in email params by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7409
* Add a flag to install and upgrade commands to not start Appwrite by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7271
* Add support for querying topic total by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7388
* Adds uniform error logic for messaging worker and extra params for email  by @fanatic75 in https://github.com/appwrite/appwrite/pull/7245
* Update the delete identity endpoints to set the params and payload by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7348
* Fix utopia-php/framework version by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7410
* feat: account delete by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7415
* chore: update collection name in hamster by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7417
* Feat: Magic URL improvements by @Meldiron in https://github.com/appwrite/appwrite/pull/7416
* feat: update assistant by @loks0n in https://github.com/appwrite/appwrite/pull/7421
* Feat: Improve worker logging by @Meldiron in https://github.com/appwrite/appwrite/pull/7192
* Added security phrase to magic URL by @Meldiron in https://github.com/appwrite/appwrite/pull/7424
* fix: hotfix for redirect param in custom templates by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7437
* Make OTP template more contextual by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7434
* Fix: Remove passwordAgain by @Meldiron in https://github.com/appwrite/appwrite/pull/7441
* Rename usage_network_infinity to usage_bandwidth_infinity by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7443
* Added cloud base templates to appwrite/appwrite by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7440
* Remove the endpoint param for APNS providers by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7418
* Default topic description to an empty string instead of null by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7430
* feat: SSR by @loks0n in https://github.com/appwrite/appwrite/pull/5777
* Add support for draft messages by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7429
* Add search param for list subscribers endpoint by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7382
* Delete subscribers and update topic totals when deleting target by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7396
* Fix list messages allowed queries by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7381
* Allow filtering targets by provider type by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7419
* Feat message scheduling by @abnegate in https://github.com/appwrite/appwrite/pull/7431
* Fix create/update push target routes by @abnegate in https://github.com/appwrite/appwrite/pull/7461
* Fix cc + bcc targets fetched by identifier instead of $id by @abnegate in https://github.com/appwrite/appwrite/pull/7468
* Add endpoint to list a message's targets by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7463
* Limit webhook failure attempts to 10 by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7128
* Follow existing style of code by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7470
* Refactored url construction by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7478
* hamster additions by @shimonewman in https://github.com/appwrite/appwrite/pull/7462
* Feat: session renewal by @Meldiron in https://github.com/appwrite/appwrite/pull/7452
* Feat: Email OTP by @Meldiron in https://github.com/appwrite/appwrite/pull/7422
* feat: delete account by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7392
* Fix: Email image endpoints by @Meldiron in https://github.com/appwrite/appwrite/pull/7474
* Feat smtp test endpoint by @eldadfux in https://github.com/appwrite/appwrite/pull/7307
* Update ruby by @abnegate in https://github.com/appwrite/appwrite/pull/7464
* Refactor usage by @shimonewman in https://github.com/appwrite/appwrite/pull/7005
* Fix: Certificate email style by @Meldiron in https://github.com/appwrite/appwrite/pull/7475
* sync: 1.5.x with main by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7486
* 1.5.x <- main by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7485
* Refactor Usage Stats by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7311
* Feat maintenance delete expired targets by @abnegate in https://github.com/appwrite/appwrite/pull/7460
* Trigger deletes worker when target is deleted by @abnegate in https://github.com/appwrite/appwrite/pull/7490
* Throw on enable if provider credentials are missing instead of ignoring by @abnegate in https://github.com/appwrite/appwrite/pull/7484
* Add Queue Retry Command to Appwrite by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7391
* Add failed queues endpoint to health by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7466
* Feat: CNAME validation logs by @Meldiron in https://github.com/appwrite/appwrite/pull/7482
* Add queue management commands by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7497
* Add threshold to queue failed health endpoint by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7499
* fix: use atomic operations for count updates by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7511
* chore: add logs by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7513
* chore: add logs by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7514
* chore: add auth label to phone endpoint by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7515
* JSON + OR query support by @fogelito in https://github.com/appwrite/appwrite/pull/7252
* Labels limit by @fogelito in https://github.com/appwrite/appwrite/pull/7504
* chore: update rate limits by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7523
* Add message delete route by @abnegate in https://github.com/appwrite/appwrite/pull/7510
* Feat remove description by @abnegate in https://github.com/appwrite/appwrite/pull/7518
* Upgrade to PHP 8.2 by @eldadfux in https://github.com/appwrite/appwrite/pull/7067
* Add SMTP provider by @abnegate in https://github.com/appwrite/appwrite/pull/7525
* remove debug leftovers by @shimonewman in https://github.com/appwrite/appwrite/pull/7531
* feat: ssr changes by @loks0n in https://github.com/appwrite/appwrite/pull/7532
* Add webhook max failed attempts as env variable by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7489
* Feat: Rename security phrases by @Meldiron in https://github.com/appwrite/appwrite/pull/7533
* Update to standard namespacing for enums by @abnegate in https://github.com/appwrite/appwrite/pull/7537
* Remove redundant usage labels by @abnegate in https://github.com/appwrite/appwrite/pull/7536
* Update containers by @abnegate in https://github.com/appwrite/appwrite/pull/7526
* feat: mfa by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7346
* Fix: client error reporting by @Meldiron in https://github.com/appwrite/appwrite/pull/7539
* Feat session target delete by @abnegate in https://github.com/appwrite/appwrite/pull/7540
* Fix spec generation by @abnegate in https://github.com/appwrite/appwrite/pull/7538
* Feat block countries from certain regions by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7487
* Fix: Empty values in PATCH of users by @Meldiron in https://github.com/appwrite/appwrite/pull/7471
* Add a 20 second delay to maintenance worker by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7544
* Add health certificate validity endpoint by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7547
* Add count for messages(sms) metric by @shimonewman in https://github.com/appwrite/appwrite/pull/7520
* Fix user API mfa route auth by @abnegate in https://github.com/appwrite/appwrite/pull/7549
* Fix graphql tests by @abnegate in https://github.com/appwrite/appwrite/pull/7553
* Fix message status values and enums by @abnegate in https://github.com/appwrite/appwrite/pull/7552
* Update param name by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7519
* Fix SHA function enum name by @abnegate in https://github.com/appwrite/appwrite/pull/7554
* Sync main with refactor-usage-sn by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7556
* Usage queue poc by @shimonewman in https://github.com/appwrite/appwrite/pull/7503
* Update team counts by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7561
* PEA-233-prevent console user deletion before deleting their team by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7500
* PEA-233-prevent console user deletion before deleting their team by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7563
* Update place holders from [PARAM_NAME] to <PARAM_NAME> by @gewenyu99 in https://github.com/appwrite/appwrite/pull/7560
* PEA-334 - Fix spec use cloud endpoint by default by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7567
* Merge main by @abnegate in https://github.com/appwrite/appwrite/pull/7568
* Hardcode size and orders for Json Key Indexes by @fogelito in https://github.com/appwrite/appwrite/pull/7557
* Feat support label queries by @abnegate in https://github.com/appwrite/appwrite/pull/7570
* Fix missing user activity logs by @Souptik2001 in https://github.com/appwrite/appwrite/pull/7559
* Refactor usage sn by @shimonewman in https://github.com/appwrite/appwrite/pull/7576
* Catch errors parseQueries by @fogelito in https://github.com/appwrite/appwrite/pull/7558
* fix Indexes by @fogelito in https://github.com/appwrite/appwrite/pull/7572
* Fix updating message status by @abnegate in https://github.com/appwrite/appwrite/pull/7571
* Fix telesign params by @abnegate in https://github.com/appwrite/appwrite/pull/7569
* Fix email/sms template type enums getting the same values by @abnegate in https://github.com/appwrite/appwrite/pull/7551
* Replace catching \Exception with \Throwable by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7555
* cache collection attr migration by @shimonewman in https://github.com/appwrite/appwrite/pull/7575
* Remove database methods from deletes worker  by @shimonewman in https://github.com/appwrite/appwrite/pull/7135
* Fix smtp provider update by @abnegate in https://github.com/appwrite/appwrite/pull/7578
* usage logs updates by @shimonewman in https://github.com/appwrite/appwrite/pull/7588
* Add unknown error is delivered total is 0 but there were no delivery … by @abnegate in https://github.com/appwrite/appwrite/pull/7579
* Feat subscribe permission by @abnegate in https://github.com/appwrite/appwrite/pull/7580
* Remove redundant hook by @abnegate in https://github.com/appwrite/appwrite/pull/7581
* Update geodb by @abnegate in https://github.com/appwrite/appwrite/pull/7582
* Project id to logger  by @shimonewman in https://github.com/appwrite/appwrite/pull/7598
* Refactor cache by @shimonewman in https://github.com/appwrite/appwrite/pull/5616
* Feat topic totals per type by @abnegate in https://github.com/appwrite/appwrite/pull/7589
* Update docker base by @abnegate in https://github.com/appwrite/appwrite/pull/7599
* Update Response and Request filters aswell as Migrations for 1.5.x by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7457
* fix: blocked users web controllers by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7601
* dev: introduce redis insights by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7583
* Update image lib by @abnegate in https://github.com/appwrite/appwrite/pull/7566
* Update exception for github session not found by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7375
* Feat more phone validation by @loks0n in https://github.com/appwrite/appwrite/pull/7165
* fix project network usage by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7608
* usage logs updates by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7615
* usage/usage-dump queue health endpoints by @shimonewman in https://github.com/appwrite/appwrite/pull/7614
* Make self-hosted and cloud specs consistent by @abnegate in https://github.com/appwrite/appwrite/pull/7617
* Remove callback resources from workers by @abnegate in https://github.com/appwrite/appwrite/pull/7618
* Feat email attachments by @abnegate in https://github.com/appwrite/appwrite/pull/7611
* Update GETTING_STARTED.md by @GuptaPratik02 in https://github.com/appwrite/appwrite/pull/6826
* Refactor remove resource collection by @abnegate in https://github.com/appwrite/appwrite/pull/7620
* Fix duplicate subscribers by @abnegate in https://github.com/appwrite/appwrite/pull/7624
* Allow push images by @abnegate in https://github.com/appwrite/appwrite/pull/7594
* fix: msg91 by @loks0n in https://github.com/appwrite/appwrite/pull/7626
* Fix: Router CURL by @Meldiron in https://github.com/appwrite/appwrite/pull/7627
* Add storage health check by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7591
* Revert "usage/usage-dump queue health endpoints" by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7629
* Revert "Fix: Router CURL" by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7630
* Fix: Router CURL by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7632
* Use swoole process mode instead of base by @abnegate in https://github.com/appwrite/appwrite/pull/7635
* feat: ssr dx by @loks0n in https://github.com/appwrite/appwrite/pull/7619
* Remove preloading by @abnegate in https://github.com/appwrite/appwrite/pull/7637
* fix: add mfa path to console by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7639
* Fix 1.5.x Migrations by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7621
* Provider null by @fogelito in https://github.com/appwrite/appwrite/pull/7625
* Add ARM64 to docker publish by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7622
* Fix tests by @abnegate in https://github.com/appwrite/appwrite/pull/7640
* fix: email templates by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7641
* Fix content-type of file by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7643
* Fix update topics permissions by @abnegate in https://github.com/appwrite/appwrite/pull/7638
* Allow setting APNS to sandbox mode by @abnegate in https://github.com/appwrite/appwrite/pull/7645
* Fix: Functions CI/CD tests by @Meldiron in https://github.com/appwrite/appwrite/pull/7647
* Fix missing userId on update challenge by @abnegate in https://github.com/appwrite/appwrite/pull/7650
* Fix mail reset attachment by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7653
* Fix return by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7654
* Fix-function-id-2 by @eldadfux in https://github.com/appwrite/appwrite/pull/7656
* Only return allowed runtimes in runtime list route by @abnegate in https://github.com/appwrite/appwrite/pull/7659
* Add twoWayKey checks and multiple many-to-many restrictions by @fogelito in https://github.com/appwrite/appwrite/pull/7153
* chore: remove array sdk method by @loks0n in https://github.com/appwrite/appwrite/pull/7649
* Use new migration error handling by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7652
* fix: phone verification flaky by @loks0n in https://github.com/appwrite/appwrite/pull/7666
* feat: test perf by @loks0n in https://github.com/appwrite/appwrite/pull/7665
* Fix: False positive MFA by @Meldiron in https://github.com/appwrite/appwrite/pull/7664
* Fix: Spanish template translations by @DH-555 in https://github.com/appwrite/appwrite/pull/7658
* Bug 7597 - Adding default value by @navjotNSK in https://github.com/appwrite/appwrite/pull/7651
* 1.5.x api descriptions by @gewenyu99 in https://github.com/appwrite/appwrite/pull/7667
* Fix: Empty password validation by @Meldiron in https://github.com/appwrite/appwrite/pull/7662
* [Fix]: Remove internal attributes on select query by @UtkarshAhuja2003 in https://github.com/appwrite/appwrite/pull/7648
* Feat remove status param by @abnegate in https://github.com/appwrite/appwrite/pull/7670
* Sync main by @abnegate in https://github.com/appwrite/appwrite/pull/7669
* Rescheduling fixes by @abnegate in https://github.com/appwrite/appwrite/pull/7668
* Disallow creating a session if one already exists by @abnegate in https://github.com/appwrite/appwrite/pull/7616
* Fix duplication by @abnegate in https://github.com/appwrite/appwrite/pull/7679
* Feat fix 1.5.x migrations by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/7671
* Allow ssl along with tls in custom smtp by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7674
* Change status code from 503 to 403 by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7672
* Train Assistant for Appwrite 1.5 by @loks0n in https://github.com/appwrite/appwrite/pull/7686
* adding limit to queue retry by @shimonewman in https://github.com/appwrite/appwrite/pull/7692
* fix: encode secret in oauth workaround by @loks0n in https://github.com/appwrite/appwrite/pull/7695
* Update generator and deploy RC SDKs by @abnegate in https://github.com/appwrite/appwrite/pull/7545
* Update endpoint description by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7704
* Chore sync 1.4.x into main by @stnguyen90 in https://github.com/appwrite/appwrite/pull/7697
* chore: update error types for create account endpoints by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7698
* usage/usage-dump queue health endpoints by @shimonewman in https://github.com/appwrite/appwrite/pull/7661
* Refactor usage sn by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7660
* Fix: MFA flows and docs by @Meldiron in https://github.com/appwrite/appwrite/pull/7709
* update cover image for SDKs by @lohanidamodar in https://github.com/appwrite/appwrite/pull/7715
* Feat: More Recovery code endpoints by @Meldiron in https://github.com/appwrite/appwrite/pull/7713
* feat: mfa collection restructure by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7696
* Fix: SDKs enums by @Meldiron in https://github.com/appwrite/appwrite/pull/7723
* sync: main -> 1.5.x by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7705
* Sync main 1.5.x by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7727
* Updated header by @DylanG-64 in https://github.com/appwrite/appwrite/pull/7728
* chore: update exector, runtimes by @loks0n in https://github.com/appwrite/appwrite/pull/7729
* feat: pint by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7738
* feat: cascading response/request filters by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7745
* Revert 7629 revert 7614 chore usage queue health by @christyjacob4 in https://github.com/appwrite/appwrite/pull/7707
* fix: migration 1.5.x by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7737
* sync: main 1.5.x 3 by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/7747
* Allow users to disable APIs by @vermakhushboo in https://github.com/appwrite/appwrite/pull/7725
* Feat seperate image IDs by @abnegate in https://github.com/appwrite/appwrite/pull/7749
* fix: account endpoint order by @loks0n in https://github.com/appwrite/appwrite/pull/7739
* Feat image jwts by @abnegate in https://github.com/appwrite/appwrite/pull/7751

## New Contributors
* @fanksin made their first contribution in https://github.com/appwrite/appwrite/pull/7276
* @iMacHumphries made their first contribution in https://github.com/appwrite/appwrite/pull/7350
* @UtkarshAhuja2003 made their first contribution in https://github.com/appwrite/appwrite/pull/7365
* @Souptik2001 made their first contribution in https://github.com/appwrite/appwrite/pull/7559
* @GuptaPratik02 made their first contribution in https://github.com/appwrite/appwrite/pull/6826
* @navjotNSK made their first contribution in https://github.com/appwrite/appwrite/pull/7651
* @DylanG-64 made their first contribution in https://github.com/appwrite/appwrite/pull/7728

**Full Changelog**: https://github.com/appwrite/appwrite/compare/1.4.13...1.5.0

# Version 1.4.14

## Changes
- New usage metrics collection flow [#7005](https://github.com/appwrite/appwrite/pull/7005)
  - Deprecated influxdb, telegraf containers and removed all of their occurrences from the code.
  - Removed _APP_INFLUXDB_HOST, _APP_INFLUXDB_PORT, _APP_STATSD_HOST, _APP_STATSD_PORT env variables.
  - Removed usage labels dependency.
  - Dropped type attribute from stats collection.
  - Usage metrics are processed via new usage worker.
  - updated Metric names.
  
# Version 1.4.13

## Notable changes

* Change enum size validation in update controller [#7164](https://github.com/appwrite/appwrite/pull/7164)
* Bump console to version 3.2.8 in [#7167](https://github.com/appwrite/appwrite/pull/7167)

## Bug fixes

* Fix error after adding bigger enum [#7162](https://github.com/appwrite/appwrite/pull/7162)
* Add chunkId to abuse key to prevent rate limit for SDKs [#7154](https://github.com/appwrite/appwrite/pull/7154)

## Miscellaneous

* Fix enum test case [#7163](https://github.com/appwrite/appwrite/pull/7163)
* Add flag to send logs to logger [#7155](https://github.com/appwrite/appwrite/pull/7155)
* Add a CI task to validate composer file and lock [#7142](https://github.com/appwrite/appwrite/pull/7142)

# Version 1.4.12

## Miscellaneous
* Bump console to version 3.2.7 [#7148](https://github.com/appwrite/appwrite/pull/7148)
* Chore update database to 0.45.2 [#7138](https://github.com/appwrite/appwrite/pull/7138)
* Implement queue thresholds for the health API [#7123](https://github.com/appwrite/appwrite/pull/7123)
* Add $auth->skip to the usage worker [#7124](https://github.com/appwrite/appwrite/pull/7124)

## Bug fixes
* fix: use queueForDeletes in git installation delete endpoint [#7140](https://github.com/appwrite/appwrite/pull/7140)
* fix: patch script, make errors silent [#7134](https://github.com/appwrite/appwrite/pull/7134)
* fix: repositories recreation script [#7133](https://github.com/appwrite/appwrite/pull/7133)
* fix: Only delete repositories linked to the particular project [#7131](https://github.com/appwrite/appwrite/pull/7131)

# Version 1.4.11

## Miscellaneous

* Update database by @abnegate in [#7111](https://github.com/appwrite/appwrite/pull/7111)

# Version 1.4.10

## Bug fixes
* Handle cases where password history could contain NULLs [#7092](https://github.com/appwrite/appwrite/pull/7092)
* Missing functionId error on create execution [#7091](https://github.com/appwrite/appwrite/pull/7091)
* Ensure usage endpoints don't throw 500 when usage is disabled [#7087](https://github.com/appwrite/appwrite/pull/7087)
* Missing sessionId error when deleting all user sessions [#7085](https://github.com/appwrite/appwrite/pull/7085)
* Domain validation in Create Proxy rule results in 500 error [#7084](https://github.com/appwrite/appwrite/pull/7084)
* Fix optional services [#7078](https://github.com/appwrite/appwrite/pull/7078)
* Fix regression from worker refactor [#7074](https://github.com/appwrite/appwrite/pull/7074)
* Use getQueueSize() in the Health service's get X queue endpoints [#7073](https://github.com/appwrite/appwrite/pull/7073)
* Delete linked VCS repos and comments [#7066](https://github.com/appwrite/appwrite/pull/7066)


# Version 1.4.9

## Bug fixes

* Fix 400 error on function domain execution in [#7059](https://github.com/appwrite/appwrite/pull/7059)

# Version 1.4.8

## Notable changes

* Fix certificate emails and add support for variables in email template subject in [#6495](https://github.com/appwrite/appwrite/)pull/6495
* Bump console to version 3.2.5 in [#7027](https://github.com/appwrite/appwrite/pull/7027)
* Bump utopia database and storage versions in [#7002](https://github.com/appwrite/appwrite/pull/7002)

## Bug fixes

* Fixes cookie headers not being passed properly by router in [#7024](https://github.com/appwrite/appwrite/pull/7024)
* Fix permission problem in deletes worker in [#7013](https://github.com/appwrite/appwrite/pull/7013)

## Miscellaneous

* Improve error handling in the realtime service in [#6998](https://github.com/appwrite/appwrite/pull/6998)
* Update the error code for unsupported protocol in [#7006](https://github.com/appwrite/appwrite/pull/7006)
* Improve CI tests by executing them in parallel in [#6198](https://github.com/appwrite/appwrite/pull/6198)
* Update README.md to add links to orchestration tools in [#7011](https://github.com/appwrite/appwrite/pull/7011)
* Update gitpod setup to install instead of update dependencies in [#6938](https://github.com/appwrite/appwrite/pull/6938)
* Remove analytics from install script in [#7017](https://github.com/appwrite/appwrite/pull/7017)
* Improve database logging in [#7003](https://github.com/appwrite/appwrite/pull/7003)
* Add VCS tests in [#6894](https://github.com/appwrite/appwrite/pull/6894)
* Improve error messages in [#6487](https://github.com/appwrite/appwrite/pull/6487)
* Add command to delete orphaned projects in [#7015](https://github.com/appwrite/appwrite/pull/7015)

# Version 1.4.7

## Fixes
- Fix missing body in async function execution in [#6988](https://github.com/appwrite/appwrite/pull/6988)

# Version 1.4.6

## Changes
- Bump console to version 3.2.3 in [#6947](https://github.com/appwrite/appwrite/pull/6947)
- New health endpoints in [#6319](https://github.com/appwrite/appwrite/pull/6319)
- 30 second sync executions timeout in [#6370](https://github.com/appwrite/appwrite/pull/6370)
- Feat db per worker in [#6888](https://github.com/appwrite/appwrite/pull/6888)
- Feat: Dart 3.1 support in [#6936](https://github.com/appwrite/appwrite/pull/6936)
- chore: remove resque library and update health check endpoints in [#6946](https://github.com/appwrite/appwrite/pull/6946)
- Refactor workers in [#6928](https://github.com/appwrite/appwrite/pull/6928)

## Fixes
- Fix realtime deletes in [#6897](https://github.com/appwrite/appwrite/pull/6897)
- Update teamInternalId when updating project team in [#6898](https://github.com/appwrite/appwrite/pull/6898)
- Fix: spanish translations (emails) in [#5290](https://github.com/appwrite/appwrite/pull/5290)
- chore: fix spec links in [#6434](https://github.com/appwrite/appwrite/pull/6434)
- Delegate custom deletes in [#6934](https://github.com/appwrite/appwrite/pull/6934)

# Version 1.4.5

## Changes
- Bump console to version 3.2.1 in [#6868](https://github.com/appwrite/appwrite/pull/6868)

## Fixes
- Fix realtime logs in [#6478](https://github.com/appwrite/appwrite/pull/6478)
- Fix "File not found" error in executor in [#6476](https://github.com/appwrite/appwrite/pull/6476)
- Fix missing array flag on migration errors response model rule in [#6469](https://github.com/appwrite/appwrite/pull/6469)
- Ensure openruntimes-executor restarts after a server reboot in [#6490](https://github.com/appwrite/appwrite/pull/6490)

# Version 1.4.4

## Features
- Feat: Function domains force https in [#6269](https://github.com/appwrite/appwrite/pull/6269)
- Feat: router protection in [#6272](https://github.com/appwrite/appwrite/pull/6272)
- Feat: Parse event body in [#6317](https://github.com/appwrite/appwrite/pull/6317)

## Fixes
- Fix: wrong device type in [#6271](https://github.com/appwrite/appwrite/pull/6271)
- Fix: build race condition in [#6270](https://github.com/appwrite/appwrite/pull/6270)
- Fix: Large builds in [#6273](https://github.com/appwrite/appwrite/pull/6273)
- Fix: migrations in [#6302](https://github.com/appwrite/appwrite/pull/6302)
- Add Description for Download Deployment in [#6268](https://github.com/appwrite/appwrite/pull/6268)
- Fix deployment delete in [#6290](https://github.com/appwrite/appwrite/pull/6290)
- Fix project deletion in [#6260](https://github.com/appwrite/appwrite/pull/6260)
- fix-6212-Issue-With-Linkedin-OAuth in [#6229](https://github.com/appwrite/appwrite/pull/6229)
- Fix: Execution body limit in [#6326](https://github.com/appwrite/appwrite/pull/6326)
- Patch: Disable console protection in [#6329](https://github.com/appwrite/appwrite/pull/6329)
- converted desc to sentence case in [#5926](https://github.com/appwrite/appwrite/pull/5926)
- Update avatar font and default colors in [#6277](https://github.com/appwrite/appwrite/pull/6277)
- Bump composer to fix migration bug in [#6344](https://github.com/appwrite/appwrite/pull/6344)
- Fix execution call timeout in [#6332](https://github.com/appwrite/appwrite/pull/6332)
- Bump appwrite-assistant to prevent it from crashing w/o open ai key in [#6342](https://github.com/appwrite/appwrite/pull/6342)
- Remove Special Chars from Initials [#6164](https://github.com/appwrite/appwrite/pull/6164)

# Version 1.4.3

## Features
- Support for the all new bun runtime [#6230](https://github.com/appwrite/appwrite/pull/6230)
- Stripe function templates [Console #540](https://github.com/appwrite/console/pull/540)

## Fixes
- Fix missing _APP_OPENSSL_KEY_V1 in the compose file [#6199](https://github.com/appwrite/appwrite/pull/6199)
- Fix V2 functions env vars [#6215](https://github.com/appwrite/appwrite/pull/6215)
- Fix Don't update User Accessed At for Users and Teams APIs [#6222](https://github.com/appwrite/appwrite/pull/6222)
- Fix Git deploys with S3 [#6227](https://github.com/appwrite/appwrite/pull/6227)
- Fix manual internal id insertion [#6232](https://github.com/appwrite/appwrite/pull/6232)
- Fix function timeout [#6235](https://github.com/appwrite/appwrite/pull/6235)
- Fix collections with datetime attributes migration [#17](https://github.com/utopia-php/migration/pull/17)
- Fix not all user data being migrated [#17](https://github.com/utopia-php/migration/pull/17)
- Fix team memberships migration [#16](https://github.com/utopia-php/migration/pull/16)
- Fix events validation on create/update webhooks [#6219](https://github.com/appwrite/appwrite/pull/6219)
- Fix schedules task [#6246](https://github.com/appwrite/appwrite/pull/6246)
- Fix missing keys when updating document via relationship [Database #320](https://github.com/utopia-php/database/pull/320)
- Fix Discord template [Console #538](https://github.com/appwrite/console/pull/538)
- Fix form var is url not text [Console #539](https://github.com/appwrite/console/pull/539)
- Fix incorrect link to migration docs for self-hosted to cloud [Console #543](https://github.com/appwrite/console/pull/543)
- Fix can't disable smtp [Console #548](https://github.com/appwrite/console/pull/548)
- Fix create function cover for case where VCS is not enabled [Console #544](https://github.com/appwrite/console/pull/544)
- Fix users list not re-rendering [Console #537](https://github.com/appwrite/console/pull/537)
- Fix create attribute modal null when selecting same time twice [Console #549](https://github.com/appwrite/console/pull/549)
- Fix runtime versions in templates [Console #546](https://github.com/appwrite/console/pull/546)

# Version 1.4.2

## Fixes

- Fix create phone session abuse key [#6134](https://github.com/appwrite/appwrite/pull/6134)
- Fix CLI backwards compatibility [#6125](https://github.com/appwrite/appwrite/pull/6125)
- Fix Not Found error when deploying function from git [#6133](https://github.com/appwrite/appwrite/pull/6133)
- Fix _APP_EXECUTOR_HOST for upgrades [#6141](https://github.com/appwrite/appwrite/pull/6141)
- Fix create execution request filter from previous SDK version [#6146](https://github.com/appwrite/appwrite/pull/6146)
- Fix migrations worker [#6116](https://github.com/appwrite/appwrite/pull/6116)
- Fix: Global variables by [#6150](https://github.com/appwrite/appwrite/pull/6150)
- Fix webhook secret validation and executor path validation [#6162](https://github.com/appwrite/appwrite/pull/6162)
- Fix: Untrusted custom domains + auto-ssl [#6155](https://github.com/appwrite/appwrite/pull/6155)
- Fix: AI Assistant [#6153](https://github.com/appwrite/appwrite/pull/6153)

## Changes
- Add required params for scheduled functions [#6148](https://github.com/appwrite/appwrite/pull/6148)
- Update the error message for router_domain_not_configured [#6145](https://github.com/appwrite/appwrite/pull/6145)
- Override forEachDocument() to skip the cache collection [#6144](https://github.com/appwrite/appwrite/pull/6144)
- Support for v2 functions [#6142](https://github.com/appwrite/appwrite/pull/6142)
- Change executor hostname back to appwrite-executor [#6160](https://github.com/appwrite/appwrite/pull/6160)
- Make URL optional for Create Membership API and Serverside Requests [#6157](https://github.com/appwrite/appwrite/pull/6157)

# Version 1.4.1

## Features

- Add upgrade task [#6068](https://github.com/appwrite/appwrite/pull/6068)

## Fixes

- Fix VCS/migration/assistant scopes [#6071](https://github.com/appwrite/appwrite/pull/6071)
- Add missing parameters required for custom email templates [#6077](https://github.com/appwrite/appwrite/pull/6077)
- Fix `Call to a member function label() on null` error when using a custom domain [#6079](https://github.com/appwrite/appwrite/pull/6079)

## Changes

- Update console to 3.0.2 [#6071](https://github.com/appwrite/appwrite/pull/6071)

# Version 1.4.0

## Features

- Add error attribute to indexes and attributes [#4575](https://github.com/appwrite/appwrite/pull/4575)
- Add new index validation rules [#5710](https://github.com/appwrite/appwrite/pull/5710)
- Added support for disallowing passwords that contain personal data [#5371](https://github.com/appwrite/appwrite/pull/5371)

## Fixes

- Fix cascading deletes across multiple levels [DB #269](https://github.com/utopia-php/database/pull/269)
- Fix identical two-way keys not throwing duplicate exceptions [DB #273](https://github.com/utopia-php/database/pull/273)
- Fix search wildcards [DB #279](https://github.com/utopia-php/database/pull/279)
- Fix permissions returning as an object instead of list [DB #281](https://github.com/utopia-php/database/pull/281)
- Fix missing collection not found error [DB #282](https://github.com/utopia-php/database/pull/282)

## Changes

- Improve permission indexes [DB #248](https://github.com/utopia-php/database/pull/248)
- Validators back-ported to Utopia [#5439](https://github.com/appwrite/appwrite/pull/5439)

# Version 1.3.8

## Changes

- Replace Appwrite executor with OpenRuntimes Executor [#4650](https://github.com/appwrite/appwrite/pull/4650)
- Add `_APP_CONNECTIONS_MAX` env var [#4673](https://github.com/appwrite/appwrite/pull/4673)
- Increase Traefik TCP + file limits [#4673](https://github.com/appwrite/appwrite/pull/4673)
- Store build output file size [#4844](https://github.com/appwrite/appwrite/pull/4844)

## Bugs
- Fix audit user internal [#5809](https://github.com/appwrite/appwrite/pull/5809)

# Version 1.3.7

## Bugs
- Fix the routing for the default OAuth2 pages [#5640](https://github.com/appwrite/appwrite/pull/5640) [#5648](https://github.com/appwrite/appwrite/pull/5648)
- Add support for trailing slashes in Routes and URLs [#5647](https://github.com/appwrite/appwrite/pull/5647) [#5648](https://github.com/appwrite/appwrite/pull/5648)

# Version 1.3.6

## Bugs

- Fix Console deep linking to result in a 404 [#5632](https://github.com/appwrite/appwrite/pull/5632)
- Fix ACME HTTP Challenge [#5632](https://github.com/appwrite/appwrite/pull/5632)

# Version 1.3.5

## Bugs

- Fix minimum length for string attribute default values [#5606](https://github.com/appwrite/appwrite/pull/5606), [#5602](https://github.com/appwrite/appwrite/pull/5602)
- Update framework to fix route mismatches [#5603](https://github.com/appwrite/appwrite/pull/5603)

# Version 1.3.4

## Bugs

- Update migration to properly migrate bucket permissions [#5497](https://github.com/appwrite/appwrite/pull/5497)

# Version 1.3.3

## Bugs
- Fixed migration resetting some data [#5455](https://github.com/appwrite/appwrite/pull/5455)

# Version 1.3.2

## Bugs
- Fixed auto-setting custom ID on nested documents [#5363](https://github.com/appwrite/appwrite/pull/5363)
- Fixed listDocuments not returning all the documents [#5395](https://github.com/appwrite/appwrite/pull/5395)
- Fixed deleting keys, webhooks, platforms and domains after deleting project [#5395](https://github.com/appwrite/appwrite/pull/5395)
- Fixed empty team prefs returning as JSON object rather array [#5361](https://github.com/appwrite/appwrite/pull/5361)

# Version 1.3.1

## Bugs
- Fixed Migration issue regarding 500 error [#5356](https://github.com/appwrite/appwrite/pull/5356)

# Version 1.3.0

## Features
- Password dictionary setting allows to compare user's password against command password database [#4906](https://github.com/appwrite/appwrite/pull/4906)
- Password history setting allows to save user's last used password so that it may not be used again.  Maximum number of history saved is 20, which can be configured. Minimum is 0 which means disabled. [#4866](https://github.com/appwrite/appwrite/pull/4866)
- Update APIs to check X-Appwrite-Timestamp header [#5024](https://github.com/appwrite/appwrite/pull/5024)
- Database relationships [#5238](https://github.com/appwrite/appwrite/pull/5238)
- New query operators [#5238](https://github.com/appwrite/appwrite/pull/5238)
- Team preferences [#5196](https://github.com/appwrite/appwrite/pull/5196)
- Update attribute metadata [#5164](https://github.com/appwrite/appwrite/pull/5164)

## Bugs
- Fix not storing function's response on response codes 5xx [#4610](https://github.com/appwrite/appwrite/pull/4610)
- Fix expire to formatTz in create account session [#4985](https://github.com/appwrite/appwrite/pull/4985)
- Fix deleting projects when organization is deleted [#5335](https://github.com/appwrite/appwrite/pull/5335)
- Fix deleting collections from a project [#4983](https://github.com/appwrite/appwrite/pull/4983)
- Fix cleaning up project databases [#4984](https://github.com/appwrite/appwrite/pull/4984)
- Fix creating documents with attributes with special characters [#246](https://github.com/utopia-php/database/pull/246)
- Fix deleting attribute not deleting metadata index [#246](https://github.com/utopia-php/database/pull/246)
- Fix create attribute event payload [#246](https://github.com/utopia-php/database/pull/246)

# Version 1.2.1
## Changes
- Upgrade Console to [2.2.0](https://github.com/appwrite/console/releases/tag/2.2.0)
- Update DBIP Database [#5049](https://github.com/appwrite/appwrite/pull/5049)

## Bugs
- Fix a few null safety warnings [#4654](https://github.com/appwrite/appwrite/pull/4654)
- Fix timestamp format in Realtime response [#4515](https://github.com/appwrite/appwrite/pull/4515)
- Add flutter-web as a platform type [#4992](https://github.com/appwrite/appwrite/pull/4992)
- Fix typo in Model/Locale.php [#4669](https://github.com/appwrite/appwrite/pull/4669)
- Fix deletes worker not deleting project database tables [#4984](https://github.com/appwrite/appwrite/pull/4984)
- Fix deletes worker not deleting database collections [#4983](https://github.com/appwrite/appwrite/pull/4983)
- Fix restart policy for worker-messaging container [#4994](https://github.com/appwrite/appwrite/pull/4994)
- Fix validating origin for apple platforms [#5089](https://github.com/appwrite/appwrite/pull/5089)

# Version 1.2.0
## Features
- Added GraphQL API [#974](https://github.com/appwrite/appwrite/pull/974)
- Added GraphQL Explorer [#974](https://github.com/appwrite/appwrite/pull/974)
- Added ability to set max sessions per user per project [#4831](https://github.com/appwrite/appwrite/pull/4831)

## Changes
- Get default region from environment on project create [#4780](https://github.com/appwrite/appwrite/pull/4780)
- Fix french translation [#4782](https://github.com/appwrite/appwrite/pull/4782)
- Fix max mimetype size [#4814](https://github.com/appwrite/appwrite/pull/4814)
## Bugs
- Fix invited account verified status [#4776](https://github.com/appwrite/appwrite/pull/4776)

# Version 1.1.2
## Changes
- Released `appwrite/console` [2.0.2](https://github.com/appwrite/console/releases/tag/2.0.2)
- Make `region` parameter optional with default for project create [#4763](https://github.com/appwrite/appwrite/pull/4763)
- Add security headers to the console endpoint [#4758](https://github.com/appwrite/appwrite/pull/4758)

## Bugs
- Fix default oauth paths [#4725](https://github.com/appwrite/appwrite/pull/4725)
- Fix session expiration, and expired session deletion [#4739](https://github.com/appwrite/appwrite/pull/4739)
- Fix processing status on sync executions [#4737](https://github.com/appwrite/appwrite/pull/4737)
- Fix Locale API returning Unknown continent [#4761](https://github.com/appwrite/appwrite/pull/4761)

# Version 1.1.1
## Bugs
- Fix Deletes worker using incorrect device for file deletion [#4662](https://github.com/appwrite/appwrite/pull/4662)
- Fix Migration for Stats adding the region attribute [#4704](https://github.com/appwrite/appwrite/pull/4704)
- Fix Migration stopping scheduled functions [#4704](https://github.com/appwrite/appwrite/pull/4704)
- Fix Migration enabling OAuth providers with data by default [#4704](https://github.com/appwrite/appwrite/pull/4704)
- Fix Error pages when OAuth providers are disabled [#4704](https://github.com/appwrite/appwrite/pull/4704)

# Version 1.1.0
## Features
- Added support for the new Appwrite Console [#4655](https://github.com/appwrite/appwrite/pull/4655)
- Added new property to projects configuration: `authDuration` which allows you to alter the duration of signed in sessions for your project. [#4618](https://github.com/appwrite/appwrite/pull/4618)

## Bugs
- Fix license detection for Flutter and Dart SDKs [#4435](https://github.com/appwrite/appwrite/pull/4435)
- Fix missing realtime event for create function deployment [#4574](https://github.com/appwrite/appwrite/pull/4574)
- Fix missing `status`, `buildStderr` and `buildStderr` from get deployment response [#4611](https://github.com/appwrite/appwrite/pull/4611)
- Fix project pagination in DB usage aggregation [#4517](https://github.com/appwrite/appwrite/pull/4517)
- Fix missing file permissions due to cache [#4661](https://github.com/appwrite/appwrite/pull/4661)
- Fix usage stats for async function executions [#4674](https://github.com/appwrite/appwrite/pull/4674)

# Version 1.0.3
## Bugs
- Fix document audit deletion [#4429](https://github.com/appwrite/appwrite/pull/4429)
- Fix attribute and index deletion when deleting a collection [#4429](https://github.com/appwrite/appwrite/pull/4429)

# Version 1.0.2
## Bugs
- Fixed nullable values in functions variables [#3885](https://github.com/appwrite/appwrite/pull/3885)
- Fixed migration for audit by migrating the `time` attribute [#4038](https://github.com/appwrite/appwrite/pull/4038)
- Fixed default value for creating Boolean Attribute [#4040](https://github.com/appwrite/appwrite/pull/4040)
- Fixed phone authentication code to be hashed in the internal database [#3906](https://github.com/appwrite/appwrite/pull/3906)
- Fixed `/v1/teams/:teamId/memberships/:membershipId` response [#3883](https://github.com/appwrite/appwrite/pull/3883)
- Fixed removing variables when function is deleted [#3884](https://github.com/appwrite/appwrite/pull/3884)
- Fixed scheduled function not being triggered [#3908](https://github.com/appwrite/appwrite/pull/3908)
- Fixed Phone Provider configuration [#3862](https://github.com/appwrite/appwrite/pull/3883)
- Fixed Queries with `0` values [utopia-php/database#194](https://github.com/utopia-php/database/pull/194)

# Version 1.0.1
## Bugs
- Fixed migration for abuse by migrating the `time` attribute [#3839](https://github.com/appwrite/appwrite/pull/3839)

# Version 1.0.0
## BREAKING CHANGES
- All Date values are now stored as ISO-8601 instead of UNIX timestamps [#3516](https://github.com/appwrite/appwrite/pull/3516)
- Permission levels and syntax have been reworked. See the Permissions V2 section in the document for more information [#3700](https://github.com/appwrite/appwrite/pull/3700)
- Function Variables are now stored in a separate collection with their own API endpoints [#3634](https://github.com/appwrite/appwrite/pull/3634)
- Resources that are computed asynchronously, such as function deployments, will now return a `202 Accepted` status code instead of `200 OK` [#3547](https://github.com/appwrite/appwrite/pull/3547)
- Queries have been improved to allow even more flexibility, and introduced to new endpoints. See the Queries V2 section in the document for more information [#3702](https://github.com/appwrite/appwrite/pull/3702)
- Compound indexes are now more flexible [#151](https://github.com/utopia-php/database/pull/151)
- `createExecution` parameter `async` default value was changed from `true` to `false` [#3781](https://github.com/appwrite/appwrite/pull/3781)
- String attribute `status` has been refactored to a Boolean attribute `enabled` in the functions collection [#3798](https://github.com/appwrite/appwrite/pull/3798)
- `time` attribute in Execution response model has been renamed to `duration` to be more consistent with other response models. [#3801](https://github.com/appwrite/appwrite/pull/3801) 
- Renamed the following list endpoints to stay consistent with other endpoints [#3825](https://github.com/appwrite/appwrite/pull/3825)
  - `getMemberships` to `listMemberships` in Teams API
  - `getMemberships` to `listMemberships` in Users API
  - `getLogs` to `listLogs` in Users API
  - `getLogs` to `listLogs` in Accounts API
  - `getSessions` to `listSessions` in Accounts API
  - `getSessions` to `listSessions` in Users API
  - `getCountries` to `listCountries` in Locale API
  - `getCountriesEU` to `listCountriesEU` in Locale API
  - `getCountriesPhones` to `listCountriesPhones` in Locale API
  - `getContinents` to `listContinents` in Locale API
  - `getCurrencies` to `listCurrencies` in Locale API
  - `getLanguages` to `listLanguages` in Locale API

## Features
- Added the UI to see the Parent ID of all resources within the UI. [#3653](https://github.com/appwrite/appwrite/pull/3653)
- Added automatic cache cleaning for internal Appwrite services [#3491](https://github.com/appwrite/appwrite/pull/3491)
- Added the ability for Appwrite to handle importing hashed passwords, this can be leveraged to import existing user data from other systems. More information can be found in the document linked above. [#2747](https://github.com/appwrite/appwrite/pull/2747)
- `Users` has now been renamed to `Authentication` within the Appwrite console [#3664](https://github.com/appwrite/appwrite/pull/3664)
- More endpoints were made public (for guests) with proper rate limits [#3741](https://github.com/appwrite/appwrite/pull/3741)
- Added Disqus, Podio, and Etsy OAuth providers [#3526](https://github.com/appwrite/appwrite/pull/3526), [#3488](https://github.com/appwrite/appwrite/pull/3488), [#3522](https://github.com/appwrite/appwrite/pull/3522)
- Function logs now capture stdout [#3656](https://github.com/appwrite/appwrite/pull/3656)
- Added the ability to grant guests write permissions for documents, files and executions [#3727](https://github.com/appwrite/appwrite/pull/3727)

## Bugs
- Fixed an issue where after resetting your password in the Appwrite console, you would not be redirected to the login page. [#3654](https://github.com/appwrite/appwrite/pull/3654)
- Fixed an issue where invalid data could be loaded into the Appwrite console. [#3660](https://github.com/appwrite/appwrite/pull/3660)
- Fixed an issue where users using the MySQL adapter for Appwrite would run into an issue with full text indexes [#154](https://github.com/utopia-php/database/pull/154)
- Fix teams being created with no owners [#3558](https://github.com/appwrite/appwrite/pull/3558)
- Fixed a bug where you could not search users by phone [#3619](https://github.com/appwrite/appwrite/pull/3619)
- Fixed a bug where unaccepted invitations would grant access to projects [#3738](https://github.com/appwrite/appwrite/pull/3738)

# Version 0.15.3
## Features
- Added hint during Installation for DNS Configuration by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/2450
## Bugs
- Fixed Migration for Attributes and Indexes by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3568
- Fixed Closed Icon in the alerts to be centered by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3594
- Fixed Response Model for Get and Update Database Endpoint by @ishanvyas22 in https://github.com/appwrite/appwrite/pull/3553
- Fixed Missing Usage on Functions exection by @Meldiron in https://github.com/appwrite/appwrite/pull/3543
- Fixed Validation for Permissions to only accept a maximum of 100 Permissions for all endpoints by @Meldiron in https://github.com/appwrite/appwrite/pull/3532
- Fixed backwards compatibility for Create Email Session Endpoint by @stnguyen90 in https://github.com/appwrite/appwrite/pull/3517

# Version 0.15.2
## Bugs
- Fixed Realtime Authentication for the Console by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3506
- Fixed Collection Usage by @stnguyen90 in https://github.com/appwrite/appwrite/pull/3505
- Fixed `$createdAt` after updating document by @Meldiron in https://github.com/appwrite/appwrite/pull/3498
- Fixed Redirect after deleting Collection in Console @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3476
- Fixed broken Link for Documents under Collections by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3469

# Version 0.15.1
## Bugs
- Fixed SMS for `createVerification` by @christyjacob4 in https://github.com/appwrite/appwrite/pull/3454
- Fixed missing Attributes when creating an Index by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3461
- Fixed broken Link for Documents under Collections by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3461
- Fixed all `$createdAt` and `$updatedAt` occurences in the UI by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3461
- Fixed Delete Document from the UI by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3463
- Fixed internal Attribute and Index key on Migration by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3455

## Docs
- Updated Phone Authentication by @christyjacob4 in https://github.com/appwrite/appwrite/pull/3456

# Version 0.15.0

## BREAKING CHANGES
- Docker Compose V2 is required now
- The `POST:/v1/account/sessions` endpoint is now `POST:/v1/account/sessions/email`
- All `/v1/database/...` endpoints are now `/v1/databases/...`
- `dateCreated` attribute is removed from Teams
- `dateCreated` attribute is removed from Executions
- `dateCreated` attribute is removed from Files
- `dateCreated` and `dateUpdated` attributes are removed from Functions
- `dateCreated` and `dateUpdated` attributes are removed from Deployments
- `dateCreated` and `dateUpdated` attributes are removed from Buckets
- Following Events for Webhooks and Functions are changed:
  - `collections.[COLLECTION_ID]` is now `databases.[DATABASE_ID].collections.[COLLECTION_ID]`
  - `collections.[COLLECTION_ID].documents.[DOCUMENT_ID]` is now `databases.[DATABASE_ID].collections.[COLLECTION_ID].documents.[DOCUMENT_ID]`
- Following Realtime Channels are changed:
  - `collections.[COLLECTION_ID]` is now `databases.[DATABASE_ID].collections.[COLLECTION_ID]`
  - `collections.[COLLECTION_ID].documents` is now `databases.[DATABASE_ID].collections.[COLLECTION_ID].documents`
- After Migration a Database called `default` is created for all your existing Database Collections

## Features
- Added Phone Authentication by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3357
  - Added Twilio Support
  - Added Textmagic Support
  - Added Telesign Support
  - Added Endpoint to create Phone Session (`POST:/v1/account/sessions/phone`)
  - Added Endpoint to confirm Phone Session (`PUT:/v1/account/sessions/phone`)
  - Added Endpoint to update Account Phone Number (`PATCH:/v1/account/phone`)
  - Added Endpoint to create Account Phone Verification (`POST:/v1/account/verification/phone`)
  - Added Endpoint to confirm Account Phone Verification (`PUT:/v1/account/verification/phone`)
  - Added `_APP_PHONE_PROVIDER` and `_APP_PHONE_FROM` Environment Variable
  - Added `phone` and `phoneVerification` Attribute to User
- Added `$createdAt` and `$updatedAt` Attributes by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3382
  - Bucket
  - Collection
  - Deployment
  - Document
  - Domain
  - Execution
  - File
  - Func
  - Key
  - Membership
  - Platform
  - Project
  - Team
  - User
  - Webhook
  - Session (only `$createdAt`)
  - Token (only `$createdAt`)
- Added Databases Resource to the Database Service by @lohanidamodar in https://github.com/appwrite/appwrite/pull/3338
  - Added `databases.read` and `databases.write` Scopes for API Keys
- Added New Runtimes
  - Dart 2.17
  - Deno 1.21
  - Java 18
  - Node 18
- Webhooks now have a Signature Key for proof of Origin by @shimonewman in https://github.com/appwrite/appwrite/pull/3351
- Start using Docker Compose V2 (from `docker-compose` to `docker compose`) by @Meldiron in https://github.com/appwrite/appwrite/pull/3362
- Added support for selfhosted Gitlab (OAuth) by @Meldiron in https://github.com/appwrite/appwrite/pull/3366
- Added Dailymotion OAuth Provider by @2002Bishwajeet in https://github.com/appwrite/appwrite/pull/3371
- Added Autodesk OAuth Provider by @Haimantika in https://github.com/appwrite/appwrite/pull/3420
- Ignore Service Checks when using API Key by @stnguyen90 in https://github.com/appwrite/appwrite/pull/3270
- Added WebM as MIME- and Preview Type by @chuongtang in https://github.com/appwrite/appwrite/pull/3327
- Expired User Sessions are now deleted by the Maintenance Worker by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3324
- Increased JWT rate-limit to 100 per hour by @abnegate in https://github.com/appwrite/appwrite/pull/3345
- Internal Database Relations are now resolved using the Internal ID by @fogelito in https://github.com/appwrite/appwrite/pull/3383
- Permissions for Documents can be updated without payload now by @gepd in https://github.com/appwrite/appwrite/pull/3346

## Bugs
- Fixed Zoom OAuth scopes
- Fixed empty build logs for Functions
- Fixed unnecessary SMTP check on Team Invite using an API Key by @stnguyen90 in https://github.com/appwrite/appwrite/pull/3270
- Fixed Error Message when adding Team Member to project by @stnguyen90 in https://github.com/appwrite/appwrite/pull/3296
- Fixed .NET Runtime Logo by @adityaoberai in https://github.com/appwrite/appwrite/pull/3315
- Fixed unnecessary Function execution delays by @Meldiron in https://github.com/appwrite/appwrite/pull/3348
- Fixed Runtime race conditions on cold start by @PineappleIOnic in https://github.com/appwrite/appwrite/pull/3361
- Fixed Malayalam translation by @varghesejose2020 in https://github.com/appwrite/appwrite/pull/2561
- Fixed English translation by @MATsxm in https://github.com/appwrite/appwrite/pull/3337
- Fixed spelling in Realtime Worker logs by @gireeshp in https://github.com/appwrite/appwrite/pull/1663
- Fixed Docs URL for Yammer OAuth by @everly-gif in https://github.com/appwrite/appwrite/pull/3402

# Version 0.14.2

## Features

- Support for Backblaze adapter in Storage
- Support for Linode adapter in Storage
- Support for Wasabi adapter in Storage
- New Cloud Function Runtimes:
  - Dart 2.17
  - Deno 1.21
  - Java 18
  - Node 18
- Improved overall Migration speed


# Version 0.14.1

## Bugs
* Fixed scheduled Cloud Functions execution with cron-job by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3245
* Fixed missing runtime icons by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3234
* Fixed Google OAuth by @Meldiron in https://github.com/appwrite/appwrite/pull/3236
* Fixed certificate generation when hostname was set to 'localhost' by @Meldiron in https://github.com/appwrite/appwrite/pull/3237
* Fixed Installation overriding default env variables by @TorstenDittmann in https://github.com/appwrite/appwrite/pull/3241

# Version 0.14.0

## Features
- **BREAKING CHANGE** New Event Model
  - The new Event Model allows you to define events for Webhooks or Functions more granular
  - Account and Users events have been merged to just Users
  - Examples:
    - `database.documents.create` is now `collections.[COLLECTION_ID].documents.[DOCUMENT_ID].create`
    - Both placeholders needs to be replaced with either `*` for wildcard or an ID of the respective collection or document
    - So you can listen to every document that is created in the `posts` collection with `collections.posts.*.documents.*.create`
  - `event` in the Realtime payload has been renamed to `events` and contains all possible events
  - `X-Appwrite-Webhook-Event` Webhook header has been renamed to `X-Appwrite-Webhook-Events` and contains all possible events
- **BREAKING CHANGE** Renamed `providers` to `authProviders` in Projects
- **BREAKING CHANGE** Renamed `stdout` to `response` in Execution
- **BREAKING CHANGE** Removed delete endpoint from the Accounts API
- **BREAKING CHANGE** Renamed `name` to `userName` on Membership response model
- **BREAKING CHANGE** Renamed `email` to `userEmail` on Membership response model
- **BREAKING CHANGE** Renamed `event` to `events` on Realtime Response and now is an array of strings
- Added `teamName` to Membership response model
- Added new endpoint to update user's status from the Accounts API
- Deleted users will now free their ID and not reserve it anymore
- Added new endpoint to list all memberships on the Users API
- Increased Execution `response` to 1MB
- Increased Build `stdout` to 1MB
- Added Wildcard support to Platforms
- Added Activity page to Teams console
- Added button to verify/unverify user's e-mail address in the console
- Added Docker log limits to `docker-compose.yaml`
- Renamed `_APP_EXECUTOR_RUNTIME_NETWORK` environment variable to `OPEN_RUNTIMES_NETWORK`
- Added Auth0 OAuth2 provider
- Added Okta Oauth2 provider @tanay1337 in https://github.com/appwrite/appwrite/pull/3139

## Bugs
- Fixed issues with `min`, `max` and `default` values for float attributes
- Fixed account created with Magic URL to set a new password
- Fixed Database to respect `null` values
- Fixed missing realtime events from the Users API
- Fixed missing events when all sessions are deleted from the Users and Account API
- Fixed dots in database attributes
- Fixed renewal of SSL certificates
- Fixed errors in the certificates workers
- Fixed HTTPS redirect bug for non GET requests
- Fixed search when a User is updated
- Fixed aspect ratio bug in Avatars API
- Fixed wrong `Fail to Warmup ...` error message in Executor
- Fixed UI when file uploader is covered by jumpt to top button
- Fixed bug that allowed Queries on failed indexes
- Fixed UI when an alert with a lot text disappears too fast by increasing duration
- Fixed issues with cache and case-sensivity on ID's
- Fixed storage stats by upgrading to `BIGINT`
- Fixed `storage.total` stats which now is a sum of `storage.files.total` and `storage.deployments.total`
- Fixed Project logo preview
- Fixed UI for missing icons in Collection attributes
- Fixed UI to allow single-character custom ID's
- Fixed array size validation in the Database Service
- Fixed file preview when file extension is missing
- Fixed `Open an Issue` link in the console
- Fixed missing environment variables on Executor service
- Fixed all endpoints that expect an Array in their params to have not more than 100 items
- Added Executor host variables as a part of infrastructure configuration by @sjke in https://github.com/appwrite/appwrite/pull/3084
- Added new tab/window for new release link by @Akshay-Rana-Gujjar in https://github.com/appwrite/appwrite/pull/3202

# Version 0.13.4

## Features
- Added `detailedTrace` to Logger events
- Added new `_APP_STORAGE_PREVIEW_LIMIT` environment variable to configure maximum preview file size

## Bugs
- Fixed missing volume mount in Docker Compose
- Fixed upload with Bucket File permission
- Fixed custom ID validation in Console
- Fixed file preview with no `output` passed
- Fixed GitHub issue URL in Console
- Fixed double PDOException logging
- Fixed functions cleanup when container is already initialized
- Fixed float input precision in Console

# Version 0.13.3
## Bugs
- Fixed search for terms that inlcude `@` characters
- Fixed Bucket permissions
- Fixed file upload error in UI
- Fixed input field for float attributes in UI
- Fixed `appwrite-executor` restart behavior in docker-compose.yml

# Version 0.13.2
## Bugs
- Fixed global issue with write permissions
- Added missing `_APP_EXECUTOR_SECRET` environment variable for deletes worker
- Increased execution `stdout` and `stderr` from 8000 to 16384 character limit
- Increased maximum file size for image preview to 20mb
- Fixed iOS platforms for origin validation by @stnguyen90 in https://github.com/appwrite/appwrite/pull/2907

# Version 0.13.1
## Bugs
- Fixed the Console UI redirect breaking the header and navigation
- Fixed timeout in Functions API to respect the environment variable `_APP_FUNCTIONS_TIMEOUT`
- Fixed team invite to be invalid after successful use by @Malte2036 in https://github.com/appwrite/appwrite/issues/2593

# Version 0.13.0
## Features
### Functions
- Synchronous function execution
- Improved functions execution times by alot
- Added a new worker to build deployments
- Functions are now executed differently and your functions need to be adapted **Breaking Change**
- Tags are now called Deployments **Breaking Change**
- Renamed `tagId` to `deplyomentId` in collections **Breaking Change**
- Updated event names from `function.tags.*` to `function.deployments.*` **Breaking Change**
- Java runtimes are currently not supported **Breaking Change**
### Storage
- Added Buckets
- Buckets allow you to configure following settings:
  - Maximum File Size
  - Enabled/Disabled
  - Encryption
  - Anti Virus
  - Allowed file extensions
  - Permissions
    - Bucket Level
    - File Level
- Support for S3 and Digitalocean Spaces
- Efficiently process large files by loading only chunks
- Files larger then 5MB needs to be uploaded in chunks using Content-Range header. SDKs handle this internally **Breaking Change**
- Encryption, Compression is now limited to files smaller or equal to 20MB
- New UI in the console for uploading files with progress indication
- Concurrent file uploads
- Added `buckets.read` and `buckets.write` scope to API keys

### Account
- Renamed `providerToken` to `providerAccessToken` in sessions **Breaking Change**
- New endpoint to refresh the OAuth Access Token
- OAuth sessions now include `providerAccessTokenExpiry` and `providerRefreshToken`
- Notion and Stripe have been added to the OAuth Providers
- Microsoft OAuth provider now supports custom domains

### Others
- Renamed `sum` to `total` on multiple endpoints returning a list of resource **Breaking Change**
- Added new `_APP_WORKER_PER_CORE` environment variable to configure the amount of internal workers per core for performance optimization

## Bugs
- Fixed issue with 36 character long custom IDs
- Fixed permission issues and is now more consistent and returns all resources
- Fixed total amount of documents not being updated
- Fixed issue with searching though memberships
- Fixed image preview rotation
- Fixed Database index names that contain SQL keywords
- Fixed UI to reveal long e-mail addresses on User list
- Fixed UI for Attribute default value field to reset after submit
- Fixed UI to check for new available version of Appwrite
- Fixed UI default values when creating Integer or Float attributes
- Removed `_project` prepend from internal Database Schema
- Added dedicated internal permissions table for each Collection

## Security
- Remove `appwrite.io` and `appwrite.test` from authorized domains for session verification

## Upgrades

- Upgraded `redis` extenstion to version 5.3.7
- Upgraded `swoole` extenstion to version 4.8.7
- Upgraded GEO IP database to version March 2022

# Version 0.12.3

## Bugs
- Fix update membership roles (#2799)
- Fix migration to 0.12.x to populate search fields (#2799)

## Security
- Fix URL schema Validation to only allow http/https (#2801)

# Version 0.12.2

## Bugs
- Fix security vulnerability in the Console (#2778)
- Fix security vulnerability in the ACME-Challenge (#2780)

## Upgrades

- Upgraded `redis` extenstion to version 5.3.6
- Upgraded `swoole` extenstion to version 4.8.6
- Upgraded `imagick` extenstion to version 3.7.0
- Upgraded GEO IP database to version February 2022

# Version 0.12.1

## Bugs
- Fixed some issues with the Migration
- Fixed the UI to add Variables to Functions
- Fixed wrong data type for String Attribute size
- Fixed Request stats on the console
- Fixed Realtime Connection stats with high number by abbreviation
- Fixed backward compatibility of account status attribute.

# Version 0.12.0

## Features

- Completely rewritten Database service: **Breaking Change**
  - Collection rules are now attributes
  - Filters for have been replaced with a new, more powerful syntax
  - Custom indexes for more performant queries
  - Enum Attributes
  - Maximum `sum` returned does not exceed 5000 documents anymore **Breaking Change**
  - **DEPRECATED** Nested documents has been removed
  - **DEPRECATED** Wildcard rule has been removed
- You can now set custom ID’s when creating following resources:
  - User
  - Team
  - Function
  - Project
  - File
  - Collection
  - Document
- All resources with custom ID support required you to set an ID now
  - Passing `unique()` will generate a unique ID
- Auto-generated ID's are now 20 characters long
- Wildcard permissions `*` are now `role:all` **Breaking Change**
- Collections can be enabled and disabled
- Permissions are now found as top-level keys `$read` and `$write` instead of nested under `$permissions`
- Accessing collections with insufficient permissions now return a `401` isntead of `404` status code
- Offset cannot be higher than 5000 now and cursor pagination is required
- Added Cursor pagination to all endpoints that provide pagination by offset
- Added new Usage worker to aggregate usage statistics
- Added new Database worker to handle heavy database tasks in the background
- Added detailed Usage statistics to following services in the Console:
  - Users
  - Storage
  - Database
- You can now disable/enable following services in the Console:
  - Account
  - Avatars
  - Database
  - Locale
  - Health
  - Storage
  - Teams
  - Users
  - Functions
- Fixed several memory leaks in the Console
- Added pagination to account activities in the Console
- Added following events from User service to Webhooks and Functions:
  - `users.update.email`
  - `users.update.name`
  - `users.update.password`
- Added new environment variables to enable error logging:
  - The `_APP_LOGGING_PROVIDER` variable allows you to enable the logger set the value to one of `sentry`, `raygun`, `appsignal`.
  - The `_APP_LOGGING_CONFIG` variable configures authentication to 3rd party error logging providers. If using Sentry, this should be 'SENTRY_API_KEY;SENTRY_APP_ID'. If using Raygun, this should be Raygun API key. If using AppSignal, this should be AppSignal API key.
- Added new environment variable `_APP_USAGE_AGGREGATION_INTERVAL` to configure the usage worker interval
- Added negative rotation values to file preview endpoint
- Multiple responses from the Health service were changed to new (better) schema  **Breaking Change**
- Method `health.getAntiVirus()` has been renamed to `health.getAntivirus()`
- Added following langauges to the Locale service:
  - Latin
  - Sindhi
  - Telugu
- **DEPRECATED** Tasks service **Breaking Change**

## Bugs
- Fixed `/v1/avatars/initials` when no space in the name, will try to split by `_`
- Fixed all audit logs now saving all relevant informations
- Fixed Health endpoints for `db` and `cache`

## Security
- Increased minimum password length to 8 and removed maximum length
- Limited User Preferences to 65kb total size
- Upgraded Redis to 6.2
- Upgraded InfluxDB to 1.4.0
- Upgraded Telegraf to 1.3.0

# Version 0.11.1

## Bugs
- Fix security vulnerability in the Console (#2777)
- Fix security vulnerability in the ACME-Challenge (#2779)

## Upgrades
- Upgraded redis extenstion to version 5.3.6
- Upgraded swoole extenstion to version 4.8.6
- Upgraded imagick extenstion to version 3.7.0
- Upgraded yaml extenstion to version 2.2.2
- Upgraded maxminddb extenstion to version 1.11.0
- Upgraded GEO IP database to version February 2022

# Version 0.11.0

## Features
- Added Swift Platform Support
- Added new Cloud Functions Runtimes:
  - Swift 5.5
  - Java 17
  - Python 3.10
  - Deno 1.12
  - Deno 1.13
  - Deno 1.14
  - PHP 8.1
  - Node 17
- Added translations:
  - German `de` by @SoftCreatR in https://github.com/appwrite/appwrite/pull/1790
  - Hebrew `he` by @Kokoden in https://github.com/appwrite/appwrite/pull/1846
  - Oriya `or` by @Rutam21 in https://github.com/appwrite/appwrite/pull/1827
  - Italian `it` by @ilmalte in https://github.com/appwrite/appwrite/pull/1824
  - Portugese (Portugal) `pt-PT` by @OscarRG in https://github.com/appwrite/appwrite/pull/1820
  - Portugese (Brazil) `pt-BR` by @renato04 in https://github.com/appwrite/appwrite/pull/1817
  - Indonesian `id` by @Hrdtr in https://github.com/appwrite/appwrite/pull/1816
  - Korean `ko` by @ssong in https://github.com/appwrite/appwrite/pull/1814
  - Ukrainian `uk` by @daniloff200 in https://github.com/appwrite/appwrite/pull/1794
  - Russian `ru` by @daniloff200 in https://github.com/appwrite/appwrite/pull/1795
  - Belarusian `be` by @daniloff200 in https://github.com/appwrite/appwrite/pull/1796
  - Arabic `ar` by @arsangamal in https://github.com/appwrite/appwrite/pull/1800
  - Malay `ms` by @izqalan in https://github.com/appwrite/appwrite/pull/1806
  - Gujarati `gu` by @honeykpatel in https://github.com/appwrite/appwrite/pull/1808
  - Polish `pl` by @achromik in https://github.com/appwrite/appwrite/pull/1811
  - Malayalam `ml` by @anoopmsivadas in https://github.com/appwrite/appwrite/pull/1813
  - Croatian `hr` by @mbos2 in https://github.com/appwrite/appwrite/pull/1825
  - Danish `da` by @Ganzabahl in https://github.com/appwrite/appwrite/pull/1829
  - French `fr` by @Olyno in https://github.com/appwrite/appwrite/pull/1771
  - Spanish `es` by @chuiizeet in https://github.com/appwrite/appwrite/pull/1833
  - Vietnamese `vt` by @hdkhoasgt in https://github.com/appwrite/appwrite/pull/1880
  - Kannada `kn` by @Nikhil-1503 in https://github.com/appwrite/appwrite/pull/1840
  - Finnish `fi` by @minna-xD in https://github.com/appwrite/appwrite/pull/1847
  - Thai `th` by @teeradon43 in https://github.com/appwrite/appwrite/pull/1851
  - Persian `fa` by @aerabi in https://github.com/appwrite/appwrite/pull/1878
  - Norwegian `no` by @NeonSpork in https://github.com/appwrite/appwrite/pull/1871
  - Norwegian (Nynorsk) `nn` by @NeonSpork in https://github.com/appwrite/appwrite/pull/2019
  - Norwegian (Bokmål) `nb` by @Exouxas in https://github.com/appwrite/appwrite/pull/1877
  - Dutch `nl` by @ArtixAllMighty in https://github.com/appwrite/appwrite/pull/1879
  - Sanskrit `sa` by @Rutam21 in https://github.com/appwrite/appwrite/pull/1895
  - Nepali `ne` by @TheLearneer in https://github.com/appwrite/appwrite/pull/1807
  - Swedish `sv` by @didair in https://github.com/appwrite/appwrite/pull/1948
  - Hindi `hi` by @willtryagain in https://github.com/appwrite/appwrite/pull/1810
  - Luxembourgish `lb` by @OscarRG in https://github.com/appwrite/appwrite/pull/1857
  - Catalan `ca` by @und1n3 in https://github.com/appwrite/appwrite/pull/1875
  - Chinese (Taiwan) `zh-TW` by @HelloSeaNation in https://github.com/appwrite/appwrite/pull/2134
  - Chinese (PRC)	`zh-CN` by @HelloSeaNation in https://github.com/appwrite/appwrite/pull/1836
  - Bihari `bh` by @dazzlerkumar in https://github.com/appwrite/appwrite/pull/1841
  - Romanian `ro` by @cristina-sirbu in https://github.com/appwrite/appwrite/pull/1868
  - Slovak `sk` by @jakubhi in https://github.com/appwrite/appwrite/pull/1958
  - Greek `el` by @kostapappas in https://github.com/appwrite/appwrite/pull/1992
  - Assamese `as` by @PrerakMathur20 in https://github.com/appwrite/appwrite/pull/2023
  - Esperanto `eo` by @tacoelho in https://github.com/appwrite/appwrite/pull/1927
  - Irish `ga` by @ivernus in https://github.com/appwrite/appwrite/pull/2178
  - Azerbaijani `az` by @aerabi in https://github.com/appwrite/appwrite/pull/2129
  - Latvian `lv` by @RReiso in https://github.com/appwrite/appwrite/pull/2022
  - Lithuanian `lt` by @mantasio in https://github.com/appwrite/appwrite/pull/2018
  - Japanese `jp` by @takmar in https://github.com/appwrite/appwrite/pull/2177
- Added new audio mime-types for viewing audio files on browsers by @eldadfux in https://github.com/appwrite/appwrite/pull/2239

## Bugs
- Fixed `sum` description by @eldadfux in https://github.com/appwrite/appwrite/pull/1659
- Fixed `Add Team Membership` parameter order by @deshankoswatte in https://github.com/appwrite/appwrite/pull/1818
- Fixed Storage File Preview on mobile devices by @m1ga in https://github.com/appwrite/appwrite/pull/2230
- Fixed `top-left` gravity on `Get File Preview` endpoint by @lohanidamodar in https://github.com/appwrite/appwrite/pull/2249

# Version 0.10.4

## Bugs
- Fixed another memory leak in realtime service (#1627)

# Version 0.10.3

## Bugs
- Fixed memory leak in realtime service (#1606)
- Fixed function execution output now being UTF-8 encoded before saved (#1607)
# Version 0.10.2

## Bugs
- Fixed SSL certificates status not being updated (#1592)
- Fixed failing team invites on console (#1580)

# Version 0.10.1

## Bugs
- Improved error messages on Migration regarding invalid document structures (#1576)
- Fixed Console SDK endpoint to work with multiple proxies (#1575)
- Fixed last function environments variables being corrupt (#1577)
- Fixed `_APP_FUNCTIONS_CPUS` variable for cloud functions (#1568)

# Version 0.10.0

## Features
- Added Realtime (#948)
- Added Realtime statistics to the console (#948)
- Added Magic URL login (#1552)
- Refactored E-Mail template (#1422)
- Improved locale management (#1440)
- Added `$permissions` to execution response (#948)
- Switch from using Docker CLI to Docker API by integrating [utopia-php/orchestration](https://github.com/utopia-php/orchestration) (#1420)
- Added DOCKERHUB_PULL_USERNAME, DOCKERHUB_PULL_PASSWORD and DOCKERHUB_PULL_EMAIL env variables for pulling from private DockerHub repos (#1420)
- Added `updateName`, `updateEmail` and `updatePassword` to Users service and console (#1547)

## Bugs
- Fixed MariaDB timeout after 24 hours (#1510)
- Fixed upgrading installation with customized `docker-compose.yml` file (#1513)
- Fixed usage stats on the dashboard displaying invalid total users count (#1514)
# Version 0.9.4
## Security

- Fixed security vulnerability that exposes project ID's from other admin users (#1453)


# Version 0.9.3

## Bugs

- Fixed Abuse Limit keys for JWT and E-Mail confirmation (#1434)

# Version 0.9.2

## Bugs

- Fixed JWT session validation (#1408)
- Fixed passing valid JWT session to Cloud Functions (#1421)
- Fixed race condition when uploading and extracting bigger Cloud Functions (#1419)

# Version 0.9.1

## Bugs

- Fixed PDO Connection timeout (#1385)
- Removed unnecessary `app` resource and replace with `utopia` (#1384)
- Fixed missing quote in Functions Worker logs (#1375)

# Version 0.9.0

## Features

- Added support for Android
- Added a new Cloud Functions runtime for
  - Java 16.0
  - Java 11.0
  - Node 16.0
  - Dart 2.13
- Added a new gravity option when croping storage images using the file preview endpoint (#1260)
- Upgraded GEOIP DB file to Jun 2021 release (#1256)
- Added file created date to file info on the console (#1183)
- Added file size to file info on the console (#1183)
- Added internal support for connection pools for improved performance (#1278)
- Added new abstraction for workers executable files (#1276)
- Added a new API in the Users API to allow you to force update your user verification status (#1223)
- Using a fixed commit to avoid breaking changes for imagemagick extension (#1274)
- Updated the design of all the email templates (#1225)
- Refactored Devices page in Console: (#1167)
  - Renamed *Devices* to *Sessions*
  - Add Provider Icon to each Session
  - Add Anonymous Account Placeholder
- Upgraded phpmailer version to 6.5.0 (#1317)
- Upgraded telegraf docker image version to v1.2.0
- Added new environment variables to the `telegraf` service: (#1202)
  - _APP_INFLUXDB_HOST
  - _APP_INFLUXDB_PORT
- Added `expires` parameter to Account Recovery and Email Validation URL's
- Added new endpoint to get a session based on it's ID (#1294)
- Added added a new version param to the migration script (#1342)
- Improved Queue Interval for all workers from 5 seconds to 1 (#1308 Thanks to @Meldiron)

## Breaking Changes (Read before upgrading!)
- Renamed `env` param on `/v1/functions` to `runtime` (#1314)
- Renamed `deleteUser` method in all SDKs to `delete` (#1216)

## Bugs

- Fixed bug causing runtimes conflict and hanging executions when max Functions containers limit passed (#1288)
- Fixed 404 error when removing a project member on the Appwrite console (#1214)
- Fixed Swoole buffer output size to allow downloading files bigger than allowed size (#1189)
- Fixed ClamAV status when anti virus is not running (#1188)
- Fixed deleteSession which was removing cookieFallback from the localstorage on any logout instead of current session (#1206)
- Fixed Nepal flag (#1173)
- Fixed a bug in the Twitch OAuth adapter (#1209)
- Fixed missing session object when OAuth session creation event is triggered (#1208)
- Fixed bug where we didn't ignore the email case, converted all emails to lowercase internally (#1243)
- Fixed a console bug where you can't click a user with no name, added a placeholder for anonymous users (#1220)
- Fixed unique keys not being updated when changing a user's email address (#1301)
- Fixed a bug where decimal integers where wrongly used with database filters (#1349)

## Security

- Fixed potential XSS injection on the console

# Version 0.8.0

## Features
- Refactoring SSL generation to work on every request so no domain environment variable is required for SSL generation (#1133)
- Added Anonymous Login ([RFC-010](https://github.com/appwrite/rfc/blob/main/010-anonymous-login.md), #914)
- Added events for functions and executions (#971)
- Added JWT support (#784)
- Added ARM support (#726)
- New awesome image preview features, supports borderRadius, borderColor, borderWidth 
- Split token & session models to become 2 different internal entities (#922)
- Added Dart 2.12 as a new Cloud Functions runtime (#989)
- Added option to disable email/password (#947)
- Added option to disable anonymous login (need to merge and apply changed) (#947)
- Added option to disable JWT auth (#947)
- Added option to disable team invites (#947)
- Option to limit number of users (good for app launches + root account PR) (#947)
- Added 2 new endpoints to the projects API to allow new settings 
- Enabled 501 errors (Not Implemented) from the error handler
- Added Python 3.9 as a new Cloud Functions runtime (#1044)
- Added Deno 1.8 as a new Cloud Functions runtime (#989)
- Upgraded to PHP 8.0 (#713)
- ClamAV is now disabled by default to allow lower min requirements for Appwrite (#1064)
- Added a new env var named `_APP_LOCALE` that allow to change the default `en` locale value (#1056)
- Updated all the console bottom control to be consistent. Dropped the `+` icon (#1062)
- Added Response Models for Documents and Preferences (#1075, #1102)
- Added new endpoint to update team membership roles (#1142)
- Removed DB connection from webhooks worker for improved performance (#1150)

## Bugs

- Fixed default value for HTTPS force option
- Fixed form array casting in dashboard (#1070)
- Fixed collection document rule form in dashboard (#1069)
- Bugs in the Teams API:
  - Fixed incorrect audit worker event names (#1143)
  - Increased limit of memberships fetched in `createTeamMembership` to 2000 (#1143)
  - Fixed exception thrown when SSL certificate is already stored in the database (#1151)
- Fixed user delete button in the Appwrite console (#1216)
- Fixed missing placeholder for user name when empty (#1220)

## Breaking Changes (Read before upgrading!)

- Rename `deleteuser` to `delete` on Users Api (#1089)
- Environment variable `_APP_FUNCTIONS_ENVS` renamed to `_APP_FUNCTIONS_RUNTIMES` (#1101)
- Only logged in users can execute functions (for guests, use anonymous login) (#976)
- Only the user who has triggered the execution get access to the relevant execution logs (#1045)
- Function execution environment variable `APPWRITE_FUNCTION_EVENT_PAYLOAD` renamed to `APPWRITE_FUNCTION_EVENT_DATA`  (#1045)
- Function execution environment variable `APPWRITE_FUNCTION_ENV_NAME` renamed to `APPWRITE_FUNCTION_RUNTIME_NAME` (#1101)
- Function execution environment variable `APPWRITE_FUNCTION_ENV_VERSION` renamed to `APPWRITE_FUNCTION_RUNTIME_VERSION` (#1101)
- Introduces rate limits for:
  - Team invite (10 requests in every 60 minutes per IP address) (#1088)
- Rename param `inviteId` to the more accurate `membershipId` in the Teams API (#1129)

# Version 0.7.2

## Features

- When creating new resources from the client API, the current user gets both read & write permissions by default. (#1007)
- Added timestamp to errors logs on the HTTP API container (#1002)
- Added verbose tests output on the terminal and CI (#1006)

## Upgrades

- Upgraded utopia-php/abuse to version 0.4.0
- Upgraded utopia-php/analytics to version 0.2.0

## Bugs

- Fixed certificates worker error on successful operations (#1010)
- Fixed head requests not responding (#998)
- Fixed bug when using auth credential for the Redis container (#993)
- Fixed server warning logs on 3** redirect endpoints (#1013)

# Version 0.7.1

## Features

- Better error logs on appwrite certificates worker
- Added option for Redis authentication
- Force adding a security email on setup
- SMTP is now disabled by default, no dummy SMTP is included in setup
- Added a new endpoint that returns the server and SDKs latest versions numbers #941
- Custom data strings, userId, and JWT available for cloud functions #967

## Upgrades

- Upgraded redis extension lib to version 5.3.3
- Upgraded maxmind extension lib to version 1.10.0
- Upgraded utopia-php/cli lib to version 0.10.0
- Upgraded matomo/device-detector lib to version 4.1.0
- Upgraded dragonmantank/cron-expression lib to version 3.1.0
- Upgraded influxdb/influxdb-php lib to version 1.15.2
- Upgraded phpmailer/phpmailer lib to version 6.3.0
- Upgraded adhocore/jwt lib to version 1.1.2
- Upgraded domnikl/statsd to slickdeals/statsd version 3.0
 
## Bug Fixes

- Updated missing storage env vars
- Fixed a bug, that added a wrong timezone offset to user log timestamps
- Fixed a bug, that Response format header was not added in the access-control-allow-header list.
- Fixed a bug where countryName is unknown on sessions (#933)
- Added missing event users.update.prefs (#952)
- Fixed bug not allowing to reset document permissions (#977)

## Security

- Fixed an XSS vulnerability in the Appwrite console

# Version 0.7.0

## Features

- Improved Webhooks and added new system events - [Learn more]()
- Added response to /locale/languages API with a list of languages (@TorstenDittmann ,[#351](https://github.com/appwrite/appwrite/issues/351))
- Added a new route in the Avatars API to get user initials avatar ([#386](https://github.com/appwrite/appwrite/issues/386))
- Added API response payload structure info and examples to the docs site ([#381](https://github.com/appwrite/appwrite/issues/381))
- Added support for Brotli compression (@PedroCisnerosSantana, @Rohitub222, [#310](https://github.com/appwrite/appwrite/issues/310))
- New deletion worker ([#521](https://github.com/appwrite/appwrite/issues/521))
- New maintenance worker - cleaning up system logs and other optimizations ([#766](https://github.com/appwrite/appwrite/pull/766))
- New email worker - all emails are now sent asynchronously for improved performance (@TorstenDittmann ,[#402](https://github.com/appwrite/appwrite/pull/402))
- Moved all Appwrite container logs to STDOUT & STDERR ([#389](https://github.com/appwrite/appwrite/issues/389))
- New Doctor CLI to debug the Appwrite server ([#415](https://github.com/appwrite/appwrite/issues/415))
- Added container names to docker-compose.yml (@drandell)
- Optimised function execution by using fully-qualified function calls
- Added support for boolean 'true' and 'false' in query strings alongside 1 and 0
- Updated storage calculation to match IEC standards
- Now using Alpine as base Docker image
- Switch standard dev ports to 95xx prefix ([#780](https://github.com/appwrite/appwrite/pull/780))
- User & Team name max length is now 128 chars and not 100 for better API consistency
- Collection name max length is now 128 chars and not 256 for better API consistency
- Project name max length is now 128 chars and not 100 for better API consistency
- Webhook name max length is now 128 chars and not 256 for better API consistency
- API Key name max length is now 128 chars and not 256 for better API consistency
- Task name max length is now 128 chars and not 256 for better API consistency
- Platform name max length is now 128 chars and not 256 for better API consistency
- Webhooks payloads are now exactly the same as any of the API response objects, documentation added
- Added new locale: Marathi -mr (@spielers)
- New and consistent response format for all API object + new response examples in the docs
  - Removed user roles attribute from user object (can be fetched from /v1/teams/memberships) **
  - Removed type attribute from session object response (used only internally)
  - ** - might be changed before merging to master
  - Added fallback option to 0.6 format for backward compatibility with any changes (@christyjacob4 [#772](https://github.com/appwrite/appwrite/pull/772))
- Added option to disable mail sending by setting an empty SMTP host value ([#730](https://github.com/appwrite/appwrite/issues/730))
- Upgraded installation script ([#490](https://github.com/appwrite/appwrite/issues/490))
- Added new environment variables for ClamAV hostname and port ([#780](https://github.com/appwrite/appwrite/pull/780))
- New OAuth adapter for Box.com (@armino-dev - [#420](https://github.com/appwrite/appwrite/issues/410))
- New OAuth adapter for PayPal sandbox  (@armino-dev - [#420](https://github.com/appwrite/appwrite/issues/410))
- New OAuth adapter for Tradeshift  (@armino-dev - [#855](https://github.com/appwrite/appwrite/pull/855))
- New OAuth adapter for Tradeshift sandbox  (@armino-dev - [#855](https://github.com/appwrite/appwrite/pull/855))
- Introducing new permssion types: role:guest & role:member
- Disabled rate-limits on server side integrations
- Refactored migration script 

### User Interface

- Updated grid for OAuth2 providers list in the console ([#413](https://github.com/appwrite/appwrite/issues/413))
- Added Google Fonts to Appwrite for offline availability 
- Added option to delete user from the console (@PineappleIOnic - [#538](https://github.com/appwrite/appwrite/issues/538))
- Added option to delete team from the console ([#380](https://github.com/appwrite/appwrite/issues/380))
- Added option to view team members from the console ([#378](https://github.com/appwrite/appwrite/issues/378))
- Add option to assign new team members to a team from the console and the API ([#379](https://github.com/appwrite/appwrite/issues/379))
- Added Select All Checkbox for on Console API key Scopes Screen ([#477](https://github.com/appwrite/appwrite/issues/477))
- Added pagination and search for team memberships route ([#387](https://github.com/appwrite/appwrite/issues/387))
- Added pagination for projects list on the console home page.
- UI performance & accessibility improvements ([#406](https://github.com/appwrite/appwrite/pull/406))
- New UI micro-interactions and CSS fixes (@AnatoleLucet)
- Added toggle to hide/show secret keys and passwords inside the dashboard (@kodumbeats, [#535](https://github.com/appwrite/appwrite/issues/535))

### Upgrades

- Upgraded QR codes generator library (@PedroCisnerosSantana - [#475](https://github.com/appwrite/appwrite/issues/475))
- Upgraded Traefik image to version 2.3
- Upgraded MariaDB to version 10.5.5
- Upgraded Redis Docker image to version 6.0 (alpine)
- Upgraded Influxdb Docker image to version 1.8 (alpine)
- Upgraded Redis Resque queue library to version 1.3.6 ([#319](https://github.com/appwrite/appwrite/issues/319))
- Upgraded ClamAV container image to version 1.0.11 ([#412](https://github.com/appwrite/appwrite/issues/412))
- Upgraded device detector to version 3.12.6
- Upgraded GEOIP DB file to Feb 2021 release

## Breaking Changes (Read before upgrading!)

- **Deprecated** `first` and `last` query params for documents list route in the database API
- **Deprecated** Deprecated Pubjabi Translations ('pn')
- **Deprecated** `PATCH /account/prefs` is now updating the prefs payload and not just merging it
- **Deprecated** `PATCH /users/:userId/prefs` is now updating the prefs payload and not just merging it
- Switched order of limit and offset params in all the SDKs `listDocuments` method for better consistency
- Default `limit` param value in all the SDKs `listDocuments` method is now 25 for better consistency

## Bug Fixes

- Fixed a bug that caused blocked users to be able to create sessions ([#777](https://github.com/appwrite/appwrite/pull/781))
- Fixed an issue where Special characters in _APP_OPENSSL_KEY_V1_ env caused an error ([#732](https://github.com/appwrite/appwrite/issues/732))
- Fixed an issue where Account webhook doesn't trigger through the console ([#493](https://github.com/appwrite/appwrite/issues/493))
- Fixed case sensitive country flag code ([#526](https://github.com/appwrite/appwrite/issues/526))
- Fixed redirect to Appwrite login page when deep link is provided ([#427](https://github.com/appwrite/appwrite/issues/427))
- Fixed an issue where Creating documents fails for parent documents would result in an error ([#514](https://github.com/appwrite/appwrite/issues/514))
- Fixed an issue with Email Sending Problem using external smtp ([#707](https://github.com/appwrite/appwrite/issues/707))
- Fixed an issue where you could not remove a key from User Prefs ([#316](https://github.com/appwrite/appwrite/issues/316))
- Fixed an issue where events are not fully visible in the console ([#492](https://github.com/appwrite/appwrite/issues/492))
- Fixed an issue where UI would wrongly validate integers ([#394](https://github.com/appwrite/appwrite/issues/394))
- Fixed an issue where graphs were cut in mobile view ([#376](https://github.com/appwrite/appwrite/issues/376))
- Fixed URL issue where console/ would not display list of projects ([#372](https://github.com/appwrite/appwrite/issues/372))
- Fixed output of /v1/health/queue/certificates returning wrong data
- Fixed bug where team members count was wrong in some cases
- Fixed network calculation for uploaded files
- Fixed a UI bug preventing float values in numeric fields
- Fixed scroll positioning when moving rules order up & down
- Fixed missing validation for database documents key length (32 chars)
- Grammar fix for pt-br email templates (@rubensdemelo)
- Fixed update form labels and tooltips for Flutter Android apps
- Fixed missing custom scopes param for OAuth2 session create API route
- Fixed wrong JSON validation when creating and updating database documents
- Fixed bug where max file size was limited to a max of 10MB
- Fixed bug preventing the deletion of the project logo
- Fixed Bug when trying to overwrite OAuth cookie in the Flutter SDK
- Fixed OAuth redirect when using the self-hosted instance default success URL ([#454](https://github.com/appwrite/appwrite/issues/454))
- Fixed bug denying authentication with Github OAuth provider
- Fixed a bug making read permission overwrite write permission in some cases
- Fixed consistent property names in databases by enforcing camel case

## Security

- Access to Health API now requires authentication with an API Key with access to `health.read` scope allowed
- Added option to force HTTPS connection to the Appwrite server (_APP_OPTIONS_FORCE_HTTPS)
- Now using your `_APP_SYSTEM_EMAIL_ADDRESS` as the email address for issuing and renewing SSL certificates
- Block iframe access to Appwrite console using the `X-Frame-Options` header.
- Fixed `roles` param input validator
- API Keys are now stored encrypted 
- Disabled domains whitelist ACL for the Appwrite console

# Version 0.6.2 (PRE-RELEASE)

## Features

- New OAuth adapter for sign-in with Apple

## Bug Fixes

- Fixed custom domain not setting correct domain
- Fixed wrong SDK method type in avatars browser route 
- Fixed bug denied public documents (*) to be accessed by guest users
- Fixed cache-control issue not allowing collection UI to update properly
- Fixed a bug where single permission tag in the console was not being saved
- Added missing webhooks events in the console
- Added missing option to delete project
- Fixed a bug where the session was not set properly when the API used an IP with a non-standard port as hostname
- Fixed bug where requests number on the dashboard was hidden when the number got too long
- Permission fields are not required for file creation or update

## Security

- [low severity] Patch for email library (https://github.com/advisories/GHSA-f7hx-fqxw-rvvj)

# Version 0.6.1 (PRE-RELEASE)

## Bug Fixes

- Fix for Google OAuth provider not working properly
- Fix for login error when using a remote host with non-default ports
- Removed empty activity tab on the document editor
- Changed upgrade script name to ‘migrate’ to better reflect what it actually does
- Fixed bug where after clicking the cancel option in the confirmation dialog the button got disabled
- Fixed a small grammar error in the documents list screen

# Version 0.6.0 (PRE-RELEASE)

## Features

- New collections UI with ability to create and update a collection
- New documents UI with ability to create and update a document
- Added support for Flutter iOS & Android apps
- Added support for default DB document values
- Exposed health API to all the server SDKs
- New locale for Khmer
- Added TypeScript type hinting to the JS SDK (@zevektor)
- Added LTR/RTL support for markdown editor
- Added cachebuster to version number on footer
- New OAuth logos
- Minor fixes to the dark mode theme
- Added JSON view for a project user
- Removed setKey and setMode methods from all client SDKs

## Breaking Changes

- Updated all the REST API query params to be in camelCase
- Normalized locale phone codes response body

## Bug Fixes

- Fixed project users logout button
- Fixed wrong target in database back link

# Version 0.5.3 (PRE-RELEASE)

## Bug Fixes

- Fixed bug where multiple unique attribute were allowed
- Blocked forms from being submitted unlimited times
  
# Version 0.5.2 (PRE-RELEASE)

## Bug Fixes

- Fixed missing attributes in user account

# Version 0.5.1 (PRE-RELEASE)

## Bug Fixes

- Delayed SSL init when server startup for traefik to be ready for HTTP challenge
- Enabled easy access to the upgrade tool from the terminal

# Version 0.5.0 (PRE-RELEASE)

## Features

- Upgraded core API PHP version to 7.4
- New database rule validation options
- Allow non-web platform to skip origin header
- Limited console dashboard to show max 5 alerts at the same time
- Added more webhooks events
- Normalized all webhooks event names
- Added support for SameSite cookie option with fallback cookie for old clients
- Added a new Discord OAuth adapter
- Added a new Twitch OAuth adapter
- Added a new Spotify OAuth adapter
- Added a new Yahoo OAuth adapter
- Added a new Salesforce OAuth adapter
- Added a new Yandex OAuth adapter
- Added a new Paypal OAuth adapter
- Added a new Bitly OAuth adapter
- Upgraded MariaDB image to version 1.0.2
- Upgraded SMTP image to version 1.0.1
- File upload route (POST /v1/storage/files) now accept a single file per request
- Added ENV vars to change system email sender name and address 
- Usage for requests made by project admin in the console are not counted as API usage
- Added ENV var to change default file upload size limit. New default value is 100MB
- Added option to delete file directly from the dashboard
- Added option to view file preview from the dashboard
- Added option to add custom domains with auto SSL certificate generator

## Bug Fixes

- Fixed bug where user status was saved as a string instead of an integer
- Fixed gravatar icons not showing up correctly on the console
- Fixed code location of project not found error
- Fixed bug where tags element would ignore tab key for parsing new tags
- Fixed OAuth login error saying project UID is missing when its not
- Fixed wrong input validation for user preferences

## Breaking Changes

- Merged Auth and Account service route to make the API REST compatible

# Version 0.4.0 (PRE-RELEASE)

## Features

- Added 5 new locales for locale service and email templates (is, ml, th, fo, ph, pn)
- 2 stage Docker build
- Limit HTTP origin check only to browser integrations
- Updated new Brexit date to 31-01-2020
- Added a version number to sign in and signup pages for easier debugging of issues
- Preparation for adding SameSite cookie option support
- Using native Docker volumes for setup for better cross-platform support and easier management of read/write permissions
- Added support for custom SSL certificates without needing to set a proxy
- Added project UID validation check when making an API call. This should help developers to understand our authentication errors better.
- Updated ClamAV docker image to version 1.0.7
- Updated MariaDB docker image to version 1.0.1
- Core Docker image size reduced to 127MB

## Security

- [PHP-FPM security patch fix](https://bugs.php.net/patch-display.php?bug_id=78599&patch=0001-Fix-bug-78599-env_path_info-underflow-can-lead-to-RC.patch&revision=latest) - Upgraded PHP version to 7.3.12 [Major]
- Remove executable permission from avatars files [Minor]
- Updated SDK Generator Twig dependency with security issue: https://www.exploit-db.com/exploits/44102 [Minor]

## Bug Fixes

- New loading message when creating a new project
- Fixed broken redirect URL when creating a new project
- Fixed broken modal when a user password is too short
- Fixed issue denying the creation of session cookies on localhosts with port other than 80 or 443
- Fixed bug that prevented actual file size calculation
- Fixed MariaDB SQL abuse table time field-type
- Fixed error message not showing up in console failed signup
- Fixed cookie session not being appropriately set when accessing the console from IP hostname

## Breaking Changes

- OAuth path is now /auth/login/oauth instead of /auth/oauth and /auth/oauth/callback is now /auth/login/oauth/callback, this is for better consistency with new login methods we will introduce in the future
- Changed file attribute sizeCompressed to sizeActual to better reflect server logic

# Version 0.3.0 (PRE-RELEASE)

## Features

- Added 19 new locales for locale service and email templates (af, ar, bn, cz, hu, hy, jv, ko, lt, ml, no, ru, si, sq, sv, ta, vi, zh-cn, zh-tw)
- New users service routes to allow updates pref and name update
- New OAuth adapters (Amazon, Dropbox, Microsoft, Slack, VK)
- Added support for ES6 require statements in JS SDK
- New Locale API route for fetching a list of continents

## Bug Fixes
- Fix for typos in PT-BR translations
- Fix for UI crash when project user was missing a name
- Fix for it locale including the en templates by mistake
- Fix for UI not showing user's prefs properly
- Fixed 401 unexpected error when no permission passed in creation of a new resource

## Breaking Changes

- users/deleteUsersSession method name changed to users/deleteUserSession in all SDKs for better consistency

# Version 0.2.0 (PRE-RELEASE)

## Features

- Added option to limit access to the Appwrite console
- Added option to disable abuse check and rate limits
- Added input field with the server API endpoint for easy access
- Added new OAuth providers for Google, Bitbucket, and GitLab
- Added 15 new locales for locale service and email templates (cat, de, es, fi, fr, gr, hi, id, it, nl, pt-br, pt-pt, ro, tr, ua)
- Improved test coverage for the project and synced DEV & CI environments settings

## Bug Fixes

- Fixed bug not allowing to update OAuth providers settings
- Fixed some broken API examples in docs
- Fixed bug that caused the Appwrite container to change DB directory file permissions.

## Breaking Changes

- Changed auth service 'redirect' param to 'confirm' for better clarity
- Updated all SDKs to sync with API changes
