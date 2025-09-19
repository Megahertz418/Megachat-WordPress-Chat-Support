=== Megachat Support ===
Contributors: megahertz418
Tags: chat, support, ai, wordpress, openai, gemini, telegram
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Megachat Support is a WordPress plugin that adds **AI-powered website chat support** without altering your existing site design.  
It integrates with **OpenAI** or **Gemini**, supports a **Telegram bot webhook**, and includes an **admin logs UI** with a wide layout.  
You can also connect a CSV-based Knowledge Base for custom responses.

== Features ==
* Live support chat that matches your existing site design.
* AI provider integration: OpenAI or Gemini.
* Telegram bot integration with secret-protected webhook.
* Admin log panel with wide, clean UI.
* Customizable branding (light/dark logos).
* Knowledge Base support via CSV (Google Sheets).

== Installation ==
1. Download the plugin ZIP from GitHub Releases.  
2. Upload it to your WordPress site: **Plugins → Add New → Upload Plugin**.  
3. Activate the plugin in the WordPress dashboard.  
4. Go to **Settings → Megachat Support** and configure:
   * Select AI provider (OpenAI or Gemini) and enter API key.
   * Add Knowledge Base CSV URL.
   * Enter Telegram Bot Token and set webhook.

== Frequently Asked Questions ==

= Can I connect multiple Telegram bots? =  
No. Each Telegram bot can only have one active webhook, so each bot can be connected to only one site.

= How is the Secret generated? =  
A secure secret is generated and stored automatically when you save plugin settings.

= Does the plugin store sensitive data? =  
No. Only technical logs are stored (status code, message, timestamp, debug info). No sensitive user data is stored.

== Screenshots ==
1. Chat Widget (frontend).  
2. Agent Chat (user request to admin).  
3. Telegram Bot integration.  
4. Admin Settings panel.  

== Changelog ==

= 0.1.0 =
* Initial public release.
* Added AI provider integration (OpenAI/Gemini).
* Telegram bot webhook with secret authentication.
* Admin logs UI with wide layout.
* Knowledge Base support via CSV.
* Branding options (light/dark logos).
* Licensed under GPL-2.0-or-later.

== Upgrade Notice ==
= 0.1.0 =
First stable release of Megachat Support plugin.