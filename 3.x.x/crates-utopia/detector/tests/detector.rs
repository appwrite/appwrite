//! Port of `tests/unit/DetectorTest.php` plus extra error-path coverage.

use serde_json::json;
use utopia_detector::prelude::*;
use utopia_detector::DetectorError;

type PackagerCase = (&'static [&'static str], Option<&'static str>);
type RuntimeTripleCase = (
    &'static [&'static str],
    Option<(&'static str, &'static str, &'static str)>,
    &'static str,
);
type RuntimePairCase = (
    &'static [&'static str],
    Option<(&'static str, &'static str)>,
    &'static str,
);
type FrameworkCase = (
    &'static [&'static str],
    Option<(&'static str, &'static str, &'static str, &'static str)>,
    &'static str,
);

fn packager_options(detector: &mut Packager) {
    detector
        .add_option(PNPM::new())
        .add_option(Yarn::new())
        .add_option(NPM::new());
}

fn runtime_options(detector: &mut Runtime) {
    detector
        .add_option(Node::new())
        .add_option(Bun::new())
        .add_option(Deno::new())
        .add_option(PHP::new())
        .add_option(Python::new())
        .add_option(Dart::new())
        .add_option(Swift::new())
        .add_option(Ruby::new())
        .add_option(Java::new())
        .add_option(CPP::new())
        .add_option(Dotnet::new());
}

fn framework_file_options(detector: &mut Framework) {
    detector
        .add_option(Flutter::new())
        .add_option(Nuxt::new())
        .add_option(Astro::new())
        .add_option(Remix::new())
        .add_option(SvelteKit::new())
        .add_option(NextJs::new())
        .add_option(Lynx::new())
        .add_option(Angular::new())
        .add_option(Analog::new())
        .add_option(TanStackStart::new());
}

fn framework_edge_options(detector: &mut Framework) {
    detector
        .add_option(Analog::new())
        .add_option(Angular::new())
        .add_option(Astro::new())
        .add_option(Flutter::new())
        .add_option(Lynx::new())
        .add_option(NextJs::new())
        .add_option(Nuxt::new())
        .add_option(React::new())
        .add_option(ReactNative::new())
        .add_option(Remix::new())
        .add_option(Svelte::new())
        .add_option(SvelteKit::new())
        .add_option(TanStackStart::new())
        .add_option(Vue::new());
}

/// `DetectorTest::packagerDataProvider` / `testDetectPackager`
#[test]
fn test_detect_packager() {
    let cases: &[PackagerCase] = &[
        (
            &["bun.lockb", "fly.toml", "package.json", "remix.config.js"],
            Some("npm"),
        ),
        (&["yarn.lock"], Some("yarn")),
        (&["pnpm-lock.yaml"], Some("pnpm")),
        (&["composer.json"], None),
    ];

    for (files, expected) in cases {
        let mut detector = Packager::new();
        packager_options(&mut detector);
        for file in *files {
            detector.add_input(*file, "");
        }
        let detected = detector.detect();
        match expected {
            Some(name) => assert_eq!(detected.expect("packager").get_name(), *name),
            None => assert!(detected.is_none()),
        }
    }
}

/// `DetectorTest::runtimeDataProviderByFilematch` / `testDetectRuntimeByFilematch`
#[test]
fn test_detect_runtime_by_filematch() {
    let cases: &[RuntimeTripleCase] = &[
        (
            &["package-lock.json", "yarn.lock", "tsconfig.json"],
            Some(("node", "pnpm install", "index.js")),
            "pnpm",
        ),
        (
            &["package-lock.json", "yarn.lock", "tsconfig.json"],
            Some(("node", "yarn install", "index.js")),
            "yarn",
        ),
        (
            &["composer.json", "composer.lock"],
            Some(("php", "composer install && composer run build", "index.php")),
            "pnpm",
        ),
        (
            &["pubspec.yaml"],
            Some(("dart", "dart pub get", "main.dart")),
            "pnpm",
        ),
        (
            &["Gemfile", "Gemfile.lock"],
            Some((
                "ruby",
                "bundle install && bundle exec rake build",
                "main.rb",
            )),
            "pnpm",
        ),
        (&["index.html", "style.css"], None, "pnpm"),
    ];

    for (files, expected, packager) in cases {
        let mut detector = Runtime::new(Strategy::new(Strategy::FILEMATCH).unwrap(), *packager);
        runtime_options(&mut detector);
        for file in *files {
            detector.add_input(*file, "");
        }
        let detected = detector.detect();
        match expected {
            Some((name, commands, entrypoint)) => {
                let runtime = detected.expect("runtime");
                assert_eq!(runtime.get_name(), *name);
                assert_eq!(runtime.get_commands(), *commands);
                assert_eq!(runtime.get_entrypoint(), *entrypoint);
            }
            None => assert!(detected.is_none()),
        }
    }
}

