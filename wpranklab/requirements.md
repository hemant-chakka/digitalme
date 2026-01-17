# WPRankLab — Requirements (AI Visibility Plugin)

## Overview
WPRankLab is a WordPress plugin (Yoast SEO–level UI/UX quality) focused on improving a website’s **visibility across AI search engines** (e.g., ChatGPT, Perplexity, Google Gemini, Claude, etc.).

It will ship in two editions:
- **Free** (WordPress.org)
- **Pro** (license-activated with strict kill-switch behavior)

It also includes a **weekly email report system** modeled after PromptWatch:
- Simple report for Free users
- Full insights for Pro users

## Goals
WPRankLab should:
1. Scan a website and compute an **AI Visibility Score**.
2. Provide suggestions to improve ranking in AI search results.
3. Send **weekly emails** summarizing the site’s performance.
4. Provide **real-time recommendations** inside WordPress (similar to Yoast’s colored indicators).
5. Allow users to **upgrade to Pro** directly within the plugin.
6. Integrate with an existing **license server + kill switch** (similar to ActiveMemb).

## Key Features

### 1) AI Visibility Engine
The plugin should analyze:
- Content structure
- Schema markup
- Page readability
- AI model comprehension signals
- Presence of Q&A-style content
- Entity clarity (using NLP)
- Clustering of topics
- Internal linking structure
- Missing topical coverage
- AI-friendly metadata & summaries

Outputs must be displayed using colored indicators (**green/orange/red**), similar to Yoast’s scoring system.

### 2) Free Version (WordPress.org)
#### Core features
- Basic AI visibility scan
- Single AI Visibility Score with an **up/down arrow** (weekly)
- Limited recommendations
- Limited Q&A enhancement suggestions
- Basic internal link suggestions
- Limited keyword/entity suggestions
- Weekly report email includes only:
  - AI Visibility Score
  - Whether visibility went up or down
  - No detailed breakdown
- In-dashboard prompts to upgrade to Pro
- Basic dashboard analytics (**past 4 weeks only**)

#### Locked/upgrade features
- Pro features are blurred/locked with messaging like **“Upgrade to Pro to unlock.”**

### 3) Pro Version
#### Full feature set
- Full AI visibility analysis
- Deep content scoring (per page & per post)
- AI-specific schema recommendations
- AI model comprehension enhancement engine
- Entity graph builder (shows missing structured data)
- Competitor AI-visibility comparison (via API)
- Unlimited historical data
- Automatic **AI Summary** generator for each post
- Automatic **AI Q&A block** generator (FAQ-style content)
- Advanced link analysis

#### Pro weekly email (full insights)
Modeled after the PromptWatch paid report screenshot, including:
- Visibility Score
- Citation Rank
- AI visits
- Crawler visits
- Summary
- Recommendations for next week
- Charts (optional but desirable)
- “View Full Report” button linking to the plugin report page

#### Automation features
- Auto-insert structured summaries
- Auto-generate missing Q&A sections
- Auto internal linking suggestions
- Page audits for all posts (batch mode)

## Licensing System (Critical Requirement)
The plugin must integrate with the existing license server, with behaviors identical to the **ActiveMemb** system.

### Requirements
- On activation, user enters license key
- Plugin validates with an API endpoint
- Licenses include:
  - Status (active, expired, invalid)
  - Domain binding
  - Expiration date
  - Version allowed
- Daily cron check to validate license

### Kill-switch behavior (must be enforced)
If the license is invalid, missing, or expired:
- **ALL Pro functionality stops immediately**
- Admin area shows a **large prominent notice**
- No Pro features, API calls, or UI elements should function
- Notice cannot be hidden via CSS (intentionally persistent)

## Weekly Email Engine
Runs via WP-Cron.

### Free email example
**Subject:** Your Weekly AI Visibility Update — WPRankLab

**Body includes:**
- Visibility Score
- Arrow up/down
- Suggestion to upgrade for full insights

### Pro email example
Includes the detailed report (PromptWatch / AI Ranking Lab style):
- Visibility Score
- Citation Rank
- AI visits
- Crawler visits
- Week summary and recommendations
- (Optional) charts
- “View Full Report” button linking to plugin report page

### Styling and webhook notes
- Emails should match the style of PromptWatch / AI Ranking Lab.
- Designs can be provided separately.
- Like other plugins, emails should send a **make.com webhook request** “back home” (simple webhook).

## AI SEO Checklist requirement (additional)
- Provide an **AI SEO checklist** in a crawlable form (e.g., `.txt` files).
- The plugin should be able to generate one.

## Reference
- Shared conversation: https://chatgpt.com/share/692ac912-2e4c-8003-84e7-65aceff9bed1