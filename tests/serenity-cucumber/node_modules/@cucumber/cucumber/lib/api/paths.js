"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.resolvePaths = void 0;
const util_1 = require("util");
const glob_1 = __importDefault(require("glob"));
const path_1 = __importDefault(require("path"));
const fs_1 = __importDefault(require("mz/fs"));
async function resolvePaths(logger, cwd, sources, support = {
    requireModules: [],
    requirePaths: [],
    importPaths: [],
}) {
    const unexpandedFeaturePaths = await getUnexpandedFeaturePaths(cwd, sources.paths);
    const featurePaths = await expandFeaturePaths(cwd, unexpandedFeaturePaths);
    logger.debug('Found feature files based on configuration:', featurePaths);
    const { requirePaths, importPaths } = await deriveSupportPaths(cwd, featurePaths, support.requirePaths, support.importPaths);
    logger.debug('Found support files to load via `require` based on configuration:', requirePaths);
    logger.debug('Found support files to load via `import` based on configuration:', importPaths);
    return {
        unexpandedFeaturePaths,
        featurePaths,
        requirePaths,
        importPaths,
    };
}
exports.resolvePaths = resolvePaths;
async function expandPaths(cwd, unexpandedPaths, defaultExtension) {
    const expandedPaths = await Promise.all(unexpandedPaths.map(async (unexpandedPath) => {
        const matches = await (0, util_1.promisify)(glob_1.default)(unexpandedPath, {
            absolute: true,
            cwd,
        });
        const expanded = await Promise.all(matches.map(async (match) => {
            if (path_1.default.extname(match) === '') {
                return await (0, util_1.promisify)(glob_1.default)(`${match}/**/*${defaultExtension}`);
            }
            return [match];
        }));
        return expanded.flat();
    }));
    const normalized = expandedPaths.flat().map((x) => path_1.default.normalize(x));
    return [...new Set(normalized)];
}
async function getUnexpandedFeaturePaths(cwd, args) {
    if (args.length > 0) {
        const nestedFeaturePaths = await Promise.all(args.map(async (arg) => {
            const filename = path_1.default.basename(arg);
            if (filename[0] === '@') {
                const filePath = path_1.default.join(cwd, arg);
                const content = await fs_1.default.readFile(filePath, 'utf8');
                return content.split('\n').map((x) => x.trim());
            }
            return [arg];
        }));
        const featurePaths = nestedFeaturePaths.flat();
        if (featurePaths.length > 0) {
            return featurePaths.filter((x) => x !== '');
        }
    }
    return ['features/**/*.{feature,feature.md}'];
}
function getFeatureDirectoryPaths(cwd, featurePaths) {
    const featureDirs = featurePaths.map((featurePath) => {
        let featureDir = path_1.default.dirname(featurePath);
        let childDir;
        let parentDir = featureDir;
        while (childDir !== parentDir) {
            childDir = parentDir;
            parentDir = path_1.default.dirname(childDir);
            if (path_1.default.basename(parentDir) === 'features') {
                featureDir = parentDir;
                break;
            }
        }
        return path_1.default.relative(cwd, featureDir);
    });
    return [...new Set(featureDirs)];
}
async function expandFeaturePaths(cwd, featurePaths) {
    featurePaths = featurePaths.map((p) => p.replace(/(:\d+)*$/g, '')); // Strip line numbers
    return await expandPaths(cwd, featurePaths, '.feature');
}
async function deriveSupportPaths(cwd, featurePaths, unexpandedRequirePaths, unexpandedImportPaths) {
    if (unexpandedRequirePaths.length === 0 &&
        unexpandedImportPaths.length === 0) {
        const defaultPaths = getFeatureDirectoryPaths(cwd, featurePaths);
        const requirePaths = await expandPaths(cwd, defaultPaths, '.js');
        const importPaths = await expandPaths(cwd, defaultPaths, '.mjs');
        return { requirePaths, importPaths };
    }
    const requirePaths = unexpandedRequirePaths.length > 0
        ? await expandPaths(cwd, unexpandedRequirePaths, '.js')
        : [];
    const importPaths = unexpandedImportPaths.length > 0
        ? await expandPaths(cwd, unexpandedImportPaths, '.@(js|cjs|mjs)')
        : [];
    return { requirePaths, importPaths };
}
//# sourceMappingURL=paths.js.map