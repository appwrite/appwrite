[npm]: https://img.shields.io/npm/v/@rollup/plugin-typescript
[npm-url]: https://www.npmjs.com/package/@rollup/plugin-typescript
[size]: https://packagephobia.now.sh/badge?p=@rollup/plugin-typescript
[size-url]: https://packagephobia.now.sh/result?p=@rollup/plugin-typescript

[![npm][npm]][npm-url]
[![size][size]][size-url]
[![libera manifesto](https://img.shields.io/badge/libera-manifesto-lightgrey.svg)](https://liberamanifesto.com)

# @rollup/plugin-typescript

🍣 A Rollup plugin for seamless integration between Rollup and Typescript.

## Requirements

This plugin requires an [LTS](https://github.com/nodejs/Release) Node version (v8.0.0+) and Rollup v1.20.0+. This plugin also requires at least [TypeScript 3.7](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-3-7.html).

## Install

Using npm:

```console
npm install @rollup/plugin-typescript --save-dev
```

Note that both `typescript` and `tslib` are peer dependencies of this plugin that need to be installed separately.

## Why?

See [@rollup/plugin-babel](https://github.com/rollup/plugins/tree/master/packages/babel).

## Usage

Create a `rollup.config.js` [configuration file](https://www.rollupjs.org/guide/en/#configuration-files) and import the plugin:

```js
// rollup.config.js
import typescript from '@rollup/plugin-typescript';

export default {
  input: 'src/index.ts',
  output: {
    dir: 'output',
    format: 'cjs'
  },
  plugins: [typescript()]
};
```

Then call `rollup` either via the [CLI](https://www.rollupjs.org/guide/en/#command-line-reference) or the [API](https://www.rollupjs.org/guide/en/#javascript-api).

## Options

The plugin loads any [`compilerOptions`](http://www.typescriptlang.org/docs/handbook/compiler-options.html) from the `tsconfig.json` file by default. Passing options to the plugin directly overrides those options:

```js
...
export default {
  input: './main.ts',
  plugins: [
      typescript({lib: ["es5", "es6", "dom"], target: "es5"})
  ]
}
```

The following options are unique to `rollup-plugin-typescript`:

### `exclude`

Type: `String` | `Array[...String]`<br>
Default: `null`

A [minimatch pattern](https://github.com/isaacs/minimatch), or array of patterns, which specifies the files in the build the plugin should _ignore_. By default no files are ignored.

### `include`

Type: `String` | `Array[...String]`<br>
Default: `null`

A [minimatch pattern](https://github.com/isaacs/minimatch), or array of patterns, which specifies the files in the build the plugin should operate on. By default all `.ts` and `.tsx` files are targeted.

### `tsconfig`

Type: `String` | `Boolean`<br>
Default: `true`

When set to false, ignores any options specified in the config file. If set to a string that corresponds to a file path, the specified file will be used as config file.

### `typescript`

Type: `import('typescript')`<br>
Default: _peer dependency_

Overrides the TypeScript module used for transpilation.

```js
typescript({
  typescript: require('some-fork-of-typescript')
});
```

### `tslib`

Type: `String`<br>
Default: _peer dependency_

Overrides the injected TypeScript helpers with a custom version.

```js
typescript({
  tslib: require.resolve('some-fork-of-tslib')
});
```

### `transformers`

Type: `{ [before | after | afterDeclarations]: TransformerFactory[] }`<br>
Default: `undefined`

Allows registration of TypeScript custom transformers at any of the supported stages:

- **before**: transformers will execute before the TypeScript's own transformers on raw TypeScript files
- **after**: transformers will execute after the TypeScript transformers on transpiled code
- **afterDeclarations**: transformers will execute after declaration file generation allowing to modify existing declaration files

Supported transformer factories:

- all **built-in** TypeScript custom transformer factories:

  - `import('typescript').TransformerFactory` annotated **TransformerFactory** bellow
  - `import('typescript').CustomTransformerFactory` annotated **CustomTransformerFactory** bellow

- **ProgramTransformerFactory** represents a transformer factory allowing the resulting transformer to grab a reference to the **Program** instance

  ```js
  {
    type: 'program',
    factory: (program: Program) => TransformerFactory | CustomTransformerFactory
  }
  ```

- **TypeCheckerTransformerFactory** represents a transformer factory allowing the resulting transformer to grab a reference to the **TypeChecker** instance
  ```js
  {
    type: 'typeChecker',
    factory: (typeChecker: TypeChecker) => TransformerFactory | CustomTransformerFactory
  }
  ```

```js
typescript({
  transformers: {
    before: [
      {
        // Allow the transformer to get a Program reference in it's factory
        type: 'program',
        factory: program => {
          return ProgramRequiringTransformerFactory(program);
        }
      },
      {
        type: 'typeChecker',
        factory: typeChecker => {
          // Allow the transformer to get a Program reference in it's factory
          return TypeCheckerRequiringTransformerFactory(program);
        }
      }
    ],
    after: [
      // You can use normal transformers directly
      require('custom-transformer-based-on-Context')
    ],
    afterDeclarations: [
      // Or even define in place
      function fixDeclarationFactory(context) {
        return function fixDeclaration(source) {
          function visitor(node) {
            // Do real work here

            return ts.visitEachChild(node, visitor, context);
          }

          return ts.visitEachChild(source, visitor, context);
        };
      }
    ]
  }
});
```

### `cacheDir`

Type: `String`<br>
Default: _.rollup.cache_

When compiling with `incremental` or `composite` options the plugin will
store compiled files in this folder. This allows the use of incremental
compilation.

```js
typescript({
  cacheDir: '.rollup.tscache'
});
```

### Typescript compiler options

Some of Typescript's [CompilerOptions](https://www.typescriptlang.org/docs/handbook/compiler-options.html) affect how Rollup builds files.

#### `noEmitOnError`

Type: `Boolean`<br>
Default: `false`

If a type error is detected, the Rollup build is aborted when this option is set to true.

#### `files`, `include`, `exclude`

Type: `Array[...String]`<br>
Default: `[]`

Declaration files are automatically included if they are listed in the `files` field in your `tsconfig.json` file. Source files in these fields are ignored as Rollup's configuration is used instead.

#### Ignored options

These compiler options are ignored by Rollup:

- `noEmitHelpers`, `importHelpers`: The `tslib` helper module always must be used.
- `noEmit`, `emitDeclarationOnly`: Typescript needs to emit code for the plugin to work with.
- `noResolve`: Preventing Typescript from resolving code may break compilation

### Importing CommonJS

Though it is not recommended, it is possible to configure this plugin to handle imports of CommonJS files from TypeScript. For this, you need to specify `CommonJS` as the module format and add `rollup-plugin-commonjs` to transpile the CommonJS output generated by TypeScript to ES Modules so that rollup can process it.

```js
// rollup.config.js
import typescript from '@rollup/plugin-typescript';
import commonjs from '@rollup/plugin-commonjs';

export default {
  input: './main.ts',
  plugins: [
    typescript({ module: 'CommonJS' }),
    commonjs({ extensions: ['.js', '.ts'] }) // the ".ts" extension is required
  ]
};
```

Note that this will often result in less optimal output.

### Preserving JSX output

Whenever choosing to preserve JSX output to be further consumed by another transform step via `tsconfig` `compilerOptions` by setting `jsx: 'preserve'` or [overriding options](#options), please bear in mind that, by itself, this plugin won't be able to preserve JSX output, usually failing with:

```sh
[!] Error: Unexpected token (Note that you need plugins to import files that are not JavaScript)
file.tsx (1:15)
1: export default <span>Foobar</span>
                  ^
```

To prevent that, make sure to use the acorn plugin, namely `acorn-jsx`, which will make Rollup's parser acorn handle JSX tokens. (See https://rollupjs.org/guide/en/#acorninjectplugins)

After adding `acorn-jsx` plugin, your Rollup config would look like the following, correctly preserving your JSX output.

```js
import jsx from 'acorn-jsx';
import typescript from '@rollup/plugin-typescript';

export default {
  // … other options …
  acornInjectPlugins: [jsx()],
  plugins: [typescript({ jsx: 'preserve' })]
};
```

### Faster compiling

Previous versions of this plugin used Typescript's `transpileModule` API, which is faster but does not perform typechecking and does not support cross-file features like `const enum`s and emit-less types. If you want this behaviour, you can use [@rollup/plugin-sucrase](https://github.com/rollup/plugins/tree/master/packages/sucrase) instead.

## Meta

[CONTRIBUTING](/.github/CONTRIBUTING.md)

[LICENSE (MIT)](/LICENSE)
