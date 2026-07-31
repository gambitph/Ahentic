# Ahentic – AI Workspace

An intelligent AI agent that understands your WordPress site and works alongside you to build, edit, troubleshoot, and manage it.

## Requirements

- Node.js v18 or higher
- npm v9 or higher
- WordPress

## Scripts

- `npm start` – Development build with watch mode
- `npm run build` – Production build and package (free)
- `npm run build:premium` – Production build and package (premium)
- `npm run lint:js` – Lint JavaScript
- `npm run format` – Format code with Prettier

## Stack

- **JavaScript** (not TypeScript) for plugin UI — the React sidebar is the main workspace (admin + front-end for capable users)
- **React** via `@wordpress/element` (JSX in `.js` files, same approach as Cimo)
- **WordPress AI Client / [php-ai-client](https://github.com/WordPress/php-ai-client)** for provider-agnostic LLM access ([WP 7.0 note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/))
- **WordPress Abilities API** for agent tools/actions ([6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/), [client-side 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/))
- Sidebar chrome persists in `localStorage`; conversation contents will persist in the database (not yet)

## Structure

- `ahentic.php` – Main plugin bootstrap
- `src/admin/` – Admin PHP classes and React UI (`js/`)
- `build/` – Compiled output (generated)
- `pro__premium_only/` – Premium-only code (separate private repo; gitignored here)

## Repositories

- Free: https://github.com/gambitph/Ahentic
- Premium: https://github.com/bfintal/Ahentic-Premium (checked out into `pro__premium_only/`)

## License

This plugin is licensed under the GPL v2 or later.
