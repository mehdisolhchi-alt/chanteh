<?php
// Copy this file to config.php (same folder) on the host and fill in the values.
// config.php is not in git, so the bot token never ends up in the repository.
return [
    'telegram_token'   => '',  // from @BotFather, e.g. '123456789:AA...'
    'telegram_chat_id' => '',  // your numeric chat id (send /start to the bot, then see README)
    // Iranian hosts often cannot reach Telegram. For Bale use: 'https://tapi.bale.ai'
    'api_base'         => 'https://api.telegram.org',
];