/// `DetectorTest::runtimeDataProviderByLanguages` / `testDetectRuntimeByLanguage`
#[test]
fn test_detect_runtime_by_language() {
    let cases: &[RuntimePairCase] = &[
        (
            &["TypeScript", "JavaScript", "DockerFile"],
            Some(("node", "pnpm install")),
            "pnpm",
        ),
        (
            &["TypeScript", "JavaScript", "DockerFile"],
            Some(("node", "yarn install")),
            "yarn",
        ),
        (&["HTML"], None, "pnpm"),
    ];

    for (files, expected, packager) in cases {
        let mut detector = Runtime::new(Strategy::new(Strategy::LANGUAGES).unwrap(), *packager);
        runtime_options(&mut detector);
        for file in *files {
            detector.add_input(*file, "");
        }
        let detected = detector.detect();
        match expected {
            Some((name, commands)) => {
                let runtime = detected.expect("runtime");
                assert_eq!(runtime.get_name(), *name);
                assert_eq!(runtime.get_commands(), *commands);
            }
            None => assert!(detected.is_none()),
        }
    }
}

/// `DetectorTest::runtimeDataProviderByFileExtensions` / `testDetectRuntimeByFileExtension`
#[test]
fn test_detect_runtime_by_file_extension() {
    let cases: &[RuntimePairCase] = &[
        (
            &["main.ts", "main.js", "DockerFile"],
            Some(("node", "pnpm install")),
            "pnpm",
        ),
        (
            &["main.ts", "main.js", "DockerFile"],
            Some(("node", "yarn install")),
            "yarn",
        ),
        (
            &["composer.json", "index.php", "DockerFile"],
            Some(("php", "composer install && composer run build")),
            "pnpm",
        ),
        (&["index.html", "style.css"], None, "pnpm"),
    ];

    for (files, expected, packager) in cases {
        let mut detector = Runtime::new(Strategy::new(Strategy::EXTENSION).unwrap(), *packager);
        runtime_options(&mut detector);
        for file in *files {
            detector.add_input(*file, "");
        }
        let detected = detector.detect();
        match expected {
            Some((name, commands)) => {
                let runtime = detected.expect("runtime");
                assert_eq!(runtime.get_name(), *name);
                assert_eq!(runtime.get_commands(), *commands);
            }
            None => assert!(detected.is_none()),
        }
    }
}

