// Relay for Telegram Bot API: lets an Iranian host (which cannot reach
// api.telegram.org) send messages. Only sendMessage is forwarded; the bot
// token stays in the site's data/config.php, never in this Worker.
export default {
  async fetch(req) {
    const url = new URL(req.url);
    if (req.method !== 'POST' || !/^\/bot[^/]+\/sendMessage$/.test(url.pathname)) {
      return new Response('Not found', { status: 404 });
    }
    return fetch('https://api.telegram.org' + url.pathname, {
      method: 'POST',
      headers: { 'Content-Type': req.headers.get('Content-Type') || 'application/x-www-form-urlencoded' },
      body: req.body,
    });
  },
};
