export const VERSION: string;

export interface RollupError extends RollupLogProps {
	parserError?: Error;
	stack?: string;
	watchFiles?: string[];
}

export interface RollupWarning extends RollupLogProps {
	chunkName?: string;
	cycle?: string[];
	exportName?: string;
	exporter?: string;
	guess?: string;
	importer?: string;
	missing?: string;
	modules?: string[];
	names?: string[];
	reexporter?: string;
	source?: string;
	sources?: string[];
}

export interface RollupLogProps {
	code?: string;
	frame?: string;
	hook?: string;
	id?: string;
	loc?: {
		column: number;
		file?: string;
		line: number;
	};
	message: string;
	name?: string;
	plugin?: string;
	pluginCode?: string;
	pos?: number;
	url?: string;
}

export type SourceMapSegment =
	| [number]
	| [number, number, number, number]
	| [number, number, number, number, number];

export interface ExistingDecodedSourceMap {
	file?: string;
	mappings: SourceMapSegment[][];
	names: string[];
	sourceRoot?: string;
	sources: string[];
	sourcesContent?: string[];
	version: number;
}

export interface ExistingRawSourceMap {
	file?: string;
	mappings: string;
	names: string[];
	sourceRoot?: string;
	sources: string[];
	sourcesContent?: string[];
	version: number;
}

export type DecodedSourceMapOrMissing =
	| {
			mappings?: never;
			missing: true;
			plugin: string;
	  }
	| ExistingDecodedSourceMap;

export interface SourceMap {
	file: string;
	mappings: string;
	names: string[];
	sources: string[];
	sourcesContent: string[];
	version: number;
	toString(): string;
	toUrl(): string;
}

export type SourceMapInput = ExistingRawSourceMap | string | null | { mappings: '' };

type PartialNull<T> = {
	[P in keyof T]: T[P] | null;
};

interface ModuleOptions {
	meta: CustomPluginOptions;
	moduleSideEffects: boolean | 'no-treeshake';
	syntheticNamedExports: boolean | string;
}

export interface SourceDescription extends Partial<PartialNull<ModuleOptions>> {
	ast?: AcornNode;
	code: string;
	map?: SourceMapInput;
}

export interface TransformModuleJSON extends Partial<PartialNull<ModuleOptions>> {
	ast?: AcornNode;
	code: string;
	// note if plugins use new this.cache to opt-out auto transform cache
	customTransformCache: boolean;
	originalCode: string;
	originalSourcemap: ExistingDecodedSourceMap | null;
	resolvedIds?: ResolvedIdMap;
	sourcemapChain: DecodedSourceMapOrMissing[];
	transformDependencies: string[];
}

export interface ModuleJSON extends TransformModuleJSON {
	ast: AcornNode;
	dependencies: string[];
	id: string;
	transformFiles: EmittedFile[] | undefined;
}

export interface PluginCache {
	delete(id: string): boolean;
	get<T = any>(id: string): T;
	has(id: string): boolean;
	set<T = any>(id: string, value: T): void;
}

export interface MinimalPluginContext {
	meta: PluginContextMeta;
}

export interface EmittedAsset {
	fileName?: string;
	name?: string;
	source?: string | Uint8Array;
	type: 'asset';
}

export interface EmittedChunk {
	fileName?: string;
	id: string;
	implicitlyLoadedAfterOneOf?: string[];
	importer?: string;
	name?: string;
	preserveSignature?: PreserveEntrySignaturesOption;
	type: 'chunk';
}

export type EmittedFile = EmittedAsset | EmittedChunk;

export type EmitAsset = (name: string, source?: string | Uint8Array) => string;

export type EmitChunk = (id: string, options?: { name?: string }) => string;

export type EmitFile = (emittedFile: EmittedFile) => string;