/// `DetectorTest::frameworkDataProvider` / `testFrameworkDetection`
#[test]
fn test_framework_detection() {
    let cases: &[FrameworkCase] = &[
        (
            &[
                "src",
                "types",
                "makefile",
                "components.js",
                "debug.js",
                "package.json",
                "svelte.config.js",
            ],
            Some(("sveltekit", "pnpm install", "pnpm run build", "./build")),
            "pnpm",
        ),
        (
            &[
                "app",
                "backend",
                "public",
                "Dockerfile",
                "docker-compose.yml",
                "ecosystem.config.js",
                "middleware.ts",
                "next.config.js",
                "package-lock.json",
                "package.json",
                "server.js",
                "tsconfig.json",
            ],
            Some(("nextjs", "pnpm install", "pnpm run build", "./.next")),
            "pnpm",
        ),
        (
            &[
                "assets",
                "components",
                "layouts",
                "pages",
                "babel.config.js",
                "error.vue",
                "nuxt.config.js",
                "yarn.lock",
            ],
            Some(("nuxt", "pnpm install", "pnpm run build", "./.output")),
            "pnpm",
        ),
        (
            &["lynx.config.js"],
            Some(("lynx", "pnpm install", "pnpm run build", "./dist")),
            "pnpm",
        ),
        (
            &[
                "src",
                "package.json",
                "tsconfig.json",
                "angular.json",
                "logo.png",
            ],
            Some((
                "angular",
                "pnpm install",
                "pnpm run build",
                "./dist/angular",
            )),
            "pnpm",
        ),
        (
            &[
                "app",
                "public",
                "remix.config.js",
                "remix.env.d.ts",
                "sandbox.config.js",
                "tsconfig.json",
                "package.json",
            ],
            Some(("remix", "pnpm install", "pnpm run build", "./build")),
            "pnpm",
        ),
        (
            &[
                "public",
                "src",
                "astro.config.mjs",
                "package-lock.json",
                "package.json",
                "tsconfig.json",
            ],
            Some(("astro", "pnpm install", "pnpm run build", "./dist")),
            "pnpm",
        ),
        (
            &[
                "src",
                "static",
                "scripts",
                "eslint.config.js",
                "package.json",
                "pnpm-lock.yaml",
                "svelte.config.js",
                "tsconfig.js",
                "vite.config.js",
                "vite.config.lib.js",
            ],
            Some(("sveltekit", "pnpm install", "pnpm run build", "./build")),
            "pnpm",
        ),
        (&["index.html", "style.css"], None, "pnpm"),
    ];

    for (files, expected, packager) in cases {
        let mut detector = Framework::new(*packager);
        framework_file_options(&mut detector);
        for file in *files {
            detector.add_input(*file, Framework::INPUT_FILE).unwrap();
        }
        let detected = detector.detect();
        match expected {
            Some((name, install, build, output)) => {
                let framework = detected.expect("framework");
                assert_eq!(framework.get_name(), *name);
                assert_eq!(framework.get_install_command(), *install);
                assert_eq!(framework.get_build_command(), *build);
                assert_eq!(framework.get_output_directory(), *output);
            }
            None => assert!(detected.is_none()),
        }
    }
}

/// `DetectorTest::renderingDataProvider` / `testRenderingDetection`
#[test]
fn test_rendering_detection() {
    let cases: &[(&[&str], &str, &str, Option<&str>)] = &[
        (
            &[
                "server/pages/index.html",
                "server/pages/api/users.js",
                ".next/server/unrelated-file.js",
            ],
            "nextjs",
            "static",
            Some("server/pages/index.html"),
        ),
        (
            &["server/pages/api/users.js", ".next/server/pages/_app.js"],
            "nextjs",
            "static",
            None,
        ),
        (
            &[
                "server/pages/index.html",
                "server/pages/api/users.js",
                ".next/turbopack",
            ],
            "nextjs",
            "ssr",
            None,
        ),
        (
            &[
                "server/pages/index.html",
                "server/pages/api/users.js",
                ".next/server/webpack-runtime.js",
            ],
            "nextjs",
            "ssr",
            None,
        ),
        (
            &[".next/some-standalone-files.js", "server.js"],
            "nextjs",
            "ssr",
            None,
        ),
        (
            &["nuxt.config.js", "server/index.mjs", "server.js"],
            "nuxt",
            "ssr",
            None,
        ),
        (
            &["nuxt.config.js", "index.html", "server.js"],
            "nuxt",
            "static",
            Some("index.html"),
        ),
        (
            &["nuxt.config.js", "200.html", "202.html", "server.js"],
            "nuxt",
            "static",
            None,
        ),
        (
            &["index.html", "about.html", "404.html"],
            "nextjs",
            "static",
            None,
        ),
        (&["nitro.json", "server/index.mjs"], "nuxt", "ssr", None),
        (&["server/server.mjs"], "angular", "ssr", None),
        (&["server/index.mjs"], "analog", "ssr", None),
        (&["server/index.mjs"], "tanstack-start", "ssr", None),
        (
            &["index.html", "_nuxt/something.js"],
            "nuxt",
            "static",
            Some("index.html"),
        ),
        (
            &[
                "server/pages/index.js",
                "prerendered/about.html",
                "handler.js",
            ],
            "sveltekit",
            "ssr",
            None,
        ),
        (&["index.html", "about.html"], "sveltekit", "static", None),
        (
            &["index.html", "style.css"],
            "nextjs",
            "static",
            Some("index.html"),
        ),
        (
            &["server/entry.mjs", "server/renderers.mjs", "server/pages/"],
            "astro",
            "ssr",
            None,
        ),
        (&["index.html", "about.html"], "astro", "static", None),
        (
            &["build/server/index.js", "build/server/renderers.js"],
            "remix",
            "ssr",
            None,
        ),
        (&["index.html", "about.html"], "remix", "static", None),
        (
            &["about.html", "style.css"],
            "remix",
            "static",
            Some("about.html"),
        ),
        (
            &["index.html", "style.css"],
            "flutter",
            "static",
            Some("index.html"),
        ),
        (
            &["index.html", "about.html"],
            "tanstack-start",
            "static",
            None,
        ),
    ];

    for (files, framework, rendering, fallback) in cases {
        let mut detector = Rendering::new(*framework);
        detector
            .add_option(SSR::new(None))
            .add_option(XStatic::new(None));
        for file in *files {
            detector.add_input(*file, "");
        }
        let detected = detector.detect();
        assert_eq!(detected.get_name(), *rendering);
        assert_eq!(detected.get_fallback_file(), *fallback);
    }
}

