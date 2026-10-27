# BD Courier Customer Lookup API

A single-file PHP endpoint that checks a Bangladeshi customer's phone number against six couriers at once — **Steadfast, RedX, Pathao, Carrybee, Paperfly and eCourier** — and returns their delivery history in one JSON response.

Use it before confirming a Cash-on-Delivery order to see whether a customer usually accepts or refuses parcels.

> ⚠️ **Unofficial.** Apart from eCourier's documented fraud check, these are the couriers' merchant-dashboard endpoints, not published public APIs. They can change or break without notice, and using them may be subject to each courier's terms of service. Use your own merchant accounts and check those terms before going to production.

---

## Features

- **One request, six couriers**, queried in parallel with `curl_multi`.
- **No secrets in code.** Credentials come from the request body, HTTP headers or environment variables.
- **Automatic logins** for Pathao, RedX, Carrybee and Paperfly, with tokens cached for about an hour.
- **Honest results.** A courier that wasn't queried or failed is reported as `Not Connected` or `Error`, never as fake zeros.
- **Optional access key** so only your app can call the endpoint.
- **Debug mode** that shows each courier's raw response, without ever including credentials.

## What each courier returns

| Courier | Data returned | Notes |
|---|---|---|
| **Steadfast** | Total, delivered, canceled, success ratio, fraud reports, volume band | Uses `/fraud_check/score/{phone}` (the old endpoint stopped returning counts on 2026-09-27). |
| **Paperfly** | Total, delivered, returned, partial, plus Paperfly's "Smart Check" label, colour, icon and note | |
| **Carrybee** | Total, delivered, canceled, success ratio, fraud reports | A number Carrybee has never seen returns zeros with "No Carrybee history". |
| **Pathao** | Customer rating (e.g. `Excellent Customer`) | Pathao only gives counts to entitled merchant accounts. Most accounts get `"Counts Available": false` and a rating only. |
| **RedX** | Total, delivered, canceled, success ratio, customer segment | Currently unreliable: RedX often rejects automated lookups (HTTP 400), possibly because of login OTP. Placeholder data is never shown. |
| **eCourier** | Customer status (`Verified by eCourier`, `New Customer for eCourier`, `Warning!`) and fraud reasons | Official API. Returns a status, never counts. May need enabling by eCourier support and may be charged from your COD balance. |

## Requirements

- PHP **8.1+** with the `curl` and `json` extensions
- Outbound HTTPS access to the courier domains
- A writable system temp directory (used for the token cache)

## Installation

