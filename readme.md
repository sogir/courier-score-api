# BD Courier Customer Lookup API

A single PHP file that checks a Bangladeshi phone number against Steadfast, RedX, Pathao, Carrybee, Paperfly and eCourier in parallel and returns the customer's delivery history as JSON.

## Requirements

PHP 8.1+ with `curl` and `json`.

## Setup

1. Upload `check.php` to your server.
2. Set credentials as environment variables (below).
3. Set `COURIER_API_KEY` to a long random string.

| Courier | Environment variables |
|---|---|
| Access key | `COURIER_API_KEY` |
| Steadfast | `COURIER_STEADFAST_KEY`, `COURIER_STEADFAST_SECRET` |
| Pathao | `COURIER_PATHAO_USERNAME`, `COURIER_PATHAO_PASSWORD` (optional: `COURIER_PATHAO_CLIENT_ID`, `COURIER_PATHAO_CLIENT_SECRET`) |
| RedX | `COURIER_REDX_PHONE`, `COURIER_REDX_PASSWORD` |
| Carrybee | `COURIER_CARRYBEE_PHONE`, `COURIER_CARRYBEE_PASSWORD` |
| Paperfly | `COURIER_PAPERFLY_USER`, `COURIER_PAPERFLY_PASS` (or `COURIER_PAPERFLY_TOKEN`) |
| eCourier | `COURIER_ECOURIER_KEY`, `COURIER_ECOURIER_SECRET`, `COURIER_ECOURIER_USERID` |

Disable a courier with `COURIER_<NAME>_ENABLED=false`.

## Usage

```bash
curl -X POST https://your-domain.com/check.php \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_COURIER_API_KEY" \
  -d '{"phone": "01XXXXXXXXX"}'
```

Credentials can also be sent in the body; they override environment variables:

```json
{
    "phone": "01XXXXXXXXX",
    "steadfast": { "api_key": "...", "api_secret": "..." },
    "pathao":    { "username": "...", "password": "..." },
    "redx":      { "phone": "...", "password": "..." },
    "carrybee":  { "phone": "...", "password": "..." },
    "paperfly":  { "username": "...", "password": "..." },
    "ecourier":  { "api_key": "...", "api_secret": "...", "user_id": "..." }
}
```

## Response

```json
{
    "Summaries": {
        "Steadfast": { "Total Parcels": 196, "Delivered Parcels": 186, "Canceled Parcels": 10, "Success Ratio": 94 },
        "Pathao":    { "Total Delivery": 0, "Successful Delivery": 0, "Canceled Delivery": 0,
                       "Customer Rating": "Excellent Customer", "Counts Available": false },
        "Carrybee":  { "Total Parcels": 5, "Delivered Parcels": 5, "Canceled Parcels": 0, "Success Ratio": 100 },
        "Paperfly":  { "Total Parcels": 6, "Delivered Parcels": 5, "Canceled Parcels": 1, "Smart Check": { "label": "Excellent" } },
        "eCourier":  { "Customer Status": "Verified by eCourier", "Counts Available": false },
        "RedX":      { "Status": "Error", "Details": ["RedX rejected the lookup (HTTP 400) ..."] }
    }
}
```

- **Numbers without `Status`:** real data.
- **`"Counts Available": false`:** the courier only gives a rating or status (Pathao, eCourier).
- **`"Status": "Not Connected"`:** missing credentials or failed login.
- **`"Status": "Error"`:** the courier was queried but failed; see `Details`.

Add `"debug": true` to the body to see each courier's raw response (credentials are never shown).

## Known limitations

- **Pathao** returns counts only for entitled merchant accounts; most get a rating only.
- **RedX** currently rejects most automated lookups.
- **eCourier** returns a status, not counts, and may need enabling by eCourier support.

## Security

Always set `COURIER_API_KEY`, keep credentials in environment variables, and call this endpoint only from your backend.

## License

GPL
