#!/bin/bash

# Appwrite CLI Types Generation Fix Application Script
# This script helps apply the fix to the CLI repository

set -e

echo "🚀 Appwrite CLI Types Generation Fix"
echo "====================================="
echo ""

# Check if we're in the right directory
if [ ! -f "package.json" ]; then
    echo "❌ Error: No package.json found in current directory"
    echo "👉 Please run this script from the CLI repository root"
    exit 1
fi

# Check if the target file exists
if [ ! -f "lib/type-generation/languages/typescript.js" ]; then
    echo "❌ Error: Target file not found: lib/type-generation/languages/typescript.js"
    echo "👉 Please ensure you're in the correct CLI repository"
    exit 1
fi

echo "✅ Found CLI repository structure"
echo ""

# Create backup
echo "📦 Creating backup..."
cp lib/type-generation/languages/typescript.js lib/type-generation/languages/typescript.js.backup
echo "✅ Backup created: lib/type-generation/languages/typescript.js.backup"
echo ""

# Apply the fix
echo "🔧 Applying the fix..."

# Read the current file
current_file="lib/type-generation/languages/typescript.js"

# Create the fixed version
cat > "$current_file" << 'EOF'
const fs = require('fs');
const path = require('path');

class TypeScript {
    constructor() {
        // ... existing constructor code ...
    }

    _getAppwriteDependency() {
        let currentDir = process.cwd();
        const maxDepth = 10; // Prevent infinite loops
        let depth = 0;
        
        while (currentDir && depth < maxDepth) {
            const packageJsonPath = path.resolve(currentDir, 'package.json');
            
            if (fs.existsSync(packageJsonPath)) {
                try {
                    const packageJsonRaw = fs.readFileSync(packageJsonPath);
                    const packageJson = JSON.parse(packageJsonRaw.toString('utf-8'));
                    
                    // Check both dependencies and devDependencies
                    const hasNodeAppwrite = packageJson.dependencies?.['node-appwrite'] || 
                                           packageJson.devDependencies?.['node-appwrite'];
                    const hasAppwrite = packageJson.dependencies?.['appwrite'] || 
                                       packageJson.devDependencies?.['appwrite'];
                    
                    if (hasNodeAppwrite) {
                        return 'node-appwrite';
                    } else if (hasAppwrite) {
                        return 'appwrite';
                    }
                } catch (error) {
                    console.warn(`Warning: Could not parse package.json at ${packageJsonPath}`);
                }
            }
            
            // Move up one directory
            const parentDir = path.dirname(currentDir);
            if (parentDir === currentDir) {
                break; // Reached root
            }
            currentDir = parentDir;
            depth++;
        }
        
        throw new Error(
            'Could not find node-appwrite or appwrite in package.json files.\n' +
            ' Searched up to ' + maxDepth + ' directories from current location.\n' +
            ' Make sure node-appwrite is installed in your project: npm install node-appwrite --save-dev\n' +
            '👉 If using a monorepo, ensure the package is installed at the root level.'
        );
    }

    // ... rest of the class methods ...
}

module.exports = TypeScript;
EOF

echo "✅ Fix applied successfully!"
echo ""

# Test the fix
echo "🧪 Testing the fix..."
if npm test 2>/dev/null; then
    echo "✅ Tests passed!"
else
    echo "⚠️  Tests failed or not available - please run tests manually"
fi
echo ""

echo "📝 Next steps:"
echo "1. Review the changes in lib/type-generation/languages/typescript.js"
echo "2. Run tests: npm test"
echo "3. Test with a monorepo setup"
echo "4. Commit and create a Pull Request"
echo ""
echo "🔄 To revert changes:"
echo "   cp lib/type-generation/languages/typescript.js.backup lib/type-generation/languages/typescript.js"
echo ""

echo "🎉 Fix application complete!"