interface ModuleInfo {
	ast: AcornNode | null;
	code: string | null;
	dynamicImporters: readonly string[];
	dynamicallyImportedIds: readonly string[];
	hasModuleSideEffects: boolean | 'no-treeshake';
	id: string;
	implicitlyLoadedAfterOneOf: readonly string[];
	implicitlyLoadedBefore: readonly string[];
	importedIds: readonly string[];
	importers: readonly string[];
	isEntry: boolean;
	isExternal: boolean;
	meta: CustomPluginOptions;
	syntheticNamedExports: boolean | string;
}

export type GetModuleInfo = (moduleId: string) => ModuleInfo | null;

export interface CustomPluginOptions {
	[plugin: string]: any;
}

export interface PluginContext extends MinimalPluginContext {
	addWatchFile: (id: string) => void;
	cache: PluginCache;
	/** @deprecated Use `this.emitFile` instead */
	emitAsset: EmitAsset;
	/** @deprecated Use `this.emitFile` instead */
	emitChunk: EmitChunk;
	emitFile: EmitFile;
	error: (err: RollupError | string, pos?: number | { column: number; line: number }) => never;
	/** @deprecated Use `this.getFileName` instead */
	getAssetFileName: (assetReferenceId: string) => string;
	/** @deprecated Use `this.getFileName` instead */
	getChunkFileName: (chunkReferenceId: string) => string;
	getFileName: (fileReferenceId: string) => string;
	getModuleIds: () => IterableIterator<string>;
	getModuleInfo: GetModuleInfo;
	getWatchFiles: () => string[];
	/** @deprecated Use `this.resolve` instead */
	isExternal: IsExternal;
	/** @deprecated Use `this.getModuleIds` instead */
	moduleIds: IterableIterator<string>;
	parse: (input: string, options?: any) => AcornNode;
	resolve: (
		source: string,
		importer?: string,
		options?: { custom?: CustomPluginOptions; skipSelf?: boolean }
	) => Promise<ResolvedId | null>;
	/** @deprecated Use `this.resolve` instead */
	resolveId: (source: string, importer?: string) => Promise<string | null>;
	setAssetSource: (assetReferenceId: string, source: string | Uint8Array) => void;
	warn: (warning: RollupWarning | string, pos?: number | { column: number; line: number }) => void;
}

export interface PluginContextMeta {
	rollupVersion: string;
	watchMode: boolean;
}

export interface ResolvedId extends ModuleOptions {
	external: boolean | 'absolute';
	id: string;
}

export interface ResolvedIdMap {
	[key: string]: ResolvedId;
}

interface PartialResolvedId extends Partial<PartialNull<ModuleOptions>> {
	external?: boolean | 'absolute' | 'relative';
	id: string;
}

export type ResolveIdResult = string | false | null | undefined | PartialResolvedId;

export type ResolveIdHook = (
	this: PluginContext,
	source: string,
	importer: string | undefined,
	options: { custom?: CustomPluginOptions }
) => Promise<ResolveIdResult> | ResolveIdResult;

export type IsExternal = (
	source: string,
	importer: string | undefined,
	isResolved: boolean
) => boolean;

export type IsPureModule = (id: string) => boolean | null | undefined;

export type HasModuleSideEffects = (id: string, external: boolean) => boolean;

type LoadResult = SourceDescription | string | null | undefined;

export type LoadHook = (this: PluginContext, id: string) => Promise<LoadResult> | LoadResult;

export interface TransformPluginContext extends PluginContext {
	getCombinedSourcemap: () => SourceMap;
}

export type TransformResult = string | null | undefined | Partial<SourceDescription>;

export type TransformHook = (
	this: TransformPluginContext,
	code: string,
	id: string
) => Promise<TransformResult> | TransformResult;

export type ModuleParsedHook = (this: PluginContext, info: ModuleInfo) => Promise<void> | void;

export type RenderChunkHook = (
	this: PluginContext,
	code: string,
	chunk: RenderedChunk,
	options: NormalizedOutputOptions
) =>
	| Promise<{ code: string; map?: SourceMapInput } | null>
	| { code: string; map?: SourceMapInput }
	| string
	| null
	| undefined;

export type ResolveDynamicImportHook = (
	this: PluginContext,
	specifier: string | AcornNode,
	importer: string
) => Promise<ResolveIdResult> | ResolveIdResult;

