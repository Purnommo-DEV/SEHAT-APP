# Realtime Guide

Use `REALTIME_DRIVER=reverb` with `BROADCAST_CONNECTION=reverb`. Start the broadcast worker and Reverb server, then build or run Vite. Echo creates a Reverb connection only in this mode.

```powershell
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan queue:work --queue=broadcasts,default --tries=3 --backoff=2 --timeout=120
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan reverb:start
npm run dev
```

Set `APP_URL`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, and corresponding `VITE_REVERB_*` values to the same public origin/protocol. In HTTPS deployments use `https` and WSS through the reverse proxy. Browser clients on public Menunggu, Screen Petugas, Cek Kesehatan, Sedang Donor, and Selesai screens subscribe to the public `events.{eventId}` channel (and Dashboard/TV Monitor also observe `operational` for an active-event change), then refresh only their own JSON snapshot after broadcasts; they never reload the page. Cek Kesehatan, Sedang Donor, and Dashboard refresh their donor-capacity snapshot after each operational broadcast. Private channels remain for management-only views.
