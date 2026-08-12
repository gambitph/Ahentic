# Ahentic help

Ahentic is an AI workspace that lives inside your WordPress site.
It opens as a sidebar so you can ask questions, explore your site, and get help making changes while you watch.

This page is for beginners.
If you just installed Ahentic and are not sure what to click next, start at the top and work down.

## What is Ahentic?

Think of Ahentic as a helper that can see the WordPress admin you are using and talk about your site in plain language.

With Ahentic you can:

- Ask what something on your site means
- Get help writing or editing pages and posts
- Ask it to make changes for you (with your approval when those changes are important)
- Keep several conversations open at once, like browser tabs

Ahentic is not a separate website you log into.
It runs in WordPress, next to the pages you already manage.

## Before you start

Ahentic needs two things before it can chat:

1. The free **WordPress AI** plugin (WordPress’s official AI building block)
2. At least one **AI connector** with an API key from a provider you choose

You only set this up once per site.

### 1. Install the WordPress AI plugin

When you open Ahentic for the first time, the empty chat may say that WordPress AI is missing.

- If you see **Install & Activate** or **Activate**, click it and wait for it to finish
- If you only see **Get WordPress AI**, open that link, install the plugin from WordPress.org, then activate it on your site

Plugin page: [WordPress AI on WordPress.org](https://wordpress.org/plugins/ai/)

Ahentic uses WordPress AI so your site can talk to AI models in a standard way.
You do not paste API keys into Ahentic itself.

### 2. Add an AI connector

After WordPress AI is ready, Ahentic may ask you to add a connector.

1. Click **Open Connectors**, or go to **Settings → Connectors** in WordPress admin
2. Choose a provider (for example OpenAI, Anthropic, or Google)
3. Paste the API key from that provider
4. Save, then return to Ahentic

A **connector** is WordPress’s saved link to an AI company.
Your API key stays in WordPress settings.
Ahentic then uses whatever connector your site has configured.

### 3. Get an API key (OpenAI, Anthropic, or Google)

An **API key** is a secret password that lets your site call an AI service.
You create it on the provider’s website.
You may need a paid account or billing set up with that provider.
Treat the key like a password: do not share it publicly or commit it to code.

Pick one provider to start:

| Provider | What people often call it | Where to create a key |
| --- | --- | --- |
| OpenAI | ChatGPT / GPT models | [platform.openai.com/api-keys](https://platform.openai.com/api-keys) |
| Anthropic | Claude | [console.anthropic.com](https://console.anthropic.com/settings/keys) |
| Google | Gemini | [Google AI Studio API keys](https://aistudio.google.com/apikey) |

Steps in general:

1. Create or sign in to an account with the provider
2. Open their API keys page (links above)
3. Create a new key and copy it
4. Paste it into **Settings → Connectors** in WordPress for that provider
5. Come back to Ahentic and send a message

Provider dashboards change often.
If a button label looks different, look for “API keys” or “Create key” on their site.
Official docs: [OpenAI](https://platform.openai.com/docs/overview), [Anthropic](https://docs.anthropic.com/), [Gemini API keys](https://ai.google.dev/gemini-api/docs/api-key).

## Open the sidebar

You can open Ahentic in any of these ways (when you are logged in as an admin):

- Press **Cmd+I** on Mac, or **Ctrl+I** on Windows/Linux
- Click **Ahentic** in the WordPress admin bar at the top of the screen
- On the front end (if the admin bar is hidden), use the floating Ahentic button

The sidebar can sit on the right, on the left, or float over the page.
Use the **Placement** control in the toolbar if you want to move it.

**Help** in the toolbar opens this guide.
**Collapse** hides the sidebar (the same keyboard shortcut toggles it again).

## Your first message

1. Open the sidebar
2. Type a question or task in the box at the bottom (for example: “What plugins are active on this site?”)
3. Press **Enter** or click the round **Send** button

While Ahentic is working, Send turns into **Stop**.
Click **Stop** if you want to end the current run early.

Tips:

- **Enter** sends; **Shift+Enter** adds a new line
- You can keep browsing your site while a run is in progress
- If Ahentic pauses and offers **Continue**, it is asking whether to keep going after a long or expensive run

## Agent tabs

Near the top of the sidebar you will see tabs, starting with **New Agent**.
Each tab is a separate conversation (a **session**).

Click **+ New agent** when you want a fresh thread that does not mix with an old one.

Good reasons to open a new tab:

- You finished one goal and are starting a different one
- You want to compare two approaches side by side
- The current chat is long and you want a clean start

Your messages are saved on the server for that session.
Closing a tab in the sidebar does not delete your whole WordPress site.
It only closes that conversation workspace in the UI.

## Ask vs Agent

At the bottom of the composer, a mode control switches between **Ask** and **Agent**.

| Mode | What it is for |
| --- | --- |
| **Ask** | Questions and exploration. Ahentic answers and looks things up. It should not change your site. |
| **Agent** | Getting work done. Ahentic may plan steps and make changes, with your approval when needed. |

If you only want advice, stay in **Ask**.
If you want Ahentic to edit content or change settings, use **Agent**.

## When Ahentic asks permission

In **Agent** mode, some actions pause for your OK.
You may see choices like:

- **Allow once** - run this action this time only
- **Allow for this chat** - allow similar actions for the rest of this conversation
- **Skip** - do not run this action

This is normal.
Ahentic is asking before it changes something important.
Read the request, then choose.

## What you can do / what not to expect yet

**Ahentic is good for:**

- Learning how your site is set up
- Drafting and editing content with you in the loop
- Multi-step help in **Agent** mode, with permission prompts when needed
- Keeping several goals in separate tabs

**Do not expect (for now):**

- Fully unattended robots that run while you are logged out
- Perfect results with no review from you
- Every toolbar control to matter on day one.
  You can ignore unfamiliar items until you need them.

When in doubt: ask a small question first in **Ask** mode, then switch to **Agent** for a concrete change.

## Quick troubleshooting

**I still see “install WordPress AI”.**
Install or activate [WordPress AI](https://wordpress.org/plugins/ai/), then reopen the sidebar.

**I still see “add an AI connector”.**
Open **Settings → Connectors**, add a provider key, save, then send a message again.

**Send does nothing / I get an AI unavailable error.**
Confirm WordPress AI is active and at least one connector works.
Try a short prompt in **Ask** mode.

**I do not see the sidebar or the shortcut.**
You need a user that can manage the site (`manage_options`).
Try the admin bar **Ahentic** link while logged in as an administrator.

**I clicked the wrong Allow.**
You can **Stop** a run.
For site changes you did not want, use WordPress’s normal undo paths (revisions, trash, restore) where they apply, or ask Ahentic in a new tab how to reverse a specific change.

**Still stuck?**
Open **Help** in the Ahentic toolbar to return here: [docs.wpahentic.com](https://docs.wpahentic.com).