export type ResolveImportMetaHook = (
	this: PluginContext,
	prop: string | null,
	options: { chunkId: string; format: InternalModuleFormat; moduleId: string }
) => string | null | undefined;

export type ResolveAssetUrlHook = (
	this: PluginContext,
	options: {
		assetFileName: string;
		chunkId: string;
		format: InternalModuleFormat;
		moduleId: string;
		relativeAssetPath: string;
	}
) => string | null | undefined;

export type ResolveFileUrlHook = (
	this: PluginContext,
	options: {
		assetReferenceId: string | null;
		chunkId: string;
		chunkReferenceId: string | null;
		fileName: string;
		format: InternalModuleFormat;
		moduleId: string;
		referenceId: string;
		relativePath: string;
	}
) => string | null | undefined;

export type AddonHookFunction = (this: PluginContext) => string | Promise<string>;
export type AddonHook = string | AddonHookFunction;

export type ChangeEvent = 'create' | 'update' | 'delete';
export type WatchChangeHook = (
	this: PluginContext,
	id: string,
	change: { event: ChangeEvent }
) => void;

/**
 * use this type for plugin annotation
 * @example
 * ```ts
 * interface Options {
 * ...
 * }
 * const myPlugin: PluginImpl<Options> = (options = {}) => { ... }
 * ```
 */
// eslint-disable-next-line @typescript-eslint/ban-types
export type PluginImpl<O extends object = object> = (options?: O) => Plugin;

export interface OutputBundle {
	[fileName: string]: OutputAsset | OutputChunk;
}

export interface FilePlaceholder {
	type: 'placeholder';
}

export interface OutputBundleWithPlaceholders {
	[fileName: string]: OutputAsset | OutputChunk | FilePlaceholder;
}

export interface PluginHooks extends OutputPluginHooks {
	buildEnd: (this: PluginContext, err?: Error) => Promise<void> | void;
	buildStart: (this: PluginContext, options: NormalizedInputOptions) => Promise<void> | void;
	closeBundle: (this: PluginContext) => Promise<void> | void;
	closeWatcher: (this: PluginContext) => void;
	load: LoadHook;
	moduleParsed: ModuleParsedHook;
	options: (
		this: MinimalPluginContext,
		options: InputOptions
	) => Promise<InputOptions | null | undefined> | InputOptions | null | undefined;
	resolveDynamicImport: ResolveDynamicImportHook;
	resolveId: ResolveIdHook;
	transform: TransformHook;
	watchChange: WatchChangeHook;
}

interface OutputPluginHooks {
	augmentChunkHash: (this: PluginContext, chunk: PreRenderedChunk) => string | void;
	generateBundle: (
		this: PluginContext,
		options: NormalizedOutputOptions,
		bundle: OutputBundle,
		isWrite: boolean
	) => void | Promise<void>;
	outputOptions: (this: PluginContext, options: OutputOptions) => OutputOptions | null | undefined;
	renderChunk: RenderChunkHook;
	renderDynamicImport: (
		this: PluginContext,
		options: {
			customResolution: string | null;
			format: InternalModuleFormat;
			moduleId: string;
			targetModuleId: string | null;
		}
	) => { left: string; right: string } | null | undefined;
	renderError: (this: PluginContext, err?: Error) => Promise<void> | void;
	renderStart: (
		this: PluginContext,
		outputOptions: NormalizedOutputOptions,
		inputOptions: NormalizedInputOptions
	) => Promise<void> | void;
	/** @deprecated Use `resolveFileUrl` instead */
	resolveAssetUrl: ResolveAssetUrlHook;
	resolveFileUrl: ResolveFileUrlHook;
	resolveImportMeta: ResolveImportMetaHook;
	writeBundle: (
		this: PluginContext,
		options: NormalizedOutputOptions,
		bundle: OutputBundle
	) => void | Promise<void>;
}

