/**
 * Build getting-started.md into dist/ with Spruce CSS + docs chrome.
 * Cloudflare Pages: root docs-site, build `npm run build`, output `dist`.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as sass from 'sass'
import { marked } from 'marked'

const ROOT = path.dirname( fileURLToPath( import.meta.url ) )
const SOURCE = path.join( ROOT, 'getting-started.md' )
const SCSS = path.join( ROOT, 'scss', 'main.scss' )
const ICON = path.join( ROOT, 'ahentic-icon.svg' )
const OUT_DIR = path.join( ROOT, 'dist' )

function slugify( text ) {
	return String( text )
		.toLowerCase()
		.replace( /<[^>]+>/g, '' )
		.replace( /&[a-z]+;/gi, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' )
}

function extractTitle( markdown ) {
	const match = markdown.match( /^#\s+(.+)$/m )
	return match ? match[ 1 ].trim() : 'Ahentic help'
}

/**
 * Collect h2/h3 for TOC and inject matching ids into HTML headings.
 *
 * @param {string} html
 * @return {{ html: string, nav: Array<{ id: string, text: string, level: number }> }}
 */
function enrichHeadings( html ) {
	const nav = []
	const used = new Map()

	const nextId = ( text ) => {
		const base = slugify( text ) || 'section'
		const count = used.get( base ) || 0
		used.set( base, count + 1 )
		return count === 0 ? base : `${ base }-${ count + 1 }`
	}

	const withIds = html.replace(
		/<h([23])>([\s\S]*?)<\/h\1>/gi,
		( full, level, inner ) => {
			const text = inner.replace( /<[^>]+>/g, '' ).trim()
			const id = nextId( text )
			nav.push( { id, text, level: Number( level ) } )
			return `<h${ level } id="${ id }">${ inner }</h${ level }>`
		}
	)

	return { html: withIds, nav }
}

function renderNav( nav ) {
	const items = nav
		.map( ( item ) => {
			const cls = item.level === 3 ? ' class="toc__sub"' : ''
			return `<li${ cls }><a href="#${ item.id }">${ escapeHtml( item.text ) }</a></li>`
		} )
		.join( '\n' )

	return `<ul class="toc__list">\n${ items }\n</ul>`
}

function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
}

function pageTemplate( { title, navHtml, bodyHtml } ) {
	return `<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>${ escapeHtml( title ) } · Ahentic</title>
	<meta name="description" content="Getting started with Ahentic: install WordPress AI, add a connector, and use the sidebar." />
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
	<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet" />
	<link rel="stylesheet" href="./styles.css" />
	<link rel="icon" href="./ahentic-icon.svg" type="image/svg+xml" />
</head>
<body>
	<header class="site-header">
		<a class="site-header__brand" href="#">
			<span class="site-header__mark" aria-hidden="true">
				<img src="./ahentic-icon.svg" alt="" width="17" height="17" />
			</span>
			<span class="site-header__name">Ahentic</span>
		</a>
		<p class="site-header__meta">Help &amp; getting started</p>
	</header>
	<div class="site-shell">
		<nav class="toc" id="doc-nav" aria-label="On this page">
			<p class="toc__label">On this page</p>
			<button type="button" class="toc-toggle" id="nav-toggle" aria-expanded="false" aria-controls="doc-nav-list">
				Sections
			</button>
			<div class="toc__nav" id="doc-nav-list">
				${ navHtml }
			</div>
		</nav>
		<div class="site-main">
			<article class="article">
				${ bodyHtml }
			</article>
			<p class="site-footer">
				<a href="https://wpahentic.com">wpahentic.com</a>
				· Docs for the Ahentic WordPress plugin
			</p>
		</div>
	</div>
	<script>
		(function () {
			var nav = document.getElementById('doc-nav');
			var toggle = document.getElementById('nav-toggle');
			if (toggle && nav) {
				toggle.addEventListener('click', function () {
					var open = nav.classList.toggle('is-open');
					toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
			}
			var links = document.querySelectorAll('.toc__list a');
			var sections = [];
			links.forEach(function (link) {
				var id = link.getAttribute('href').slice(1);
				var el = document.getElementById(id);
				if (el) sections.push({ id: id, el: el, link: link });
			});
			function syncCurrent() {
				var fromTop = 100;
				var current = sections[0];
				for (var i = 0; i < sections.length; i++) {
					if (sections[i].el.getBoundingClientRect().top <= fromTop) {
						current = sections[i];
					}
				}
				links.forEach(function (l) { l.removeAttribute('aria-current'); });
				if (current) current.link.setAttribute('aria-current', 'true');
			}
			window.addEventListener('scroll', syncCurrent, { passive: true });
			syncCurrent();
		})();
	</script>
</body>
</html>
`
}

function buildStyles() {
	const result = sass.compile( SCSS, {
		loadPaths: [ path.join( ROOT, 'node_modules' ) ],
		style: 'compressed',
	} )
	return result.css
}

function build() {
	const markdown = fs.readFileSync( SOURCE, 'utf8' )
	const title = extractTitle( markdown )
	const rawHtml = marked.parse( markdown, { gfm: true, breaks: false } )
	const { html: withIds, nav } = enrichHeadings( rawHtml )
	const bodyHtml = withIds.replace(
		/<table>[\s\S]*?<\/table>/gi,
		( table ) => `<div class="table-wrap">${ table }</div>`
	)
	const navHtml = renderNav( nav )
	const css = buildStyles()

	fs.mkdirSync( OUT_DIR, { recursive: true } )
	fs.writeFileSync(
		path.join( OUT_DIR, 'index.html' ),
		pageTemplate( { title, navHtml, bodyHtml } )
	)
	fs.writeFileSync( path.join( OUT_DIR, 'styles.css' ), css )
	fs.copyFileSync( ICON, path.join( OUT_DIR, 'ahentic-icon.svg' ) )

	console.log( `Built ${ path.join( 'dist', 'index.html' ) } (${ nav.length } nav items, Spruce CSS)` )
}

build()
