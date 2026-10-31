Before a project can be built, something has to work out what it is: which package manager it uses, which runtime runs it, which framework builds it, and whether the build is served by a server or as files. Each of these is a detector: add the options it may choose from and the inputs you have (file names, languages, a manifest), then call `detect`. This guide covers the common tasks.

## Find the package manager

`PackagerDetector` returns the first option, in the order you added them, that has one of its files among the inputs. Put the more specific lockfiles first, since every npm-compatible project has a `package.json`.

```
use utopia_detector::{Npm, PackagerDetector, Pnpm, Yarn};

let mut detector = PackagerDetector::new();
detector.add_option(Pnpm).add_option(Yarn).add_option(Npm);
for file in ["package.json", "pnpm-lock.yaml", "src"] {
    detector.add_input(file);
}

assert_eq!(detector.detect().map(|p| p.name()), Some("pnpm"));
```

```php
use Utopia\Detector\Detection\Packager\NPM;
use Utopia\Detector\Detection\Packager\PNPM;
use Utopia\Detector\Detection\Packager\Yarn;
use Utopia\Detector\Detector\Packager;

$detector = new Packager();
$detector->addOption(new PNPM())->addOption(new Yarn())->addOption(new NPM());
foreach (['package.json', 'pnpm-lock.yaml', 'src'] as $file) {
    $detector->addInput($file);
}

echo $detector->detect()?->getName(), "\n"; // pnpm
```

## Find the runtime

A `RuntimeDetector` matches by a `Strategy`: file names (`FileMatch`), their extensions (`Extension`) or language names (`Languages`). The packager you pass shapes the install command of runtimes that depend on it.

```
use utopia_detector::{Node, Php, Python, RuntimeDetector, Strategy};

let mut detector = RuntimeDetector::new(Strategy::Languages, "yarn");
detector.add_option(Node).add_option(Php).add_option(Python);
detector.add_input("TypeScript").add_input("Dockerfile");

let runtime = detector.detect().expect("a runtime");
assert_eq!(runtime.name(), "node");
assert_eq!(runtime.commands(), "yarn install");
assert_eq!(runtime.entrypoint(), "index.js");

assert_eq!(Strategy::new("languages")?, Strategy::Languages);
assert!(Strategy::new("magic").is_err());
# Ok::<(), utopia_detector::Error>(())
```

```php
use Utopia\Detector\Detection\Runtime\Node;
use Utopia\Detector\Detection\Runtime\PHP;
use Utopia\Detector\Detection\Runtime\Python;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;

$detector = new Runtime(new Strategy(Strategy::LANGUAGES), 'yarn');
$detector->addOption(new Node())->addOption(new PHP())->addOption(new Python());
$detector->addInput('TypeScript')->addInput('Dockerfile');

$runtime = $detector->detect();
echo $runtime->getName(), "\n";       // node
echo $runtime->getCommands(), "\n";   // yarn install
echo $runtime->getEntrypoint(), "\n"; // index.js
```

## Find the framework from files and dependencies

A `FrameworkDetector` scores every framework: a point for each of its files among the file inputs, and a point for each of its packages in each manifest. Feed it the repository's file names and the content of `package.json` or `pubspec.yaml`.

```
use utopia_detector::{Astro, FrameworkDetector, NextJs, React, ReactNative};

let mut detector = FrameworkDetector::new("npm");
detector.add_option(Astro).add_option(NextJs).add_option(React).add_option(ReactNative);
detector.add_file("package.json");
detector.add_manifest(r#"{"dependencies": {"react": "^18.2.0", "react-native": "^0.74.0"}}"#);

let framework = detector.detect().expect("a framework");
assert_eq!(framework.name(), "react-native");
assert_eq!(framework.install_command(), "npm install");
assert_eq!(framework.build_command(), "npm run build");
```

```php
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\NextJs;
use Utopia\Detector\Detection\Framework\React;
use Utopia\Detector\Detection\Framework\ReactNative;
use Utopia\Detector\Detector\Framework;

$detector = new Framework('npm');
$detector->addOption(new Astro())->addOption(new NextJs())->addOption(new React())->addOption(new ReactNative());
$detector->addInput('package.json', Framework::INPUT_FILE);
$detector->addInput('{"dependencies": {"react": "^18.2.0", "react-native": "^0.74.0"}}', Framework::INPUT_PACKAGES);

$framework = $detector->detect();
echo $framework->getName(), "\n";           // react-native
echo $framework->getInstallCommand(), "\n"; // npm install
echo $framework->getBuildCommand(), "\n";   // npm run build
```

## Read how a framework is configured to render

Some frameworks say in their configuration whether they render on a server. `config_files` lists the files to read, in priority order, and `adapter` reads one.

```
use utopia_detector::{Adapter, Astro, Framework, Jaspr};

let astro = Astro;
assert_eq!(astro.config_files()[0], "astro.config.mjs");
assert_eq!(astro.adapter(b"export default defineConfig({ output: 'server' })"), Some(Adapter::Ssr));
assert_eq!(astro.adapter(b"// output: 'server'\nexport default defineConfig({})"), Some(Adapter::Static));

assert_eq!(Jaspr.adapter(b"jaspr:\n  mode: static\n"), Some(Adapter::Static));
assert_eq!(Jaspr.adapter(b"name: my_site\n"), None);
```

```php
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\Jaspr;

$astro = new Astro();
echo $astro->getConfigFiles()[0], "\n"; // astro.config.mjs
echo $astro->getAdapter("export default defineConfig({ output: 'server' })"), "\n"; // ssr
echo $astro->getAdapter("// output: 'server'\nexport default defineConfig({})"), "\n"; // static

echo (new Jaspr())->getAdapter("jaspr:\n  mode: static\n"), "\n"; // static
var_dump((new Jaspr())->getAdapter("name: my_site\n")); // string(0) ""
```

## Tell server-rendered builds from static ones

A `RenderingDetector` looks at a framework's build output. It returns the first option with one of that framework's server files; otherwise the build is static, with its only HTML file as the fallback page when there is exactly one.

```
use utopia_detector::{RenderingDetector, Ssr, Static};

let mut detector = RenderingDetector::new("nuxt");
detector.add_option(Ssr::new()).add_option(Static::new());
detector.add_input("nuxt.config.js").add_input("server/index.mjs");
assert_eq!(detector.detect().name(), "ssr");

let mut detector = RenderingDetector::new("nuxt");
detector.add_option(Ssr::new()).add_option(Static::new());
detector.add_input("index.html").add_input("_nuxt/entry.js");
let rendering = detector.detect();
assert_eq!((rendering.name(), rendering.fallback_file()), ("static", Some("index.html")));
```

```php
use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detector\Rendering;

$detector = new Rendering('nuxt');
$detector->addOption(new SSR())->addOption(new XStatic());
$detector->addInput('nuxt.config.js')->addInput('server/index.mjs');
echo $detector->detect()->getName(), "\n"; // ssr

$detector = new Rendering('nuxt');
$detector->addOption(new SSR())->addOption(new XStatic());
$detector->addInput('index.html')->addInput('_nuxt/entry.js');
$rendering = $detector->detect();
echo $rendering->getName(), ' ', $rendering->getFallbackFile(), "\n"; // static index.html
```