export type AsyncPluginHooks =
	| 'options'
	| 'buildEnd'
	| 'buildStart'
	| 'generateBundle'
	| 'load'
	| 'moduleParsed'
	| 'renderChunk'
	| 'renderError'
	| 'renderStart'
	| 'resolveDynamicImport'
	| 'resolveId'
	| 'transform'
	| 'writeBundle'
	| 'closeBundle';

export type PluginValueHooks = 'banner' | 'footer' | 'intro' | 'outro';

export type SyncPluginHooks = Exclude<keyof PluginHooks, AsyncPluginHooks>;

export type FirstPluginHooks =
	| 'load'
	| 'renderDynamicImport'
	| 'resolveAssetUrl'
	| 'resolveDynamicImport'
	| 'resolveFileUrl'
	| 'resolveId'
	| 'resolveImportMeta';

export type SequentialPluginHooks =
	| 'augmentChunkHash'
	| 'closeWatcher'
	| 'generateBundle'
	| 'options'
	| 'outputOptions'
	| 'renderChunk'
	| 'transform'
	| 'watchChange';

export type ParallelPluginHooks =
	| 'banner'
	| 'buildEnd'
	| 'buildStart'
	| 'footer'
	| 'intro'
	| 'moduleParsed'
	| 'outro'
	| 'renderError'
	| 'renderStart'
	| 'writeBundle'
	| 'closeBundle';

interface OutputPluginValueHooks {
	banner: AddonHook;
	cacheKey: string;
	footer: AddonHook;
	intro: AddonHook;
	outro: AddonHook;
}

export interface Plugin extends Partial<PluginHooks>, Partial<OutputPluginValueHooks> {
	// for inter-plugin communication
	api?: any;
	name: string;
}

export interface OutputPlugin extends Partial<OutputPluginHooks>, Partial<OutputPluginValueHooks> {
	name: string;
}

type TreeshakingPreset = 'smallest' | 'safest' | 'recommended';

export interface TreeshakingOptions {
	annotations?: boolean;
	correctVarValueBeforeDeclaration?: boolean;
	moduleSideEffects?: ModuleSideEffectsOption;
	preset?: TreeshakingPreset;
	propertyReadSideEffects?: boolean | 'always';
	/** @deprecated Use `moduleSideEffects` instead */
	pureExternalModules?: PureModulesOption;
	tryCatchDeoptimization?: boolean;
	unknownGlobalSideEffects?: boolean;
}

export interface NormalizedTreeshakingOptions {
	annotations: boolean;
	correctVarValueBeforeDeclaration: boolean;
	moduleSideEffects: HasModuleSideEffects;
	propertyReadSideEffects: boolean | 'always';
	tryCatchDeoptimization: boolean;
	unknownGlobalSideEffects: boolean;
}

interface GetManualChunkApi {
	getModuleIds: () => IterableIterator<string>;
	getModuleInfo: GetModuleInfo;
}
export type GetManualChunk = (id: string, api: GetManualChunkApi) => string | null | undefined;

export type ExternalOption =
	| (string | RegExp)[]
	| string
	| RegExp
	| ((
			source: string,
			importer: string | undefined,
			isResolved: boolean
	  ) => boolean | null | undefined);
export type PureModulesOption = boolean | string[] | IsPureModule;
export type GlobalsOption = { [name: string]: string } | ((name: string) => string);
export type InputOption = string | string[] | { [entryAlias: string]: string };
export type ManualChunksOption = { [chunkAlias: string]: string[] } | GetManualChunk;
export type ModuleSideEffectsOption = boolean | 'no-external' | string[] | HasModuleSideEffects;
export type PreserveEntrySignaturesOption = false | 'strict' | 'allow-extension' | 'exports-only';
export type SourcemapPathTransformOption = (
	relativeSourcePath: string,
	sourcemapPath: string
) => string;

