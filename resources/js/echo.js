import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const reverbUsesTls = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https';
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? (reverbUsesTls ? 443 : 80));

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbUsesTls,
    enabledTransports: reverbUsesTls ? ['wss'] : ['ws'],
});
