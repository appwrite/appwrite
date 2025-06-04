'use strict';
const path = require('path');
const resolveFrom = require('resolve-from');

module.exports = (moduleId, options = {}) => {
	const parts = moduleId.replace(/\\/g, '/').split('/');
	let packageName = '';

	// Handle scoped package name
	if (parts.length > 0 && parts[0][0] === '@') {
		packageName += parts.shift() + '/';
	}

	packageName += parts.shift();

	const packageJson = path.join(packageName, 'package.json');
	const resolved = resolveFrom.silent(options.cwd || process.cwd(), packageJson);

	if (!resolved) {
		return;
	}

	return path.join(path.dirname(resolved), parts.join('/'));
};