export interface InputOptions {
	acorn?: Record<string, unknown>;
	acornInjectPlugins?: (() => unknown)[] | (() => unknown);
	cache?: false | RollupCache;
	context?: string;
	experimentalCacheExpiry?: number;
	external?: ExternalOption;
	/** @deprecated Use the "inlineDynamicImports" output option instead. */
	inlineDynamicImports?: boolean;
	input?: InputOption;
	makeAbsoluteExternalsRelative?: boolean | 'ifRelativeSource';
	/** @deprecated Use the "manualChunks" output option instead. */
	manualChunks?: ManualChunksOption;
	moduleContext?: ((id: string) => string | null | undefined) | { [id: string]: string };
	onwarn?: WarningHandlerWithDefault;
	perf?: boolean;
	plugins?: (Plugin | null | false | undefined)[];
	preserveEntrySignatures?: PreserveEntrySignaturesOption;
	/** @deprecated Use the "preserveModules" output option instead. */
	preserveModules?: boolean;
	preserveSymlinks?: boolean;
	shimMissingExports?: boolean;
	strictDeprecations?: boolean;
	treeshake?: boolean | TreeshakingPreset | TreeshakingOptions;
	watch?: WatcherOptions | false;
}

export interface NormalizedInputOptions {
	acorn: Record<string, unknown>;
	acornInjectPlugins: (() => unknown)[];
	cache: false | undefined | RollupCache;
	context: string;
	experimentalCacheExpiry: number;
	external: IsExternal;
	/** @deprecated Use the "inlineDynamicImports" output option instead. */
	inlineDynamicImports: boolean | undefined;
	input: string[] | { [entryAlias: string]: string };
	makeAbsoluteExternalsRelative: boolean | 'ifRelativeSource';
	/** @deprecated Use the "manualChunks" output option instead. */
	manualChunks: ManualChunksOption | undefined;
	moduleContext: (id: string) => string;
	onwarn: WarningHandler;
	perf: boolean;
	plugins: Plugin[];
	preserveEntrySignatures: PreserveEntrySignaturesOption;
	/** @deprecated Use the "preserveModules" output option instead. */
	preserveModules: boolean | undefined;
	preserveSymlinks: boolean;
	shimMissingExports: boolean;
	strictDeprecations: boolean;
	treeshake: false | NormalizedTreeshakingOptions;
}

export type InternalModuleFormat = 'amd' | 'cjs' | 'es' | 'iife' | 'system' | 'umd';

export type ModuleFormat = InternalModuleFormat | 'commonjs' | 'esm' | 'module' | 'systemjs';

export type OptionsPaths = Record<string, string> | ((id: string) => string);

export type InteropType = boolean | 'auto' | 'esModule' | 'default' | 'defaultOnly';

export type GetInterop = (id: string | null) => InteropType;

export type AmdOptions = (
	| {
			autoId?: false;
			id: string;
	  }
	| {
			autoId: true;
			basePath?: string;
			id?: undefined;
	  }
	| {
			autoId?: false;
			id?: undefined;
	  }
) & {
	define?: string;
};

export type NormalizedAmdOptions = (
	| {
			autoId: false;
			id?: string;
	  }
	| {
			autoId: true;
			basePath: string;
	  }
) & {
	define: string;
};

export interface OutputOptions {
	amd?: AmdOptions;
	assetFileNames?: string | ((chunkInfo: PreRenderedAsset) => string);
	banner?: string | (() => string | Promise<string>);
	chunkFileNames?: string | ((chunkInfo: PreRenderedChunk) => string);
	compact?: boolean;
	// only required for bundle.write
	dir?: string;
	/** @deprecated Use the "renderDynamicImport" plugin hook instead. */
	dynamicImportFunction?: string;
	entryFileNames?: string | ((chunkInfo: PreRenderedChunk) => string);
	esModule?: boolean;
	exports?: 'default' | 'named' | 'none' | 'auto';
	extend?: boolean;
	externalLiveBindings?: boolean;
	// only required for bundle.write
	file?: string;
	footer?: string | (() => string | Promise<string>);
	format?: ModuleFormat;
	freeze?: boolean;
	globals?: GlobalsOption;
	hoistTransitiveImports?: boolean;
	indent?: string | boolean;
	inlineDynamicImports?: boolean;
	interop?: InteropType | GetInterop;
	intro?: string | (() => string | Promise<string>);
	manualChunks?: ManualChunksOption;
	minifyInternalExports?: boolean;
	name?: string;
	namespaceToStringTag?: boolean;
	noConflict?: boolean;
	outro?: string | (() => string | Promise<string>);
	paths?: OptionsPaths;
	plugins?: (OutputPlugin | null | false | undefined)[];
	preferConst?: boolean;
	preserveModules?: boolean;
	preserveModulesRoot?: string;
	sanitizeFileName?: boolean | ((fileName: string) => string);
	sourcemap?: boolean | 'inline' | 'hidden';
	sourcemapExcludeSources?: boolean;
	sourcemapFile?: string;
	sourcemapPathTransform?: SourcemapPathTransformOption;
	strict?: boolean;
	systemNullSetters?: boolean;
	validate?: boolean;
}