/// `DetectorTest::testTanStackStartDetectionWithPackages`
#[test]
fn test_tanstack_start_detection_with_packages() {
    let mut detector = Framework::new("npm");
    framework_file_options(&mut detector);
    let package_json = json!({
        "name": "my-app",
        "dependencies": {
            "@tanstack/react-start": "^1.0.0",
            "react": "^18.0.0",
        }
    })
    .to_string();
    detector
        .add_input(package_json, Framework::INPUT_PACKAGES)
        .unwrap();
    let detected = detector.detect().expect("framework");
    assert_eq!(detected.get_name(), "tanstack-start");
    assert_eq!(detected.get_install_command(), "npm install");
    assert_eq!(detected.get_build_command(), "npm run build");
    assert_eq!(detected.get_output_directory(), "./.output");
}

/// `DetectorTest::testTanStackStartDetectionWithDevPackages`
#[test]
fn test_tanstack_start_detection_with_dev_packages() {
    let mut detector = Framework::new("pnpm");
    detector.add_option(TanStackStart::new());
    let package_json = json!({
        "name": "my-app",
        "devDependencies": {
            "@tanstack/react-start": "^1.0.0",
        }
    })
    .to_string();
    detector
        .add_input(package_json, Framework::INPUT_PACKAGES)
        .unwrap();
    let detected = detector.detect().expect("framework");
    assert_eq!(detected.get_name(), "tanstack-start");
    assert_eq!(detected.get_install_command(), "pnpm install");
    assert_eq!(detected.get_build_command(), "pnpm run build");
}

/// `DetectorTest::testFrameworkDetectorRejectsInvalidInputType`
#[test]
fn test_framework_detector_rejects_invalid_input_type() {
    let mut detector = Framework::new("npm");
    let err = detector
        .add_input("JavaScript", "language")
        .expect_err("invalid type");
    assert_eq!(err, DetectorError::InvalidInputType("language".to_string()));
    assert_eq!(err.to_string(), "Invalid input type 'language'");
}

