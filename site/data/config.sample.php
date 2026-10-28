<?php
// Copy this file to config.php (same folder) on the host and fill in ONE of the two modes.
// config.php is not in git, so secrets never end up in the repository.
return [
    // Mode 1 — Cloudflare relay Worker (holds the bot token itself). Iranian hosts: use this.
    'relay_url' => '',   // e.g. 'https://tg.ecotez.ir'
    'relay_key' => '',   // the Worker's RELAY_KEY secret

    // Mode 2 — direct Bot API (only if the host can reach Telegram)
    'telegram_token'   => '',  // from @BotFather
    'telegram_chat_id' => '',  // from @userinfobot
    'api_base'         => 'https://api.telegram.org',
];