export interface NormalizedOutputOptions {
	amd: NormalizedAmdOptions;
	assetFileNames: string | ((chunkInfo: PreRenderedAsset) => string);
	banner: () => string | Promise<string>;
	chunkFileNames: string | ((chunkInfo: PreRenderedChunk) => string);
	compact: boolean;
	dir: string | undefined;
	/** @deprecated Use the "renderDynamicImport" plugin hook instead. */
	dynamicImportFunction: string | undefined;
	entryFileNames: string | ((chunkInfo: PreRenderedChunk) => string);
	esModule: boolean;
	exports: 'default' | 'named' | 'none' | 'auto';
	extend: boolean;
	externalLiveBindings: boolean;
	file: string | undefined;
	footer: () => string | Promise<string>;
	format: InternalModuleFormat;
	freeze: boolean;
	globals: GlobalsOption;
	hoistTransitiveImports: boolean;
	indent: true | string;
	inlineDynamicImports: boolean;
	interop: GetInterop;
	intro: () => string | Promise<string>;
	manualChunks: ManualChunksOption;
	minifyInternalExports: boolean;
	name: string | undefined;
	namespaceToStringTag: boolean;
	noConflict: boolean;
	outro: () => string | Promise<string>;
	paths: OptionsPaths;
	plugins: OutputPlugin[];
	preferConst: boolean;
	preserveModules: boolean;
	preserveModulesRoot: string | undefined;
	sanitizeFileName: (fileName: string) => string;
	sourcemap: boolean | 'inline' | 'hidden';
	sourcemapExcludeSources: boolean;
	sourcemapFile: string | undefined;
	sourcemapPathTransform: SourcemapPathTransformOption | undefined;
	strict: boolean;
	systemNullSetters: boolean;
	validate: boolean;
}

export type WarningHandlerWithDefault = (
	warning: RollupWarning,
	defaultHandler: WarningHandler
) => void;
export type WarningHandler = (warning: RollupWarning) => void;

export interface SerializedTimings {
	[label: string]: [number, number, number];
}

export interface PreRenderedAsset {
	name: string | undefined;
	source: string | Uint8Array;
	type: 'asset';
}

export interface OutputAsset extends PreRenderedAsset {
	fileName: string;
	/** @deprecated Accessing "isAsset" on files in the bundle is deprecated, please use "type === \'asset\'" instead */
	isAsset: true;
}

export interface RenderedModule {
	code: string | null;
	originalLength: number;
	removedExports: string[];
	renderedExports: string[];
	renderedLength: number;
}

export interface PreRenderedChunk {
	exports: string[];
	facadeModuleId: string | null;
	isDynamicEntry: boolean;
	isEntry: boolean;
	isImplicitEntry: boolean;
	modules: {
		[id: string]: RenderedModule;
	};
	name: string;
	type: 'chunk';
}

export interface RenderedChunk extends PreRenderedChunk {
	code?: string;
	dynamicImports: string[];
	fileName: string;
	implicitlyLoadedBefore: string[];
	importedBindings: {
		[imported: string]: string[];
	};
	imports: string[];
	map?: SourceMap;
	referencedFiles: string[];
}

export interface OutputChunk extends RenderedChunk {
	code: string;
}