/// `DetectorTest::frameworkEdgeCasesProvider` / `testFrameworkEdgeCases`
#[test]
fn test_framework_edge_cases() {
    let cases: &[(&str, &[&str], serde_json::Value, &str)] = &[
        (
            "Just react should mean just react",
            &["package.json"],
            json!({"dependencies": {"react": "^17.0.2"}}),
            "react",
        ),
        (
            "React with Next package is Next.js",
            &["package.json"],
            json!({"dependencies": {"react": "^17.0.2", "next": "^12.0.7"}}),
            "nextjs",
        ),
        (
            "React with Next config is Next.js",
            &["package.json", "next.config.js"],
            json!({"dependencies": {"react": "^17.0.2"}}),
            "nextjs",
        ),
        (
            "React with React Native is React Native",
            &["package.json"],
            json!({"dependencies": {"react": "^17.0.2", "react-native": "^0.68.2"}}),
            "react-native",
        ),
        (
            "React with Tanstack Start is Tanstack Start",
            &["package.json"],
            json!({"dependencies": {"react": "^17.0.2", "@tanstack/react-start": "^1.0.0"}}),
            "tanstack-start",
        ),
        (
            "React with Remix is Remix",
            &["package.json", "remix.config.js"],
            json!({"dependencies": {"react": "^17.0.2"}}),
            "remix",
        ),
        (
            "React with Lynx config file is Lynx",
            &["package.json", "lynx.config.ts"],
            json!({"dependencies": {"react": "^17.0.2"}}),
            "lynx",
        ),
        (
            "React with Lynx package is Lynx",
            &["package.json"],
            json!({"dependencies": {"react": "^17.0.2", "@lynx-js/react": "^1.0.0"}}),
            "lynx",
        ),
        (
            "Just Angular should mean just Angular",
            &["package.json"],
            json!({"dependencies": {"@angular/core": "^14.0.0"}}),
            "angular",
        ),
        (
            "Angular with Analog is Analog",
            &["package.json", "angular.json"],
            json!({"dependencies": {"@angular/core": "^14.0.0", "@analogjs/platform": "^14.0.0"}}),
            "analog",
        ),
        (
            "Just Vue should mean just Vue",
            &["package.json"],
            json!({"dependencies": {"vue": "^3.2.47"}}),
            "vue",
        ),
        (
            "Vue with Nuxt config file is Nuxt",
            &["package.json", "nuxt.config.js"],
            json!({"dependencies": {"vue": "^3.2.47"}}),
            "nuxt",
        ),
        (
            "Vue with Nuxt package is Nuxt",
            &["package.json"],
            json!({"dependencies": {"vue": "^3.2.47", "nuxt": "^3.0.0"}}),
            "nuxt",
        ),
        (
            "Just Astro should mean just Astro",
            &["package.json"],
            json!({"dependencies": {"astro": "^5.0.0"}}),
            "astro",
        ),
        (
            "Astro with React is Astro",
            &["package.json"],
            json!({"dependencies": {"astro": "^5.0.0", "react": "^18.2.0"}}),
            "astro",
        ),
        (
            "Astro with Angular package is Astro",
            &["package.json"],
            json!({"dependencies": {"astro": "^5.0.0", "@angular/core": "^18.2.0"}}),
            "astro",
        ),
        (
            "Astro with Angular file is Astro",
            &["package.json", "angular.json"],
            json!({"dependencies": {"astro": "^5.0.0"}}),
            "astro",
        ),
        (
            "Astro with Angular file and package is Astro",
            &["package.json", "angular.json"],
            json!({"dependencies": {"astro": "^5.0.0", "angular": "^18.2.0"}}),
            "astro",
        ),
        (
            "Astro with Vue is Astro",
            &["package.json"],
            json!({"dependencies": {"astro": "^5.0.0", "vue": "^3.2.47"}}),
            "astro",
        ),
        (
            "Just Svelte should mean just Svelte",
            &["package.json"],
            json!({"dependencies": {"svelte": "^3.54.0"}}),
            "svelte",
        ),
        (
            "Svelte with SvelteKit is SvelteKit",
            &["package.json"],
            json!({"dependencies": {"svelte": "^3.54.0", "@sveltejs/kit": "^1.0.0"}}),
            "sveltekit",
        ),
    ];

    for (assertion, files, package, framework) in cases {
        let mut detector = Framework::new("npm");
        framework_edge_options(&mut detector);
        for file in *files {
            detector.add_input(*file, Framework::INPUT_FILE).unwrap();
        }
        detector
            .add_input(package.to_string(), Framework::INPUT_PACKAGES)
            .unwrap();
        let detection = detector.detect().unwrap_or_else(|| panic!("{assertion}"));
        assert_eq!(detection.get_name(), *framework, "{assertion}");
    }
}

