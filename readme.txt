=== Ahentic - AI Workspace ===
Contributors: bfintal, gambitph
Tags: ai, agent, chatgpt, claude, mcp
Requires at least: 7.0.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI workspace in WordPress. Ahentic is your very own AI agent inside your site and uses your own API key.

== Description ==

**Ahentic** is an AI workspace in WordPress.
It is your own AI agent inside the site you already manage, not an AI chatbot on a separate service, nor is it a chatbot creator.

Open it whenever you need it: press **Cmd+I** on Mac or **Ctrl+I** on Windows and Linux, use **Ahentic** in the admin bar, or open it on the front end while you are logged in as an admin.
Ask about this site, get help with site-wide adjustments, get help writing and editing, and let it make changes while you watch.

Ahentic is free and uses your own AI key in WordPress.
It is not an AI subscription.

= Who it is for =

Ahentic is for people who manage a WordPress site and want help doing the work.

That includes site owners, admins, and anyone who writes content, installs plugins, or keeps a site in shape.
If you care that AI in WordPress should use WordPress's own APIs, this is built that way.

= What you can do =

Open the page or screen you are already on, then ask in plain language.
Ahentic can see that screen, including the block editor and what you have selected.

* **"What is this site, and what should I fix first?"** It reads *this* install: Site Health, plugins, settings, and the page in front of you.
* **"Rewrite our feature showcase"** It edits the block editor while you watch, not a blob of HTML pasted into chat.
* **"Add internal links to this article."** Finds relevant posts and adds links within the content to help improve site navigation and SEO.
* **"Make a hero image that fits this layout"** and place it where it belongs.
* **"What can I remove?"** Unused plugins, leftover media, pre-launch gaps. Operator work on a real site, with your approval before anything destructive.
* Open it on the **front end** of a published page when you want to work from the live site, not only from wp-admin.

You stay in WordPress while it works.
You can keep using the admin during a run.

= You stay in control =

**Ask** is for questions and exploration.
Ahentic looks things up and answers.
It should not change the site.

**Agent** is for getting work done.
Ahentic may plan steps and make changes.
Important changes pause for your OK: **Allow once**, **Allow for this chat**, or **Skip**.

Review what it did before you publish.
You remain the person in charge of the site.

= Your API key, not a subscription =

Ahentic does not sell you model access, use OpenAI, Claude or Gemini, whatever AI connector is available in WordPress.
BYOK = You bring your own API key.

1. Install the free [WordPress AI](https://wordpress.org/plugins/ai/) plugin if it is not already active.
2. Go to **Settings → Connectors** in WordPress.
3. Add a key from the provider you choose, such as OpenAI, Anthropic, or Google.
4. Come back to Ahentic and send a message.

Your key stays in WordPress Connectors.
You do not paste it into Ahentic.
You pay your provider for usage.
Ahentic is the workspace that uses that connection to work on your site.

= Built on WordPress =

Ahentic is built on the WordPress APIs meant for this, not a private stack bolted on the side.

* **WordPress AI Client** so the site talks to models the WordPress way, with the provider you configured
* **Connectors** so the API key lives in WordPress settings
* **Abilities API** so the agent runs named WordPress actions (with permission checks), not a hidden tool list inside Ahentic
* It can work **inside the block editor**, on the page you have open, not only through the server

No vendor SDK is required inside Ahentic.
Switch providers in Connectors without rewriting the workspace.

= Features =

* Sidebar workspace on wp-admin and the front end
* Open anytime: **Cmd+I** / **Ctrl+I**, admin bar, or the front-end control
* **Ask** and **Agent** modes
* Approval before important changes
* Several conversations at once, as tabs
* Conversations stored on your WordPress site, not only in the browser
* Optional **Did this run go well?** after a run, so you can tell us when the agent helped or missed

= After a run =

When a run finishes, Ahentic can ask **Did this run go well?**

Yes or No is optional.
If you answer, an anonymized report is sent to Ahentic (via `feedback.wpahentic.com`) so we can improve the agent.
It does not include your site URL or admin identity.

Help: [docs.wpahentic.com](https://docs.wpahentic.com)
Source: [github.com/gambitph/Ahentic](https://github.com/gambitph/Ahentic)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ahentic` directory, or install the plugin through the **Plugins** screen in WordPress.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Install and activate [WordPress AI](https://wordpress.org/plugins/ai/) if Ahentic asks for it.
4. Open **Settings → Connectors**, add an API key for a provider you use, and save.
5. Open Ahentic with **Cmd+I** / **Ctrl+I**, or **Ahentic** in the admin bar.

== Frequently Asked Questions ==

= Does Ahentic include an AI subscription? =

No.
You bring your own API key in **Settings → Connectors**.
Ahentic is not selling model access.

= What do I need before I can chat? =

WordPress 7.0.3 or later, the [WordPress AI](https://wordpress.org/plugins/ai/) plugin, and at least one connector with an API key.
You only set this up once per site.

= Will it change my site without asking? =

**Ask** mode is for answers and exploration, and should not change the site.
**Agent** mode can make changes, and it asks for approval before important ones.
You can **Skip** a change, or **Stop** a run.

= Where do I open it? =

Anytime you need it, while logged in as an administrator: **Cmd+I** (Mac) or **Ctrl+I** (Windows/Linux), **Ahentic** in the admin bar, or on the front end of the site.

= Where do my conversations live? =

On your WordPress site, as Ahentic sessions.
They are not only stored in the browser.

= What happens after a run? =

Ahentic may ask **Did this run go well?**
Answering is optional.
If you send feedback, the report is anonymized (no site URL) and goes to Ahentic so we can improve the agent.

= Who is it for? =

People who manage a WordPress site and want an AI agent **inside** that site, with their own API key, not another chatbot in a settings screen.

== Screenshots ==

1. Agent editing a page in the block editor (animated)
2. Ask answering from this WordPress site (animated)
3. Approval before an important change, then the change lands (animated)
4. The Ahentic sidebar in wp-admin, with Ask and Agent
5. Settings → Connectors: your API key, not an Ahentic subscription
6. Ahentic on the front end, so you can open it whenever you need it (animated)

== Changelog ==

= 0.1.0 =
* First Directory release: sidebar AI workspace with Ask and Agent, WordPress AI Client and Connectors (bring your own key), and Abilities for site work.
