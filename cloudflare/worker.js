// Chanteh lead relay: an Iranian host cannot reach api.telegram.org, so send.php
// posts {text} here and this Worker forwards it to Telegram.
// Secrets (Settings → Variables and Secrets): BOT_TOKEN, CHAT_ID, RELAY_KEY
export default {
  async fetch(req, env) {
    if (req.method !== 'POST') return new Response('ok');
    if (!env.RELAY_KEY || req.headers.get('x-relay-key') !== env.RELAY_KEY)
      return new Response('forbidden', { status: 403 });
    if (!env.BOT_TOKEN || !env.CHAT_ID)
      return new Response('not configured', { status: 500 });

    let body;
    try { body = await req.json(); }
    catch (e) { return new Response('bad json', { status: 400 }); }

    const text = String(body.text || '').slice(0, 4000);
    if (!text) return new Response('empty', { status: 400 });

    const r = await fetch('https://api.telegram.org/bot' + env.BOT_TOKEN + '/sendMessage', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ chat_id: env.CHAT_ID, text, parse_mode: 'HTML', disable_web_page_preview: true }),
    });
    return new Response(await r.text(), { status: r.status, headers: { 'content-type': 'application/json' } });
  },
};
