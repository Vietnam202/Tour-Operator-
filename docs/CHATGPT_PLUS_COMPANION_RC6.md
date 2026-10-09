# VTA RC6 · ChatGPT Plus Companion (MVP)

**Scope:** A manual assistant bridge in Itinerary/Proposal, Quote Document and Sales Workspaces. No OpenAI API key, OAuth, backend API integration or ChatGPT subscription impersonation.

## Flow
1. In **Itinerary & Proposal Studio**, click **ChatGPT Plus**; copy the prompt and open ChatGPT in a separate tab. Edit the prompt and insert only sanitized tour information. Paste reviewed results into VTA editor fields.
2. In **Quote Document Editor**, click **ChatGPT Plus**, copy/open, then paste ChatGPT's response into the assistant dialog and click **Chèn vào vị trí con trỏ**. Only editable documents offer the insertion action. It inserts plain text content into the existing editor and uses its current save mechanism.
3. In **Sales Command Center**, click **ChatGPT Plus** from shortcuts. The generic prompt helps write B2B replies. No CRM/customer/supplier data is automatically attached.

## Safety and limitations
- The Plus subscription is **not** activated in RC6. The app never asks for ChatGPT credentials and never copies ChatGPT session tokens.
- There is no automatic completion, server-side inference, synchronization or background AI processing. The user operates their own ChatGPT account in another tab.
- Generic prompts contain **no stored guest data, supplier rates, personal contacts, or costing values**; the user decides what to paste into ChatGPT. Company policy must determine whether material is appropriate for external AI.
- AI output must be reviewed before applying. Pricing, supplier confirmation, FX and profit continue to be owned by VTA.
- This feature is separate from the pre-existing AI Chat and Marketing modules; they are unchanged.
- No new database schema, credentials, environmental variables or production migrations.

## QA checklist
```sh
node --check vta-plus-assist.js
node --check document-editor.js
node --check tour-proposal-studio.js
node --check workspace-centers.js
node tests/vta-plus-assist.cjs
node tests/vta-plus-assist-dom.cjs
```
Automated DOM interaction coverage checks clipboard, ChatGPT URL without data in query parameters, no automatic CRM changes, read-only apply restrictions, copy/paste approval, failure recovery and modal closing. GitHub Actions passed these checks on the companion branch.

Still do real-browser UAT on desktop and mobile: clipboard, popup blocking, modal close, preview read-only document, editor insertion, autosave and no unintentional CRM disclosure.

**Deployment:** review-only feature branch and draft PR. Do not deploy RC6 to CyberPanel or merge onto a production branch without acceptance.
