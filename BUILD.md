# Build and Packaging

This document explains how to build and package the Ahentic plugin for production use.

## Prerequisites

- Node.js 18 or higher
- npm

## Available Scripts

### Development

```bash
# Start development mode with hot reloading (free build)
npm run start

# Build for production AND create production package (free)
npm run build

# Premium development / build (requires pro__premium_only checkout)
npm run start:premium
npm run build:premium

# Lint JavaScript and CSS
npm run lint
npm run lint:js
npm run lint:css

# Format code
npm run format
```

### Production Packaging

```bash
npm run build
```

The `build` script:

1. Sets `AHENTIC_BUILD` to `free`
2. Syncs versions across `ahentic.php`, `package.json`, and `readme.txt`
3. Compiles JavaScript and CSS with `@wordpress/scripts`
4. Packages a zip into `dist/` (excludes `pro__premium_only` for free builds)

## Package Contents

The final free package includes:

- Main plugin files: `ahentic.php`, `composer.json`, `readme.txt`
- Built assets from `build/`
- PHP source under `src/`
- Security `index.php` files in directories

## Output

- **Location**: `dist/ahentic-{version}.zip`
- **Format**: Standard WordPress plugin zip file
