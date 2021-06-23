# rollup changelog

## 2.52.2
*2021-06-21*

### Bug Fixes
* Support falsy plugins in types (#4144)
* Do not require return value in renderChunkHook type (#4144)

### Pull Requests
* [#4144](https://github.com/rollup/rollup/pull/4144): Use TypeScript config and improve some types (@lukastaegert)

## 2.52.1
*2021-06-17*

### Bug Fixes
* Fix a memory leak in watch mode (#4142)

### Pull Requests
* [#4142](https://github.com/rollup/rollup/pull/4142): Make array and object prototype singletons immutable for now (@lukastaegert)

## 2.52.0
*2021-06-16*

### Features
* Add `--configPlugin` CLI option to apply plugins to the config file for e.g. TypeScript configs (#3835)
* Add "safest" and "smallest" presets to tree-shaking options for easier configuration (#4131)
* Add `treeshake.correctVarValueBeforeDeclaration` option to deoptimize `var` declarations (#4139)

### Pull Requests
* [#3835](https://github.com/rollup/rollup/pull/3835): Add typescript config support (@TheRealSyler)
* [#4131](https://github.com/rollup/rollup/pull/4131): Add presets to the tree-shaking options (@lukastaegert)
* [#4139](https://github.com/rollup/rollup/pull/4139): Add option to deoptimize var declarations for tree-shaking (@lukastaegert)
* [#4141](https://github.com/rollup/rollup/pull/4141): Update dependencies (@lukastaegert)

## 2.51.2
*2021-06-11*

### Bug Fixes
* Include modules imported from no-treeshake modules even if they would be empty (#4138)

### Pull Requests
* [#4138](https://github.com/rollup/rollup/pull/4138): Include all dependencies from modules with no-treeshake (@lukastaegert)

## 2.51.1
*2021-06-08*

### Bug Fixes
* Fix error when using `defineConfig` (#4134)

### Pull Requests
* [#4134](https://github.com/rollup/rollup/pull/4134): export `rollup.defineConfig` at runtime (@mshrtsr)

## 2.51.0
*2021-06-06*

### Features
* Add a helper for IntelliSense support in config files (#4127)

### Bug Fixes
* Improve performance when generating source maps (#4122)

### Pull Requests
* [#4122](https://github.com/rollup/rollup/pull/4122): User Map to optimize performance (@izevo)
* [#4127](https://github.com/rollup/rollup/pull/4127): Export defineConfig defines the auxiliary function of the configuration (@rxliuli)

## 2.50.6
*2021-06-03*

### Bug Fixes
* Do not consider the object spread operator as side effect when `propertyReadSideEffects` are false (#4119)
* Detect side effects when returning thenables from async arrow functions (#4120)

### Pull Requests
* [#4119](https://github.com/rollup/rollup/pull/4119): Respect propertyReadSideEffects in spread elements (@lukastaegert)
* [#4120](https://github.com/rollup/rollup/pull/4120): Detect async arrow thenable side effects (@lukastaegert)

## 2.50.5
*2021-05-30*

### Bug Fixes
* Detect side effects when accessing thenables (#4115)

### Pull Requests
* [#4114](https://github.com/rollup/rollup/pull/4114): use `colorette` instead of `turbocolor` (@ryuever)
* [#4115](https://github.com/rollup/rollup/pull/4115): Tracks side effects of thenables (@lukastaegert)

## 2.50.4
*2021-05-29*

### Bug Fixes
* Fix a situation where tree-shaking would stop including nodes prematurely (#4111)
* Track mutations and accessor side effects when using `__proto__` in an object literal (#4112)
* Check for getter effects when spreading an object (#4113)

### Pull Requests
* [#4111](https://github.com/rollup/rollup/pull/4111): Always request a new tree-shaking pass when deoptimizations of a node are first included (@lukastaegert)
* [#4112](https://github.com/rollup/rollup/pull/4112): Actually set the prototype when using a __proto__ property (@lukastaegert)
* [#4113](https://github.com/rollup/rollup/pull/4113): Track access side effects when using object spread operator (@lukastaegert)

## 2.50.3
*2021-05-28*

### Bug Fixes
* Wrap parentheses around leading elements in simplified sequence expressions if this would otherwise lead to invalid code (#4110)
* Do not associate block soped variables in catch clauses with the clause parameter (#4108)
* Do not associate hoisted variables in catch clauses with outside variables if they match the parameter (#4108)
* Use correct "this" context for tagged template literal member expressions in simplified sequences (#4110)

### Pull Requests
* [#4108](https://github.com/rollup/rollup/pull/4108): Correctly handle catch declarations (@lukastaegert)
* [#4110](https://github.com/rollup/rollup/pull/4110): Invalid sequence expression simplification (@lukastaegert)

## 2.50.2
*2021-05-27*

### Bug Fixes
* Avoid unnecessary side effects when using methods like `.filter` and `.map` (#4103)
* Avoid crash when a module with moduleSideEffects no-treeshake imports a tree-shaken module (#4104)

### Pull Requests
* [#4103](https://github.com/rollup/rollup/pull/4103): Do not track side-effect-free array methods as side effects (@lukastaegert)
* [#4104](https://github.com/rollup/rollup/pull/4104): Fix crash when using inlineDynamicImports with no-treeshake (@lukastaegert)

## 2.50.1
*2021-05-26*

### Bug Fixes
* Do not associate pure annotations in simplified expressions with wrong elements (#4095)
* Prevent invalid code when simplified conditionals start with an IIFE function expression (#4099)

### Pull Requests
* [#4095](https://github.com/rollup/rollup/pull/4095): Correctly associate pure annotations and remove invalid ones (@lukastaegert)
* [#4099](https://github.com/rollup/rollup/pull/4099): Wrap leading function expression iifes in conditionals (@lukastaegert)

## 2.50.0
*2021-05-25*

### Features
* Only include last elements of comma expressions if they are used or have side effects (#4087)

### Bug Fixes
* Prevent a crash that could occur when calling object methods (#4091)

### Pull Requests
* [#4085](https://github.com/rollup/rollup/pull/4085): Switch to ESLint (@lukastaegert)
* [#4087](https://github.com/rollup/rollup/pull/4087): Drop unused last sequence element (@lukastaegert)
* [#4091](https://github.com/rollup/rollup/pull/4091): Prevent crash for recursive "this" deoptimization (@lukastaegert)

## 2.49.0
*2021-05-23*

### Features
* Detect side-effect-free static class methods and properties (#4018)
* Detect side-effect-free array elements (#4018)
* Do not apply deoptimizations from dead code (#4018)

### Bug Fixes
* Handle side effect detection for getters and setters added in untracked code (#4018)
* Track "this" mutations for methods, getters and setters (#4018)

### Pull Requests
* [#4018](https://github.com/rollup/rollup/pull/4018): Class method effects (@marijnh and @lukastaegert)

## 2.48.0
*2021-05-15*

### Features
* Add replacement to conditionally insert asset extensions in `entryFileNames` when preserving modules (#4077)

### Bug Fixes
* Fix crash when dynamically assigning to namespace members (#4070)
* Do not associate pure annotations in front of a semi-colon or comma with succeeding code (#4068)

### Pull Requests
* [#4068](https://github.com/rollup/rollup/pull/4068): ignore invalid trailing pure annotations (@kzc)
* [#4070](https://github.com/rollup/rollup/pull/4070): undefined `deoptimizePath` when the first element is empty string (@si3nloong)
* [#4071](https://github.com/rollup/rollup/pull/4071): add node.js v16 support (@dnalborczyk)
* [#4077](https://github.com/rollup/rollup/pull/4077): Add assetExtname replacement in entryFileNames (@BPScott)
* [#4080](https://github.com/rollup/rollup/pull/4080): Added Rollup logo in README.md (@priyanshurav)
* [#4081](https://github.com/rollup/rollup/pull/4081): fix comment regarding invalid annotation handling (@kzc)

## 2.47.0
*2021-05-04*

### Features
* Warn about ambiguous imports from combined external namespace reexports (#4064)
* In case of combined namespace reexports, always prefer local exports over external namespaces (#4064)
* Treat conflicting names in local namespace reexports as undefined (#4064)

### Pull Requests
* [#4064](https://github.com/rollup/rollup/pull/4064): Prefer locally defined exports and reexports over external namespaces (@lukastaegert)

## 2.46.0
*2021-04-29*

### Features
* Add option to disable file name sanitation (#4058)
* Add information about importers to unused external import warning (#4054)

### Pull Requests
* [#4042](https://github.com/rollup/rollup/pull/4042): Use Github actions only (@lukastaegert)
* [#4045](https://github.com/rollup/rollup/pull/4045): Fix REPL artefact branch reference (@lukastaegert)
* [#4046](https://github.com/rollup/rollup/pull/4046): Use codecov action for coverage (@lukastaegert)
* [#4054](https://github.com/rollup/rollup/pull/4054): Add to `UNUSED_EXTERNAL_IMPORT` warning information about the origin of the problem (@cawa-93)
* [#4058](https://github.com/rollup/rollup/pull/4058): Add sanitizeFileName option (@guybedford)

## 2.45.2
*2021-04-13*

### Bug Fixes
* Do not user a dynamic entry file name for naming a manual chunk (#4040)

### Pull Requests
* [#4040](https://github.com/rollup/rollup/pull/4040): Prioritize manual chunk name over dynamic entry id (@lukastaegert)

## 2.45.1
*2021-04-10*

### Bug Fixes
* Handle falsy return values from async plugin options hooks (#4039)

### Pull Requests
* [#4039](https://github.com/rollup/rollup/pull/4039): Do not fail when returning null or undefined from an async options hook (@lukastaegert)

## 2.45.0
*2021-04-09*

### Features
* Support private class instance methods and accessors (#4034)

### Pull Requests
* [#4034](https://github.com/rollup/rollup/pull/4034): feat: add support for private class methods (@dnalborczyk)

## 2.44.0
*2021-03-29*

### Features
* Add a new option `makeAbsoluteExternalsRelative` to opt out of renormalizing absolute external ids to relative ids (#4021)
* Extend the `resolveId` plugin hook to allow forcing or preventing renormalization of absolute external ids (#4021)
* Make the rendered code of individual modules available in the generated bundle (#4028)

### Bug Fixes
* Handle objects with `__proto__` properties correctly (#4019)

### Pull Requests
* [#4019](https://github.com/rollup/rollup/pull/4019): Deoptimize ObjectExpression when a `__proto__` property is present (@marijnh)
* [#4021](https://github.com/rollup/rollup/pull/4021): Improve absolute path handling (@lukastaegert)
* [#4026](https://github.com/rollup/rollup/pull/4026): chore: fix vscode launch config (change tdd to bdd) (@jameslahm)
* [#4027](https://github.com/rollup/rollup/pull/4027): Post comment for PRs from forks (@lukastaegert)
* [#4028](https://github.com/rollup/rollup/pull/4028): Expose rendered module code to generateBundle hook (@btd)

## 2.43.1
*2021-03-28*

### Bug Fixes
* Prevent infinite recursions in certain scenarios when calling object properties (#4025)

### Pull Requests
* [#4025](https://github.com/rollup/rollup/pull/4025): Handle recursive this mutation detection (@lukastaegert)

## 2.43.0
*2021-03-27*

### Features
* Track side effects of function properties in objects for better tree-shaking (#4011)

### Pull Requests
* [#4011](https://github.com/rollup/rollup/pull/4011): Disable pessimistic object deoptimization for calls when the called function doesn't ref this (@marijnh)
* [#4012](https://github.com/rollup/rollup/pull/4012): fix `sourcemap` reference in docs (@tjenkinson)
* [#4015](https://github.com/rollup/rollup/pull/4015): Use SIGTERM instead of SIGINT to kill test child processes in tests (@marijnh)

## 2.42.4
*2021-03-24*

### Bug Fixes
* Do not discard plugin return values when using perf option (#4010)

### Pull Requests
* [#4010](https://github.com/rollup/rollup/pull/4010): Return hook result inside promise with async timer end (@SuperOleg39)

## 2.42.3
*2021-03-22*

### Bug Fixes
* Do not ignore `#__PURE__` comments in front of optional chaining expressions (#4007)

### Pull Requests
* [#4007](https://github.com/rollup/rollup/pull/4007): Tree-shake pure call expressions with optional chaining (@lukastaegert)

## 2.42.2
*2021-03-22*

### Bug Fixes
* Use correct import.meta.url in relative imports from transpiled config files (#4005)

### Pull Requests
* [#4005](https://github.com/rollup/rollup/pull/4005): Use correct import.meta.url in config files (@lukastaegert)

## 2.42.1
*2021-03-20*

### Bug Fixes
* Do not produce unhandled Promise rejections when plugins throw while using the `perf` option (#4004)

### Pull Requests
* [#4004](https://github.com/rollup/rollup/pull/4004): Fixed unhandled promise rejections (@gluck)

## 2.42.0
*2021-03-19*

### Features
* Prevent infinite loops when several plugins are using `this.resolve` in their resolveId hook (#4000)

### Pull Requests
* [#4000](https://github.com/rollup/rollup/pull/4000): Break infinite loops in this.resolve (@lukastaegert)

## 2.41.5
*2021-03-18*

### Bug Fixes
* Make sure unused property accesses of external namespaces can be tree-shaken (#4001)

### Pull Requests
* [#4001](https://github.com/rollup/rollup/pull/4001): Do not count accessing members of an external namespace as side-effects (@lukastaegert)

## 2.41.4
*2021-03-16*

### Bug Fixes
* Do not replace external namespace imports with individual named imports to avoid changing behaviour with regard to missing exports (#3999)

### Pull Requests
* [#3999](https://github.com/rollup/rollup/pull/3999): Allow to safely probe external namespaces (@lukastaegert)

## 2.41.3
*2021-03-16*

### Bug Fixes
* Always retain arguments passed to empty object pattern parameters (#3998)

### Pull Requests
* [#3998](https://github.com/rollup/rollup/pull/3998): Do not create invalid code if a function argument is an empty object pattern (@lukastaegert)

## 2.41.3
*2021-03-16*

### Bug Fixes
* Always retain arguments passed to empty object pattern parameters (#3998)

### Pull Requests
* [#3998](https://github.com/rollup/rollup/pull/3998): Do not create invalid code if a function argument is an empty object pattern (@lukastaegert)

## 2.41.2
*2021-03-12*

### Bug Fixes
* Also remove sourcemaps comments if plugins return a pre-made ast (#3987)

### Pull Requests
* [#3987](https://github.com/rollup/rollup/pull/3987): Change removal of sourcemap comment (@yannayl)

## 2.41.1
*2021-03-11*

### Pull Requests
* [#3990](https://github.com/rollup/rollup/pull/3990): Add browser sourcemap and remove log (@lukastaegert)

## 2.41.0
*2021-03-09*

### Features
* Add option to `treeshake.propertyReadSideEffects` to keep all property accesses (#3985)

### Bug Fixes
* Also respect pure comment annotations when a plugin provides an AST in the transform hook provided they use this.parse (#3981)

### Pull Requests
* [#3981](https://github.com/rollup/rollup/pull/3981): Move pure comment annotation to Graph.contextParse (@yannayl)
* [#3985](https://github.com/rollup/rollup/pull/3985): implement --treeshake.propertyReadSideEffects=always to handle getters with side effects (@kzc)

## 2.40.0
*2021-02-26*

### Features
* Make sure that entry point variable names take precedence over variable names in dependencies when deconflicting (#3977)

### Bug Fixes
* Replace `:` in generated file names to prevent invalid files on Windows (#3972)

### Pull Requests
* [#3972](https://github.com/rollup/rollup/pull/3972): Don't allow `:` in file names (@lukastaegert)
* [#3976](https://github.com/rollup/rollup/pull/3976): Add soft breaks to guide to improve mobile experience (@lukastaegert)
* [#3977](https://github.com/rollup/rollup/pull/3977): Reverse module deconflicting order (@lukastaegert)

## 2.39.1
*2021-02-23*

### Bug Fixes
* Make sure local variables named Symbol, Object or Promise do not conflict with code generated by Rollup (#3971)

### Pull Requests
* [#3964](https://github.com/rollup/rollup/pull/3964): Remove extra word (@jamonholmgren)
* [#3971](https://github.com/rollup/rollup/pull/3971): Avoid conflicts with local variables named Symbol, Object, Promise (@lukastaegert)

## 2.39.0
*2021-02-12*

### Features
* Add "validate" option to verify generated chunks are valid JavaScript (#3952)

### Bug Fixes
* Always add exports properties for uninitialized named exports (#3957)
* Allow using an external namespace reexport together with named exports (#3959)
* Avoid invalid generated code in certain scenarios with SystemJS exports (#3960)

### Pull Requests
* [#3952](https://github.com/rollup/rollup/pull/3952): implement `validate` output option and `--validate` CLI option (@kzc)
* [#3956](https://github.com/rollup/rollup/pull/3956): Update dependencies, fix fsevents issue (@lukastaegert)
* [#3957](https://github.com/rollup/rollup/pull/3957): Make sure uninitialised exports turn up via .hasOwnProperty for non-ES formats (@lukastaegert)
* [#3959](https://github.com/rollup/rollup/pull/3959): Allow overriding individual exports of reexported external namespaces (@lukastaegert)
* [#3960](https://github.com/rollup/rollup/pull/3960): Make sure system exports are valid JavaScript
  (@lukastaegert)

## 2.38.5
*2021-02-05*

### Bug Fixes
* Prevent invalid code when simplifying assignments and delcarations (#3951)
* Prevent behaviour-changing line-breaks when simplifying assignments in return statements (#3951)
* Slightly improve white-space rendering when simplifying certain expressions (#3951)

### Pull Requests
* [#3951](https://github.com/rollup/rollup/pull/3951): Wrap simplified assignments if necessary (@lukastaegert)

## 2.38.4
*2021-02-02*

### Bug Fixes
* Do not change logic when tree-shaking declarations in if statements or loops (#3947)

### Pull Requests
* [#3947](https://github.com/rollup/rollup/pull/3947): Do not tear apart declarations in loop or if bodies (@lukastaegert)

## 2.38.3
*2021-02-01*

### Bug Fixes
* Prevent an unexpected live-binding when default exporting a synthetic named export (#3946)

### Pull Requests
* [#3945](https://github.com/rollup/rollup/pull/3945): Upgrade chokidar and fsevents for Apple M1 compatibility (@threepointone)
* [#3946](https://github.com/rollup/rollup/pull/3946): Make sure default exports snapshot synthetic named exports (@lukastaegert)

## 2.38.2
*2021-01-31*

### Bug Fixes
* Do not generate invalid code for partially tree-shaken declarations in for loops (#3943)
* Always include function bodies of functions in side-effect-free modules (#3944)

### Pull Requests
* [#3943](https://github.com/rollup/rollup/pull/3943): Do not partially tree-shake unused declarations in for loops (@lukastaegert)
* [#3944](https://github.com/rollup/rollup/pull/3944): Correctly include functions with side effects from side-effect-free modules (@lukastaegert)

## 2.38.1
*2021-01-28*

### Bug Fixes
* Fix internal error when resolving a missing entry point in the browser build (#3935)

### Pull Requests
* [#3935](https://github.com/rollup/rollup/pull/3935): fix: remove isolated resolve() for compat with browser distribution (@cmorten and @lukastaegert)
* [#3936](https://github.com/rollup/rollup/pull/3936): Ensure test after() callback is always executed (@Benjamin-Dobell)
* [#3937](https://github.com/rollup/rollup/pull/3937): Modernize references to other software (@ludofischer)

## 2.38.0
*2021-01-22*

### Features
* Entirely remove declared variables that only have an initializer side effect (#3933)

### Pull Requests
* [#3933](https://github.com/rollup/rollup/pull/3933): Tree-shake unused declarations while keeping initializer side-effects (@lukastaegert)

## 2.37.1
*2021-01-20*

### Pull Requests
* [#3929](https://github.com/rollup/rollup/pull/3929): Deduplicate acorn import (@lukastaegert)

## 2.37.0
*2021-01-19*

### Features
* Always check modules for side effects that only indirectly reexport a used variable (#3840)
* Warn if a circular dependency would cause wrong execution order when preserving modules (#3840)

### Bug Fixes
* Allow consuming synthetic exports via modules that reexport a namespace (#3894)
* Do not crash for circular default reexports (#3840)
* Do not crash for circular synthetic namespaces (#3840)
* Improve circular dependency execution order in certain scenarios (#3840)

### Pull Requests
* [#3840](https://github.com/rollup/rollup/pull/3840): Improve circular dependency execution order (@lukastaegert)
* [#3894](https://github.com/rollup/rollup/pull/3894): Always respect synthetic namespaces in namespace reexport (@lukastaegert)

## 2.36.2
*2021-01-16*

### Bug Fixes
* Fix an issue where invalid code was generated for unused assignments with side effects (#3926)

### Pull Requests
* [#3926](https://github.com/rollup/rollup/pull/3926): Correctly simplify assignments with parentheses (@lukastaegert)

## 2.36.1
*2021-01-06*

### Bug Fixes
* Solve issues that result in invalid code when partially removing assignments (#3921)

### Pull Requests
* [#3921](https://github.com/rollup/rollup/pull/3921): Prevent invalid code when removing assignment target of side-effectful object expression (@lukastaegert)

## 2.36.0
*2021-01-05*

### Features
* Support partial tree-shaking of chained assignments and unused assignment targets (#3919)

### Pull Requests
* [#3919](https://github.com/rollup/rollup/pull/3919): Treeshake chained assignment expressions (@lukastaegert)

## 2.35.1
*2020-12-14*

### Bug Fixes
* Allow closing the bundle when watching in case of generate errors by adding the bundle to the error event (#3909)
* Automatically close all bundles on generate errors when watching and using the CLI (#3909)
* Try to create remaining bundles when watching and one of them throws (#3909)

### Pull Requests
* [#3909](https://github.com/rollup/rollup/pull/3909): Forward bundle through watch error events (@lukastaegert)

## 2.35.0
*2020-12-14*

### Features
* Add `closeBundle` hook that is triggered by `bundle.close()` in the JS API (#3883)

### Pull Requests
* [#3883](https://github.com/rollup/rollup/pull/3883): Revert pattern to folder export (@intrnl)

## 2.34.2
*2020-12-06*

### Bug Fixes
* Revert pattern export change (#3898)

### Pull Requests
* [#3898](https://github.com/rollup/rollup/pull/3898): Revert pattern to folder export (@lukastaegert)

## 2.34.1
*2020-12-03*

### Bug Fixes
* Avoid Node deprecation warning by using a pattern export for nested Rollup files (#3896)

### Pull Requests
* [#3887](https://github.com/rollup/rollup/pull/3887): Run memory leak test on all systems (@lukastaegert)
* [#3892](https://github.com/rollup/rollup/pull/3892): Add pull_request to windows github actions (@shellscape)
* [#3893](https://github.com/rollup/rollup/pull/3893): Update dependencies (@lukastaegert)
* [#3896](https://github.com/rollup/rollup/pull/3896): Replace deprecated folder package export with pattern export (@lukastaegert)

## 2.34.0
*2020-11-29*

### Features
* Support RequireJS comaptible AMD ids in code-splitting builds via amd.autoId (#3867)
* Allow adding an AMD id base path (#3867)

### Bug Fixes
* Warn when using an constant AMD id in a code-splitting build (#3867)

### Pull Requests
* [#3867](https://github.com/rollup/rollup/pull/3867): Implement amd.autoId/amd.basePath options (@tjenkinson)

## 2.33.3
*2020-11-18*

### Bug Fixes
* Do not use `.js` extension when importing AMD files from a UMD bundle (#3872)

### Pull Requests
* [#3861](https://github.com/rollup/rollup/pull/3861): Update chat/support links (@shellscape)
* [#3872](https://github.com/rollup/rollup/pull/3872): Also removeExtensionFromRelativeAmdId in UMD finaliser (@tjenkinson)

## 2.33.2
*2020-11-14*

### Bug Fixes
* Fix an issue where statements were ignored after a conditional return in a labeled statement (#3871)

### Pull Requests
* [#3871](https://github.com/rollup/rollup/pull/3871): Correctly track label usage in try statements (@Amareis)

## 2.33.1
*2020-11-02*

### Bug Fixes
* Add `syntheticNamedExports` to `this.getModuleInfo` to align with documentation (#3847)

### Pull Requests
* [#3847](https://github.com/rollup/rollup/pull/3847): Expose syntheticNamedExports to ModuleInfo (@Amareis)
* [#3852](https://github.com/rollup/rollup/pull/3852): Fix typo on docs (@jpsc)

## 2.33.0
*2020-11-01*

### Features
* Add parameter to "watchChange" hook to denote if a change was an addition, update or deletion (#3841)
* Add "closeWatcher" hook to allow plugins to clean up resources when the watcher is closed (#3841)
* Add "this.getWatchFiles" function to plugin context to get the current set of watched files (#3841)

### Pull Requests
* [#3841](https://github.com/rollup/rollup/pull/3841): Improved watcher hooks (@Amareis)
* [#3848](https://github.com/rollup/rollup/pull/3848): Add options hook to asyncpluginhooks (@intrnl)

## 2.32.1
*2020-10-21*

### Bug Fixes
* Print warning location for plugin warnings if only `loc` is supplied (#3824)

### Pull Requests
* [#3824](https://github.com/rollup/rollup/pull/3824): plugin warnings not showing warning.loc (@luciotato)

## 2.32.0
*2020-10-16*

### Features
* Extend `preserveEntrySignatures` with a value `"exports-only"` to allow extension only if an entry does not have exports (#3823)

### Pull Requests
* [#3823](https://github.com/rollup/rollup/pull/3823): Add "exports-only" option to preserveSignature (@lukastaegert)

## 2.31.0
*2020-10-15*

### Features
* When using the `output.moduleToStringTag` option, also add the tag to entries with exports and simulated external namespaces (#3822)
* Add the `__esModule` interop marker to IIFE global variables unless `output.esModule` is turned off (#3822)

### Pull Requests
* [#3822](https://github.com/rollup/rollup/pull/3822): Add module toStringTag to entries and interop namespaces (@lukastaegert)

## 2.30.0
*2020-10-13*

### Features
* Add `moduleParsed` hook that is called for each module once code and AST are available (#3813)
* Include code and AST in `this.getModuleInfo` (#3813)

### Bug Fixes
* Provide the original Acorn AST instead of the internal one when resolving dynamic imports that contain non-trivial expressions (#3813)

### Pull Requests
* [#3813](https://github.com/rollup/rollup/pull/3813): Add moduleParsed plugin hook (@lukastaegert)
* [#3815](https://github.com/rollup/rollup/pull/3815): Docs: wile => while (@tjenkinson)
* [#3817](https://github.com/rollup/rollup/pull/3817): Docs: fix code snippet (@codefrau)
* [#3818](https://github.com/rollup/rollup/pull/3818): Update documentation on configuring Babel, removing the section on passing '{"modules": false}' as that is no longer needed since Babel 7 (@Robin-Hoodie)

## 2.29.0
*2020-10-08*

### Features
* Allow passing custom options to other plugins via `this.resolve` (#3807)
* Allow attaching custom meta information to modules when resolving, loading or transforming (#3807)
* Do not throw but return `null` when using `this.getModuleInfo` for an unknown id (#3807)

### Bug Fixes
* Trigger build in watch mode when files are added to a watched directory (#3812)
* Make `code` optional when transforming modules (#3807)

### Pull Requests
* [#3807](https://github.com/rollup/rollup/pull/3807): Implement new APIs for inter-plugin communication (@lukastaegert)
* [#3808](https://github.com/rollup/rollup/pull/3808): Document that the default value of --format is 'es' (@jameshfisher)
* [#3812](https://github.com/rollup/rollup/pull/3812): Watch: listen for new files added to a directory (@dmitrage)

## 2.28.2
*2020-09-24*

### Bug Fixes
* Fix a source of possible variable name conflicts when using preserveModules with SystemJS (#3796)

### Pull Requests
* [#3792](https://github.com/rollup/rollup/pull/3792): add documentation for output.PreserveModulesRoot (@davidroeca)
* [#3796](https://github.com/rollup/rollup/pull/3796): Fix SystemJS default variable conflict (@lukastaegert)

## 2.28.1
*2020-09-21*

### Bug Fixes
* Fix a path slash issue when using the preserveModulesRoot option on Windows (#3791)

### Pull Requests
* [#3791](https://github.com/rollup/rollup/pull/3791): Fix preserveModulesRoot path on Windows (@lukastaegert)

## 2.28.0
*2020-09-21*

### Features
* Add an option to treat modules at a given path as located at root when preserving modules (#3786)

### Pull Requests
* [#3786](https://github.com/rollup/rollup/pull/3786): Add preserveModulesRoot config option (@davidroeca)

## 2.27.1
*2020-09-17*

### Bug Fixes
* Do not fail when using ES module imports in symlinked config files (#3783)

### Pull Requests
* [#3783](https://github.com/rollup/rollup/pull/3783): Handle loading symlinked config files (@lukastaegert)

## 2.27.0
*2020-09-16*

### Features
* Support specifying a file extension when reading from stdin (#3775)

### Bug Fixes
* Do not break logic if a branch with hoisted variables is tree-shaken in an else-if statement (#3782)

### Pull Requests
* [#3770](https://github.com/rollup/rollup/pull/3770): Docs: Exception for babel plugin and commonjs plugin (@jsk7)
* [#3775](https://github.com/rollup/rollup/pull/3775): add ability to specify stdin file extension via --stdin=ext (@kzc)
* [#3782](https://github.com/rollup/rollup/pull/3782): Handle hoisted variables in dead branches of nested if statements (@lukastaegert)

## 2.26.11
*2020-09-08*

### Bug Fixes
* Do not fail for unknown nodes as if statement branches (#3769)

### Pull Requests
* [#3769](https://github.com/rollup/rollup/pull/3769): Handle debugger statements as if-statement branches (@lukastaegert)

## 2.26.10
*2020-09-04*

### Bug Fixes
* Do not create invalid code when simplifying expressions in return statements that contain line comments (#3762)

### Pull Requests
* [#3757](https://github.com/rollup/rollup/pull/3757): Fix api docs loadconfigfile (@maxwell8888)
* [#3762](https://github.com/rollup/rollup/pull/3762): Handle line-comments when removing line-breaks to prevent ASI (@lukastaegert)

## 2.26.9
*2020-09-01*

### Bug Fixes
* Add regular expression support to watch include/exclude types (#3754)

### Pull Requests
* [#3754](https://github.com/rollup/rollup/pull/3754): Add RegExp to the include and exclude fields of the WatcherOptions type (@dagda1)
* [#3756](https://github.com/rollup/rollup/pull/3756): Update FAQ: I think it was meant "external" instead of "other-entry.js" (@madacol)

## 2.26.8
*2020-08-29*

### Bug Fixes
* Make sure that both unresolved and resolved ids are passed to the `external` option in all cases (#3753)

### Pull Requests
* [#3753](https://github.com/rollup/rollup/pull/3753): Also pass resolved ids to external if they use the object for (@lukastaegert)

## 2.26.7
*2020-08-28*

### Bug Fixes
* Avoid invalid code when rendering hoisted variable declarations from dead branches (#3752)
* Mark the `options` parameter of `this.parse` as optional for TypeScript plugins (#3750)

### Pull Requests
* [#3750](https://github.com/rollup/rollup/pull/3750): Make `options` of `PluginContext#parse` optional (@intrnl)
* [#3752](https://github.com/rollup/rollup/pull/3752): Extract hoisted variables from dead branches (@lukastaegert)

## 2.26.6
*2020-08-27*

### Bug Fixes
* Avoid conflicts between the namespace of synthetic named exports and local variables (#3747)

### Pull Requests
* [#3747](https://github.com/rollup/rollup/pull/3747): Properly deconflict synthetic named exports (@lukastaegert)

## 2.26.5
*2020-08-22*

### Bug Fixes
* Use correctly deconflicted variable names for reexported namespaces in ES formats (#3742)

### Pull Requests
* [#3742](https://github.com/rollup/rollup/pull/3742): Avoid variable name conflict when reexporting several namespaces from a chunk (@lukastaegert)

## 2.26.4
*2020-08-19*

### Bug Fixes
* Fix a situation where invalid code was rendered when dynamically importing a module with synthetic named exports when preserving modules (#3738)
* Create a proper namespace object when in a non-es format, a namespace is imported from a chunk with `default` export mode (#3738)
* Use the same variable when in a chunk, a namespace is both imported and reexported as a binding (#3738)
* Do not include the synthetic namespace in static entry points unless it is actually used (#3738)
* Make sure the chunking of one output does not interfere with the namespace objects of another output (#3738)

### Pull Requests
* [#3738](https://github.com/rollup/rollup/pull/3738): Improve synthetic entry handling (@lukastaegert)

## 2.26.3
*2020-08-16*

### Bug Fixes
* Fix a situation where line-breaks in a nested simplified conditional expression could result in broken code (#3734)

### Pull Requests
* [#3734](https://github.com/rollup/rollup/pull/3734): Prevent ASI when simplifying a nested logical expression (@lukastaegert)

## 2.26.2
*2020-08-16*

### Bug Fixes
* Fix a situation where line-breaks in a simplified conditional expression could result in broken code (#3732)

### Pull Requests
* [#3732](https://github.com/rollup/rollup/pull/3732): Prevent unintended ASI for nested conditionals (@lukastaegert)

## 2.26.1
*2020-08-16*

### Bug Fixes
* Correctly render external namespace imports when only generating SystemJS output (#3731)

### Pull Requests
* [#3731](https://github.com/rollup/rollup/pull/3731): Render system namespace import (@sastan and @lukastaegert)

## 2.26.0
*2020-08-15*

### Features
* Add a new entry `importedBindings` to the bundle information to list bindings per dependency (#3722)

### Bug Fixes
* Do not render an invalid UMD wrapper when no bindings are imported from a dependency (#3724)
* Avoid situations where removing the `else` branch from an `if` statement might catch the `else` branch from another one (#3725)

### Pull Requests
* [#3722](https://github.com/rollup/rollup/pull/3722): Add import specifiers to bundle information (@lukastaegert)
* [#3724](https://github.com/rollup/rollup/pull/3724): Fix missing variables for UMD and IIFE builds (@lukastaegert)
* [#3725](https://github.com/rollup/rollup/pull/3725): Do not entirely remove else branch if another else branch might accidentally be referenced (@lukastaegert)

## 2.25.0
*2020-08-14*

### Features
* Add `--failAfterWarnings` CLI flag that will complete builds with warnings but return an error at the end (#3712)

### Pull Requests
* [#3712](https://github.com/rollup/rollup/pull/3712): Implement `--failAfterWarnings` flag (@tjenkinson)

## 2.24.0
*2020-08-13*

### Features
* Allow defining interop per dependency via a function (#3710)
* Support interop "auto" as a more compatible version of "true" (#3710)
* Support interop "default" and "esModule" to avoid unnecessary interop helpers (#3710)
* Support interop "defaultOnly" for simplified helpers and Node ESM interop compatible output (#3710)
* Respect interop option for external dynamic imports (#3710)
* Support live-bindings for external default imports in non-ES formats unless "externalLiveBindings" is "false" (#3710)
* Use shared default interop helpers for AMD, UMD and IIFE formats (#3710)
* Avoid unnecessarily deconflicted module variables in non-ES formats (#3710)
* Freeze generated interop namespace objects (#3710)
* Always mark interop helpers as pure (#3710)
* Avoid default export interop if there is already an interop namespace object (#3710)
* Sort all `require` statements to the top in CommonJS output for easier back-transpilation to ES modules by other tools (#3710)

### Bug Fixes
* Handle accessing `super` in static class fields (#3720)
* Deconflict the names of helper variables introduced for interop (#3710)
* Generate proper namespace objects for static namespace imports in non-ES formats (#3710)
* Do not add unused interop helpers when using the renderDynamicImport hook (#3710)

### Pull Requests
* [#3710](https://github.com/rollup/rollup/pull/3710): Rework interop handling (@lukastaegert)
* [#3720](https://github.com/rollup/rollup/pull/3720): Handle super in static class fields (@lukastaegert)

## 2.23.1
*2020-08-07*

### Bug Fixes
* Fix an issue where dynamically importing an entry point could return a malformed namespace for CJS and AMD formats (#3709)

### Pull Requests
* [#3709](https://github.com/rollup/rollup/pull/3709): Properly construct namespace when dynamically importing chunks with facades in default export mode (@lukastaegert)

## 2.23.0
*2020-07-23*

### Features
* Handle environments with only globalThis in UMD output (#3691)

### Pull Requests
* [#3691](https://github.com/rollup/rollup/pull/3691): Check for globalThis in UMD wrapper (@lukastaegert)

## 2.22.2
*2020-07-21*

### Bug Fixes
* Always generate correct exports when an implicit entry is reexporting from another module (#3688)

### Pull Requests
* [#3688](https://github.com/rollup/rollup/pull/3688): Include all relevant modules to generate reexports for implicit dependencies (@lukastaegert)

## 2.22.1
*2020-07-18*

### Bug Fixes
* Remove unused arguments when calling a conditional expression (#3680)

### Pull Requests
* [#3680](https://github.com/rollup/rollup/pull/3680): Allow tree-shaking of arguments of functions that are returned by conditional expressions (@lukastaegert)

## 2.22.0
*2020-07-18*

### Features
* Allow resolving synthetic named exports via an arbitrary export name (#3657)
* Display a warning when the user does not explicitly select an export mode and would generate a chunk with default export mode when targeting CommonJS (#3657)

### Pull Requests
* [#3657](https://github.com/rollup/rollup/pull/3657): Add basic support for using a non-default export for syntheticNamedExports (@lukastaegert)
* [#3659](https://github.com/rollup/rollup/pull/3659): Warn when implicitly using default export mode (@lukastaegert)

## 2.21.0
*2020-07-07*

### Features
* Allow plugins to disable tree-shaking for individual modules to ensure even empty modules are associated with chunks (#3663)

### Pull Requests
* [#3663](https://github.com/rollup/rollup/pull/3663): Disable treeshaking per module (@lukastaegert)

## 2.20.0
*2020-07-06*

### Features
* Support using a function to generate different chunk and asset naming patterns per chunk or asset (#3658)
* Add `referencedFiles` property to the chunk info in generateBundle to list referenced assets (#3661)

### Pull Requests
* [#3658](https://github.com/rollup/rollup/pull/3658): Add ability to use a function that returns a pattern string in all places where you could use a pattern string before (@frank-dspeed)
* [#3661](https://github.com/rollup/rollup/pull/3661): Add referenced files to bundle (@lukastaegert)

## 2.19.0
*2020-07-05*

### Features
* Allow plugins to return a Promise in the options hook (#3660)

### Pull Requests
* [#3660](https://github.com/rollup/rollup/pull/3660): Make options hooks async (@TomerAberbach)

## 2.18.2
*2020-07-02*

### Bug Fixes
* Do not remove spread element args when the corresponding positional parameter is unused (#3652)

### Pull Requests
* [#3652](https://github.com/rollup/rollup/pull/3652): Do not tree-shake arguments that contain a spread element (@lukastaegert)

## 2.18.1
*2020-06-26*

### Bug Fixes
* Make sure synthetic exports are present when a module is imported dynamically (#3648)
* Strip the `rollup-plugin-` prefix off the plugin name when looking for the plugin export in a CLI plugin without a default export (#3647)
* Convert plugin names with dashes to camel case when looking for the plugin export in a CLI plugin without a default export (#3647)

### Pull Requests
* [#3647](https://github.com/rollup/rollup/pull/3647): Strip rollup-plugin prefix to find named plugin exports, throw when export cannot be found (@lukastaegert)
* [#3648](https://github.com/rollup/rollup/pull/3648): Always create a dynamic namespace object when a module with synthetic named exports is imported dynamically (@lukastaegert)

## 2.18.0
*2020-06-22*

### Features
* `inlineDynamicImports`, `manualChunks` and `preserveModules` can now be used as output options (#3645)
* Use sourcemaps for certain warnings that reference source code locations (#3645)

### Bug Fixes
* `this.getFileName` will now always return the correct file name for chunks when multiple outputs are created (#3645)

### Pull Requests
* [#3645](https://github.com/rollup/rollup/pull/3645): Per output chunking (@lukastaegert)

## 2.17.1
*2020-06-19*

### Bug Fixes
* Properly resolve accessing properties of namespace members again (#3643)

### Pull Requests
* [#3643](https://github.com/rollup/rollup/pull/3643): Fix accessing nested properties of namespaces (@lukastaegert)

## 2.17.0
*2020-06-17*

### Features
* When importing Rollup via package.exports, always fall back to the browser ESM build for non-Node environments (#3634)
* Create more efficient code when handling namespace mutations (#3637)

### Bug Fixes
* Fix a severe performance regression when the same module is imported by a lot of modules (#3641)
* Properly escape special characters in imports (#3638)

### Pull Requests
* [#3634](https://github.com/rollup/rollup/pull/3634): Set browser build in exports (@guybedford)
* [#3637](https://github.com/rollup/rollup/pull/3637): Do not include the whole namespace when illegally mutating a namespace (@lukastaegert)
* [#3638](https://github.com/rollup/rollup/pull/3638): Support backslash escaping, retain exact newline escaping (@guybedford)
* [#3641](https://github.com/rollup/rollup/pull/3641): Fix performance regression when a file is imported by many importers (@lukastaegert)

## 2.16.1
*2020-06-13*

### Bug Fixes
* Do not produce invalid code when an external or chunk id contain quotes or line-breaks (#3632)
* Do not fail but emit a warning when mutating a namespace object (#3633)

### Pull Requests
* [#3632](https://github.com/rollup/rollup/pull/3632): Handle single quote escaping in ids (@guybedford)
* [#3633](https://github.com/rollup/rollup/pull/3633): Turn namespace assignment error into a warning (@guybedford)

## 2.16.0
*2020-06-12*

### Features
* Add support for numeric separators (#3626)
* Switch to finalized ESTree optional chaining AST (#3628)

### Pull Requests
* [#3626](https://github.com/rollup/rollup/pull/3626): Support numeric separator (@TrySound)
* [#3628](https://github.com/rollup/rollup/pull/3628): Acorn 7.3.0 upgrade (@guybedford)
* [#3631](https://github.com/rollup/rollup/pull/3631): Improve discoverability of `manualChunks` for code splitting (@zlamma)

## 2.15.0
*2020-06-08*

### Features
* Allow to skip watching some configs via `watch: false` (#3620)
* Provide the resolved sourcemap path to `sourcemapPathTransform` (#3617)

### Pull Requests
* [#3617](https://github.com/rollup/rollup/pull/3617): Update sourcemapPathTransform to also take the path to the sourcemap file as a second argument (@dgoldstein0)
* [#3620](https://github.com/rollup/rollup/pull/3620): Rollup watch only one config in exported array (@luwol03)

## 2.14.0
*2020-06-07*

### Features
* Make `this.meta.watchMode` available for plugins to detect watch mode (#3616)

### Bug Fixes
* Handle exporting the same binding with different names in SystemJS (#3575)

### Pull Requests
* [#3575](https://github.com/rollup/rollup/pull/3575): Handle some cases of duplicate export bindings (@joeljeske)
* [#3616](https://github.com/rollup/rollup/pull/3616): Make watch mode available in plugins (@lukastaegert)

## 2.13.1
*2020-06-04*

### Bug Fixes
* Prevent conflicts in SystemJS when `module` is used as a top-level variable (#3614)

### Pull Requests
* [#3614](https://github.com/rollup/rollup/pull/3614): Handle system reserved identifier dedupes (@guybedford)

## 2.13.0
*2020-06-03*

### Features
* Allow to specify that an emitted chunk is only loaded after a given module has loaded to improve chunking (#3606)

### Pull Requests
* [#3606](https://github.com/rollup/rollup/pull/3606): Enable specifying implicit dependencies when emitting chunks (@lukastaegert)

## 2.12.1
*2020-06-02*

### Bug Fixes
* Render valid imports when chunk names correspond to directory names in virtual setups (#3609)

### Pull Requests
* [#3609](https://github.com/rollup/rollup/pull/3609): Handle imports from chunks with names that correspond to parent directory names of other chunks (@guybedford)

## 2.12.0
*2020-05-31*

### Features
* Add an option `--waitForBundleInput` to let the build wait until all entry point files are available before starting (#3577)

### Pull Requests
* [#3577](https://github.com/rollup/rollup/pull/3577): Wait for bundle input option (@Heerschop)

## 2.11.2
*2020-05-28*

### Bug Fixes
* Include side-effects in the second argument of `Array.from` (#3604)

### Pull Requests
* [#3604](https://github.com/rollup/rollup/pull/3604): Mark `Array.from` as side-effectful, use two-argument Array.from when mapping Sets (@lukastaegert)

## 2.11.1
*2020-05-28*

### Bug Fixes
* Also include side-effects in files that are marked as side-effect-free if they contain an included default export that is reexported (#3602)

### Pull Requests
* [#3602](https://github.com/rollup/rollup/pull/3602): Handle side-effects next to side-effect-free default exports in case the default export is reexported (@lukastaegert)

## 2.11.0
*2020-05-27*

### Features
* Add basic support for optional chaining (#3582)
* Provide a normalized set of options with proper default values to `buildStart` and `renderStart` (#3597)
* Do not count adding properties to the prototype of an unused class as a side-effect (#3598)
* Support providing `null` for empty setters in SystemJS via option (#3592)

### Bug Fixes
* Do not fail when using a `/*#__PURE__*/` annotation inside a class field (#3599)
* Allow using `--watch` and `--treeshake` together with sub-options such as `--watch.clearScreen` on the command line (#3597)

### Pull Requests
* [#3582](https://github.com/rollup/rollup/pull/3582): Support optional chaining via acorn fork(@guybedford)
* [#3592](https://github.com/rollup/rollup/pull/3592): System format optional setters(@guybedford)
* [#3597](https://github.com/rollup/rollup/pull/3597): Provide normalized options (@lukastaegert)
* [#3598](https://github.com/rollup/rollup/pull/3598): Treeshake prototype modifications in classes (@lukastaegert)
* [#3599](https://github.com/rollup/rollup/pull/3599): Retain pure annotations in class fields (@lukastaegert)
* [#3601](https://github.com/rollup/rollup/pull/3601): Fix white-space in docs (@tu4mo)

## 2.10.9
*2020-05-24*

### Bug Fixes
* Prevent invalid exports when facades are created (#3590)

### Pull Requests
* [#3590](https://github.com/rollup/rollup/pull/3590): Prevent unneeded exports when entry facades are created and ensure all exported variables in facades are imported (@lukastaegert)

## 2.10.8
*2020-05-23*

### Bug Fixes
* Fix issues when synthetic named exports are reexported as default exports (#3586)

### Pull Requests
* [#3584](https://github.com/rollup/rollup/pull/3584): Clarify documentation for `output.paths` (@jacksteamdev)
* [#3585](https://github.com/rollup/rollup/pull/3585): Target Node.js v14 instead of v13 in Windows tests (@mangs)
* [#3586](https://github.com/rollup/rollup/pull/3586): Handle default reexports of synthetic named exports over several stages (@lukastaegert)

## 2.10.7
*2020-05-22*

### Bug Fixes
* Handle modules re-exporting namespaces without further own code (#3576)

### Pull Requests
* [#3576](https://github.com/rollup/rollup/pull/3576): Fix "cannot read exports of undefined" error (@guybedford)

## 2.10.6
*2020-05-22*

### Bug Fixes
* Fix some issues around class fields (#3580)
* Prevent a maximum call stack error when a called entity references itself in its declaration (#3581)

### Pull Requests
* [#3580](https://github.com/rollup/rollup/pull/3580): Update acorn class features (@guybedford)
* [#3581](https://github.com/rollup/rollup/pull/3581): Do not fail when including call arguments of recursively defined variables (@lukastaegert)

## 2.10.5
*2020-05-19*

### Bug Fixes
* Do not remove side-effects that may influence an included default export declaration when side-effects are disabled (#3572)

### Pull Requests
* [#3572](https://github.com/rollup/rollup/pull/3572): Observe side-effects in files containing a default export declaration that reexports a variable (@lukastaegert)

## 2.10.4
*2020-05-19*

### Bug Fixes
* Tree-shake unused classes with fields unless there are side-effects in the field declaration (#3569)

### Pull Requests
* [#3569](https://github.com/rollup/rollup/pull/3569): Make sure unused classes with fields are tree-shaken if possible (@lukastaegert)

## 2.10.3
*2020-05-18*

### Bug Fixes
* Validate return value of sourcemapPathTransform option (#3561)

### Pull Requests
* [#3561](https://github.com/rollup/rollup/pull/3561): Throw error if sourcemapPathTransform-option does not return a string (@Simonwep)

## 2.10.2
*2020-05-15*

### Bug Fixes
* Properly include calls to mutating array methods in certain scenarios (#3559)

### Pull Requests
* [#3559](https://github.com/rollup/rollup/pull/3559): Make sure UnknownFooExpressions are included when referenced as return values in a MultiExpression (@lukastaegert)

## 2.10.1
*2020-05-15*

### Bug Fixes
* Do not throw when "undefined" is used as a default export (#3558)

### Pull Requests
* [#3558](https://github.com/rollup/rollup/pull/3558): Properly handle default exporting undefined (@lukastaegert)

## 2.10.0
*2020-05-13*

### Features
* Avoid unnecessary empty imports from a facade chunk to the original chunk (#3552)
* Pin facade creation order so that if several user-defined chunks reference the same module, the first always becomes the "actual" chunk while the later ones become facades (#3552)

### Bug Fixes
* Do not omit reexports from secondary chunks when creating facades for entry points without hoisting transitive dependencies (#3552)

### Pull Requests
* [#3552](https://github.com/rollup/rollup/pull/3552): Avoid unnecessary facade dependency inlining (@guybedford)

## 2.9.1
*2020-05-11*

### Bug Fixes
* Do not create unintended live-bindings or invalid reexports when reexporting global variables (#3550)

### Pull Requests
* [#3550](https://github.com/rollup/rollup/pull/3550): Track updates of globals that are exported as default (@lukastaegert)

## 2.9.0
*2020-05-10*

### Features
* Add ids of static and dynamic imports to `this.getModuleInfo` (#3542)
* Provide `getModuleInfo` and `getModuleIds` to `manualChunks` functions (#3542)
* Add nullish coalescing support (#3548)
* Make the rebuild delay in watch mode configurable and set the default to `0` for snappy rebuilds (#3502)
* Add `this.getModuleIds` to the plugin context as future replacement for `this.moduleIds` (#3542)

### Pull Requests
* [#3502](https://github.com/rollup/rollup/pull/3502): Configurable build delay (@mattdesl)
* [#3542](https://github.com/rollup/rollup/pull/3542): Extend manualChunks API (@lukastaegert)
* [#3548](https://github.com/rollup/rollup/pull/3548): Support nullish coalescing with tree-shaking (@lukastaegert)

## 2.8.2
*2020-05-07*

### Bug Fixes
* Avoid invalid code when simplifying the body of a shorthand arrow function expression (#3540)

### Pull Requests
* [#3540](https://github.com/rollup/rollup/pull/3540): Wrap object expressions in parentheses if they become children of an arrow function expression (@lukastaegert)

## 2.8.1
*2020-05-07*

### Bug Fixes
* Allow using plugins on CLI that are exported as `exports.default` (#3529)
* Do not fail side-effect detection in nested callbacks of builtins (#3539)

### Pull Requests
* [#3529](https://github.com/rollup/rollup/pull/3529): Use default named export with plugins (@NotWoods)
* [#3539](https://github.com/rollup/rollup/pull/3539): Track call side-effects both by entity and CallExpression to avoid untracked side-effects in nested calls (@lukastaegert)

## 2.8.0
*2020-05-06*

### Features
* When a dynamically imported chunk contains more exports than the imported module namespace, do not create a facade chunk but an inline namespace (#3535)

### Bug Fixes
* Do not execute dynamically imported code before synchronous code in the importing module when generating CommonJS (#3535)

### Pull Requests
* [#3535](https://github.com/rollup/rollup/pull/3535): Avoid dynamic facade chunks (@lukastaegert)

## 2.7.6
*2020-04-30*

### Bug Fixes
* Fix a type issue when a default export references a global variable (#3526)

### Pull Requests
* [#3526](https://github.com/rollup/rollup/pull/3526): Handles default exporting global variables (@lukastaegert)

## 2.7.5
*2020-04-29*

### Bug Fixes
* Prevent infinite loop when default values of function parameters in a default export contain a slash (#3522)

### Pull Requests
* [#3522](https://github.com/rollup/rollup/pull/3522): Avoid infinite loop when finding position for id insertion in default export (@lukastaegert)

## 2.7.4
*2020-04-29*

### Bug Fixes
* Fix an issue where wrong variable names were used when preserving modules (#3521)

### Pull Requests
* [#3521](https://github.com/rollup/rollup/pull/3521): Fix and improve default export alias logic (@lukastaegert)

## 2.7.3
*2020-04-27*

### Bug Fixes
* Do not access `__proto__` when running Rollup (#3518)

### Pull Requests
* [#3518](https://github.com/rollup/rollup/pull/3518): use acorn-class-fields and acorn-static-class-features from npm (@nitsky)

## 2.7.2
*2020-04-22*

### Bug Fixes
* Prevent an infinite loop when creating separate manual chunks with circular dependencies (#3510)
* Do not fail if "super" is used in the definition of a class field (#3511)
* Throw if a plugin tries to emit a file with an absolute Windows path (#3509)

### Pull Requests
* [#3509](https://github.com/rollup/rollup/pull/3509): Ban emitFile via absolute paths on Windows OS (@SASUKE40)
* [#3510](https://github.com/rollup/rollup/pull/3510): Do not fail for circular imports between manual chunks (@lukastaegert)
* [#3511](https://github.com/rollup/rollup/pull/3511): Support "super" in class fields (@lukastaegert)

## 2.7.1
*2020-04-21*

### Bug Fixes
* Use correct path for dynamic imports if `output.paths` is used (#3508)

### Pull Requests
* [#3508](https://github.com/rollup/rollup/pull/3508): Respect output.paths in dynamic imports (@lukastaegert)

## 2.7.0
*2020-04-21*

### Features
* Add `preserveEntrySignatures` option to control how exports of entry points are handled (#3498)
* Add `preserveSignature` flag to `this.emitFile` to control exports of emitted chunks (#3498)
* Add `output.minifyInternalExports` option to control if internal exports are minified (#3498)

### Pull Requests
* [#3498](https://github.com/rollup/rollup/pull/3498): Add option to configure if entry signatures are preserved (@lukastaegert)

## 2.6.1
*2020-04-12*

### Bug Fixes
* Close watch mode when stdin closes in a non-TTY environment (#3493)

### Pull Requests
* [#3493](https://github.com/rollup/rollup/pull/3493): Ensure --watch mode exits correctly when stdin is closed (@jakesgordon)

## 2.6.0
*2020-04-10*

### Features
* Allow regular expressions to declare external modules (#3482)

### Pull Requests
* [#3482](https://github.com/rollup/rollup/pull/3482): Allow regular expressions in config.external (@johannes-z)

## 2.5.0
This version is identical to 2.4.0

## 2.4.0
*2020-04-09*

### Features
* Add support for most private and public class field features (#3488)

### Bug Fixes
* Do not replace `this` with `undefined` in class field definitions (#3488)

### Pull Requests
* [#3488](https://github.com/rollup/rollup/pull/3488): Rollup class fields support (@guybedford and @lukastaegert)

## 2.3.5
*2020-04-09*

### Bug Fixes
* Never remove labels when tree-shaking is disabled (#3492)

### Pull Requests
* [#3492](https://github.com/rollup/rollup/pull/3492): Always use a new inclusion context when including declarations of variables, always inlcude labels when not treeshaking (@lukastaegert)

## 2.3.4
*2020-04-07*

### Bug Fixes
* Handle re-exporting synthetic exports from entry-points (#3319)
* Fix cross-chunk imports of synthetic exports (#3319)
* Handle namespace objects that contain re-exported synthetic namespaces (#3319)

### Pull Requests
* [#3319](https://github.com/rollup/rollup/pull/3319): Handle re-exports of synthetic named exports (@manucorporat and @lukastaegert)

## 2.3.3
*2020-04-04*

### Bug Fixes
* Add external namespaces to dynamic namespace objects (#3474)

### Pull Requests
* [#3474](https://github.com/rollup/rollup/pull/3474): Support external star exports on namespace objects (@guybedford)

## 2.3.2
*2020-03-31*

### Bug Fixes
* Only warn but do not fail build when a namespace is called as a function (#3475)
* Make sure pre-existing sourcemap comments are also removed when rebuilding using the cache (#3476)

### Pull Requests
* [#3475](https://github.com/rollup/rollup/pull/3475): Call namespace error as a warning (@guybedford)
* [#3476](https://github.com/rollup/rollup/pull/3476): Store locations for removed comments in cache (@lukastaegert)

## 2.3.1
*2020-03-30*

### Bug Fixes
* Do not fail if the config file returns an function returning a Promise (#3472)

### Pull Requests
* [#3472](https://github.com/rollup/rollup/pull/3472): Fix support for async functions as config (@lukastaegert)

## 2.3.0
*2020-03-29*

### Features
* Do not transpile config files with `.mjs` extension in Node 13+ or `.cjs` extension in any Node version and load them appropriately (#3445)
* Extract helper to load a config file the way rollup does it via `rollup/dist/loadConfigFile` (#3445)

### Bug Fixes
* Keep watching the config file if an error occurs during initial load in watch node (#3445)
* Add a helpful error message when using a transpiled config in a repository with "type": "module" (#3445)

### Pull Requests
* [#3445](https://github.com/rollup/rollup/pull/3445): Support native ESM configs in Node 13, support untranspiled configs (@lukastaegert)
* [#3468](https://github.com/rollup/rollup/pull/3468): Don't use esm output format alias in the documentation (@vsn4ik)

## 2.2.0
*2020-03-24*

### Features
* Add `renderDynamicImport` hook to rewrite dynamic import expressions (#3449)
* Add information about dynamically imported modules to `this.getModuleInfo` (#3449)

### Bug Fixes
* Make file emission work with Uin8Array sources when using Rollup in the browser (#3452)
* Fix types to allow watch to accept an array of configs (#3453)
* Do not strip `.js` extensions from AMD imports when the import is a user-supplied replacement for a non-resolvable dynamic import target (#3453)

### Pull Requests
* [#3449](https://github.com/rollup/rollup/pull/3449): Add hook to rewrite dynamic import expressions (@lukastaegert)
* [#3452](https://github.com/rollup/rollup/pull/3452): Avoid the assumption of Buffer in browser envs (@JoviDeCroock)
* [#3453](https://github.com/rollup/rollup/pull/3453): fix types since watch accepts single or array config (@lukeed)
* [#3456](https://github.com/rollup/rollup/pull/3456): fix SystemJS url in tutorial (@guybedford)

## 2.1.0
*2020-03-18*

### Features
* Allow specifying an importer when emitting files to resolve relative ids (#3442)

### Pull Requests
* [#3442](https://github.com/rollup/rollup/pull/3442): Add optional `importer` parameter to `this.emitFile` (@lukastaegert)

## 2.0.6
*2020-03-13*

### Bug Fixes
* Do not use file names from different outputs when generating sourcemaps using the `dir` option (#3440)

### Pull Requests
* [#3440](https://github.com/rollup/rollup/pull/3440): Use correct file names when writing sourcemaps for multiple outputs (@lukastaegert)

## 2.0.5
*2020-03-12*

### Bug Fixes
* Fix an issue where conditional statements would assume they have the wrong test value (#3438)

### Pull Requests
* [#3438](https://github.com/rollup/rollup/pull/3438): Make sure logical expressions always check the left argument for side-effects (@lukastaegert)

## 2.0.4
*2020-03-12*

### Bug Fixes
* Avoid conflicts between namespace imports when preserving modules (#3435)

### Pull Requests
* [#3435](https://github.com/rollup/rollup/pull/3435): Deconflict multiple `index` imports for ES format using nested export star statements (@kamranayub)

## 2.0.3
*2020-03-10*

### Bug Fixes
* Add type for this.getCombinedSourcemap to transform context (#3431)

### Pull Requests
* [#3377](https://github.com/rollup/rollup/pull/3377): Switch to yargs-parser lib (@jamesgeorge007)
* [#3426](https://github.com/rollup/rollup/pull/3426): Use strict types with PluginDriver (@NotWoods)
* [#3431](https://github.com/rollup/rollup/pull/3431): Add missing type declaration for getCombinedSourcemap (@Anidetrix)
* [#3432](https://github.com/rollup/rollup/pull/3432): Detail how return values from `augmentChunkHash` are used (@jakearchibald)

## 2.0.2
*2020-03-07*

### Bug Fixes
* Make sure the ESM import still works (#3430)

### Pull Requests
* [#3430](https://github.com/rollup/rollup/pull/3430): Fix conditional exports again (@lukastaegert)

## 2.0.1
*2020-03-07*

### Bug Fixes
* Reenable importing rollup in Node 13.0 - 13.7 (#3428)

### Pull Requests
* [#3428](https://github.com/rollup/rollup/pull/3428): Fix conditional exports in Node 13.0 - 13.7 (@lukastaegert)

## 2.0.0
*2020-03-06*

### Breaking Changes
* Rollup now requires at least Node 10 to run, or a sufficiently modern browser (#3346)
* The file structure of Rollup's ESM builds has changed:
  - The main ESM entry point is now at `rollup/dist/es/rollup.js` instead of `rollup/dist/rollup.es.js`
  - The ESM browser build is at `rollup/dist/es/rollup.browser.js` instead of `rollup/dist/rollup.browser.es.js`

  In general, the ESM builds now follow the same naming scheme as the CJS builds but are located in the `rollup/dist/es` subfolder instead of `rollup/dist` (#3391)
* The "watch.chokidar" option no longer accepts a `boolean` value but only an object of parameters that is passed to the bundled Chokidar instance. Chokidar installations by the user will be ignored in favour of the bundled instance (#3331)
* Modules that are completely tree-shaken will no longer be listed as part of any chunks in `generateBundle`
* The `experimentalOptimizeChunks` and `chunkGroupingSize` options have been removed
* [acorn](https://github.com/acornjs/acorn) plugins can only be used if they accept a passed-in acorn instance instead of importing it themselves. See https://github.com/acornjs/acorn/pull/870#issuecomment-527339830 for what needs to be done to make plugins compatible that do not support this yet (#3391)
* Emitted chunks now have the TypeScript type `Uint8Array` instead of `Buffer`. A `Buffer` can still be used, though (#3395)
* The TypeScript types no longer use ESTree types for AST nodes but a very generic type that does not contain information specific to certain node types (#3395)
* The signature of the `writeBundle` plugin hook has been changed to match `generateBundle`: The bundle object is now passed as second parameter instead of first and the first parameter is the output options (#3361)
* The following plugin hooks have been removed:
  - ongenerate: use `generateBundle` instead
  - onwrite: use `writeBundle` instead
  - transformBundle: use `renderChunk` instead
  - transformChunk: use `renderChunk` instead
* You can no longer access `this.watcher` on the plugin context.
* The `transform` hook can no longer return `dependencies`.
* The `treeshake.pureExternalModules` option will now show a deprecation warning when used: use `treeshake.moduleSideEffects: 'no-external'` instead
* Using `import.meta.ROLLUP_ASSET_URL_<..>` and `import.meta.ROLLUP_CHUNK_URL_<..>` in code will now show warnings: use `import.meta.ROLLUP_FILE_URL_<..>` instead
* The `resolveAssetUrl` hook will now show a deprecation warning when used: use `resolveFileUrl` instead
* The following plugin context functions will show warnings when used:
  - `this.emitAsset`: use `this.emitFile`
  - `this.emitChunk`: use `this.emitFile`
  - `this.getAssetFileName`: use `this.getFileName`
  - `this.getChunkFileName`: use `this.getFileName`
  - `this.isExternal`: use `this.resolve`
  - `this.resolveId`: use `this.resolve`
* Directly adding properties to the bundle object in the `generateBundle` is deprecated will show a warning (removing properties is allowed, though): Use `this.emitFile`
* Accessing `chunk.isAsset` on the bundle is deprecated: Use `chunk.type === 'asset'` instead
* The error code for a missing `name` property when targeting UMD has been changed to `MISSING_NAME_OPTION_FOR_IIFE_EXPORT` to emphasize this is needed for the IIFE part of UMD (#3393)

### Features
* Rollup now bundles [Chokidar](https://github.com/paulmillr/chokidar) for a better watch experience (#3331)
* Rollup now bundles [acorn](https://github.com/acornjs/acorn) again, removing its only external dependency (#3391)
* Do not consider empty imports from side-effect-free modules for chunking and hoist side-effect imports if necessary (#3369)
* Rollup can now be imported as an ES module in Node via `import {rollup} from 'rollup'`. Note that this relies on Node's experimental [conditional package exports](https://nodejs.org/dist/latest-v13.x/docs/api/esm.html#esm_conditional_exports) feature and is therefore itself experimental (#3391)
* `systemjs` can be used as format alias for `system` (#3381)

### Bug Fixes
* Unknown output options now trigger a warning when using the JavaScript API (#3352)
* Rollup will no longer introduce Node types into TypeScript projects that do not use them (#3395)
* Generate correct sourcemaps when tree-shaking occurs in a multi-file bundle (#3423)

### Pull Requests
* [#3331](https://github.com/rollup/rollup/pull/3331): Bundle Chokidar (@lukastaegert)
* [#3343](https://github.com/rollup/rollup/pull/3343): Remove experimentalOptimizeChunks (@lukastaegert)
* [#3346](https://github.com/rollup/rollup/pull/3346): Update minimum required Node version to 10 (@lukastaegert)
* [#3352](https://github.com/rollup/rollup/pull/3352): Remove active deprecations (@lukastaegert)
* [#3361](https://github.com/rollup/rollup/pull/3361): Change writeBundle signature to match generateBundle (@lukastaegert)
* [#3369](https://github.com/rollup/rollup/pull/3369): Avoid empty imports from side-effect-free chunks (@lukastaegert)
* [#3381](https://github.com/rollup/rollup/pull/3381): Rename esm to es everywhere, add systemjs alias (@lukastaegert)
* [#3391](https://github.com/rollup/rollup/pull/3391): Bundle acorn, allow importing Rollup as Node ES module, update dependencies (@lukastaegert)
* [#3393](https://github.com/rollup/rollup/pull/3393): Better error code for name-less umd bundle (@rail44)
* [#3395](https://github.com/rollup/rollup/pull/3395): Remove `@types` dependencies (@lukastaegert)
* [#3423](https://github.com/rollup/rollup/pull/3423): Update magic-string and fix sourcemaps (@lukastaegert)

## 1.32.1
*2020-03-06*

### Bug Fixes
* Handle default export detection for AMD and IIFE externals that do not have a prototype (#3420)
* Handle missing whitespace when the else branch of an if-statement is simplified (#3421)
* Mention the importing module when reporting errors for missing named exports (#3401)
* Add code to warning for missing output.name of IIFE bundles (#3372)

### Pull Requests
* [#3372](https://github.com/rollup/rollup/pull/3372): Add warning code for missing output.name of IIFE bundle that has export (@rail44)
* [#3401](https://github.com/rollup/rollup/pull/3401): Missing exports errors now print the importing module (@timiyay)
* [#3418](https://github.com/rollup/rollup/pull/3418): Structure lifecycle hooks, add links to build time hooks (@lukastaegert)
* [#3420](https://github.com/rollup/rollup/pull/3420): Update generated code of getInteropBlock() to work with null prototype objects (@jdalton)
* [#3421](https://github.com/rollup/rollup/pull/3421): Avoid invalid code when "else" branch is simplified (@lukastaegert)

## 1.32.0
*2020-02-28*

### Features
* Allow adding plugins on the command line via `--plugin <plugin>` (#3379)

### Pull Requests
* [#3379](https://github.com/rollup/rollup/pull/3379): introduce CLI --plugin support (@kzc)
* [#3390](https://github.com/rollup/rollup/pull/3390): fix typo: this.addWatchfile (@mistlog)
* [#3392](https://github.com/rollup/rollup/pull/3392): Bump codecov from 3.6.1 to 3.6.5
* [#3404](https://github.com/rollup/rollup/pull/3404): Update resolveFileUrl docs (@jakearchibald)

## 1.31.1
*2020-02-14*

### Bug Fixes
* Make sure errored files are always re-evaluated in watch mode to avoid an issue in the typescript plugin (#3388)

### Pull Requests
* [#3366](https://github.com/rollup/rollup/pull/3366): Correct spelling minifaction to minification (@VictorHom)
* [#3371](https://github.com/rollup/rollup/pull/3371): Adjust bug template to mention REPL.it (@lukastaegert)
* [#3388](https://github.com/rollup/rollup/pull/3388): Run transform hooks again in watch mode on files that errored (@lukastaegert)

## 1.31.0
*2020-01-31*

### Features
* Always disable tree-shaking for asm.js functions to maintain semantics (#3362)

### Pull Requests
* [#3362](https://github.com/rollup/rollup/pull/3362): Preserve asm.js code (@lukastaegert)

## 1.30.1
*2020-01-27*

### Bug Fixes
* Do not mistreat static entgry points as dynamic ones when chunking (#3357)
* Resolve a crash when chunking circular dynamic imports (#3357)

### Pull Requests
* [#3357](https://github.com/rollup/rollup/pull/3357): Resolve issues with circular dynamic entries (@lukastaegert)

## 1.30.0
*2020-01-27*

### Features
* Do not split chunks when dynamically imported modules import modules that are already loaded by all dynamic importers (#3354)
* Add `hoistTransitiveImports` option to disable hoisting imports of static dependencies into entry chunks (#3353)

### Bug Fixes
* Make sure polyfills are always loaded first when each static entry point contains them as first import (#3354)

### Pull Requests
* [#3353](https://github.com/rollup/rollup/pull/3353): Add option to avoid hoisting transitive imports (@lukastaegert)
* [#3354](https://github.com/rollup/rollup/pull/3354): Improve chunking algorithm for dynamic imports (@tjenkinson and @lukastaegert)

## 1.29.1
*2020-01-21*

### Bug Fixes
* Avoid crashes for circular reexports when named exports cannot be found (#3350)

### Pull Requests
* [#3335](https://github.com/rollup/rollup/pull/3335): Fix typo (@robbinworks)
* [#3342](https://github.com/rollup/rollup/pull/3342): Remove ":" from test file names for Windows and update dependencies (@lukastaegert)
* [#3350](https://github.com/rollup/rollup/pull/3350): Properly handle circular reexports (@lukastaegert)

## 1.29.0
*2020-01-08*

### Features
* Enable top-level await by default (#3089)
* Add typings for watch events (#3302)

### Bug Fixes
* Deconflict files that would conflict only on a case-insensitive OS (#3317)
* Do not fail in certain scenarios where a logical expression inside a sequence expression was being directly included (#3327)

### Pull Requests
* [#3089](https://github.com/rollup/rollup/pull/3089): Move top-level await out of experimental (@guybedford)
* [#3302](https://github.com/rollup/rollup/pull/3302): Adds type definitions for RollupWatcher events (@NotWoods)
* [#3317](https://github.com/rollup/rollup/pull/3317): Fix module id conflict on a case insensitive OS (@yesmeck)
* [#3327](https://github.com/rollup/rollup/pull/3327): Handle deoptimizations while a node is being included (@lukastaegert)

## 1.28.0
*2020-01-04*

### Features
* Allow piping in stdin via the command line interface (#3312, #3290)
* Allow plugins to mark modules as having syntheticNamedExports for e.g. better CJS interoperability (#3295)
* Ignore variable reassignments in dead code when tree-shaking to remove more unneeded code (#3212)

### Bug Fixes
* Properly respect tree-shaken code when generating sourcemaps (#3318)

### Pull Requests
* [#3212](https://github.com/rollup/rollup/pull/3212): Handle assignments in dead code (@tjenkinson)
* [#3290](https://github.com/rollup/rollup/pull/3290): Implement stdin input with optional "-" as the file name (@kzc)
* [#3295](https://github.com/rollup/rollup/pull/3295): Add syntheticNamedExports (@manucorporat)
* [#3300](https://github.com/rollup/rollup/pull/3300): Add note about setting `types` in tsconfig file (@tjenkinson)
* [#3303](https://github.com/rollup/rollup/pull/3303): Use ! to assert not-null in TypeScript (@NotWoods)
* [#3312](https://github.com/rollup/rollup/pull/3312): Implement stdin input (@lukastaegert)
* [#3318](https://github.com/rollup/rollup/pull/3318): Update magic-string and other dependencies (@lukastaegert)

## 1.27.14
*2019-12-22*

### Bug Fixes
* Update references to official rollup plugins in error messages (#3297, #3298)

### Pull Requests
* [#3286](https://github.com/rollup/rollup/pull/3286): Update link to JavaScript API documentation (@romankaravia)
* [#3294](https://github.com/rollup/rollup/pull/3294): Update deprecated references to the node-resolve plugin in the documentation (@Vlad-Shcherbina)
* [#3297](https://github.com/rollup/rollup/pull/3297): Update references to rollup-plugin-json (@cprecioso)
* [#3298](https://github.com/rollup/rollup/pull/3298): Update references to official rollup plugins (@cprecioso)

## 1.27.13
*2019-12-14*

### Bug Fixes
* Do not truncate environment variable values at the first colon when using the `--environment` option (#3283)

### Pull Requests
* [#3283](https://github.com/rollup/rollup/pull/3283): Allow environment variables to contain colons (@tlaverdure)

## 1.27.12
*2019-12-13*

### Bug Fixes
* Prevent invalid AMD or SystemJS code when accessing `import.meta` (#3282)

### Pull Requests
* [#3282](https://github.com/rollup/rollup/pull/3282): Always make "module" available for SystemJS and AMD formats if `import.meta` is accessed directly (@lukastaegert)

## 1.27.11
*2019-12-12*

### Bug Fixes
* Resolve a crash due to an infinite loop (#3280)

### Pull Requests
* [#3280](https://github.com/rollup/rollup/pull/3280): Prevent infinite deoptimizations (@lukastaegert)

## 1.27.10
*2019-12-11*

### Bug Fixes
* Keep track of function return values in more situations (#3278)

### Pull Requests
* [#3278](https://github.com/rollup/rollup/pull/3278): Avoid some unnecessary value tracking deoptimizations (@lukastaegert)

## 1.27.9
*2019-12-07*

### Bug Fixes
* Fix an issue where reexports could be missing when preserving modules (#3273)
* Allow turning of color output via NO_COLOR or FORCE_COLOR=0 environment variables (#3272)

### Pull Requests
* [#3272](https://github.com/rollup/rollup/pull/3272): Support either NO_COLOR or FORCE_COLOR=0 to turn off color (@kikonen)
* [#3273](https://github.com/rollup/rollup/pull/3273): Make sure that indirectly reexported modules also become chunk dependencies when preserving modules(@lukastaegert)

## 1.27.8
*2019-12-02*

### Bug Fixes
* Deoptimize objects when a method is called on them to make sure modifications via "this" are observed (#3266)

### Pull Requests
* [#3266](https://github.com/rollup/rollup/pull/3266): Workaround for various object literal mutation bugs (@kzc)

## 1.27.7
*2019-12-01*

### Bug Fixes
* Fix a scenario where a reassignments to computed properties were not tracked (#3267)

### Pull Requests
* [#3267](https://github.com/rollup/rollup/pull/3267): Fix incomplete computed property deoptimization (@lukastaegert)

## 1.27.6
*2019-11-30*

### Bug Fixes
* Use "auto" export mode by default for all modules when preserving modules (#3265)
* Observe "output.exports" when preserving modules and warn for mixed exports if necessary (#3265)

### Pull Requests
* [#3265](https://github.com/rollup/rollup/pull/3265): Use export mode "auto" by default when preserving modules (@lukastaegert)

## 1.27.5
*2019-11-25*

### Bug Fixes
* Make sure namespaces for inlined dynamic imports are treated as variable accesses when deconflicting (#3256)

### Pull Requests
* [#3256](https://github.com/rollup/rollup/pull/3256): Avoid name conflicts when inlining dynamic imports nested in functions (@lukastaegert)
* [#3257](https://github.com/rollup/rollup/pull/3257): Update dependencies (@lukastaegert)

## 1.27.4
*2019-11-22*

### Bug Fixes
* Aggregate circular dependency warnings in the CLI (#3249)
* Do not defer non-aggregated handlers in the CLI (#3249)

### Pull Requests
* [#3249](https://github.com/rollup/rollup/pull/3249): Fix broken Windows CLI tests (@lukastaegert)
* [#3251](https://github.com/rollup/rollup/pull/3251): Add installation as a separate header (@ashrith-kulai)

## 1.27.3
*2019-11-20*

### Bug Fixes
* Provide better warning when empty chunks are created in a code-splitting scenario (#3244)

### Pull Requests
* [#3244](https://github.com/rollup/rollup/pull/3244): Clearer empty chunk warning (@tjenkinson)

## 1.27.2
*2019-11-18*

### Bug Fixes
* Fix an issue where live bindings were not working correctly when using `+=` in SystemJS (#3242)

### Pull Requests
* [#3242](https://github.com/rollup/rollup/pull/3242): Export updated assignments when using shorthand update assignment expressions in SystemJS (@lukastaegert)

## 1.27.1
*2019-11-18*

### Bug Fixes
* Fix an issue where code after a switch-statement with removed cases was erroneously not included (#3241)

### Pull Requests
* [#3237](https://github.com/rollup/rollup/pull/3237): make `acornOptions` optional in `parse()` in docs (@tjenkinson)
* [#3240](https://github.com/rollup/rollup/pull/3240): Update dependencies and fix vulnerability (@lukastaegert)
* [#3241](https://github.com/rollup/rollup/pull/3241): Do not truncate after switch-statement with removed case (@lukastaegert)

## 1.27.0
*2019-11-12*

### Features
* Add support for output-specific plugins (#3218)
* Reenable parallel output processing when using the CLI (#3218)
* Warn if files are emitted that would overwrite previously emitted files (#3218)

### Bug Fixes
* Do not overwrite files emitted in other builds if outputs are generated in parallel (#3218)

### Pull Requests
* [#3218](https://github.com/rollup/rollup/pull/3218): Per output plugins (@lukastaegert)

## 1.26.5
*2019-11-11*

### Bug Fixes
* Fix a regression where it was no longer to pass a certain option format to generate (#3223)

### Pull Requests
* [#3223](https://github.com/rollup/rollup/pull/3223): Allow passing input options to output (@lukastaegert)

## 1.26.4
*2019-11-09*

### Bug Fixes
* Keep watching known files after a plugin error during the initial build (#3219)

### Pull Requests
* [#3216](https://github.com/rollup/rollup/pull/3216): Fix small typo (@kaisermann)
* [#3217](https://github.com/rollup/rollup/pull/3217): Update dependencies and fix security vulnerability (@lukastaegert)
* [#3219](https://github.com/rollup/rollup/pull/3219): Also recover from plugin errors during the initial build (@lukastaegert)

## 1.26.3
*2019-11-02*

### Bug Fixes
* Work around an incompatibility with rollup-plugin-dts (#3211)

### Pull Requests
* [#3211](https://github.com/rollup/rollup/pull/3211): Do no fail if the source attribute is `undefined` in an unused named export (@lukastaegert)

## 1.26.2
*2019-10-31*

### Bug Fixes
* Do not create invalid code when using `treeshake: false` and star re-exports (#3209)

### Pull Requests
* [#3209](https://github.com/rollup/rollup/pull/3209): Also remove export-all declarations when not tree-shaking (@lukastaegert)

## 1.26.1
*2019-10-31*

### Bug Fixes
* Prevent an issue where outputs would overwrite files emitted by other outputs (#3201)
* Do not throw an error if the config file does not have a .js extension (#3204)

### Pull Requests
* [#3201](https://github.com/rollup/rollup/pull/3201): Make the CLI run generate/output in serial (@marijnh)
* [#3204](https://github.com/rollup/rollup/pull/3204): support all config file extensions (.js,.mjs,...) (@arlac77)

## 1.26.0
*2019-10-27*

### Features
* Only warn when no output is provided for an IIFE bundle but still produce valid code (#3181)
* Support reexporting namespaces as a binding (#3193)
* Switch from hash.js to crypto for hashing in the Node build for better performance and support for very large assets (#3194)

### Bug Fixes
* Correctly handle chunks reexporting the same namespace as two different bindings (#3193)

### Pull Requests
* [#3181](https://github.com/rollup/rollup/pull/3181): Remove the need to provide an output name for IIFE bundles (@bterrier)
* [#3193](https://github.com/rollup/rollup/pull/3193): Add support for "export * as name from …" (@lukastaegert)
* [#3194](https://github.com/rollup/rollup/pull/3194): Add support for large assets (> 100 MB) (@SebastianNiemann)

## 1.25.2
*2019-10-23*

### Bug Fixes
* Improve performance of bundled UMD code by adding additional parentheses to enforce eager parsing (#3183)
* Improve types to tolerate passing a Rollup config with multiple outputs to `rollup.rollup` (#3184)

### Pull Requests
* [#3183](https://github.com/rollup/rollup/pull/3183): Add parentheses to factory function of UMD bundles (@ajihyf)
* [#3184](https://github.com/rollup/rollup/pull/3184): RollupOptions accept output as array (@imcotton)

## 1.25.1
*2019-10-20*

### Bug Fixes
* Handle a situation where code was not included after a switch statement (#3178)
* Handle a situation where code was not included after a do-while loop (#3180)
* Do not fail if different outputs emit the same file (#3175)
* Give access to the original acorn error for parse errors (#3176)

### Pull Requests
* [#3175](https://github.com/rollup/rollup/pull/3175): Disable errors for duplicate emitted file names (@marijnh)
* [#3176](https://github.com/rollup/rollup/pull/3176): Add original parser error to rollup error; Update tests (@gribnoysup)
* [#3178](https://github.com/rollup/rollup/pull/3178): Fix switch case not being included correctly (@lukastaegert)
* [#3179](https://github.com/rollup/rollup/pull/3179): Update dependencies (@lukastaegert)
* [#3180](https://github.com/rollup/rollup/pull/3180): Handle conditional breaks in do-while loops with unconditional return (@lukastaegert)

## 1.25.0
*2019-10-18*

### Features
* Remove try-catch if there is no side-effect in the try-block (#3166)
* Omit side-effect-free trailing cases in switch-statements (#3166)
* Remove unused labels (#3170)

### Bug Fixes
* Do not remove code after labeled statements that contain a throw or return if the label is used (#3170)
* Prevent invalid code when expressions are simplified that do not follow a white-space character (#3173)
* Do not remove continue statements inside switch statements (#3166)
* Prevent trailing empty lines when tree-shaking inside switch statements (#3166)

### Pull Requests
* [#3166](https://github.com/rollup/rollup/pull/3166): Better try statement tree shaking (@lukastaegert)
* [#3170](https://github.com/rollup/rollup/pull/3170): Handle optional control flow in labeled statements, remove unused labels (@lukastaegert)
* [#3173](https://github.com/rollup/rollup/pull/3173): Add missing spaces in certain statements and expressions to avoid invalid code (@lukastaegert)

## 1.24.0
*2019-10-15*

### Features
* Respect `break`, `continue`, `return` and `throw` when tree-shaking to detect dead code (#3153)
* Do treat treat hoisted function declarations as "unknown" when checking for call side-effects (#3153)

### Bug Fixes
* Make sure that unknown `import.meta` properties produce valid code in SystemJS (#3152)
* Make sure `treeshake.annotations: false` is respected for class instantiation (#3153)
* Check property access side-effects for class instantiation (#3153)
* Do not suppress break statements inside labeled statements (#3153)

### Pull Requests
* [#3152](https://github.com/rollup/rollup/pull/3152): Allow import.meta.* for systemjs format (@dmail)
* [#3153](https://github.com/rollup/rollup/pull/3153): Get rid of immutable.js and implement tree-shaking for broken control flow (@lukastaegert)

## 1.23.1
*2019-10-05*

### Bug Fixes
* Fix a regression where the node types had a specific minimal version (#3143)

### Pull Requests
* [#3143](https://github.com/rollup/rollup/pull/3143): Ensure that types packages have star version ranges (@lukastaegert)

## 1.23.0
*2019-10-03*

### Features
* Add placeholders for extensions when preserving modules (#3116)

### Pull Requests
* [#3116](https://github.com/rollup/rollup/pull/3116): Include extensions in preserveModules output filenames for scriptified assets (@Andarist)
* [#3142](https://github.com/rollup/rollup/pull/3142): Fix typo (@tu4mo)

## 1.22.0
*2019-09-29*

### Features
* Add a new "hidden" sourcemap type that generates the map files but omits the sourcemap comment (#3120)
* Generate more efficient code when using `namespaceToStringTag: true` (#3135)
* Make sure namespace objects do not have a prototype (#3136)

### Bug Fixes
* Do not ignore side-effectful iterators by always preserving for..of loops for now (#3132)
* Make sure `--context` is observed as a CLI option (#3134)
* Do not require specific versions for @types dependencies (#3131)

### Pull Requests
* [#3120](https://github.com/rollup/rollup/pull/3120): Generate sourcemaps but omit the comment (@rohitmohan96)
* [#3131](https://github.com/rollup/rollup/pull/3131): Use asterisk for @types/* dependencies (@frenzzy)
* [#3132](https://github.com/rollup/rollup/pull/3132): Preserve empty for...of loops (@imatlopez)
* [#3133](https://github.com/rollup/rollup/pull/3133): Update dependencies (@lukastaegert)
* [#3134](https://github.com/rollup/rollup/pull/3134): Wire up --context CLI flag (@tchetwin)
* [#3135](https://github.com/rollup/rollup/pull/3135): Remove Symbol polyfill in module namespaces (@mkubilayk)
* [#3136](https://github.com/rollup/rollup/pull/3136): Set null prototype on namespace objects (@rpamely)

## 1.21.4
*2019-09-16*

### Bug Fixes
* Recognize common browser globals (#3117)
* Do not treat "typeof <global>" as a side-effect (#3117)

### Pull Requests
* [#3117](https://github.com/rollup/rollup/pull/3117): Add browser globals to known globals and prevent "typeof" side-effects (@lukastaegert)

## 1.21.3
*2019-09-14*

### Bug Fixes
* Fix a regression where modifying a watched file did not trigger a rebuild (#3112)

### Pull Requests
* [#3112](https://github.com/rollup/rollup/pull/3112): Fix .addWatchFile() dependencies failing to invalidate in watch mode (@tivac)

## 1.21.2
*2019-09-09*

### Bug Fixes
* Fix wrong deprecation message to direct to `this.emitFile` instead of `this.emitAsset`

## 1.21.1
*2019-09-09*

### Bug Fixes
* Allow legacy plugins to still add assets directly to the bundle object (#3105)

### Pull Requests
* [#3105](https://github.com/rollup/rollup/pull/3105): Allow legacy plugins to still add assets directly to the bundle object (@lukastaegert)

## 1.21.0
*2019-09-08*

### Features
* Respect `output.entryFileNames` when preserving modules (#3088)
* Make accessing unknown globals a side-effect unless this is deactivated via `treeshake.unknownGlobalSideEffects` (#3068)
* Respect global objects when checking for pure global functions (#3068)
* Introduce a `type` to more easily distinguish chunks and assets in the output bundle (#3080)

### Bug Fixes
* Recover in watch mode when the initial build fails (#3081)
* Make sure `output.strict` is respected for SystemJS output (#3101)

### Pull Requests
* [#3068](https://github.com/rollup/rollup/pull/3068): Make accessing unknown globals a side-effect (@lukastaegert)
* [#3080](https://github.com/rollup/rollup/pull/3080): OutputBundle Tagged union with 'type = chunk|asset' (@askbeka)
* [#3081](https://github.com/rollup/rollup/pull/3081): Watch files onbuild, even if build fails (@mhkeller)
* [#3088](https://github.com/rollup/rollup/pull/3088): Add support for entryFileNames pattern used in combination with preserveModules option (@Andarist)
* [#3101](https://github.com/rollup/rollup/pull/3101): Remove 'use strict'; from systemjs when strict=false (@askbeka)

## 1.20.3
*2019-08-28*

### Bug Fixes
* Make sure file hashes change when a change of the naming pattern leads to a file name change of a dependency (#3083)
* Fix several issues where reexporting an external "default" export could lead to invalid or incorrect code (#3084)

### Pull Requests
* [#3078](https://github.com/rollup/rollup/pull/3078): Add github actions workflow config for windows (@shellscape)
* [#3083](https://github.com/rollup/rollup/pull/3083): Properly reflect dependency file names in hash (@lukastaegert)
* [#3084](https://github.com/rollup/rollup/pull/3084): Fix "default" reexport issues in non ESM/System formats (@lukastaegert)

## 1.20.2
*2019-08-25*

### Bug Fixes
* Avoid an issue where circular namespace reexports would crash Rollup (#3074)

### Pull Requests
* [#3077](https://github.com/rollup/rollup/pull/3077): Handle namespaces that reexport themselves (@lukastaegert)

## 1.20.1
*2019-08-22*

### Bug Fixes
* Fix an issue where variable names inside dynamic import expressions were not rendered correctly (#3073)
* Fix type definition to allow a single watcher config as well as an array (#3074)

### Pull Requests
* [#3073](https://github.com/rollup/rollup/pull/3073): Fix wrong variable name in import expression (@lukastaegert)
* [#3074](https://github.com/rollup/rollup/pull/3074): Fixes type definition on watch and Watcher constructor (@MicahZoltu)

## 1.20.0
*2019-08-21*

### Features
* Add augmentChunkHash plugin hook to be able to reflect changes in renderChunk in the chunk hash (#2921)

### Bug Fixes
* Do not mutate the acorn options object (#3051)
* Make sure the order of emitted chunks always reflects the order in which they were emitted (#3055)
* Do not hang when there are strings containing comment-like syntax in some scenarios (#3069)

### Pull Requests
* [#2921](https://github.com/rollup/rollup/pull/2921): Add augmentChunkHash plugin hook (@isidrok)
* [#2995](https://github.com/rollup/rollup/pull/2995): Add info on installing locally to docs (@mesqueeb)
* [#3037](https://github.com/rollup/rollup/pull/3037): Refresh pull request labels (@shellscape)
* [#3048](https://github.com/rollup/rollup/pull/3048): Document ROLLUP_WATCH environment variable (@shellscape)
* [#3051](https://github.com/rollup/rollup/pull/3051): Avoid changes to the original options (.acorn) object (@LongTengDao)
* [#3052](https://github.com/rollup/rollup/pull/3052): Minor refactoring: Remove one try-catch (@KSXGitHub)
* [#3053](https://github.com/rollup/rollup/pull/3053): Refactor to use async-await in more places (@KSXGitHub)
* [#3055](https://github.com/rollup/rollup/pull/3055): Provide consistent chunking via a consistent order of entry modules when emitting chunks (@lukastaegert)
* [#3058](https://github.com/rollup/rollup/pull/3058): Remove acorn-bigint and acorn-dynamic-import from bundle (@LongTengDao)
* [#3061](https://github.com/rollup/rollup/pull/3061): Update to acorn@7 (@lukastaegert)
* [#3063](https://github.com/rollup/rollup/pull/3063): Auto-generate license file (@lukastaegert)
* [#3069](https://github.com/rollup/rollup/pull/3069): Prevent infinite loop when scanning for line-breaks and there are comment-like strings (@lukastaegert)

## 1.19.4
*2019-08-07*

### Bug Fixes
* Prevent invalid code when exporting an external namespace (#3034)
* Prevent invalid or non-equivalent code when simplifying expressions in return and throw statements (#3035)

### Pull Requests
* [#3034](https://github.com/rollup/rollup/pull/3034): Avoid generating .* as export (@LongTengDao)
* [#3035](https://github.com/rollup/rollup/pull/3035): Prevent ASI errors for conditional expressions (@lukastaegert)
* [#3036](https://github.com/rollup/rollup/pull/3036): Fix documents to use https, not http (@giraffate)

## 1.19.3
*2019-08-06*

### Bug Fixes
* Fix wrong URLs in error messages (#3033)

### Pull Requests
* [#3033](https://github.com/rollup/rollup/pull/3033): Fix wrong URLs in error messages (@giraffate)

## 1.19.2
*2019-08-05*

### Bug Fixes
* Add bin file to package

## 1.19.1
*2019-08-05*

### Bug Fixes
* Remove wrong extension in package.json file (#3031)

### Pull Requests
* [#3031](https://github.com/rollup/rollup/pull/3031): Fix wrong extension (@lukastaegert)

## 1.19.0
*2019-08-05*

### Features
* Implement a new unified file emission API for assets and chunks with support for explicit file names (#2999)
* Use the id of the last module in a chunk as base for the chunk name if no better name is available (#3025)
* Use the id of the last module in a chunk as base for the variable name of a chunk in some formats if no better name is available (#2999)

### Bug Fixes
* Do not produce invalid variable names if an empty name is chosen for a virtual module (#3026)
* Fix an issue where a module variable name would conflict with a local variable name in some formats (#3020)

### Pull Requests
* [#2999](https://github.com/rollup/rollup/pull/2999): Unified file emission api (@lukastaegert)
* [#3020](https://github.com/rollup/rollup/pull/3020): Switch to a code-splitting build and update dependencies (@lukastaegert)
* [#3025](https://github.com/rollup/rollup/pull/3025): Use id of last module in chunk as name base for auto-generated chunks (@lukastaegert)
* [#3026](https://github.com/rollup/rollup/pull/3026): Avoid variable from empty module name be empty (@LongTengDao)

## 1.18.0
*2019-08-01*

### Features
* Add `externalLiveBindings: false` option to optimize code when live bindings are not needed (#3010)

### Pull Requests
* [#2997](https://github.com/rollup/rollup/pull/2997): Integrate coverage into CI setup (@lukastaegert)
* [#2998](https://github.com/rollup/rollup/pull/2998): Update readme badges (@lukastaegert)
* [#3010](https://github.com/rollup/rollup/pull/3010): Add option to prevent code for external live bindings (@lukastaegert)

## 1.17.0
*2019-07-15*

### Features
* Allow plugins to access current combined sourcemap in transform hook for coverage instrumentation (#2993)

### Pull Requests
* [#2987](https://github.com/rollup/rollup/pull/2987): Fix code fences for link (@johanholmerin)
* [#2989](https://github.com/rollup/rollup/pull/2989): Bump lodash from 4.17.11 to 4.17.14 (@dependabot)
* [#2993](https://github.com/rollup/rollup/pull/2993): Add getCombinedSourceMap in transform plugin context (@billowz)

## 1.16.7
*2019-07-09*

### Bug Fixes
* Fix an issue where exported import.meta properties would lead to invalid code (#2986)

### Pull Requests
* [#2985](https://github.com/rollup/rollup/pull/2985): Improve sourcemap types (@jridgewell)
* [#2986](https://github.com/rollup/rollup/pull/2986): Only overwrite content when resolving import.meta properties (@lukastaegert)

## 1.16.6
*2019-07-04*

### Bug Fixes
* Do not pass undefined to resolveDynamicImport for unresolvable template literals (#2984)

### Pull Requests
* [#2984](https://github.com/rollup/rollup/pull/2984): Always forward AST nodes for unresolvable dynamic imports (@lukastaegert)

## 1.16.5
*2019-07-04*

### Bug Fixes
* onwarn should still be called when --silent is used (#2982)
* Properly clean up watchers for files that are deleted between builds (#2982)

### Pull Requests
* [#2981](https://github.com/rollup/rollup/pull/2981): Do not skip onwarn handler when --silent is used (@lukastaegert)
* [#2982](https://github.com/rollup/rollup/pull/2982): Make tests run on Node 12, fix watcher cleanup issue (@lukastaegert)

## 1.16.4
*2019-07-02*

### Bug Fixes
* Do not show a TypeScript error when providing a location as number to this.warn and this.error (#2974)
* Use the correct TypeScript type for Sourcemap.version (#2976)

### Pull Requests
* [#2965](https://github.com/rollup/rollup/pull/2965): Use async readFile in getRollupDefaultPlugin (@kaksmet)
* [#2974](https://github.com/rollup/rollup/pull/2974): Align TS types, docs and implementation for this.warn and this.error (@lukastaegert)
* [#2976](https://github.com/rollup/rollup/pull/2976): Fix sourcemap type and update dependencies (@lukastaegert)

## 1.16.3
*2019-06-29*

### Bug Fixes
* Prevent name conflicts with unused function parameters (#2972)

### Pull Requests
* [#2972](https://github.com/rollup/rollup/pull/2972): Deconflict unused parameters (@lukastaegert)

## 1.16.2
*2019-06-22*

### Bug Fixes
* Properly wrap dynamic imports in Promises that can be caught when generating CJS output (#2958)

### Pull Requests
* [#2958](https://github.com/rollup/rollup/pull/2958): Make sure errors from dynamic imports can be caught (@lukastaegert)

## 1.16.1
*2019-06-21*

### Pull Requests
* [#2956](https://github.com/rollup/rollup/pull/2956): Add missing CLI docs for strictDeprecations (@lukastaegert)

## 1.16.0
*2019-06-21*

### Features
* Add strictDeprecations option to throw when currently or upcoming deprecated features are used (#2945)
* Keep annotations and comments when simplifying logical and conditional expressions (#2955)

### Bug Fixes
* Generate proper namespace objects when dynamically importing external dependencies for AMD or CJS formats (#2954)
* Fix dynamically imported variables not being resolved correctly when importing from an entry chunk with only a default export (#2954)
* Do not reexport default when reexporting a namespace (#2954)

### Pull Requests
* [#2945](https://github.com/rollup/rollup/pull/2945): Add option to handle use of features marked for deprecation as errors (@lukastaegert)
* [#2954](https://github.com/rollup/rollup/pull/2954): Improve dynamic import interop (@lukastaegert)
* [#2955](https://github.com/rollup/rollup/pull/2955): Keep annotations and comments when simplifying logical and conditional expressions (@lukastaegert)

## 1.15.6
*2019-06-16*

### Bug Fixes
* No longer use an alternate screen in watch mode to allow scrolling (#2942)
* Prioritize non-external imports over external ones when resolving conflicting namespace re-exports (#2893)

### Pull Requests
* [#2893](https://github.com/rollup/rollup/pull/2893): Improve handling of conflicting namespace exports (@aleclarson)
* [#2942](https://github.com/rollup/rollup/pull/2942): Get rid of alternate screen and simplify screen clearing (@lukastaegert)

## 1.15.5
*2019-06-14*

### Bug Fixes
* Do not include any comments for completely tree-shaken files so that `renderedLength === 0` is a reliable check (#2940)
* Do not cause type errors when returning `null` from `resolveId` (#2941)

### Pull Requests
* [#2940](https://github.com/rollup/rollup/pull/2940): Completely omit files that do not have any included statements (@lukastaegert)
* [#2941](https://github.com/rollup/rollup/pull/2941): Explicitly allow null as return value for various hooks (@lukastaegert)

## 1.15.4
*2019-06-14*

### Bug Fixes
* Improve how asset and chunk URLs are resolved for UMD, IIFE and CJS output (#2937)

### Pull Requests
* [#2937](https://github.com/rollup/rollup/pull/2937): Fix URL resolution to work when the current script contains query parameters (@lukastaegert)

## 1.15.3
*2019-06-13*

### Bug Fixes
* Always reemit assets and chunks from cached transform hooks (#2936)

### Pull Requests
* [#2936](https://github.com/rollup/rollup/pull/2936): Fix repeated re-emission of files emitted from a transform hook (@lukastaegert)

## 1.15.2
*2019-06-13*

### Bug Fixes
* Make sure chunks emitted from transform hooks are also emitted for incremental builds in watch mode (#2933)

### Pull Requests
* [#2933](https://github.com/rollup/rollup/pull/2933): Reemit chunks emitted from transform hooks (@lukastaegert)

## 1.15.1
*2019-06-11*

### Bug Fixes
* Do not fail when reexporting variables in dynamic entry points from other chunks (#2928)

### Pull Requests
* [#2928](https://github.com/rollup/rollup/pull/2928): Handle reexports from dynamic entries across chunk (@lukastaegert)

## 1.15.0
*2019-06-11*

### Features
* Tone down try-catch deoptimization while maintaining polyfill support (#2918)

### Bug Fixes
* Handle feature detection with "typeof" for regular expressios (#2916)
* Deoptimize `'' + variable'` type coercion as expression statement for feature detection (#2917)
* Always observe argument side-effects when tree-shaking (#2924)

### Pull Requests
* [#2916](https://github.com/rollup/rollup/pull/2916): Deoptimize typeof for regular expression literals to better support es6-shim (@lukastaegert)
* [#2917](https://github.com/rollup/rollup/pull/2917): Support implicit type coercion errors in es5-shim (@lukastaegert)
* [#2918](https://github.com/rollup/rollup/pull/2918): Deoptimize try-catch less radically (@lukastaegert)
* [#2924](https://github.com/rollup/rollup/pull/2924): Do not tree-shake arguments with side-effects (@lukastaegert)

## 1.14.6
*2019-06-10*

### Bug Fixes
* Fix an issue where call arguments were not included in try statements (#2914)

### Pull Requests
* [#2914](https://github.com/rollup/rollup/pull/2914): Properly include try statements for each pass when deoptimization is deactivated (@lukastaegert)

## 1.14.5
*2019-06-09*

### Bug Fixes
* Keep external ids unmodified when using the object form of resolveId (#2907)
* Cache dynamic import resolutions when using Rollup cache (#2908)
* Keep all necessary parentheses when tree-shaking call arguments (#2911)

### Pull Requests
* [#2906](https://github.com/rollup/rollup/pull/2906): Update dependencies (@lukastaegert)
* [#2907](https://github.com/rollup/rollup/pull/2907): Do not renormalize external ids when using the object form (@lukastaegert)
* [#2908](https://github.com/rollup/rollup/pull/2908): Cache dynamic ids if possible (@lukastaegert)
* [#2911](https://github.com/rollup/rollup/pull/2911): Fix treeshaken parameters around parentheses (@manucorporat)

## 1.14.4
*2019-06-07*

### Bug Fixes
* Do not omit external re-exports for `moduleSideEffects: false` (#2905)

### Pull Requests
* [#2905](https://github.com/rollup/rollup/pull/2905): Make sure external re-exports are included for moduleSideEffects: false (@lukastaegert)

## 1.14.3
*2019-06-06*

### Bug Fixes
* Generate correct external imports when importing from a directory that would be above the root of the current working directory (#2902)

### Pull Requests
* [#2902](https://github.com/rollup/rollup/pull/2902): Use browser relative path algorithm for chunks (@lukastaegert)

## 1.14.2
*2019-06-05*

### Bug Fixes
* Prevent unnecessary inclusion of external namespace import in certain situations (#2900)

### Pull Requests
* [#2900](https://github.com/rollup/rollup/pull/2900): Handle early bind for member expressions (@lukastaegert)

## 1.14.1
*2019-06-05*

### Bug Fixes
* Fix an issue where try-statements were not included properly when a variable declared inside the statement was accessed outside it (#2898)
* Fix an issue where `await` expressions were not included properly (#2899)

### Pull Requests
* [#2898](https://github.com/rollup/rollup/pull/2898): Properly include try-catch-statements even if they have already been included in some way (@lukastaegert)
* [#2899](https://github.com/rollup/rollup/pull/2899): Fix unintended early return in await inclusion handling (@lukastaegert)

## 1.14.0
*2019-06-05*

### Features
* Deoptimize code inside and called from try-statements for feature detection (#2892)
* Tree-shake unused call arguments (#2892)

### Pull Requests
* [#2892](https://github.com/rollup/rollup/pull/2892): Implement try-statement-deoptimization for feature detection, tree-shake unused arguments (@lukastaegert)


## 1.13.1
*2019-06-01*

### Bug Fixes
* Avoid conflicts between top-level module, require etc. and CommonJS runtimes (#2889)

### Pull Requests
* [#2888](https://github.com/rollup/rollup/pull/2888): Enable full TypeScript strict mode (@lukastaegert)
* [#2889](https://github.com/rollup/rollup/pull/2889): Protect all module globals for CJS output from being redefined (@lukastaegert)

## 1.13.0
*2019-05-31*

### Features
* Omit `exports` and `module` from SystemJS wrapper if possible (#2880)
* Try to use the first letters of names when mangling exports (#2885)

### Bug Fixes
* Avoid conflicts with local variables when using format specific globals to render dynamic imports and file URLs (#2880)
* Do not produce undefined reexports when reexporting from entry points (#2885)

### Pull Requests
* [#2880](https://github.com/rollup/rollup/pull/2880): Deconflict global variables used inside format-specific code (@lukastaegert)
* [#2885](https://github.com/rollup/rollup/pull/2885): Do not produce undefined reexports when reexporting from entry points and refactor chunk linking (@lukastaegert)

## 1.12.5
*2019-05-30*

### Pull Requests
* [#2884](https://github.com/rollup/rollup/pull/2884): Update pluginutils for new micormatch and reduced bundle size (@lukastaegert)

## 1.12.4
*2019-05-27*

### Bug Fixes
* Show correct error stack trace for errors throw in "load" hooks (#2871)

### Pull Requests
* [#2875](https://github.com/rollup/rollup/pull/2875): Mention subfolders in docs (@lukastaegert)
* [#2871](https://github.com/rollup/rollup/pull/2871): Reserve error stack information (@LongTengDao)

## 1.12.3
*2019-05-19*

### Bug Fixes
* Prevent duplicate imports when exports are reexported as default exports (#2866)

### Pull Requests
* [#2755](https://github.com/rollup/rollup/pull/2755): Enable TypeScript strictNullChecks (@edsrzf)
* [#2866](https://github.com/rollup/rollup/pull/2866): Properly deduplicate reexported default exports (@lukastaegert)

## 1.12.2
*2019-05-17*

### Bug Fixes
* Do not fail when using clearScreen:false in watchMode (#2858)
* Properly resolve star reexports when preserving modules (#2860)

### Pull Requests
* [#2858](https://github.com/rollup/rollup/pull/2858): Declare processConfigsErr before use (@humphd)
* [#2860](https://github.com/rollup/rollup/pull/2860): Keep nested exports with preserveModules (@TomCaserta)
* [#2864](https://github.com/rollup/rollup/pull/2864): Cache transitive reexport detection (@lukastaegert)

## 1.12.1
*2019-05-16*

### Bug Fixes
* Extend file name sanitation to also replace "?" and "*" e.g. when preserving modules with the updated commonjs plugin (#2860)
* Do not ignore module transformer that return an empty string (#2861)

### Pull Requests
* [#2860](https://github.com/rollup/rollup/pull/2860): Update to latest plugins and extend file name sanitation (@lukastaegert)
* [#2861](https://github.com/rollup/rollup/pull/2861): Allow transformers to return an empty string (@lukastaegert)

## 1.12.0
*2019-05-15*

### Features
* Add `treeshake.moduleSideEffects` option to allow removing empty imports without a side-effect check (#2844)
* Extend plugin API to allow marking modules as side-effect-free (#2844)
* Extend `this.resolve` plugin context function with an option to skip the `resolveId` hook of the calling plugin (#2844)
* Add `isEntry` flag to `this.getModuleInfo` plugin context function (#2844)
* Distribute Rollup as optimized ES2015 code (#2851)

### Pull Requests
* [#2844](https://github.com/rollup/rollup/pull/2844): Add options and hooks to control module side effects (@lukastaegert)
* [#2851](https://github.com/rollup/rollup/pull/2851): Switch to ES2015 output (@lukastaegert)

## 1.11.3
*2019-05-05*

### Bug Fixes
* Quote es3 keywords in namespace objects (#2825)

### Pull Requests
* [#2825](https://github.com/rollup/rollup/pull/2825): Add es3 support for namespace object import (@sormy)

## 1.11.2
*2019-05-04*

### Bug Fixes
* Prevent a crash when handling circular namespace exports (#2836)

### Pull Requests
* [#2836](https://github.com/rollup/rollup/pull/2836): Make sure circular `export * from X` does not stack overflow (@Swatinem)

## 1.11.1
*2019-05-04*

### Bug Fixes
* Fix an issue where rendered exports were reported as "removed" in the bundle information (#2835)

### Pull Requests
* [#2835](https://github.com/rollup/rollup/pull/2835): Fix `removedExports` to correctly track the exported item (@Swatinem)

## 1.11.0
*2019-05-03*

### Features
* Add `emitChunk` plugin context function to emit additional entry chunks that can be referenced from the code (#2809)
* Allow `manualChunks` to be a function (#2831)
* Omit `.js` extensions in AMD imports to make sure an AMD `baseUrl` would work (#2809)
* Automatically use the name of the imported module as a base for dynamically imported chunks (#2809)
* Add `resolveFileUrl` plugin hook to replace `resolveAssetUrl` and handle emitted chunks as well (#2809)
* Add `resolve` plugin hook to replace `resolveId` and `isExternal` that returns an object (#2829)
* Allow `resolveDynamicImport` to return an `{id, external}` object to also resolve unresolvable dynamic imports to a module (#2829)

### Bug Fixes
* Do not create invalid code if a dynamic import contains nothing but reexports (#2809)
* Do not fail if modules that define a manual chunk depend on each other (#2809)
* Do not fail if a module that defines a manual chunk is the dependency of a module defining a different manual chunk (#2809)
* No longer fail for unnamed duplicate entry points but combine them (#2809)
* Always return `string | null` from `this.resolveId` even if some `resolveId` hooks return objects (#2829)
* Show proper warnings when `resolveDynamicImport` resolves to a non-external module that does not exist (#2829)

### Pull Requests
* [#2809](https://github.com/rollup/rollup/pull/2809): Add hook for dynamic entry chunk emission (@lukastaegert)
* [#2821](https://github.com/rollup/rollup/pull/2821): Fix syntax error in documentation (@FFxSquall)
* [#2829](https://github.com/rollup/rollup/pull/2829): Improve id resolution (@lukastaegert)
* [#2831](https://github.com/rollup/rollup/pull/2831): Allow manualChunks to be a function (@lukastaegert)
* [#2832](https://github.com/rollup/rollup/pull/2832): Improve `generateBundle` documentation (@lukastaegert)
* [#2833](https://github.com/rollup/rollup/pull/2833): Update dependencies (@lukastaegert)

## 1.10.1
*2019-04-19*

### Bug Fixes
* Invalid options.format values will now trigger a helpful error (#2813)

### Pull Requests
* [#2812](https://github.com/rollup/rollup/pull/2812): Minor documentation update (@dnalborczyk)
* [#2813](https://github.com/rollup/rollup/pull/2813): Catch invalid options.format values (@marijnh)
* [#2816](https://github.com/rollup/rollup/pull/2816): Update all dependencies to fix security issues (@lukastaegert)

## 1.10.0
*2019-04-11*

### Features
* Improve generated code to polyfill `import.meta.url` (#2785)
* Add plugin hook to configure handling of `import.meta` (#2785)
* Improve generated code when accessing URLs of emitted assets (#2796)
* Add plugin hook to configure the generated code when accessing URLs of emitted assets (#2796)

### Bug Fixes
* No longer resolve assets to their parent URL in some cases (#2796)

### Pull Requests
* [#2785](https://github.com/rollup/rollup/pull/2785): Refactor handling of import.meta.url and add option to configure behaviour (@lukastaegert)
* [#2796](https://github.com/rollup/rollup/pull/2796): Improve and fix asset emission (@lukastaegert)

## 1.9.3
*2019-04-10*

### Bug Fixes
* Simplify return expressions that are evaluated before the surrounding function is bound (#2803)

### Pull Requests
* [#2803](https://github.com/rollup/rollup/pull/2803): Handle out-of-order binding of identifiers to improve tree-shaking (@lukastaegert)

## 1.9.2
*2019-04-10*

### Bug Fixes
* Allowing replacing `output.file` with `output.dir` in the `outputOptions` hook (#2802)

### Pull Requests
* [#2802](https://github.com/rollup/rollup/pull/2802): Observe modified output options in bundle.write (@lukastaegert)

## 1.9.1
*2019-04-10*

### Bug Fixes
* Make sure inline comments in dynamic imports are preserved (#2797)

### Pull Requests
* [#2797](https://github.com/rollup/rollup/pull/2797): Emit inline comments inside dynamic import (@manucorporat)

## 1.9.0
*2019-04-05*

### Features
* Add built-in support for bigint (#2789)

### Pull Requests
* [#2789](https://github.com/rollup/rollup/pull/2789): Ship with bigint support built-in (@danielgindi)
* [#2791](https://github.com/rollup/rollup/pull/2791): Use shared extractAssignedNames from rollup-pluginutils (@lukastaegert)
* [#2792](https://github.com/rollup/rollup/pull/2792): Test that rollup-plugin-commonjs works with preserveModules (@lukastaegert)

## 1.8.0
*2019-04-02*

### Features
* Support `module` as alias for `esm` and `commonjs` for `cjs` to match Node (#2783)

### Pull Requests
* [#2782](https://github.com/rollup/rollup/pull/2782): Inline interopDefault in config loading (@guybedford)
* [#2783](https://github.com/rollup/rollup/pull/2783): Support Node-style format aliases (@guybedford)

## 1.7.4
*2019-03-28*

### Bug Fixes
* Improve TypeScript type of the treeshaking options (#2779)

### Pull Requests
* [#2779](https://github.com/rollup/rollup/pull/2779): Make all properties in TreeshakingOptions optional (@ndelangen)

## 1.7.3
*2019-03-24*

### Bug Fixes
* Use getters when re-exporting live-bindings (#2765)

### Pull Requests
* [#2765](https://github.com/rollup/rollup/pull/2765): Support exporting live-bindings from other chunks or external dependencies (@lukastaegert)

## 1.7.2
*2019-03-24*

### Bug Fixes
* Make sure relative external ids are resolved correctly (#2774)

### Pull Requests
* [#2774](https://github.com/rollup/rollup/pull/2774): Resolve relative external ids (@lukastaegert)

## 1.7.1
*2019-03-24*

### Bug Fixes
* Prevent invalid code when exporting several hundred identifiers from a chunk (#2768)
* Do not wrongly deconflict labels (#2776)

### Pull Requests
* [#2768](https://github.com/rollup/rollup/pull/2768): Sanitize shortened internal export names (@lukastaegert)
* [#2769](https://github.com/rollup/rollup/pull/2769): Update dependencies and fix security issue (@lukastaegert)
* [#2776](https://github.com/rollup/rollup/pull/2776): Do not treat break labels as identifiers (@lukastaegert)

## 1.7.0
*2019-03-20*

### Features
* Sort chunk exports by name for greater consistency (#2757)

### Bug Fixes
* Fix a situation where tree-shakeable code would not be removed in an indeterminate manner (#2757)

### Pull Requests
* [#2757](https://github.com/rollup/rollup/pull/2757): Sort modules before binding, sort exports (@lukastaegert)

## 1.6.1
*2019-03-20*

### Bug Fixes
* Avoid name clashes of unused default exports when tree-shaking is false (#2758)
* Do not crash when generating SystemJS bundles containing array patterns with member expressions (#2760)

### Pull Requests
* [#2758](https://github.com/rollup/rollup/pull/2758): Make sure unused default exports are deconflicted when tree-shaking is false (@lukastaegert)
* [#2760](https://github.com/rollup/rollup/pull/2760): Handle member expressions in array patterns (@lukastaegert)

## 1.6.0
*2019-03-08*

### Features
* Add plugin hook to modify output options (#2736)

### Pull Requests
* [#2736](https://github.com/rollup/rollup/pull/2736): Add hook for modifying OutputOptions (@Comandeer)

## 1.5.0
*2019-03-07*

### Features
* Allow resolving to a different id while marking it as external at the same time (#2734)

### Pull Requests
* [#2734](https://github.com/rollup/rollup/pull/2734): Allow resolveId to return an object (@lukastaegert)

## 1.4.2
*2019-03-07*

### Bug Fixes
* Respect variable identity of exports when hashing (#2741)
* Resolve a situations where a variable was imported twice with the same name (#2737)

### Pull Requests
* [#2741](https://github.com/rollup/rollup/pull/2741): Fix hashing when different variable are exported using the same name (@lukastaegert)
* [#2737](https://github.com/rollup/rollup/pull/2737): Fix duplicate imports with conflicting names (@lukastaegert)

## 1.4.1
*2019-03-04*

### Bug Fixes
* Do not treat the import target "" as external by default (#2733)

### Pull Requests
* [#2733](https://github.com/rollup/rollup/pull/2733): Do not treat the import target "" as external by default (@LongTengDao)

## 1.4.0
*2019-03-01*

### Features
* Add option to change the name of the dynamic import function to allow polyfilling it (#2723)

### Pull Requests
* [#2723](https://github.com/rollup/rollup/pull/2723): Add dynamicImportFunction option (@keithamus)

## 1.3.3
*2019-03-01*

### Bug Fixes
* Fix performance regression when handling long chains of calls to property methods (#2732)

### Pull Requests
* [#2730](https://github.com/rollup/rollup/pull/2730): Order types, interfaces, classes, and object members (@lukastaegert)
* [#2732](https://github.com/rollup/rollup/pull/2732): Take chunk export mode into account when reexporting default (@lukastaegert)

## 1.3.2
*2019-02-27*

### Bug Fixes
* Allow ids of default exported classes to be exported separately (#2728)

### Pull Requests
* [#2728](https://github.com/rollup/rollup/pull/2728): Update dependencies (@lukastaegert)

## 1.3.1
*2019-02-27*

### Bug Fixes
* Correctly reexport the default export from entry chunks (#2727)

### Pull Requests
* [#2727](https://github.com/rollup/rollup/pull/2727): Take chunk export mode into account when reexporting default (@lukastaegert)

## 1.3.0
*2019-02-26*

### Features
* Treeshake call expressions prefixed with UglifyJS style `@__PURE__` annotations (#2429)

### Pull Requests
* [#2429](https://github.com/rollup/rollup/pull/2429): Add support for /*#__PURE__*/ comments (@conartist6 and @lukastaegert)

## 1.2.5
*2019-02-26*

### Bug Fixes
* Store external ids reported by plugins in watch mode (#2718)

### Pull Requests
* [#2718](https://github.com/rollup/rollup/pull/2718): Incremental external (@andreas-karlsson)

## 1.2.4
*2019-02-26*

### Bug Fixes
* Fix an issue where a variable was imported twice under the same name (#2715)

### Pull Requests
* [#2715](https://github.com/rollup/rollup/pull/2715): Deduplicate imports referencing default exports and their original variables (@lukastaegert)

## 1.2.3
*2019-02-23*

### Bug Fixes
* Use correct path when rendering dynamic imports where the entry module is empty (#2714)

### Pull Requests
* [#2714](https://github.com/rollup/rollup/pull/2714): Properly render dynamic imports when imported module is empty (@lukastaegert)

## 1.2.2
*2019-02-19*

### Bug Fixes
* Fix wrong external imports when using the `paths` options only for some outputs (#2706)

### Pull Requests
* [#2706](https://github.com/rollup/rollup/pull/2706): Always recreate paths for external modules (@lukastaegert)

## 1.2.1
*2019-02-17*

### Bug Fixes
* Fix ESM version of Rollup (#2705)

### Pull Requests
* [#2705](https://github.com/rollup/rollup/pull/2705): Fix ESM version of Rollup (@lukastaegert)

## 1.2.0
*2019-02-17*

### Features
* Fewer renamed variables due to a completely reimplemented deconflicting logic (#2689)

### Bug Fixes
* Respect rendered and tree-shaken exports when determining chunk hashes (#2695)
* Fix an error when dynamic imports end up in the same chunk as one of their importees (#2677)
* Do not generate invalid code when expressions containing IIFEs are simplified (#2696)
* Do not throw an error when more than ten bundles are watched (#2700)
* Treat re-exported globals in a spec-compliant way (#2691)
* Fix issues related to wrongly renamed variables (#2689)
* Do not throw an error if config files contain non-default exports (#2673)
* Improve type of `RollupOutput.output` to guarantee at least one chunk (#2679)

### Pull Requests
* [#2673](https://github.com/rollup/rollup/pull/2673): Allow config files to have non-default exports (@swansontec)
* [#2677](https://github.com/rollup/rollup/pull/2677): Prevent final resolution and facade creation for inlined dynamic imports (@Rich-Harris and @lukastaegert)
* [#2679](https://github.com/rollup/rollup/pull/2679): Improve type of `RollupOutput.output` (@MattiasBuelens)
* [#2689](https://github.com/rollup/rollup/pull/2689): Reimplement variable deconflicting logic (@lukastaegert)
* [#2691](https://github.com/rollup/rollup/pull/2691): Fix CI issues and update acorn dependency (@lukastaegert)
* [#2693](https://github.com/rollup/rollup/pull/2693): Fix typo in export-globals test (@MattiasBuelens)
* [#2695](https://github.com/rollup/rollup/pull/2695): Respect rendered exports when generating chunk hashes (@lukastaegert)
* [#2696](https://github.com/rollup/rollup/pull/2696): Correctly render function expression inside simplified expression statements (@lukastaegert)
* [#2700](https://github.com/rollup/rollup/pull/2700): Add a fix for MaxListenersExceededWarning (@luwes)
* [#2703](https://github.com/rollup/rollup/pull/2703): Update rollup-pluginutils (@lukastaegert)

## 1.1.2
*2019-01-21*

### Bug Fixes
* Tree-shaken dynamic imports no longer leave behind `undefined` entries in the bundle information (#2663)
* Dynamic imports in dynamically imported files could lead to crashes and would not always create new chunks (#2664)

### Pull Requests
* [#2663](https://github.com/rollup/rollup/pull/2663): Do not include tree-shaken dynamic imports in bundle information (@lukastaegert)
* [#2664](https://github.com/rollup/rollup/pull/2664): Properly handle dynamic imports declared in dynamically imported files (@everdimension)

## 1.1.1
*2019-01-19*

### Bug Fixes
* Make sure object prototype methods are not considered to be falsy when tree-shaking (#2652)

### Pull Requests
* [#2652](https://github.com/rollup/rollup/pull/2652): Make sure object prototype methods are not considered to be falsy (@lukastaegert)
* [#2654](https://github.com/rollup/rollup/pull/2654): Use correct signature for `this.setAssetSource` in docs (@everdimension)
* [#2656](https://github.com/rollup/rollup/pull/2656): Swap descriptions for `[extname]` and `[ext]` in docs (@tivac)

## 1.1.0
*2019-01-09*

### Features
* Make `this.meta` available from the `options` plugin hook (#2642)
* Add a new `writeBundle` plugin hook that is called *after* all files have been written (#2643)

### Bug Fixes
* Make sure the `acorn` import of the bundled non-ESM acorn plugins is correctly translated to ESM (#2636)
* Make sure input options are actually passed to the `buildStart` hook (#2642)

### Pull Requests
* [#2636](https://github.com/rollup/rollup/pull/2636): Improve how acorn is imported, update dependencies (@lukastaegert)
* [#2642](https://github.com/rollup/rollup/pull/2642): Make this.meta available in options hook, pass input options to buildStart (@lukastaegert)
* [#2643](https://github.com/rollup/rollup/pull/2643): Implement writeBundle hook (@lukastaegert)

## 1.0.2
*2019-01-05*

### Bug Fixes
* Make sure the transform hook is always reevaluated when a file watched by the hook changes (#2633)
* Fix a crash when generating hashes for tree-shaken dynamic imports (#2638)
* Fix a crash and some inconsistencies when using the acorn-bigint plugin (#2640)

### Pull Requests
* [#2633](https://github.com/rollup/rollup/pull/2633): Document `this.addWatchFile` and make sure it also declares transform dependencies (@lukastaegert)
* [#2635](https://github.com/rollup/rollup/pull/2635): Make sure `code` is optional in warning type (@lukastaegert)
* [#2638](https://github.com/rollup/rollup/pull/2638): Do not fail when generating hashes for tree-shaken dynamic imports (@lukastaegert)
* [#2640](https://github.com/rollup/rollup/pull/2640): Always treat bigints as unknown (@lukastaegert)
* [#2641](https://github.com/rollup/rollup/pull/2641): Make sure all CLI options are listed in the summary (@lukastaegert)

## 1.0.1
*2019-01-03*

### Bug Fixes
* Properly handle reexporting an external default export for non-ESM targets when using named exports mode (#2620)
* Do not (wrongly) re-declare input options in the merged `RollupOptions` type (#2622)

### Pull Requests
* [#2620](https://github.com/rollup/rollup/pull/2620): Fixed issue with reexporting default as default with `{exports: named, interop: true}` options (@Andarist)
* [#2622](https://github.com/rollup/rollup/pull/2622): Simplify RollupOptions (@Kinrany)
* [#2627](https://github.com/rollup/rollup/pull/2627): Show how to skip imports for optional plugins (@chris-morgan)

## 1.0.0
*2018-12-28*

### Breaking Changes
* Several (mostly deprecated) options have been removed or renamed (#2293, #2409):
  - banner -> output.banner
  - dest -> output.file
  - entry -> input
  - experimentalCodeSplitting -> now always active
  - experimentalDynamicImport -> now always active
  - experimentalPreserveModules -> preserveModules
  - exports -> output.exports
  - extend -> output.extend
  - footer -> output.footer
  - format -> output.format
  - freeze -> output.freeze
  - globals -> output.globals
  - indent -> output.indent
  - interop -> output.interop
  - intro -> output.intro
  - load -> use plugin API
  - moduleName -> output.name
  - name -> output.name
  - noConflict -> output.noConflict
  - output.moduleId -> output.amd.id
  - outro -> output.outro
  - paths -> output.paths
  - preferConst -> output.preferConst
  - pureExternalModules -> treeshake.pureExternalModules
  - resolveExternal -> use plugin API
  - resolveId -> use plugin API
  - sourcemap -> output.sourcemap
  - sourceMap -> output.sourcemap
  - sourceMapFile -> output.sourcemapFile
  - strict -> output.strict
  - targets -> use output as an array
  - transform -> use plugin API
  - useStrict -> output.strict
* In general, output options can no longer be used as input options (#2409)
* `bundle.generate` and `bundle.write` now return a new format (#2293)
* Several plugin hooks have become deprecated and will display warnings when used (#2409):
  - transformBundle
  - transformChunk
  - ongenerate
  - onwrite
* Plugin transform dependencies are deprecated in favour of using the `this.addWatchFile` plugin context function (#2409)
* Accessing `this.watcher` in plugin hooks is deprecated in favour of the `watchChange` plugin hook and the `this.addWatchFile` plugin context function (#2409)
* Using dynamic import statements will by default create a new chunk unless `inlineDynamicImports` is used (#2293)
* Rollup now uses acorn@6 which means that acorn plugins must be compatible with this version; acorn is now external for non-browser builds to make plugins work (#2293)

### Features
* The `--dir` ClI option can now be aliased as `-d` (#2293)
* The `--input` option now supports named entry points via `=` (#2293)

### Bug Fixes
* Both the `--input` option as well as the default CLI option now support named inputs (#2293)

### Pull Requests
* [#2293](https://github.com/rollup/rollup/pull/2293): Unify code paths for 1.0 relase and update documentation (@guybedford and @lukastaegert)
* [#2409](https://github.com/rollup/rollup/pull/2409): Remove old deprecated features and add new deprecation warnings (@guybedford)
* [#2486](https://github.com/rollup/rollup/pull/2486): Upgrade to acorn 6 (@marijnh)
* [#2611](https://github.com/rollup/rollup/pull/2611): Fix hook's name in test description (@Andarist)
* [#2612](https://github.com/rollup/rollup/pull/2612): Fix a self-contradicting comment in the docs (@LongTengDao)

## 0.68.2
*2018-12-23*

### Bug Fixes
* Do not assume hoisted variables to have been initialized (#2607)

### Pull Requests
* [#2607](https://github.com/rollup/rollup/pull/2607): Fix an issues where hoisted variables were assumed to have been initialized (@lye)

## 0.68.1
*2018-12-19*

### Bug Fixes
* Fix an issue with UMD wrappers where a variable is used without being defined (#2600)

### Pull Requests
* [#2600](https://github.com/rollup/rollup/pull/2600): Fix UMD and IIFE wrapper issues and add comprehensive functional wrapper tests (@lukastaegert)

## 0.68.0
*2018-12-16*

### Breaking Changes
* `optimizeChunks` is renamed to `experimentalOptimizeChunks` to reflect this feature is not production-ready yet (#2575)

### Features
* Plugins can iterate all module ids via `this.moduleIds` (#2565)
* Plugins can get graph information about a module via `this.getModuleInfo(id)` (#2565)
* Plugins and JS API users get more information about the generated chunks: `dynamicImports`, `facadeModuleId`, `isDynamicEntry`, `name` (#2575)
* Tree-shaken dynamic imports will no longer create chunks or influence chunking in any way (#2575)
* Dynamic imports will no longer follow the `entryFileNames` but the `chunkFileNames` property reflecting those are solely internally used (#2575)
* If there are chunk naming conflicts, entry chunks will always take precedence (#2575)
* If an entry facade is created, only the facade chunk is marked as `isEntry` (#2575)
* Dynamic chunks will only be marked as `isEntry` if they are actually entry chunks as well; thus there is now a 1-to-1 correspondence between modules listed in `input` and chunks marked as `isEntry` (#2575)
* Chunks no longer contain imports for variables that are tree-shaken in the chunk but used in other chunks (#2584)
* Chunks will always import re-exported variables directly from the chunk where they are originally exported from (#2584)
* Null characters will be pruned from chunk ids to allow for virtually created chunks and make `rollup-plugin-multi-entry` compatible with code-splitting and thus the upcoming 1.0 version (#2590)
* Simplify the UMD wrapper code as much as possible, especially if there are no exports (#2594)
* The UMD wrapper will now work in strict mode by checking for `self` before `this` when determining the global variable (#2594)

### Bug Fixes
* If a facade is created for a dynamic entry point, this facade will be imported instead of the facaded chunk (#2575)
* Manual chunks that include multiple entry points will have proper facades created for all entry points if necessary  (#2575)
* If missing exports are shimmed, the shim variable will not be global but created on a per-module basis and is deconflicted with variables having the same name (#2584)
* Missing export shims work properly in SystemJS (#2584)
* `preserveModules` now handles dynamic namespace imports (#2584)
* Fix chunk execution order in certain scenarios (#2584)
* Exports and assignments using destructuring syntax will properly update the exported variables when generating SystemJS output (#2587)
* Hashes in chunk names will now also take dynamic imports into account (#2596)

### Pull Requests
* [#2565](https://github.com/rollup/rollup/pull/2565): Provide module graph information on the plugin context (@samccone)
* [#2575](https://github.com/rollup/rollup/pull/2575): Extend bundle information, tree-shake dynamic imports, fix dynamic import facade creation, support manual chunks with multiple entry points, make `optimizeChunks` experimental (@lukastaegert)
* [#2577](https://github.com/rollup/rollup/pull/2577): Update dependencies (@lukastaegert)
* [#2584](https://github.com/rollup/rollup/pull/2584): Prune tree-shaken chunk imports, fix missing export shimming, support dynamic namespaces when preserving modules, improve chunk execution order (@lukastaegert)
* [#2587](https://github.com/rollup/rollup/pull/2587): Support exports using destructuring declarations and assignments in SystemJS (@lukastaegert)
* [#2590](https://github.com/rollup/rollup/pull/2590): Make sure chunk ids do not contain invalid characters to allow for chunks to correspond to virtual modules (@lukastaegert)
* [#2594](https://github.com/rollup/rollup/pull/2594): Simplify UMD wrapper code and make sure it works in strict mode (@lukastaegert)
* [#2596](https://github.com/rollup/rollup/pull/2596): Take both static and dynamic dependencies into account when calculating hashes (@lukastaegert)

## 0.67.4
*2018-12-03*

### Bug Fixes
* Prevent corrupt source maps for files with very long lines (#2571)

### Pull Requests
* [#2571](https://github.com/rollup/rollup/pull/2571): Fix an issue with long lines in sourcemaps (@mislav)

## 0.67.3
*2018-11-17*

### Bug Fixes
* Make sure the ESM browser build is actually published to npm (#2560)
* Throw proper error when using `inlineDynamicImports` with `experimentalPreserveModules` (#2560)

### Pull Requests
* [#2552](https://github.com/rollup/rollup/pull/2552): Properly include ESM browser build in package (@lukastaegert)
* [#2560](https://github.com/rollup/rollup/pull/2560): Show proper error when using `inlineDynamicImports` with `experimentalPreserveModules` (@clarkdo)

## 0.67.2
*2018-11-17*

### Bug Fixes
*  Prevent crash when not returning sourcemaps from `renderChunk` plugin hook (#2558)

### Pull Requests
* [#2558](https://github.com/rollup/rollup/pull/2558): Prevent crash when not returning sourcemaps from `renderChunk` (@kyle1320)

## 0.67.1
*2018-11-11*

### Bug Fixes
* Deconflict CLI entry points with same name but on different paths if no explicit naming is used (#2548)

### Pull Requests
* [#2548](https://github.com/rollup/rollup/pull/2548): Deconflict CLI entry points with same name but on different paths if no explicit naming is used (@lukastaegert)

## 0.67.0
*2018-11-04*

### Breaking Changes
none

### Features
* Do not resolve external dynamic imports via plugins to match the logic for static external imports again (#2505)
* Support virtual modules created by plugins when preserving modules (#2511)
* Add new `output.sourcemapExcludeSources` option to exclude the actual sources from sourcemaps (#2531)

### Bug Fixes
* Fix TypeScript type for sourcemaps (#2507)
* Fix order of external and inter-chunk imports to match the proper execution order (#2508)
* Do not tree-shake children of unknown nodes to e.g. properly handle do-expressions via acorn plugin (#2510)
* Prevent memory leak when using the bundle as cache (#2522)
* Fix mis-placed semicolons for certain SystemJS exports (#2529)

### Pull Requests
* [#2505](https://github.com/rollup/rollup/pull/2505): Do not resolve external dynamic imports via plugins (@lukastaegert)
* [#2507](https://github.com/rollup/rollup/pull/2507): Fix public sourcemap type (@aMarCruz)
* [#2508](https://github.com/rollup/rollup/pull/2508): Improve execution order of chunks and externals (@lukastaegert)
* [#2510](https://github.com/rollup/rollup/pull/2510): Do not tree-shake children of unknown nodes to e.g. properly handle do-expressions via acorn plugin (@devsnek)
* [#2511](https://github.com/rollup/rollup/pull/2511): Create chunks for virtual modules when preserving modules (@lukastaegert)
* [#2522](https://github.com/rollup/rollup/pull/2522): Prevent memory leak when using the bundle as cache (@kyle1320)
* [#2529](https://github.com/rollup/rollup/pull/2529): Fix mis-placed semicolons for certain SystemJS exports (@kyle1320)
* [#2531](https://github.com/rollup/rollup/pull/2531): add `sourcemapExcludeSources` option to exclude the source content from sourcemaps (@kitsonk)

## 0.66.6
*2018-10-10*
* Properly deconflict function and class declaration ids of reassigned default exports ([#2502](https://github.com/rollup/rollup/pull/2502))

## 0.66.5
*2018-10-09*
* Remove cache from memory once no longer needed ([#2496](https://github.com/rollup/rollup/pull/2496))
* Provide better error message when reexporting external namespace reexports ([#2499](https://github.com/rollup/rollup/pull/2499))

## 0.66.4
*2018-10-04*
* Fix links in warnings and errors ([#2471](https://github.com/rollup/rollup/pull/2471))

## 0.66.3
*2018-10-03*
* Detect side-effects in string.replace function arguments ([#2476](https://github.com/rollup/rollup/pull/2476))
* Make sure chunk ids are assigned before creating output bundle ([#2483](https://github.com/rollup/rollup/pull/2483))
* Use proper plugin name in error ([#2470](https://github.com/rollup/rollup/pull/2470))
* Update TypeScript version and fix type errors ([#2488](https://github.com/rollup/rollup/pull/2488))

## 0.66.2
*2018-09-21*
* Add additional information to parse errors messages in JSON and other non-JS files ([#2466](https://github.com/rollup/rollup/pull/2466))

## 0.66.1
*2018-09-19*
* Ignore falsy plugins ([#2464](https://github.com/rollup/rollup/pull/2464))
* Switch back to official TypeScript plugin ([#2465](https://github.com/rollup/rollup/pull/2465))

## 0.66.0
*2018-09-16*
* Support ES2019 optional catch bindings ([#2455](https://github.com/rollup/rollup/pull/2455))
* Add option to transform paths within sourcemaps ([#2430](https://github.com/rollup/rollup/pull/2430))
* Add renderStart and renderEnd plugin hooks ([#2438](https://github.com/rollup/rollup/pull/2438))
* Expose ESM browser build and minify browser builds ([#2437](https://github.com/rollup/rollup/pull/2437)
* Associate hoisted variables in function bodys with function parameters ([#2456](https://github.com/rollup/rollup/pull/2456))
* Fix issue when deconflicting variables used as pattern defaults ([#2446](https://github.com/rollup/rollup/pull/2446))
* Properly deconflict default exported class and function expressions with ids ([#2458](https://github.com/rollup/rollup/pull/2458))
* Faster internal test builds ([#2457](https://github.com/rollup/rollup/pull/2457))
* Switch to rollup-plugin-typescript2 ([#2460](https://github.com/rollup/rollup/pull/2460))
* Fix internal "perf" script ([#2433](https://github.com/rollup/rollup/pull/2433))
* Test that errors are passed to the buildEnd hook ([#2450](https://github.com/rollup/rollup/pull/2450))

## 0.65.2
*2018-09-05*
* Prevent watch mode memory leak ([#2441](https://github.com/rollup/rollup/pull/2441))

## 0.65.1
*2018-09-05*
* Prevent globbing when using chokidar ([#2432](https://github.com/rollup/rollup/pull/2432))

## 0.65.0
*2018-08-25*
* Refactor and unify plugin system ([#2382](https://github.com/rollup/rollup/pull/2382))
* Implement plugin cache API ([#2389](https://github.com/rollup/rollup/pull/2389))
* Add watchChange plugin hook to watch changed files, deprecate asset dependencies ([#2405](https://github.com/rollup/rollup/pull/2405))
* Refine asset handling ([#2369](https://github.com/rollup/rollup/pull/2369))
* Implement `renderChunk` hook to replace `transformChunk` and `transformBundle` hooks ([#2406](https://github.com/rollup/rollup/pull/2406))
* Add rollup version to plugin context ([#2394](https://github.com/rollup/rollup/pull/2394))
* Do not resume stdin in watch mode to fix it for Lerna users ([#2410](https://github.com/rollup/rollup/pull/2410))
* Allow `[format]` placeholder for id generation ([#2387](https://github.com/rollup/rollup/pull/2387))
* Always log error stacks even when a code frame is given ([#2417](https://github.com/rollup/rollup/pull/2417))
* Do not test module ids starting with `\0` as external ([#2400](https://github.com/rollup/rollup/pull/2400))
* Fix tracing of namespace variables ([#2408](https://github.com/rollup/rollup/pull/2408))
* Fix re-tracing of namespace variables ([#2420](https://github.com/rollup/rollup/pull/2420))
* Properly wrap comment annotations in SystemJS exports ([#2408](https://github.com/rollup/rollup/pull/2408))
* Fix renaming of destructured parameters ([#2419](https://github.com/rollup/rollup/pull/2419))
* Do not display version in watch mode when using `--silent` ([#2392](https://github.com/rollup/rollup/pull/2392))
* Make `cacheExpiry` an experimental option for now ([#2401](https://github.com/rollup/rollup/pull/2401))
* Lint test configs on commit ([#2402](https://github.com/rollup/rollup/pull/2402))
* Add code of conduct ([#2388](https://github.com/rollup/rollup/pull/2388))
* Move to CircleCI ([#2390](https://github.com/rollup/rollup/pull/2390))
* Update pull request template ([#2404](https://github.com/rollup/rollup/pull/2404))

## 0.64.1
*2018-08-07*
* Do not render initializers of hoisted variables in dead branches ([#2384](https://github.com/rollup/rollup/pull/2384))

## 0.64.0
*2018-08-07*
* Print memory consumption together with performance timings ([#2370](https://github.com/rollup/rollup/pull/2370))
* Enable plugins to mark imports as external by returning false for resolveId ([#2351](https://github.com/rollup/rollup/pull/2351))
* Always retain empty manual chunks ([#2362](https://github.com/rollup/rollup/pull/2362))
* Ensure CLI warnings are shown on errors and add error for external id collisions ([#2334](https://github.com/rollup/rollup/pull/2334))
* Remove unnecessary dependency, update dependencies, fix linting of test config ([#2376](https://github.com/rollup/rollup/pull/2376))
* Add targeted Github issue templates ([#2356](https://github.com/rollup/rollup/pull/2356))

## 0.63.5
*2018-08-01*
* Ensure onwrite plugin hooks execute in sequence ([#2364](https://github.com/rollup/rollup/pull/2364))
* Always warn when no name is provided for a global module ([#2359](https://github.com/rollup/rollup/pull/2359))
* Add utility type for user created plugins ([#2355](https://github.com/rollup/rollup/pull/2355))
* Remove deprecated es6 format from types ([#2349](https://github.com/rollup/rollup/pull/2349))
* Mark inlineDynamicImports as optional in types ([#2348](https://github.com/rollup/rollup/pull/2348))

## 0.63.4
*2018-07-20*
* Use turbocolor instead of chalk ([#2339](https://github.com/rollup/rollup/pull/2339))

## 0.63.3
*2018-07-20*
* Handle expressions where "in" and "instanceof" are applied to primitive values ([#2344](https://github.com/rollup/rollup/pull/2344))

## 0.63.2
*2018-07-18*
* Fix bind order in for-of loops ([#2338](https://github.com/rollup/rollup/pull/2338))

## 0.63.1
*2018-07-18*

## 0.63.0
*2018-07-17*
* Fix many tree-shaking related issues ([#2315](https://github.com/rollup/rollup/pull/2315))
* Add experimental support for top-level await ([#2235](https://github.com/rollup/rollup/pull/2235))
* Prevent duplicate version printout in watch mode ([#2325](https://github.com/rollup/rollup/pull/2325))
* Respect error frames provided by plugins ([#2309](https://github.com/rollup/rollup/pull/2309))
* Add `esm` format alias to types ([#2327](https://github.com/rollup/rollup/pull/2327))
* Further unify internal test setup ([#2329](https://github.com/rollup/rollup/pull/2329))

## 0.62.0
*2018-06-27*
* Add option to automatically shim missing exports ([#2118](https://github.com/rollup/rollup/pull/2118))
* Inline dynamic imports that are also imported statically and only used in a single chunk ([#2295](https://github.com/rollup/rollup/pull/2295))
* Handle caching and invalidation of assets ([#2267](https://github.com/rollup/rollup/pull/2267))
* Fix plugin related types ([#2299](https://github.com/rollup/rollup/pull/2299))

## 0.61.2
*2018-06-23*
* Improve watcher error handling, only rebuild invalidated outputs ([#2296](https://github.com/rollup/rollup/pull/2296))
* Update dependencies, make watcher more stable ([#2297](https://github.com/rollup/rollup/pull/2297))

## 0.61.1
*2018-06-21*
* Do not try to deconflict "undefined" ([#2291](https://github.com/rollup/rollup/pull/2291))
* Properly track values for loop interator declarations and reassigned namespaces, add smoke test ([#2292](https://github.com/rollup/rollup/pull/2292))

## 0.61.0
*2018-06-20*
* Declare file dependencies via transform plugin hooks ([#2259](https://github.com/rollup/rollup/pull/2259))
* Handle undefined values when evaluating conditionals ([#2264](https://github.com/rollup/rollup/pull/2264))
* Handle known undefined properties when evaluating conditionals ([#2265](https://github.com/rollup/rollup/pull/2265))
* Access watch events via the plugin context ([#2261](https://github.com/rollup/rollup/pull/2261))
* Add option to suppress `__esModule` flag in output ([#2287](https://github.com/rollup/rollup/pull/2287))
* Fix issue when re-declaring variables, track reassignments in more cases ([#2279](https://github.com/rollup/rollup/pull/2279))
* Add VSCode debug settings ([#2276](https://github.com/rollup/rollup/pull/2276))

## 0.60.7
*2018-06-14*
* Fix typing issue ([#2269](https://github.com/rollup/rollup/pull/2269))

## 0.60.6
*2018-06-14*
* Track mutations of included virtual arrays ([#2263](https://github.com/rollup/rollup/pull/2263))
* Update readme ([#2266](https://github.com/rollup/rollup/pull/2266))

## 0.60.5
*2018-06-14*
* Track deep reassignments of global and exported variables and improve performance ([#2254](https://github.com/rollup/rollup/pull/2254))

## 0.60.4
*2018-06-13*
* Properly handle initially uninitialized exports and exports of globals in SystemJS output ([#2258](https://github.com/rollup/rollup/pull/2258))

## 0.60.3
*2018-06-13*
* Fix types to allow watching an array of outputs ([#2262](https://github.com/rollup/rollup/pull/2262))

## 0.60.2
*2018-06-11*
* Permit setting an asset's source in `generateBundle` ([#2256](https://github.com/rollup/rollup/pull/2256))
* Add automatic linting ([#2242](https://github.com/rollup/rollup/pull/2242))

## 0.60.1
*2018-06-07*
* Fix plugin regressions ([#2246](https://github.com/rollup/rollup/pull/2246))
* Avoid conflicts for large numbers of variables ([#2244](https://github.com/rollup/rollup/pull/2244))

## 0.60.0
*2018-06-06*
* New plugin hooks: transformChunk, buildStart, buildEnd; extended plugin context with warn, error, resolveId, isExternal, emitAsset, setAssetSource and getAssetFileName available to any hook ([#2208](https://github.com/rollup/rollup/pull/2208))
* [BREAKING] Deprecate the `legacy` option and thus IE8 support ([#2141](https://github.com/rollup/rollup/pull/2141))
* Detect more known extensions and load .mjs without extension ([#2211](https://github.com/rollup/rollup/pull/2211))
* Add compact output mode ([#2151](https://github.com/rollup/rollup/pull/2151))
* Significantly improve sourcemap generation performance ([#2228](https://github.com/rollup/rollup/pull/2228))
* Enable naming SystemJS modules ([#2028](https://github.com/rollup/rollup/pull/2028))
* Do not use alternate screen if clearScreen is set in watch mode ([#2125](https://github.com/rollup/rollup/pull/2125))
* Allow object input form for code-splitting in watch mode ([#2217](https://github.com/rollup/rollup/pull/2217))
* Track reassignments of methods of exports from outside ([#2240](https://github.com/rollup/rollup/pull/2240))
* Preserve id of default exported functions and classes ([#2234](https://github.com/rollup/rollup/pull/2234))
* Add semicolons after default exports ([#2209](https://github.com/rollup/rollup/pull/2209))
* Fix build problems on Windows ([#2232](https://github.com/rollup/rollup/pull/2232))
* Display install size in readme ([#2196](https://github.com/rollup/rollup/pull/2196))
* Improve preserve modules test ([#2236](https://github.com/rollup/rollup/pull/2236))

## 0.59.4
*2018-05-28*
* Fix performance regression when many return statements are used ([#2218](https://github.com/rollup/rollup/pull/2218))

## 0.59.3
*2018-05-24*
* Fix reassignment tracking for constructor parameters ([#2214](https://github.com/rollup/rollup/pull/2214))

## 0.59.2
*2018-05-21*
* Fix reassignment tracking in for-in loops ([#2205](https://github.com/rollup/rollup/pull/2205))

## 0.59.1
*2018-05-16*
* Fix infinite recursion when determining literal values of circular structures ([#2193](https://github.com/rollup/rollup/pull/2193))
* Fix invalid code when simplifying expressions without spaces ([#2194](https://github.com/rollup/rollup/pull/2194))

## 0.59.0
*2018-05-15*
* Tree-shake statically analysable dynamic conditionals ([#2167](https://github.com/rollup/rollup/pull/2167))
* Do not emit empty chunks when code-splitting or empty files when preserving modules ([#2128](https://github.com/rollup/rollup/pull/2128))
* Support `import.meta.url` ([#2164](https://github.com/rollup/rollup/pull/2164))
* Add `esm` format alias ([#2102](https://github.com/rollup/rollup/pull/2102))
* Use alphanumeric base64 characters when deconflicting variables ([#2188](https://github.com/rollup/rollup/pull/2188))
* Improve handling of external modules imported as both default and named imports ([#2136](https://github.com/rollup/rollup/pull/2136))
* Properly deconflict named imports from other chunks ([#2177](https://github.com/rollup/rollup/pull/2177))
* Fix an issue with namespaces containing reexports ([#2157](https://github.com/rollup/rollup/pull/2157))
* Fix an issue with with incorrectly mapped default exports when code-splitting CJS or AMD modules ([#2178](https://github.com/rollup/rollup/pull/2178))
* Fix an issue with wrong paths of relative external imports ([#2160](https://github.com/rollup/rollup/pull/2160))
* Fix an issue when using default exports and `interop: false` ([#2149](https://github.com/rollup/rollup/pull/2149))
* Fix in issue with invalid syntax in SystemJS output ([#2187](https://github.com/rollup/rollup/pull/2187))
* Fix an issue when tree-shaking call expressions and reassigned variables ([#2186](https://github.com/rollup/rollup/pull/2186))
* Fix file paths in source maps ([#2161](https://github.com/rollup/rollup/pull/2161))
* Fix wrong file name in error message ([#2137](https://github.com/rollup/rollup/pull/2137))
* Always use npm 5 on CI ([#2185](https://github.com/rollup/rollup/pull/2185))

## 0.58.2
*2018-04-23*
* Fix rendering of certain statically resolvable if statements ([#2146](https://github.com/rollup/rollup/pull/2146))

## 0.58.1
*2018-04-18*
* Fix comment detection ([#2129](https://github.com/rollup/rollup/pull/2129))

## 0.58.0
*2018-04-16*
* Support individual chunk names with optional content hashes ([#2068](https://github.com/rollup/rollup/pull/2068))
* Support manually created chunks ([#2084](https://github.com/rollup/rollup/pull/2084))
* Automatically import deep dependencies when code splitting for better performance ([#2073](https://github.com/rollup/rollup/pull/2073))
* Automatically minify internal export/import names for esm and system output ([#2087](https://github.com/rollup/rollup/pull/2087))
* Add option to automatically merge small chunks ([#2090](https://github.com/rollup/rollup/pull/2090))
* Significantly improve tree-shaking performance ([#2119](https://github.com/rollup/rollup/pull/2119))
* Enable tree-shaking for logical expressions ([#2098](https://github.com/rollup/rollup/pull/2098))
* Rework external types and reduce type related dependencies ([#2108](https://github.com/rollup/rollup/pull/2108))
* Support parallel dependency resolution ([#2116](https://github.com/rollup/rollup/pull/2116))
* Improve deprecation handling ([#2076](https://github.com/rollup/rollup/pull/2076))
* Enable `--perf` timings in watch mode ([#2065](https://github.com/rollup/rollup/pull/2065))
* Improve performance timers ([#2111](https://github.com/rollup/rollup/pull/2111))
* Improve error handling for plugins ([#2100](https://github.com/rollup/rollup/pull/2100))
* Improve error when using `--dir` in a single file build ([#2123](https://github.com/rollup/rollup/pull/2123))
* Do not warn for external imports that are unused due to tree-shaking ([#2124](https://github.com/rollup/rollup/pull/2124))
* Update mixed export warning message ([#2107](https://github.com/rollup/rollup/pull/2107))
* Remove duplicate badges from readme ([#2083](https://github.com/rollup/rollup/pull/2083))
* Update readme examples ([#2086](https://github.com/rollup/rollup/pull/2086))

## 0.57.1
*2018-03-17*
* Improve sourcemap generation performance ([#2062](https://github.com/rollup/rollup/pull/2062))
* Add reserved config option namespace and improve CLI interface ([#2063](https://github.com/rollup/rollup/pull/2063))
* Fix issue with default exported function and class expressions ([#2059](https://github.com/rollup/rollup/pull/2059))
* Replace `forEach` with faster `for` loops in some places ([#2064](https://github.com/rollup/rollup/pull/2064))

## 0.57.0
*2018-03-15*
* Add option to preserve the module structure instead of bundling ([#1922](https://github.com/rollup/rollup/pull/1922))
* Enable watch mode when code-splitting ([#2035](https://github.com/rollup/rollup/pull/2035))
* Optionally pass CLI commands to config file ([#1926](https://github.com/rollup/rollup/pull/1926))
* Option to add correct `.toString` tags to namespaces ([#2041](https://github.com/rollup/rollup/pull/2041))
* Option and scripts to display performance timings ([#2045](https://github.com/rollup/rollup/pull/2045))
* Fixes for exported or early accessed namespaces + improved namespace indentation ([#2041](https://github.com/rollup/rollup/pull/2041))
* Include missing TypeScript dependencies ([#1965](https://github.com/rollup/rollup/pull/1965))
* Add #_PURE annotation to frozen namespaces ([#2044](https://github.com/rollup/rollup/pull/2044))
* Improve sourcemap and tree-shaking performance ([#2052](https://github.com/rollup/rollup/pull/2052))
* Inline sourcemap lookups and make rollup smaller ([#2055](https://github.com/rollup/rollup/pull/2055))
* Use updated magic-string types ([#2057](https://github.com/rollup/rollup/pull/2057))
* [BREAKING] Revert class id preservation from #2025 ([#2048](https://github.com/rollup/rollup/pull/2048))
* [BREAKING] Refactor missing export plugin hook ([#1987](https://github.com/rollup/rollup/pull/1987))

## 0.56.5
*2018-03-07*
* Preserve ids when deconflicting classes ([#2025](https://github.com/rollup/rollup/pull/2025))
* Fix an issue with re-exported namespace imports ([#2034](https://github.com/rollup/rollup/pull/2034))
* Prevent an infinite loop when binding member expressions ([#1963](https://github.com/rollup/rollup/pull/1963))
* Convert code style via prettier ([#2031](https://github.com/rollup/rollup/pull/2031))
* Fix links in documentation ([#2026](https://github.com/rollup/rollup/pull/2026))

## 0.56.4
*2018-03-05*
* Make rollup builds reproducible ([#2024](https://github.com/rollup/rollup/pull/2024))
* Improve error handling for source maps ([#2012](https://github.com/rollup/rollup/pull/2012))
* Properly handle SystemJS default exports without semicolons ([#2019](https://github.com/rollup/rollup/pull/2019))
* Properly handle importing the same variable several times when code-splitting ([#2020](https://github.com/rollup/rollup/pull/2020))
* Improve re-export tracing ([#2021](https://github.com/rollup/rollup/pull/2021))
* Apply "prettier" on commit ([#2017](https://github.com/rollup/rollup/pull/2017))
* Automatically clean up outdated tests ([#2009](https://github.com/rollup/rollup/pull/2009))
* Add SystemJS output to form tests ([#2022](https://github.com/rollup/rollup/pull/2022))
* Improve internal build configuration ([#2016](https://github.com/rollup/rollup/pull/2016))

## 0.56.3
*2018-02-25*
* Fix issues around default exports and module facades ([#2001](https://github.com/rollup/rollup/pull/2001))
* Improve and fix internal chunk interface ([#1994](https://github.com/rollup/rollup/pull/1994))
* Fix superfluous semicolons added after declarations ([#1999](https://github.com/rollup/rollup/pull/1999))
* Improve code-splitting tests ([#1990](https://github.com/rollup/rollup/pull/1990))

## 0.56.2
*2018-02-19*
* Fix handling of reassigned default exports ([#1975](https://github.com/rollup/rollup/pull/1975))
* Fix handling of renamed exports in entry points ([#1977](https://github.com/rollup/rollup/pull/1977))
* Update internal TypeScript version ([#1980](https://github.com/rollup/rollup/pull/1980))
* Omit compiled source files from published types ([#1981](https://github.com/rollup/rollup/pull/1981))
* Fix example in readme file ([#1984](https://github.com/rollup/rollup/pull/1984))
* Fix non-replaced dynamic imports in non-ESM output ([#1985](https://github.com/rollup/rollup/pull/1985))

## 0.56.1
*2018-02-16*
* Fix regression when rendering switch statements ([#1971](https://github.com/rollup/rollup/pull/1971))

## 0.56.0
*2018-02-15*
* Update to ECMAScript 2018 ([#1953](https://github.com/rollup/rollup/pull/1953))
* Rework tree-shaking rendering algorithm ([#1949](https://github.com/rollup/rollup/pull/1949))
* Tree-shake pure prototype calls on literals ([#1916](https://github.com/rollup/rollup/pull/1916))
* Expose AST parser to plugins ([#1945](https://github.com/rollup/rollup/pull/1945))
* Fix namespace re-export deconflicting ([#1960](https://github.com/rollup/rollup/pull/1960))
* Allow globals to be re-exported ([#1959](https://github.com/rollup/rollup/pull/1959))
* Fix internal performance timers ([#1966](https://github.com/rollup/rollup/pull/1966))

## 0.55.5
*2018-02-10*
* Remove OpenCollective dependency ([#1915](https://github.com/rollup/rollup/pull/1915))

## 0.55.4
*2018-02-09*
* Improve name deconflicting of external variables ([#1930](https://github.com/rollup/rollup/pull/1930))
* Improve re-export handling ([#1947](https://github.com/rollup/rollup/pull/1947))
* Mark preserveSymlinks option as optional ([#1939](https://github.com/rollup/rollup/pull/1939))
* Enable code-splitting tests to check directory structures ([#1924](https://github.com/rollup/rollup/pull/1924))
* Improve TypeScript definition test ([#1954](https://github.com/rollup/rollup/pull/1954))

## 0.55.3
*2018-02-01*
* Remove OpenCollective dependency ([#1915](https://github.com/rollup/rollup/pull/1915))

## 0.55.2
*2018-02-01*
* Add option to not follow symlinks ([#1819](https://github.com/rollup/rollup/pull/1819))
* Fix crash in windows shell ([#1928](https://github.com/rollup/rollup/pull/1928))
* Fix and test for external TypeScript errors ([#1903](https://github.com/rollup/rollup/pull/1903))
* Activate OpenCollective ([#1915](https://github.com/rollup/rollup/pull/1915))
* Optimize CI scripts ([#1921](https://github.com/rollup/rollup/pull/1921))

## 0.55.1
*2018-01-26*
* Improve dynamic import workflow ([#1907](https://github.com/rollup/rollup/pull/1907))
* Properly handle multiple dynamic imports of the same module ([#1911](https://github.com/rollup/rollup/pull/1911))
* Fix import specifier deshadowing ([#1912](https://github.com/rollup/rollup/pull/1912))
* Allow plugin load hook to return an empty string ([#1908](https://github.com/rollup/rollup/pull/1908))
* Let onwarn handler accept strings ([#1905](https://github.com/rollup/rollup/pull/1905))

## 0.55.0
*2018-01-23*
* Support code splitting ([#1841](https://github.com/rollup/rollup/pull/1841))
* Support SystemJS as output format ([#1897](https://github.com/rollup/rollup/pull/1897))
* Allow injecting acorn plugins ([#1857](https://github.com/rollup/rollup/pull/1857))
* Add `missingExport` plugin hook ([#1845](https://github.com/rollup/rollup/pull/1845))
* No longer parse config files via bublé ([#1899](https://github.com/rollup/rollup/pull/1899))
* Use externally maintained dynamic import acorn plugin ([#1891](https://github.com/rollup/rollup/pull/1891))
* Fix an error message ([#1886](https://github.com/rollup/rollup/pull/1886))
* Refactor internal rendering options ([#1900](https://github.com/rollup/rollup/pull/1900))
* Extract internal path resolution ([#1879](https://github.com/rollup/rollup/pull/1879))
* Extract internal bundle option handling ([#1880](https://github.com/rollup/rollup/pull/1880))
* Add import description type ([#1884](https://github.com/rollup/rollup/pull/1884))
* Clean up watch options types ([#1860](https://github.com/rollup/rollup/pull/1860))
* Clean up some tests ([#1888](https://github.com/rollup/rollup/pull/1888))

## 0.54.1
*2018-01-17*
* Fix TypeScript errors in emitted type definitions ([#1871](https://github.com/rollup/rollup/pull/1871))

## 0.54.0
*2018-01-12*
* Automatically inline locally resolvable dynamic imports ([#1816](https://github.com/rollup/rollup/pull/1816))
* Preserve directives in function bodies ([#1856](https://github.com/rollup/rollup/pull/1856))
* Refactor an error notification ([#1846](https://github.com/rollup/rollup/pull/1846))
* Refactor a wrong import ([#1863](https://github.com/rollup/rollup/pull/1863))
* Improve emitted TypeScript definitions ([#1864](https://github.com/rollup/rollup/pull/1864))
* Refactor unused import ([#1866](https://github.com/rollup/rollup/pull/1866))

## 0.53.4
*2018-01-10*
* More type cleanup ([#1858](https://github.com/rollup/rollup/pull/1858))
* Use chalks internal helper to detect if colors should be used ([#1853](https://github.com/rollup/rollup/pull/1853))
* Refactor entity handling to use interfaces ([#1840](https://github.com/rollup/rollup/pull/1840))
* Use immutable.js internal types ([#1844](https://github.com/rollup/rollup/pull/1844))
* Ship `TypeScript` declaration files ([#1837](https://github.com/rollup/rollup/pull/1837))

## 0.53.3
*2018-01-02*
* Use correct name when re-exporting from external modules ([#1794](https://github.com/rollup/rollup/pull/1794))
* Disable warnings when `resolveId` returns false ([#1825](https://github.com/rollup/rollup/pull/1825))

## 0.53.2
*2017-12-30*
* Properly handle output arrays in the JavaScript API ([#1827](https://github.com/rollup/rollup/pull/1827))

## 0.53.1
*2017-12-28*
* Properly deprecate more config options ([#1812](https://github.com/rollup/rollup/pull/1812))
* Pass output options to `transformBundle` plugin hook ([#1813](https://github.com/rollup/rollup/pull/1813))

## 0.53.0
*2017-12-22*
* Experimental dynamic import support ([#1790](https://github.com/rollup/rollup/pull/1790))
* Unify config validation ([#1805](https://github.com/rollup/rollup/pull/1805))

## 0.52.3
*2017-12-19*
* Properly hoist default exported functions in more situations ([#1799](https://github.com/rollup/rollup/pull/1799))
* Allow plugin transformations to not overwrite source maps by returning null ([#1797](https://github.com/rollup/rollup/pull/1797))
* Provide more parameters to "external" handler ([#1792](https://github.com/rollup/rollup/pull/1792))

## 0.52.2
*2017-12-15*
* No longer ignore side-effects in JSON.parse and JSON.stringify ([#1785](https://github.com/rollup/rollup/pull/1785))
* Updated links in warnings ([#1776](https://github.com/rollup/rollup/pull/1776))
* No longer prevent self-imports ([#1777](https://github.com/rollup/rollup/pull/1777))
* Properly hoist default exported functions ([#1787](https://github.com/rollup/rollup/pull/1787))
* Prevent corruption when re-exporting default exports ([#1765](https://github.com/rollup/rollup/pull/1765))

## 0.52.1
*2017-12-05*
* Improve deprecation warnings ([#1765](https://github.com/rollup/rollup/pull/1765))
* Properly use stdin ([#1774](https://github.com/rollup/rollup/pull/1774))
* Let --environment be used multiple times ([#1768](https://github.com/rollup/rollup/pull/1768))
* Always transpile config files ([#1759](https://github.com/rollup/rollup/pull/1759))
* Respect globals option in headers of UMD and IIFE files ([#1747](https://github.com/rollup/rollup/pull/1747))

## 0.52.0
*2017-11-25*
* Accept config as promise ([#1731](https://github.com/rollup/rollup/pull/1731))
* Accept promises for intro/outro/banner/footer ([#1253](https://github.com/rollup/rollup/pull/1253))
* Option to prevent freezing of namespace imports ([#1696](https://github.com/rollup/rollup/pull/1696))
* Option to retain all output in watch mode ([#1688](https://github.com/rollup/rollup/pull/1688))
* Options to fine-tune treeshaking ([#1760](https://github.com/rollup/rollup/pull/1760))

## 0.51.8
*2017-11-19*
* Fix speed problems by simplifying treeshaking reassignment handling ([#1740](https://github.com/rollup/rollup/pull/1740))
* Update dependencies ([#1742](https://github.com/rollup/rollup/pull/1742))

## 0.51.7
*2017-11-17*
* Keep "this"-context when calling sequence expressions ([#1724](https://github.com/rollup/rollup/pull/1724))

## 0.51.6
*2017-11-16*
* Use sourcemaps to determine error locations ([#1728](https://github.com/rollup/rollup/pull/1728))

## 0.51.5
*2017-11-11*
* Fix regressions with uninitialised conditional expressions ([#1720](https://github.com/rollup/rollup/pull/1720))

## 0.51.4
*2017-11-11*
* Fix regressions preventing builds ([#1725](https://github.com/rollup/rollup/pull/1725))

## 0.51.3
*2017-11-10*
* Fix regression when treeshaking sequence expressions ([#1717](https://github.com/rollup/rollup/pull/1717))

## 0.51.2
*2017-11-09*
* Fix treeshaking regression when labels are used inside functions ([#1712](https://github.com/rollup/rollup/pull/1712))

## 0.51.1
*2017-11-08*
* Fix an issue with empty return statements ([#1704](https://github.com/rollup/rollup/pull/1704))

## 0.51.0
*2017-11-08*
* Massive improvements to the treeshaking algorithm ([#1667](https://github.com/rollup/rollup/pull/1667))

## 0.50.1
*2017-11-08*
* Fix treeshaking regression ([#1695](https://github.com/rollup/rollup/pull/1695))
* Treeshaking improvements ([#1650](https://github.com/rollup/rollup/pull/1650))
* Enable installation from Github ([#1670](https://github.com/rollup/rollup/pull/1670))
* Update documentation ([#1660](https://github.com/rollup/rollup/pull/1660))

## 0.50.0
*2017-09-16*
* Many treeshaking improvements ([#1624](https://github.com/rollup/rollup/pull/1624))
* Show finish time in watch mode ([#1620](https://github.com/rollup/rollup/pull/1620))

## 0.49.3
*2017-09-08*
* Respect `watch` options ([#1596](https://github.com/rollup/rollup/issues/1596))
* Fix treeshaking regressions ([#1604](https://github.com/rollup/rollup/pull/1604))
* Allow `-o` to work with `output.format` ([#1606](https://github.com/rollup/rollup/pull/1606))

## 0.49.2
*2017-08-29*
* Fix treeshaking regressions ([#1591](https://github.com/rollup/rollup/pull/1591))

## 0.49.1
*2017-08-28*
* Fix treeshaking regressions ([#1586](https://github.com/rollup/rollup/pull/1586))

## 0.49.0
*2017-08-27*
* Completely update the treeshaking algorithm ([#1582](https://github.com/rollup/rollup/pull/1582))
* Only flip screen buffer if appropriate ([#1574](https://github.com/rollup/rollup/pull/1574))
* Guard against two instances creating the same dir ([#1576](https://github.com/rollup/rollup/pull/1576))

## 0.48.2

* Paths is an output option ([#1569](https://github.com/rollup/rollup/pull/1569))

## 0.48.1

* Print deprecation warnings in watch mode, and fix options when watcher restarts ([#1568](https://github.com/rollup/rollup/pull/1568))

## 0.48.0

* Numerous changes to the `options` object and CLI arguments ([#1479](https://github.com/rollup/rollup/issues/1479)). See [this gist](https://gist.github.com/Rich-Harris/d472c50732dab03efeb37472b08a3f32) for a rundown.

## 0.47.6

* Set `process.env.ROLLUP_WATCH` *before* loading config file

## 0.47.5

* Fix broken multi-bundle configs with `rollup.watch` ([#1532](https://github.com/rollup/rollup/issues/1532))

## 0.47.4

* Delete cached config file before reloading in watch mode

## 0.47.3

* Deshadow aliased imports ([#1550](https://github.com/rollup/rollup/issues/1550))

## 0.47.2

* Rebuild with dependency that npm randomly deleted

## 0.47.1

* Ignore external namespace imports when deshadowing ([#1547](https://github.com/rollup/rollup/issues/1547))

## 0.47.0

* Watch config file, restart `rollup.watch` on change ([#1535](https://github.com/rollup/rollup/issues/1535))
* Correctly render `export { foo } from` declarations in `es` output ([#1543](https://github.com/rollup/rollup/pull/1543))
* Reinstate missing `rollup.VERSION`

## 0.46.3

* init for/for-of loop section head with correct scopes ([#1538](https://github.com/rollup/rollup/issues/1538), [#1539](https://github.com/rollup/rollup/issues/1539))
* Fix namespace imports and re-exports in `es` output ([#1511](https://github.com/rollup/rollup/issues/1511))
* Deshadow indirectly imported namespaces ([#1488](https://github.com/rollup/rollup/issues/1488), [#1505](https://github.com/rollup/rollup/issues/1505))

## 0.46.2

* Pass options to `bundle.write` correctly in `rollup.watch` ([#1533](https://github.com/rollup/rollup/issues/1533))
* init for-in loop section head with correct scopes ([#1480](https://github.com/rollup/rollup/issues/1480))
* support `--no-interop` flag ([#1524](https://github.com/rollup/rollup/issues/1524))

## 0.46.1

* Remove `rollup.watch` from browser build ([#1530](https://github.com/rollup/rollup/issues/1530))
* Remove `source-map-support` dependency ([#1528](https://github.com/rollup/rollup/issues/1528))

## 0.46.0

* `options.format` is now required ([#1491](https://github.com/rollup/rollup/pull/1491))
* if `options.format` is `es6`, it will now throw an error (should be `es`) ([#1491](https://github.com/rollup/rollup/pull/1491))
* Add experimental `rollup.watch` method, replacing [rollup-watch](https://github.com/rollup/rollup-watch) ([#1491](https://github.com/rollup/rollup/pull/1491))
* Batch warnings together in CLI output ([#1491](https://github.com/rollup/rollup/pull/1491))
* Clear screen between rebuilds in `--watch` mode ([#1491](https://github.com/rollup/rollup/pull/1491))
* `onwarn` function's second argument is the default handler, allowing easier filtering without reimplementing any logic ([#1491](https://github.com/rollup/rollup/pull/1491))
* Prevent semi-colon removal after variable declaration that is for loop body ([#1275](https://github.com/rollup/rollup/issues/1275))
* Return `exports` for namespaced but non-extended IIFE bundles ([#1492](https://github.com/rollup/rollup/issues/1492))
* Fix scoping in switch statements ([#1498](https://github.com/rollup/rollup/pull/1498))
* Handle side-effects in tagged template expressions ([#1508](https://github.com/rollup/rollup/pull/1508))
* More descriptive error for missing options.format ([#1510](https://github.com/rollup/rollup/pull/1510))
* Refactor scope handling ([#1517](https://github.com/rollup/rollup/pull/1517))
* Handle failure of a config in multi-config build ([#1513](https://github.com/rollup/rollup/issues/1513))


## 0.45.2

* Fix interop when import is a string ([#1486](https://github.com/rollup/rollup/issues/1486))
* Separate `resolvedIds` from `resolvedExternalIds` ([#1490](https://github.com/rollup/rollup/pull/1490))
* Add `--extend` flag to CLI ([#1482](https://github.com/rollup/rollup/pull/1482))

## 0.45.1

* Remove `weak` from `optionalDependencies` ([#1483](https://github.com/rollup/rollup/issues/1483))

## 0.45.0

* [BREAKING] `bundle.generate(...)` returns a Promise, so that `transformBundle` plugin hooks can be asynchronous ([#1474](https://github.com/rollup/rollup/issues/1474))

## 0.44.0

* [BREAKING] Don't extend existing globals, unless `options.extend` is true ([#827](https://github.com/rollup/rollup/issues/827))
* Fix handling of catch clause parameters ([#1462](https://github.com/rollup/rollup/issues/1462))

## 0.43.1

* Fix memory leak on incremental rebuilds ([#883](https://github.com/rollup/rollup/issues/883))
* Allow `this.warn` and `this.error` to accept a `{line, column}` object as an alternative to a character index ([#1265](https://github.com/rollup/rollup/issues/1265))
* Print more useful error if entry module is 'external' ([#1264](https://github.com/rollup/rollup/issues/1264))
* Catch errors in `bundle.generate` options ([#1463](https://github.com/rollup/rollup/pull/1463))
* Fix magic-string deprecation warning ([#1445](https://github.com/rollup/rollup/pull/1445))

## 0.43.0

* Allow config files to import JSON ([#1426](https://github.com/rollup/rollup/issues/1426))
* Allow undefined imports in interop block ([#1341](https://github.com/rollup/rollup/issues/1341))
* Add `process.env.ROLLUP_WATCH = 'true'` in watch mode ([#1429](https://github.com/rollup/rollup/issues/1429))
* Add `pureExternalModules` option ([#1352](https://github.com/rollup/rollup/issues/1352))
* Allow plugins to specify `options.entry` ([#1270](https://github.com/rollup/rollup/issues/1270))
* Update dependencies

## 0.42.0

* Deprecate `options.moduleId` in favour of `options.amd.id` ([#1365](https://github.com/rollup/rollup/pull/1365))
* Add `options.amd.define` option to specify name of AMD `define` function ([#1365](https://github.com/rollup/rollup/pull/1365))
* Fix incorrect class removal with `treeshake: false` ([#1375](https://github.com/rollup/rollup/pull/1375))
* Deconflict module exports imported as namespaces ([#1384](https://github.com/rollup/rollup/issues/1384))
* Handle bare self-imports ([#1274](https://github.com/rollup/rollup/issues/1274))
* Allow config file to export an array of multiple configs ([#1389](https://github.com/rollup/rollup/pull/1389))
* Handle exponentiation operator, and fail gracefully on unknown operators ([#1416](https://github.com/rollup/rollup/issues/1416))
* Add `watch` option ([#1424](https://github.com/rollup/rollup/pull/1424))

## 0.41.6

* Preserve `originalSourceMap` on incremental rebuilds for loaders with sourcemaps ([#1336](https://github.com/rollup/rollup/issues/1336))

## 0.41.5

* Wrap ternary consequent/alternate sequences in parens ([#1273](https://github.com/rollup/rollup/issues/1273))
* Fix erroneous warning on multiple `export * from` declarations with defaults ([#1278](https://github.com/rollup/rollup/issues/1278))
* Prevent variable conflicts with `treeshake: false` ([#1268](https://github.com/rollup/rollup/issues/1268))
* Allow missing `source` when collapsing sourcemaps ([#1254](https://github.com/rollup/rollup/issues/1254))
* Allow plugins to log with strings ([#1316](https://github.com/rollup/rollup/pull/1316))

## 0.41.4

* Fix cases of multiple `export * from 'external'` declarations ([#1252](https://github.com/rollup/rollup/issues/1252))
* Fix 'TODO' error message ([#1257](https://github.com/rollup/rollup/issues/1257))

## 0.41.3

* Don't treat `this.foo` as possible namespace ([#1258](https://github.com/rollup/rollup/issues/1258))

## 0.41.2

* Optimize `namespace['foo']` references ([#1240](https://github.com/rollup/rollup/pull/1240))

## 0.41.1

* Include `new` expressions where callee is a class with side-effects ([#980](https://github.com/rollup/rollup/issues/980) [#1233](https://github.com/rollup/rollup/issues/1233))

## 0.41.0

* Add `this.warn(...)` and `this.error(...)` methods to plugin `transform` hook contexts ([#1140](https://github.com/rollup/rollup/issues/1140))
* `throw` always considered to be a side effect ([#1227](https://github.com/rollup/rollup/pull/1227))

## 0.40.2

* Add `file` property to sourcemaps for bundles with plugins ([#986](https://github.com/rollup/rollup/issues/986))
* Don't require globals for empty imports ([#1217](https://github.com/rollup/rollup/issues/1217))

## 0.40.1

* Allow missing space between `export default` and declaration ([#1218](https://github.com/rollup/rollup/pull/1218))

## 0.40.0

* [BREAKING] Better, more consistent error logging ([#1212](https://github.com/rollup/rollup/pull/1212))
* Don't use colours and emojis for non-TTY stderr ([#1201](https://github.com/rollup/rollup/issues/1201))

## 0.39.2

* Prevent mutation of cached ASTs (fixes stack overflow with rollup-watch) ([#1205](https://github.com/rollup/rollup/pull/1205))

## 0.39.1

* Ignore `var` initialisers in dead branches ([#1198](https://github.com/rollup/rollup/issues/1198))

## 0.39.0

* [BREAKING] Warnings are objects, rather than strings ([#1194](https://github.com/rollup/rollup/issues/1194))

## 0.38.3

* More informative warning for implicit external dependencies ([#1051](https://github.com/rollup/rollup/issues/1051))
* Warn when creating browser bundle with external dependencies on Node built-ins ([#1051](https://github.com/rollup/rollup/issues/1051))
* Statically analyse LogicalExpression nodes, for better dead code removal ([#1061](https://github.com/rollup/rollup/issues/1061))

## 0.38.2

* Preserve `var` declarations in dead branches ([#997](https://github.com/rollup/rollup/issues/997))
* Warn if exporting a call expression that looks like a function declaration ([#1011](https://github.com/rollup/rollup/issues/1011))
* Wrap function expressions in parentheses if necessary ([#1011](https://github.com/rollup/rollup/issues/1011))

## 0.38.1

* Fix sourcemap comment removal ([#1104](https://github.com/rollup/rollup/issues/1104))
* Warn if empty bundle is generated ([#444](https://github.com/rollup/rollup/issues/444))
* Support ES2017 syntax ([#492](https://github.com/rollup/rollup/issues/492))
* Remove unused imports from external modules ([#595](https://github.com/rollup/rollup/issues/595))
* Remove unused function and class declarations inside function bodies ([#1108](https://github.com/rollup/rollup/issues/1108), [#1178](https://github.com/rollup/rollup/issues/1178))
* Deconflict function expression IDs ([#1176](https://github.com/rollup/rollup/issues/1176))

## 0.38.0

* [BREAKING] `export { foo as default }` creates live binding ([#1078](https://github.com/rollup/rollup/issues/1078))
* Prevent sourceMappingURL removal edge case ([#988](https://github.com/rollup/rollup/issues/988))
* Bind function expression IDs to the correct scope ([#1083](https://github.com/rollup/rollup/issues/1083))
* Correctly deshadow destructured parameters with assignments ([#1008](https://github.com/rollup/rollup/issues/1008))

## 0.37.2

* Remove unused `new` expressions without side-effects ([#179](https://github.com/rollup/rollup/issues/179))
* Only remove valid sourceMappingURL comments ([#1132](https://github.com/rollup/rollup/issues/1132))
* Ensure blocks containing activated `var` declarations are included in output ([#1113](https://github.com/rollup/rollup/issues/1113))
* Support plugin outros ([#1116](https://github.com/rollup/rollup/issues/1116))

## 0.37.1

* Follow symlinks ([#447](https://github.com/rollup/rollup/issues/447))
* Fix tree-shaking of recursive functions and other cases ([#1120](https://github.com/rollup/rollup/issues/1120), [#1142](https://github.com/rollup/rollup/issues/1142))
* Support module names that require quotes ([#582](https://github.com/rollup/rollup/issues/582), [#584](https://github.com/rollup/rollup/issues/584))
* Fix [#957](https://github.com/rollup/rollup/issues/957)

## 0.37.0

* [BREAKING] Default exports are not included in reified namespaces ([#1028](https://github.com/rollup/rollup/issues/1028))
* Parentheses do not defeat tree-shaking ([#1101](https://github.com/rollup/rollup/issues/1101), [#1128](https://github.com/rollup/rollup/issues/1128))
* More `legacy` fixes: do not create getters ([#1069](https://github.com/rollup/rollup/pull/1069)), do not include `__esModule` ([#1068](https://github.com/rollup/rollup/pull/1068)), quote reserved property names ([#1057](https://github.com/rollup/rollup/pull/1057))
* Fix missing namespace member warnings ([#1045](https://github.com/rollup/rollup/issues/1045))
* Fix TypeError in arrow function without braces returning a function ([#1062](https://github.com/rollup/rollup/pull/1062))

## 0.36.4

* Only depend on program-level call expressions ([#977](https://github.com/rollup/rollup/issues/977))

## 0.36.3

* Add `legacy` option for IE8 support ([#989](https://github.com/rollup/rollup/pull/989))

## 0.36.2

* Insert semicolons where necessary to fix broken code ([#1004](https://github.com/rollup/rollup/issues/1004))
* Include module ID and location when warning about top-level `this`  ([#1012](https://github.com/rollup/rollup/pull/1012))
* More informative error for missing exports ([#1033](https://github.com/rollup/rollup/issues/1033))
* `options.moduleContext` for per-module context overrides ([#1023](https://github.com/rollup/rollup/pull/1023))

## 0.36.1

* Include naked block statements ([#981](https://github.com/rollup/rollup/issues/981))
* Correctly include falsy alternate statements in optimised if blocks ([#973](https://github.com/rollup/rollup/issues/973))
* Prevent omission of default exports that are only used by the exporting module ([#967](https://github.com/rollup/rollup/pull/967))
* Prevent warning on `auto` exports with ES output ([#966](https://github.com/rollup/rollup/pull/966))

## 0.36.0

* `export { foo as default }` no longer creates a live binding ([#860](https://github.com/rollup/rollup/issues/860))

## 0.35.15

* Warn on missing unused imports in deshadowing phase ([#928](https://github.com/rollup/rollup/issues/928))
* Always add a newline to the end of bundles ([#958](https://github.com/rollup/rollup/issues/958))

## 0.35.14

* Include all parent statements of expression with effects, up to function boundary ([#930](https://github.com/rollup/rollup/issues/930))

## 0.35.13

* Include superclasses when including their subclasses ([#932](https://github.com/rollup/rollup/issues/932))

## 0.35.12

* Add `interop: false` option to disable unwrapping of external imports ([#939](https://github.com/rollup/rollup/issues/939))

## 0.35.11

* Deconflict reified namespaces with other declarations ([#910](https://github.com/rollup/rollup/issues/910))

## 0.35.10

* Only remove EmptyStatement nodes directly inside blocks ([#913](https://github.com/rollup/rollup/issues/931))

## 0.35.9

* Support Node 0.12 ([#909](https://github.com/rollup/rollup/issues/909))

## 0.35.8

* Correctly deshadow re-assigned module functions ([#910](https://github.com/rollup/rollup/issues/910))

## 0.35.7

* Refactor `flushTime.js` ([#922](https://github.com/rollup/rollup/pull/922))

## 0.35.6

* Fix browser build

## 0.35.5

* Allow empty for loop heads ([#919](https://github.com/rollup/rollup/issues/919))

## 0.35.4

* Preserve effects in for-of and for-in loops ([#870](https://github.com/rollup/rollup/issues/870))
* Remove empty statements ([#918](https://github.com/rollup/rollup/pull/918))

## 0.35.3

* Render identifiers inside template literals

## 0.35.2

* Fix broken build caused by out of date locally installed dependencies

## 0.35.1

* Rewrite deconflicted class identifiers ([#915](https://github.com/rollup/rollup/pull/915))
* Include `dependencies` in `bundle.modules` objects ([#903](https://github.com/rollup/rollup/issues/903))
* Update to Acorn 4 ([#914](https://github.com/rollup/rollup/pull/914))

## 0.35.0

* Rewrite analysis/tree-shaking code ([#902](https://github.com/rollup/rollup/pull/902))
* Include conditional mutations of global objects ([#901](https://github.com/rollup/rollup/issues/901))
* Only reify namespaces if necessary ([#898](https://github.com/rollup/rollup/issues/898))
* Track mutations of aliased globals ([#893](https://github.com/rollup/rollup/issues/893))
* Include duplicated var declarations ([#716](https://github.com/rollup/rollup/issues/716))

## 0.34.13

* Pass `{ format }` through to `transformBundle` ([#867](https://github.com/rollup/rollup/issues/867))

## 0.34.12

* Fix `rollup --watch` ([#887](https://github.com/rollup/rollup/issues/887))
* Case-sensitive paths ([#862](https://github.com/rollup/rollup/issues/862))

## 0.34.11

* Prevent leaky state when `bundle` is reused ([#875](https://github.com/rollup/rollup/issues/875))
* Ensure `intro` appears before interop block ([#880](https://github.com/rollup/rollup/issues/880))

## 0.34.10

* Allow custom `options.context` to replace top-level `this` ([#851](https://github.com/rollup/rollup/issues/851))
* Fix `noConflict` when used via `rollup --config` ([#846](https://github.com/rollup/rollup/issues/846))
* Place `outro` block *after* export block ([#852](https://github.com/rollup/rollup/issues/852))

## 0.34.9

* Disable indentation by default, for faster bundle generation ([#812](https://github.com/rollup/rollup/pull/812))
* More helpful error on missing entry file ([#802](https://github.com/rollup/rollup/issues/802))
* Preserve comments before import declarations ([#815](https://github.com/rollup/rollup/pull/815))

## 0.34.8

* Wrap UMD factory function in parens to avoid lazy parsing ([#774](https://github.com/rollup/rollup/pull/774))

## 0.34.7

* Leave it up to resolveId to normalize the entry path ([#835](https://github.com/rollup/rollup/pull/835))
* Cache decoded mappings ([#834](https://github.com/rollup/rollup/pull/834))

## 0.34.5

* Fix circular export ([#813](https://github.com/rollup/rollup/issues/813))

## 0.34.4

* Module render performance tweak ([#823](https://github.com/rollup/rollup/pull/823))

## 0.34.3

* Avoid infinite recursion in `Bundle.sort()` ([#800](https://github.com/rollup/rollup/pull/800))

## 0.34.2

* resolveId calls are cached now to improve incremental build
* Fixed error message recursion in plugins

## 0.34.1

* Support `paths` config ([#754](https://github.com/rollup/rollup/issues/754))
* Allow `export *` from external module, internally

## 0.34.0

* Use resolved IDs for relative imports that are also external modules, to allow `options.globals` to work with them ([#763](https://github.com/rollup/rollup/issues/763))
* Ensure reassigned exports are declared in an ES bundle, and remove empty `exports.foo;` statements ([#755](https://github.com/rollup/rollup/issues/755))
* Add newline after sourcemap comment ([#756](https://github.com/rollup/rollup/issues/756))

## 0.33.2

* Add `bundle` as second argument to `ongenerate` and `onwrite` hooks ([#773](https://github.com/rollup/rollup/pull/773))
* Warn on top-level `this` ([#770](https://github.com/rollup/rollup/issues/770))

## 0.33.1

* Fix `--no-strict` option ([#751](https://github.com/rollup/rollup/pull/751))
* Fix Windows edge case with case-sensitive paths ([#760](https://github.com/rollup/rollup/pull/760))

## 0.33.0

* Downgrade missing transformer sourcemap to a warning, not an error, and print the name of the offending plugin if possible ([#746](https://github.com/rollup/rollup/issues/746))
* Warn if same name is re-exported from two modules ([#722](https://github.com/rollup/rollup/issues/722))

## 0.32.4

* Add `ongenerate` and `onwrite` plugin hooks ([#742](https://github.com/rollup/rollup/pull/742))

## 0.32.3

* Generated correct sourcemaps with reified namespaces ([#668](https://github.com/rollup/rollup/issues/668))
* Exclude plugin helper modules from sourcemaps ([#747](https://github.com/rollup/rollup/pull/747))

## 0.32.2

* Allow `--globals` to work with `--external` or `options.external` in whatever configuration ([#743](https://github.com/rollup/rollup/issues/743))

## 0.32.1

* Preserve side-effects to default exports that coincide with used named exports ([#733](https://github.com/rollup/rollup/issues/733))
* Support `rollup -c node:pkgname` ([#736](https://github.com/rollup/rollup/issues/736))

## 0.32.0

* Deprecate `es6` format in favour of `es` ([#468](https://github.com/rollup/rollup/issues/468))
* Add correct `jsnext:main` build ([#726](https://github.com/rollup/rollup/pull/726))

## 0.31.2

* Allow `load` plugins to provide sourcemap ([#715](https://github.com/rollup/rollup/pull/715))
* Allow `sourceMapFile` in config options ([#717](https://github.com/rollup/rollup/issues/717))

## 0.31.1

* Logging for errors emitted by `rollup-watch` ([#712](https://github.com/rollup/rollup/issues/712))

## 0.31.0

* Rewrite top-level `this` as `undefined` ([#707](https://github.com/rollup/rollup/pull/707))
* Pass `options.acorn` to Acorn ([#564](https://github.com/rollup/rollup/issues/564))

## 0.30.0

* Bundle CLI ([#700](https://github.com/rollup/rollup/issues/700))
* Ensure absolute paths are normalised ([#704](https://github.com/rollup/rollup/issues/704))
* Allow `rollup --watch` to work with targets

## 0.29.1

* Merge `target` options with main options ([#701](https://github.com/rollup/rollup/issues/701))
* Update magic-string ([#690](https://github.com/rollup/rollup/issues/690))

## 0.29.0

* `rollup --watch` ([#284](https://github.com/rollup/rollup/issues/284))

## 0.28.0

* Experimental support for incremental rebuilds ([#658](https://github.com/rollup/rollup/pull/658))

## 0.27.1

* Ensure names exported from a module are not replaced with reserved words ([#696](https://github.com/rollup/rollup/pull/696))
* Revert ([#692](https://github.com/rollup/rollup/pull/692)) – resolved IDs must be strings

## 0.27.0

* Use native promises instead of `es6-promise` ([#689](https://github.com/rollup/rollup/issues/689))
* Support multiple targets in config files ([#655](https://github.com/rollup/rollup/issues/655))
* Allow `resolveId` plugin functions to return non-strings ([#692](https://github.com/rollup/rollup/pull/692))

## 0.26.7

* Distinguish between default and namespace imports of external module ([#637](https://github.com/rollup/rollup/issues/637))
* Add `__esModule` property to named exports in AMD, CJS and UMD modes ([#650](https://github.com/rollup/rollup/issues/650))

## 0.26.6

* Deconflict named imports from external modules in ES bundles ([#659](https://github.com/rollup/rollup/issues/659))
* Support `options.preferConst` to generate `const` declarations for exports rather than `var` declarations ([#653](https://github.com/rollup/rollup/issues/653))

## 0.26.5

* Preserve `debugger` statements ([#664](https://github.com/rollup/rollup/issues/664))
* Allow `options.external` to be a function ([#522](https://github.com/rollup/rollup/issues/522))

## 0.26.4

* Prevent plugin-provided external IDs being normalised ([#630](https://github.com/rollup/rollup/issues/630), [#633](https://github.com/rollup/rollup/issues/633))
* Throw if module exports/re-exports the same name twice, or has multiple default exports ([#679](https://github.com/rollup/rollup/issues/679))
* Warn about `eval` security issue ([#675]((https://github.com/rollup/rollup/issues/675)))


## 0.26.3

* Ensure reference is not incorrectly marked as a reassignment ([#648](https://github.com/rollup/rollup/issues/648))

## 0.26.2

* Sanity check output of `load` hook ([#607](https://github.com/rollup/rollup/issues/607))
* Correct scoping for ID class expressions ([#626](https://github.com/rollup/rollup/issues/626))
* Warn if named and default exports are used together in auto mode ([#587](https://github.com/rollup/rollup/issues/587))
* Allow variable initialisers to be rewritten if necessary ([#632](https://github.com/rollup/rollup/issues/632))
* Prevent double `var` with no-treeshake option ([#639](https://github.com/rollup/rollup/pull/639))

## 0.26.1

* Add `treeshake: false`/`--no-treeshake` option for debugging ([#505](https://github.com/rollup/rollup/issues/505))
* Update build process to use Bublé ([#620](https://github.com/rollup/rollup/pull/620))

## 0.26.0

* Add `noConflict`/`--no-conflict` option for UMD builds ([#580](https://github.com/rollup/rollup/pull/580))
* Normalise relative external paths ([#591](https://github.com/rollup/rollup/pull/591))
* Report files causing transform errors ([#609](https://github.com/rollup/rollup/pull/609))
* Handle sourcemap segments with a single member ([#619](https://github.com/rollup/rollup/pull/619))
* Update dependencies

## 0.25.8

* Unixize entry path ([#586](https://github.com/rollup/rollup/pull/586))

## 0.25.7

* Expand deshadowed shorthand properties ([#575](https://github.com/rollup/rollup/issues/575))
* Allow external files to be non-existent ([#532](https://github.com/rollup/rollup/issues/532))

## 0.25.6

* Fix a regression introduced by #566 ([#569](https://github.com/rollup/rollup/issues/569))
* Prune dead conditional expressions more carefully ([#567](https://github.com/rollup/rollup/issues/567))

## 0.25.5

* Make sure shorthand destructuring assignments don't break ([#528](https://github.com/rollup/rollup/issues/528))
* Allow 'exports' key ([#542](https://github.com/rollup/rollup/issues/542))
* Ensure `foo.  bar` where `foo` is a namespace import is rewritten correctly ([#566](https://github.com/rollup/rollup/issues/566))
* Fix an edge case for exported globals (e.g. `export { document }`) ([#562](https://github.com/rollup/rollup/issues/562))

## 0.25.4

* Fix misnamed exports of default imports in ES bundles ([#513](https://github.com/rollup/rollup/issues/513))
* CLI: warn on missing config ([#515](https://github.com/rollup/rollup/pull/515))
* Detect side-effects in non-top-level member expression assignment ([#476](https://github.com/rollup/rollup/issues/476))
* Don't remove computed property class keys ([#504](https://github.com/rollup/rollup/issues/504))
* Augment existing global object rather than replacing ([#493](https://github.com/rollup/rollup/issues/493))
* Don't fail on `export {}`, warn instead ([#486](https://github.com/rollup/rollup/issues/486))

## 0.25.3

* Handle non-objects and `null` in `_interopDefault` ([#474](https://github.com/rollup/rollup/issues/474))

## 0.25.2

* Skip dead branches of a conditional expression (#[465](https://github.com/rollup/rollup/pull/465))
* Allow globals to be exported ([#472](https://github.com/rollup/rollup/pull/472))
* Ensure reassigned exports are exported ([#484](https://github.com/rollup/rollup/issues/484))

## 0.25.1

* Throw error if namespace is called ([#446](https://github.com/rollup/rollup/issues/446))
* Prevent shadowing bug in ES6 output ([#441](https://github.com/rollup/rollup/pull/441))
* Prevent `var exports.foo` ([#426](https://github.com/rollup/rollup/issues/426))
* Prevent double export of aliased symbols ([#438](https://github.com/rollup/rollup/issues/438))
* Provide more informative error if Rollup is used in-browser without appropriate `resolveId`/`load` hooks ([#275](https://github.com/rollup/rollup/issues/275))
* Use `_interopDefault` function to DRY out code with many external dependencies, in CommonJS output ([#458](https://github.com/rollup/rollup/pull/458))

## 0.25.0

* **breaking** Module order is determined according to spec, rather than in a way designed to prevent runtime errors. Rollup will warn if it detects runtime errors are likely ([#435](https://github.com/rollup/rollup/issues/435))
* Prevent overly aggressive tree-shaking with complex call expressions ([#440](https://github.com/rollup/rollup/issues/440))

## 0.24.1

* Handle calls to default exports other that are not function expressions or references to function declarations ([#421](https://github.com/rollup/rollup/issues/421))
* Ensure namespace blocks are created for chained imports ([#430](https://github.com/rollup/rollup/issues/430))
* Include references in computed property keys ([#434](https://github.com/rollup/rollup/issues/434))
* Use CLI `--external` option correctly ([#417](https://github.com/rollup/rollup/pull/417))
* Allow relative imports to be treated as external, if absolute paths are provided in `options.external` ([#410](https://github.com/rollup/rollup/issues/410))
* Make IIFE output adhere to Crockford style ([#415](https://github.com/rollup/rollup/pull/415))

## 0.24.0

* No longer attempts to resolve IDs in `options.external` ([#407](https://github.com/rollup/rollup/issues/407))
* Fix broken sourcemap resolution in cases where some modules are transformed and others aren't ([#404](https://github.com/rollup/rollup/issues/404))

## 0.23.2

* Ensure `dest` or `sourceMapFile` is resolved against CWD for purposes of sourcemap generation ([#344](https://github.com/rollup/rollup/issues/344))
* Support `banner`, `footer`, `intro` and `outro` options via CLI ([#330](https://github.com/rollup/rollup/issues/330))
* Allow `options.global` to be a function rather than an object, and warn on missing names ([#293](https://github.com/rollup/rollup/issues/293))
* Ensure side-effects are captured in cyclical call expressions ([#397](https://github.com/rollup/rollup/issues/397))
* Fix parse error with body-less arrow function expressions ([#403](https://github.com/rollup/rollup/issues/403))

## 0.23.1

* Reinstate missing fix from ([#392](https://github.com/rollup/rollup/pull/392))

## 0.23.0

* Add `bundleTransform` plugin hook and option ([#387](https://github.com/rollup/rollup/pull/387))
* Correctly store names in sourcemaps, regardless of transformers
* Add `--environment` option to CLI ([#388](https://github.com/rollup/rollup/pull/388))
* Handle destructuring in exports ([#374](https://github.com/rollup/rollup/issues/374))
* Fix UMD global exports bug introduced in 0.22.1 ([#392](https://github.com/rollup/rollup/pull/392))

## 0.22.2

* Prevent lost `var` keywords ([#390](https://github.com/rollup/rollup/issues/390))

## 0.22.1

* Update expected option keys ([#379](https://github.com/rollup/rollup/issues/379))
* Handle transformers that return stringified sourcemaps ([#377](https://github.com/rollup/rollup/issues/377))
* Automatically create missing namespaces if `moduleName` contains dots ([#378](https://github.com/rollup/rollup/issues/378))
* Ignore external dependency warnings coming from config file ([#333](https://github.com/rollup/rollup/issues/333))
* Update to latest magic-string for performance boost

## 0.22.0

* Duplicate warnings are squelched ([#362](https://github.com/rollup/rollup/issues/362))
* Plugins can manipulate or override the `options` object ([#371](https://github.com/rollup/rollup/pull/371))

## 0.21.3

* Validate option keys ([#348](https://github.com/rollup/rollup/pull/348))
* Allow namespaces imports to sit alongside named imports ([#355](https://github.com/rollup/rollup/issues/355))
* Count references inside destructured objects ([#364](https://github.com/rollup/rollup/issues/364))
* Preserve top-level `delete` statements ([#352](https://github.com/rollup/rollup/issues/352))

## 0.21.2

* Missing relative imports are an error, not a warning ([#321](https://github.com/rollup/rollup/issues/321))
* Fixed incorrectly renamed default exports in ES6 bundles ([#339](https://github.com/rollup/rollup/issues/339))
* Fixed infinite recursion bug ([#341](https://github.com/rollup/rollup/issues/341))

## 0.21.1

* Remove `aggressive: true` (was too aggressive) ([#309](https://github.com/rollup/rollup/pull/309))
* Handle top-level block statements ([#326](https://github.com/rollup/rollup/issues/326))
* Optimise namespaces with default exports ([#314](https://github.com/rollup/rollup/issues/314))

## 0.21.0

* Only include statements whose side-effects are relevant (i.e. contribute to exports or affect global state) ([#253](https://github.com/rollup/rollup/pull/253)) ([#253](https://github.com/rollup/rollup/pull/253))
* Exclude dead branches from analysis and inclusion ([#249](https://github.com/rollup/rollup/pull/249))
* Add `aggressive: true` option to eliminate all side-effects outside entry module
* More informative error when re-exporting non-existent binding ([#274](https://github.com/rollup/rollup/issues/274))
* Fix infinite recursion bug ([#291](https://github.com/rollup/rollup/issues/291))
* Log errors when using `rollup --config` ([#288](https://github.com/rollup/rollup/pull/288))
* Return rejected promises on startup instead of throwing error, if options are invalid ([#303](https://github.com/rollup/rollup/pull/303))

## 0.20.5

* Ensure re-exports don't create a local binding ([#270](https://github.com/rollup/rollup/pull/270))

## 0.20.4

* Check file exists at resolve time, to allow filenames with non-extension dots in them ([#250](https://github.com/rollup/rollup/pull/250))
* Import `Promise` where used, for Node 0.10 support ([#254](https://github.com/rollup/rollup/issues/254))
* Allow asynchronous transformer plugins ([#260](https://github.com/rollup/rollup/issues/260))
* Don't assume re-exported bindings are globals when deconflicting ([#267](https://github.com/rollup/rollup/issues/267))


## 0.20.3

* Fix bug where multiple `export *` declarations caused error ([#244](https://github.com/rollup/rollup/pulls/244))
* Missing namespace exports are a warning, not an error ([#244](https://github.com/rollup/rollup/pulls/244))
* Plugins can provide `banner` and `footer` options (string, or function that returns a string) ([#235](https://github.com/rollup/rollup/issues/235))
* Warn on encountering `eval` ([#186](https://github.com/rollup/rollup/issues/186))

## 0.20.2

* Handle errors in build config file
* More robust deconflicting, in cases where e.g. `foo$1` already exists
* Use Rollup CLI for own build process

## 0.20.1

* Support `--config` file to enable plugins with CLI ([#226](https://github.com/rollup/rollup/pulls/226))
* Prevent `default` being used as variable name ([#215](https://github.com/rollup/rollup/issues/215))
* Update deps

## 0.20.0

* Support for [plugins](https://github.com/rollup/rollup/wiki/Plugins) ([#207](https://github.com/rollup/rollup/pulls/207))
* BREAKING – `options.transform`, `options.load`, `options.resolveId`, `options.resolveExternal` and `options.external` are no longer supported, and should be handled by plugins. [More info](https://github.com/rollup/rollup/wiki/Plugins)
* BREAKING – the .js extension is only added if it looks like there's no extension, allowing e.g. `import data from 'data.json'` (with the appropriate transformer). For safety, always include the file extension – import `./foo.js`, not `./foo`

## 0.19.2

* Fix exporting namespaces to include all of their exports ([#204](https://github.com/rollup/rollup/issues/204))
* Namespace exotic objects are frozen to ensure that its properties cannot be modified, reconfigured, redefined or deleted ([#203](https://github.com/rollup/rollup/pulls/203))
* Fix `ReferenceError: Promise is not defined` in node v0.10 ([#189](https://github.com/rollup/rollup/issues/189))

## 0.19.1

* Fix `module.basename()` when used with custom `resolveId` function
* Use [rollup-babel](https://github.com/rollup/rollup-babel) to build self
* Exposed the version string through the API: `require( 'rollup' ).VERSION`

## 0.19.0

* **breaking** The `transform` option is no longer passed through to custom loaders. Loaders should only concern themselves with providing source code; transformation will *always* take place
* `options.transform` functions can return a string, or a `{code, map, ast}` object. Where possible, sourcemap chains will be flattened ([#175](https://github.com/rollup/rollup/pull/175))
* `options.resolveId`, `options.resolveExternal` and `options.load` can each be a function or an array of functions. If an array, the first non-null/undefined return value is used. In both cases, failed resolution/loading will fall back to the defaults, unless an error is thrown. ([#174](https://github.com/rollup/rollup/pull/174))
* New `intro` and `outro` options – similar to `banner` and `footer` except inserted *inside* any format-specific wrapper
* Multiple var declarations in an export block (e.g. `export let a = 1, b = 2`) are split up to facilitate tree-shaking ([#171](https://github.com/rollup/rollup/issues/171))
* More informative error when using a missing namespace property  ([#169](https://github.com/rollup/rollup/pull/169))
* Update various dependencies

## 0.18.5

* Allow namespaces to be assigned to variables ([#168](https://github.com/rollup/rollup/issues/168))
* Promote `chalk` and `source-map-support` to `dependencies`, as they're used by the CLI ([#167](https://github.com/rollup/rollup/pull/167))

## 0.18.4

* Make external modules configurable (i.e. `external.config.foo = 'bar'`) without erroring

## 0.18.3

* Crop indent exclusion ranges to exclude enclosing quotes ([#166](https://github.com/rollup/rollup/issues/166))

## 0.18.2

* Include definitions of namespace members that are exported as defaults

## 0.18.1

* Include `acorn.parse` in bundle, remove `sander` from dependencies, simplify build

## 0.18.0

* Internal rewrite
* Reinstate statically-analysable namespace imports
* Avoid using getters in namespace blocks where possible ([#144](https://github.com/rollup/rollup/issues/144))
* Track variable aliases ([#96](https://github.com/rollup/rollup/issues/96))
* Prevent multiline strings being indented ([#164](https://github.com/rollup/rollup/issues/164))

## 0.17.4

* Allow imports from hidden directories (replay of [#133](https://github.com/rollup/rollup/issues/133))

## 0.17.3

* Handle parenthesised default exports ([#136](https://github.com/rollup/rollup/issues/136))

## 0.17.2

* Allow use of scoped npm packages ([#131](https://github.com/rollup/rollup/issues/131))

## 0.17.1

* Allow namespaces to be passed to a function ([#149](https://github.com/rollup/rollup/issues/149))

## 0.17.0

* Roll back to 0.15.0 and reapply subsequent fixes pending resolution of ([#132](https://github.com/rollup/rollup/issues/132)) and related issues

## 0.16.4

* Fix import paths with `.` ([#133](https://github.com/rollup/rollup/issues/133))
* Prevent sourceMappingURL confusion leading to broken sourcemap
* Add code coverage reporting [#130](https://github.com/rollup/rollup/pull/130))
* Add `modules` property to user-facing `bundle` – an array with `{id}` objects ([#128](https://github.com/rollup/rollup/issues/128))

## 0.16.3

* Prevent adjacent blocks of multiple var declarations causing magic-string failure ([#105](https://github.com/rollup/rollup/issues/105))

## 0.16.2

* Top-level function calls and assignments to globals are treated as side-effects, and always included
* Import files from subdirectories of external packages, e.g. `import mod from 'foo/sub/mod'` ([#126](https://github.com/rollup/rollup/issues/126))

## 0.16.1

* Handle assignment patterns, and destructured/rest parameters, when analysing scopes
* Fix bug preventing project from self-building (make base `Identifier` class markable)

## 0.16.0

* Internal refactoring ([#99](https://github.com/rollup/rollup/pull/99))
* Optimisation for statically-analysable namespace imports ([#99](https://github.com/rollup/rollup/pull/99))
* Windows support (theoretically!) ([#117](https://github.com/rollup/rollup/pull/117) / [#119](https://github.com/rollup/rollup/pull/119))

## 0.15.0

* Load all modules specified by `import` statements, and do tree-shaking synchronously once loading is complete. This results in simpler and faster code, and enables future improvements ([#97](https://github.com/rollup/rollup/pull/97))
* Only rewrite `foo` as `exports.foo` when it makes sense to ([#92](https://github.com/rollup/rollup/issues/92))
* Fix bug with shadowed variables that are eventually exported ([#91](https://github.com/rollup/rollup/issues/91))
* Exclude unused function declarations that happen to modify a used name ([#90](https://github.com/rollup/rollup/pull/90))
* Simplify internal `Scope` model – scopes always attach to blocks, never function expressions/declarations

## 0.14.1

* `export { name } from './other'` does not create a local binding ([#16](https://github.com/rollup/rollup/issues/16))
* A single binding can be exported under multiple names ([#18](https://github.com/rollup/rollup/issues/18))
* `useStrict` option exposed to CLI as `--strict`/`--no-strict` ([#81](https://github.com/rollup/rollup/issues/81))
* Neater exports from ES6 bundles

## 0.14.0

* Internal refactoring
* Correctly deconflict generated default export names ([#72](https://github.com/rollup/rollup/issues/72))
* Handle `export { x } from 'y'` declarations ([#74](https://github.com/rollup/rollup/issues/74))
* Dedupe named imports from external modules in ES6 bundles ([#77](https://github.com/rollup/rollup/issues/77))

## 0.13.0

* Support `banner` and `footer` options ([#66](https://github.com/rollup/rollup/pull/66))
* Remove pre-existing sourcemap comments ([#66](https://github.com/rollup/rollup/pull/66))
* Deconflict external imports ([#66](https://github.com/rollup/rollup/pull/66))
* Use existing AST, if provided ([#66](https://github.com/rollup/rollup/pull/66))
* Rename internal namespace exports as appropriate ([#66](https://github.com/rollup/rollup/pull/66))
* Remove uninitialised var declarations that get exported ([#66](https://github.com/rollup/rollup/pull/66))
* Rename variables named `exports` to avoid conflicts ([#66](https://github.com/rollup/rollup/pull/66))

## 0.12.1

* Don't attempt to mark statements belonging to external modules ([#68](https://github.com/rollup/rollup/issues/68))
* Correctly deshadow variables that conflict with imports ([#68](https://github.com/rollup/rollup/issues/68))

## 0.12.0

* Internal re-architecting, resulting in more efficient bundling with reduced memory usage
* Shorthand properties are expanded if necessary ([#61](https://github.com/rollup/rollup/issues/61))
* Fixed various bugs with bundle external dependencies, particularly when generating ES6 bundles ([#59](https://github.com/rollup/rollup/issues/59))
* Add `--globals` option to CLI ([#60](https://github.com/rollup/rollup/pull/60))
* Allow imports of external modules for side-effects ([#55](https://github.com/rollup/rollup/pull/55))
* Prevent Rollup hanging on non-existent external module ([#54](https://github.com/rollup/rollup/pull/54))

## 0.11.4

* Side-effect preservation applies to internal default exports ([#43](https://github.com/rollup/rollup/issues/43))

## 0.11.3

* Class methods are not incorrectly renamed ([#42](https://github.com/rollup/rollup/issues/42))
* External modules are assigned names before canonical names are determined ([#42](https://github.com/rollup/rollup/issues/42))

## 0.11.2

* Correctly handle computed properties (e.g. `foo[bar]`) when discovering dependencies ([#47](https://github.com/rollup/rollup/pull/47))

## 0.11.1

* Support for `export * from '..'` ([#46](https://github.com/rollup/rollup/pull/46))

## 0.11.0

* Experimental browser-friendly build (`dist/rollup.browser.js`) ([#25](https://github.com/rollup/rollup/issues/25))
* Internal re-architecting to make discovery process simpler and more performant
* Preservation of side-effects that happen in a separate module to the affected definition ([#39](https://github.com/rollup/rollup/issues/39))

## 0.10.0

* Better sorting algorithm – sorting happens at the module level, rather than the statement level. This avoids certain edge cases
* IIFEs are ignored for the purposes of distinguishing between 'strong' and 'weak' dependencies
* Empty `var` declarations for exported bindings are omitted

## 0.9.1

* Much faster statement insertion (fixes major 0.9.0 performance regression)

## 0.9.0

* BREAKING - `resolvePath` is now `resolveId`. The returned `id` (which by default is a filepath) is passed to the `load` function, which can optionally be overridden, and which is applied to all modules including the entry module. This allows custom resolver and loading logic for integration with third party systems (e.g. JSPM) or, eventually, in-browser usage ([#30](https://github.com/rollup/rollup/issues/30))
* A statement cannot appear after later statements from the same bundle ([#34](https://github.com/rollup/rollup/issues/34))
* Tricky cyclical dependencies are handled ([#36](https://github.com/rollup/rollup/issues/36))
* `sourcemap` option is used by CLI (was omitted previously)

## 0.8.3

* Correctly rename functions that have arguments with the same name ([#32](https://github.com/rollup/rollup/issues/32))
* Ensure unused default exports are given a legal name ([#33](https://github.com/rollup/rollup/issues/33))

## 0.8.2

* Support `moduleId` and `moduleName` via CLI ([#24](https://github.com/rollup/rollup/issues/24))

## 0.8.1

* Anonymous functions that are exported as default are converted to named function declarations for correct hoisting, rather than being bound to functions ([#29](https://github.com/rollup/rollup/issues/29))
* Automatically-generated default export names are deconflicted with local definitions ([#29](https://github.com/rollup/rollup/issues/29))

## 0.8.0

* Top-level variable declarations with multiple declarators are split up, to avoid unnecessary code importing and incorrectly-ordered statements ([#26](https://github.com/rollup/rollup/issues/26))
* `this` at the top level is `undefined` ([#28](https://github.com/rollup/rollup/issues/28))

## 0.7.8

* Avoid using `path.parse` - unsupported in node 0.10

## 0.7.7

* Promise `source-map-support` from `devDependencies` to `dependencies` ([#23](https://github.com/rollup/rollup/issues/23))

## 0.7.6

* Better placement of `export default` statements ([#21](https://github.com/rollup/rollup/issues/21))
* Prevent function calls and property assignments from being treated as rebinding for sake of unbound default exports
* Add `--external foo,bar,baz` option to CLI (equivalent to `external: ['foo', 'bar', 'baz']`)
* Add CLI tests

## 0.7.5

* Prevent accidental conflicts with the global namespace ([#20](https://github.com/rollup/rollup/issues/20))

## 0.7.4

* More precise statement re-ordering to satisfy `export default` constraint (fixes bug introduced in 0.7.3)

## 0.7.3

* Default exports are not bound. To enable this, statements within a module are sorted to retain their original order ([#15](https://github.com/rollup/rollup/issues/15))
* Better positioning of comments ([#14](https://github.com/rollup/rollup/issues/14))
* Various fixes to get Travis-CI rigged up

## 0.7.2

* Fix sourcemap paths on Windows ([#6](https://github.com/rollup/rollup/pull/6))

## 0.7.1

* Named functions can be used as default exports from a bundle
* Method calls are assumed to mutate the owner (i.e. `foo.bar()` mutates `foo`) ([#13](https://github.com/rollup/rollup/issues/13))
* `options.indent` can be used to control indentation of resulting bundle. `options.true` (default) means 'auto', `options.false` means empty string. Alternatively specify whitespace e.g. `'  '` or `'\t'` ([#5](https://github.com/rollup/rollup/issues/5))

## 0.7.0

* Ensure statements are always separated by a newline ([#9](https://github.com/rollup/rollup/pull/9))
* Use CommonJS `exports` correctly (UMD exports)
* Throw error if `moduleName` is required but missing (UMD exports)
* Attach IIFE global to `this` rather than `window`
* Allow names inside bundle to the the names of `Object.prototype` properties ([#12](https://github.com/rollup/rollup/pull/12))
* Keep exports live ([#11](https://github.com/rollup/rollup/pull/11))

## 0.6.5

* Add sourceMappingURL comment to code, as appropriate
* Higher resolution sourcemaps

## 0.6.4

* Fix CJS bundling with default export

## 0.6.3

* Fix exports and external module imports with some output formats
* Fix endless cycle bug on Windows ([#3](https://github.com/rollup/rollup/pull/3)) - thanks @Bobris

## 0.6.2

* Permit assignments to properties of imported bindings

## 0.6.1

* Support for basic transformers

## 0.6.0

* BREAKING - `rollup.rollup` and `bundle.write` both take a single options argument
* BREAKING - external modules must be declared upfront with `options.external: [...]`
* Non-relative module paths will be resolved by looking for `jsnext:main` fields in the appropriate `package.json` files. This behaviour can be overridden by passing an alternative `resolveExternal` function
* Fix sourcemap options
* Include CLI files in npm package (duh)

## 0.5.0

* Command line interface
* Sourcemap generation
* Correct behaviour with `export { x as y } from 'z'`

## 0.4.1

* More import name deconflicting

## 0.4.0

* Self-hosting! `rollup.rollup` now rolls up rollup
* Fix bug with comments inside a statement later being appended to it
* Prevent shadowing of external modules
* Rewrite computed property identifiers where necessary
* Preserve original statement order where possible
* Internal refactoring

## 0.3.1

* Saner deconflicting
* Rename namespace imports from external modules correctly

## 0.3.0

* Basic functionality present, mostly spec-compliant

## 0.2.1

* Include dist files in npm package (duh)

## 0.2.0

* First release capable of doing anything useful
* Still lots of basic functionality missing

## 0.1.0

* Initial experiment