1. Copy `check.php` to your server, e.g. `https://your-domain.com/v2/check.php`.
2. Set environment variables for the couriers you use (see [Configuration](#configuration)).
3. Set `COURIER_API_KEY` to a long random string.
4. Send a test request (see [Usage](#usage)).

Any PHP host works: Apache, Nginx + PHP-FPM, Docker, or a platform such as Coolify.

## Configuration

Every credential can be set as an environment variable. Values sent in a request override them. Leave a courier's variables empty to skip it.

### Access and general

| Variable | Description | Default |
|---|---|---|
| `COURIER_API_KEY` | If set, every request must send `X-Api-Key: <key>` or `Authorization: Bearer <key>` | *(empty: open endpoint)* |
| `COURIER_NATIVE_TIMEOUT` | Per-courier timeout in seconds | `20` |

### Courier credentials

| Courier | Variables | Where to find them |
|---|---|---|
| Steadfast | `COURIER_STEADFAST_KEY`, `COURIER_STEADFAST_SECRET` | Steadfast merchant panel → API |
| Pathao | `COURIER_PATHAO_USERNAME`, `COURIER_PATHAO_PASSWORD`, optional `COURIER_PATHAO_CLIENT_ID`, `COURIER_PATHAO_CLIENT_SECRET` | Pathao merchant login. Client ID/secret enable a fallback login through the Pathao courier API. |
| RedX | `COURIER_REDX_PHONE`, `COURIER_REDX_PASSWORD` | RedX merchant panel login |
| Carrybee | `COURIER_CARRYBEE_PHONE`, `COURIER_CARRYBEE_PASSWORD` | Carrybee merchant panel login |
| Paperfly | `COURIER_PAPERFLY_USER`, `COURIER_PAPERFLY_PASS` (or `COURIER_PAPERFLY_TOKEN`) | Paperfly merchant panel login |
| eCourier | `COURIER_ECOURIER_KEY`, `COURIER_ECOURIER_SECRET`, `COURIER_ECOURIER_USERID` | eCourier merchant panel → API (`USERID` is your login ID) |

### Optional overrides

Each courier can be switched off with `COURIER_<NAME>_ENABLED=false` (`STEADFAST`, `PATHAO`, `REDX`, `CARRYBEE`, `PAPERFLY`, `ECOURIER`). Endpoint URLs can be overridden if a courier moves them:

`COURIER_STEADFAST_ENDPOINT`, `COURIER_PATHAO_MERCHANT_ENDPOINT`, `COURIER_PATHAO_ENDPOINT`, `COURIER_REDX_LOGIN_ENDPOINT`, `COURIER_REDX_LOOKUP_ENDPOINT`, `COURIER_CARRYBEE_ENDPOINT`, `COURIER_CARRYBEE_LOGIN_ENDPOINT`, `COURIER_PAPERFLY_ENDPOINT`, `COURIER_PAPERFLY_LOGIN_ENDPOINT`, `COURIER_ECOURIER_ENDPOINT`.

## Usage

`POST` a JSON body to the endpoint. With credentials in environment variables, only the phone number is needed:

```bash
curl -X POST https://your-domain.com/v2/check.php \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_COURIER_API_KEY" \
  -d '{"phone": "01XXXXXXXXX"}'
```

The phone number may be written as `01XXXXXXXXX`, `8801XXXXXXXXX`, `+880 1XXX-XXXXXX`, and so on. It is normalised to `01XXXXXXXXX`.

### Full request body template

Send credentials per request instead of (or as well as) environment variables. Leave out any courier you don't use.

```json
{
    "phone": "01XXXXXXXXX",
    "debug": false,

    "steadfast": {
        "api_key": "YOUR_STEADFAST_API_KEY",
        "api_secret": "YOUR_STEADFAST_API_SECRET"
    },

    "pathao": {
        "username": "YOUR_PATHAO_EMAIL",
        "password": "YOUR_PATHAO_PASSWORD",
        "client_id": "YOUR_PATHAO_CLIENT_ID",
        "client_secret": "YOUR_PATHAO_CLIENT_SECRET"
    },

    "redx": {
        "phone": "YOUR_REDX_MERCHANT_PHONE",
        "password": "YOUR_REDX_PASSWORD"
    },

    "carrybee": {
        "phone": "YOUR_CARRYBEE_MERCHANT_PHONE",
        "password": "YOUR_CARRYBEE_PASSWORD"
    },

    "paperfly": {
        "username": "YOUR_PAPERFLY_USERNAME",
        "password": "YOUR_PAPERFLY_PASSWORD"
    },

    "ecourier": {
        "api_key": "YOUR_ECOURIER_API_KEY",
        "api_secret": "YOUR_ECOURIER_API_SECRET",
        "user_id": "YOUR_ECOURIER_LOGIN_ID"
    }
}
```

`client_id` and `client_secret` for Pathao are optional. Paperfly also accepts `"token"` instead of username and password.

### Credentials as headers

Some credentials can be sent as headers instead: `X-Phone`, `X-Steadfast-Key`, `X-Steadfast-Secret`, `X-Pathao-Token`, `X-Redx-Token`, `X-Carrybee-Token` with `X-Carrybee-Business`, `X-Paperfly-Token`, `X-Paperfly-User`, `X-Paperfly-Pass`, `X-Ecourier-Key`, `X-Ecourier-Secret`, `X-Ecourier-Userid`.

## Response

```json
{
    "Summaries": {
        "Steadfast": {
            "Total Parcels": 196,
            "Delivered Parcels": 186,
            "Canceled Parcels": 10,
            "Success Ratio": 94,
            "Fraud Reports": 0,
            "Volume": "100+"
        },
        "RedX": {
            "Total Parcels": 0,
            "Delivered Parcels": 0,
            "Canceled Parcels": 0,
            "Status": "Error",
            "Details": ["RedX rejected the lookup (HTTP 400) and sent placeholder data, which was ignored."]
        },
        "Pathao": {
            "Total Delivery": 0,
            "Successful Delivery": 0,
            "Canceled Delivery": 0,
            "Customer Rating": "Excellent Customer",
            "Counts Available": false,
            "Details": ["Pathao only returns delivery counts to entitled merchant accounts; this account gets a rating only."]
        },
        "Carrybee": {
            "Total Parcels": 5,
            "Delivered Parcels": 5,
            "Canceled Parcels": 0,
            "Success Ratio": 100,
            "Fraud Reports": 0
        },
        "Paperfly": {
            "Total Parcels": 6,
            "Delivered Parcels": 5,
            "Canceled Parcels": 1,
            "Partial Parcels": 0,
            "Smart Check": {
                "label": "Excellent",
                "color": "#4CAF50",
                "icon": "🟢",
                "delivery_rate": 83.33,
                "return_rate": 16.67,
                "note": "Outstanding delivery record with minimal returns."
            }
        },
        "eCourier": {
            "Total Parcels": 0,
            "Delivered Parcels": 0,
            "Canceled Parcels": 0,
            "Customer Status": "Verified by eCourier",
            "Counts Available": false,
            "Details": ["eCourier only returns a customer status, not parcel counts."]
        }
    }
}
```

Pathao uses `Total Delivery` / `Successful Delivery` / `Canceled Delivery`; every other courier uses `Total Parcels` / `Delivered Parcels` / `Canceled Parcels`.

### Reading a courier's result

| What you see | Meaning |
|---|---|
| Numbers, no `Status` | The courier answered and the numbers are real. |
| `"Counts Available": false` | The courier only gives a rating or status; the zeros are not real counts. |
| `Details: ["No Carrybee history …"]` | The courier has no record of this number. |
| `"Status": "Not Connected"` | Not queried: credentials missing, or the login failed (`Details` says which). |
| `"Status": "Error"` | Queried but failed: HTTP error, timeout or unexpected response. |

### HTTP status codes

| Code | Meaning |
|---|---|
| `200` | Lookup completed (individual couriers may still show `Error`). |
| `400` | Missing or invalid Bangladeshi phone number. |
| `401` | `COURIER_API_KEY` is set and the request didn't include it. |
| `405` | Method other than `POST`. |

## Debugging

Add `"debug": true` to the body. The response gains a `Debug` block with any login failures and, for each courier, whether it was queried, the HTTP status, any cURL error and the first 800 characters of its raw response. Credentials are never included, but courier responses may contain customer details, so turn debug off in production.

To force fresh logins, delete `courier_tokens.json` from the server's temp directory (`sys_get_temp_dir()`).

## Security

- **Set `COURIER_API_KEY`.** Without it, anyone who finds the URL can run lookups on your courier accounts.
- **Keep credentials in environment variables**, not in request bodies stored in Postman collections or client apps.
- **Never call this endpoint from a browser** with credentials in the request; call it from your backend.
- The CORS header is `Access-Control-Allow-Origin: *`. Restrict it to your own domain if you don't need cross-site calls.
- **Rotate any credential** that has been committed to git or shared.

## Limitations

- Pathao counts depend on your account's entitlement and can't be unlocked through the API.
- RedX lookups are currently rejected for most automated requests.
- Carrybee's lookup may only cover customers known to your business.
- Dashboard endpoints can change at any time. If a courier that used to work starts failing, run with `"debug": true` and compare its raw response.
- The token cache is a single file in the temp directory; on multi-server setups each server logs in separately.

## License

Add your chosen license here (for example MIT).