export interface SerializablePluginCache {
	[key: string]: [number, any];
}

export interface RollupCache {
	modules: ModuleJSON[];
	plugins?: Record<string, SerializablePluginCache>;
}

export interface RollupOutput {
	output: [OutputChunk, ...(OutputChunk | OutputAsset)[]];
}

export interface RollupBuild {
	cache: RollupCache | undefined;
	close: () => Promise<void>;
	closed: boolean;
	generate: (outputOptions: OutputOptions) => Promise<RollupOutput>;
	getTimings?: () => SerializedTimings;
	watchFiles: string[];
	write: (options: OutputOptions) => Promise<RollupOutput>;
}

export interface RollupOptions extends InputOptions {
	// This is included for compatibility with config files but ignored by rollup.rollup
	output?: OutputOptions | OutputOptions[];
}

export interface MergedRollupOptions extends InputOptions {
	output: OutputOptions[];
}

export function rollup(options: RollupOptions): Promise<RollupBuild>;

export interface ChokidarOptions {
	alwaysStat?: boolean;
	atomic?: boolean | number;
	awaitWriteFinish?:
		| {
				pollInterval?: number;
				stabilityThreshold?: number;
		  }
		| boolean;
	binaryInterval?: number;
	cwd?: string;
	depth?: number;
	disableGlobbing?: boolean;
	followSymlinks?: boolean;
	ignoreInitial?: boolean;
	ignorePermissionErrors?: boolean;
	ignored?: any;
	interval?: number;
	persistent?: boolean;
	useFsEvents?: boolean;
	usePolling?: boolean;
}

export interface WatcherOptions {
	buildDelay?: number;
	chokidar?: ChokidarOptions;
	clearScreen?: boolean;
	exclude?: string | RegExp | (string | RegExp)[];
	include?: string | RegExp | (string | RegExp)[];
	skipWrite?: boolean;
}

export interface RollupWatchOptions extends InputOptions {
	output?: OutputOptions | OutputOptions[];
	watch?: WatcherOptions | false;
}

interface TypedEventEmitter<T extends { [event: string]: (...args: any) => any }> {
	addListener<K extends keyof T>(event: K, listener: T[K]): this;
	emit<K extends keyof T>(event: K, ...args: Parameters<T[K]>): boolean;
	eventNames(): Array<keyof T>;
	getMaxListeners(): number;
	listenerCount(type: keyof T): number;
	listeners<K extends keyof T>(event: K): Array<T[K]>;
	off<K extends keyof T>(event: K, listener: T[K]): this;
	on<K extends keyof T>(event: K, listener: T[K]): this;
	once<K extends keyof T>(event: K, listener: T[K]): this;
	prependListener<K extends keyof T>(event: K, listener: T[K]): this;
	prependOnceListener<K extends keyof T>(event: K, listener: T[K]): this;
	rawListeners<K extends keyof T>(event: K): Array<T[K]>;
	removeAllListeners<K extends keyof T>(event?: K): this;
	removeListener<K extends keyof T>(event: K, listener: T[K]): this;
	setMaxListeners(n: number): this;
}

export type RollupWatcherEvent =
	| { code: 'START' }
	| { code: 'BUNDLE_START'; input?: InputOption; output: readonly string[] }
	| {
			code: 'BUNDLE_END';
			duration: number;
			input?: InputOption;
			output: readonly string[];
			result: RollupBuild;
	  }
	| { code: 'END' }
	| { code: 'ERROR'; error: RollupError; result: RollupBuild | null };

export interface RollupWatcher
	extends TypedEventEmitter<{
		change: (id: string, change: { event: ChangeEvent }) => void;
		close: () => void;
		event: (event: RollupWatcherEvent) => void;
		restart: () => void;
	}> {
	close(): void;
}

export function watch(config: RollupWatchOptions | RollupWatchOptions[]): RollupWatcher;

interface AcornNode {
	end: number;
	start: number;
	type: string;
}

export function defineConfig(options: RollupOptions): RollupOptions;
export function defineConfig(options: RollupOptions[]): RollupOptions[];