/// `DetectorTest::testTanStackStartAdapterDetection`
#[test]
fn test_tanstack_start_adapter_detection() {
    let fw = TanStackStart::new();
    assert_eq!(
        fw.get_adapter("export default defineConfig({ plugins: [tanstackStart()] })"),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(
            "export default defineConfig({ plugins: [tanstackStart({ prerender: { routes: ['/'] } })] })"
        ),
        "static"
    );
    assert_eq!(
        fw.get_adapter(
            "export default defineConfig({ plugins: [tanstackStart({ prerender: false })] })"
        ),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(
            r#"export default defineConfig({ plugins: [tanstackStart({ "prerender": false })] })"#
        ),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("// prerender: true\nexport default defineConfig({})"),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("server: { url: \"https://example.com\" },\nprerender: { routes: ['/'] }"),
        "static"
    );
    assert!(!fw.get_config_files().is_empty());
}

/// `DetectorTest::testSvelteKitAdapterDetection`
#[test]
fn test_sveltekit_adapter_detection() {
    let fw = SvelteKit::new();
    assert_eq!(
        fw.get_adapter(
            "import adapter from '@sveltejs/adapter-auto'; export default { kit: { adapter: adapter() } }"
        ),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(
            "import adapter from '@sveltejs/adapter-static'; export default { kit: { adapter: adapter() } }"
        ),
        "static"
    );
    assert_eq!(
        fw.get_adapter(r#"{"dependencies":{"@sveltejs/adapter-static":"^3.0.0"}}"#),
        "static"
    );
    assert_eq!(
        fw.get_adapter(
            "// import adapter from '@sveltejs/adapter-static'\nimport adapter from '@sveltejs/adapter-auto'"
        ),
        "ssr"
    );
    assert!(fw.get_config_files().contains(&"package.json".to_string()));
    assert!(!fw.get_config_files().is_empty());
}

/// `DetectorTest::testAstroAdapterDetection`
#[test]
fn test_astro_adapter_detection() {
    let fw = Astro::new();
    assert_eq!(
        fw.get_adapter("export default defineConfig({ integrations: [] })"),
        "static"
    );
    assert_eq!(
        fw.get_adapter(
            "export default defineConfig({ output: 'server', adapter: node({ mode: 'standalone' }) })"
        ),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(r#"export default defineConfig({ output: "server" })"#),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("export default defineConfig({ output: 'hybrid' })"),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("export default defineConfig({ output  :  'server' })"),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("// output: 'server'\nexport default defineConfig({})"),
        "static"
    );
    assert_eq!(
        fw.get_adapter("site: \"https://example.com\",\noutput: \"server\""),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("export default defineConfig({ output: `server` })"),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter("export default defineConfig({ output: `hybrid` })"),
        "ssr"
    );
    assert!(!fw.get_config_files().is_empty());
}

/// `DetectorTest::testRemixAdapterDetection`
#[test]
fn test_remix_adapter_detection() {
    let fw = Remix::new();
    assert_eq!(
        fw.get_adapter(r#"{"dependencies":{"@remix-run/react":"^2.0.0"}}"#),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(r#"{"dependencies":{"@remix-run/serve":"^2.0.0"}}"#),
        "ssr"
    );
    assert_eq!(
        fw.get_adapter(r#"{"dependencies":{"@remix-run/node":"^2.0.0"}}"#),
        "ssr"
    );
    assert_eq!(fw.get_adapter(""), "ssr");
    assert!(fw.get_config_files().contains(&"package.json".to_string()));
}

/// Extra error-path coverage.
#[test]
fn test_invalid_strategy() {
    let err = Strategy::new("unknown").unwrap_err();
    assert_eq!(err, DetectorError::InvalidStrategy("unknown".to_string()));
    assert_eq!(err.to_string(), "Invalid strategy: unknown");
}
