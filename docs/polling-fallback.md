# Polling Fallback Guide

For shared hosting that cannot run Reverb, set:

```dotenv
REALTIME_DRIVER=polling
REALTIME_POLLING_INTERVAL_MS=5000
```

Echo is not initialized in this mode. Alpine components request their existing JSON data endpoints every five seconds and update only their local state. The Cek Kesehatan and Sedang Donor endpoints return `meta.donation_capacity.male`, `.female`, and `.total`, so each occupied and available donor-bed lane updates with the same request. They do not call `location.reload()` and business transitions remain server-side transactions. Keep the interval at or above 3000 ms to avoid unnecessary load.
