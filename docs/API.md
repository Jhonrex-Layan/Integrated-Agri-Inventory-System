# AgriSuppliers REST API v1

Base URL: `http://localhost/AgriSuppliers/api/v1`
Format: JSON. Success `{"data": ..., "meta": {...}}`. Error `{"error": {"code": "...", "message": "..."}}`.

## Authentication
Create a client under **AgriSuppliers > Administration > API Clients**. The key (`ags_` + 64 hex characters) is shown once;
only its SHA-256 hash is stored. Send it on every call except `/ping`:

    Authorization: Bearer ags_xxxxxxxx...

Scopes: `read` (all GET data), `receive` (acknowledge deliveries), `refund` (refund requests). A missing scope returns 403.
Rate limit: per client, per minute (default 120). Exceeding it returns 429 with `Retry-After`.

## Endpoints
| Method & path | Scope | Notes |
|---|---|---|
| `GET /ping` | none | `{"data":{"status":"ok",...}}` |
| `GET /suppliers?q=&status=active\|inactive\|archived\|all&page=&per_page=` | read | default status `active`; `meta`: page, per_page, total |
| `GET /suppliers/{id}` | read | 404 if unknown |
| `GET /suppliers/{id}/products` | read | only **approved, non-archived** catalog items |
| `GET /supplier-products?supplier_id=&page=` | read | same publishing rule |
| `GET /supplier-products/{id}` | read | 404 if unapproved/archived |
| `GET /purchase-orders/{id}` | read | approved or later orders only; includes lines with `qty_received`, `qty_outstanding` |
| `GET /deliveries?status=confirmed\|acknowledged\|cancelled` | read | default `confirmed`; only deliveries the supplier confirmed |
| `GET /deliveries/{id}` | read | lines with `delivery_item_id`, `qty_delivered`, `qty_rejected`, `unit_price` |
| `POST /deliveries/{id}/acknowledge` | receive | see below |
| `POST /purchase-orders/{id}/receive` | receive | same as acknowledge, body also carries `delivery_id`; verifies the delivery belongs to the PO |
| `POST /refund-requests` | refund | creates a refund claim (idempotent on `request_ref`) |
| `GET /refund-requests/{request_ref}` / `?refs=A,B` | refund | status and the supplier's note |

### Acknowledge a delivery
    POST /deliveries/12/acknowledge
    Authorization: Bearer ags_...
    Idempotency-Key: RCP-20261012093000-a1b2c3
    {"receipt_ref":"RCP-20261012093000-a1b2c3",
     "items":[{"delivery_item_id":31,"qty_accepted":20},{"delivery_item_id":32,"qty_accepted":0}]}

* Every delivery line must appear exactly once; `qty_accepted` is between 0 and delivered minus rejected.
* Only a delivery in status `confirmed` can be acknowledged (409 `not_confirmed` otherwise).
* The same `receipt_ref` again returns **200** with `"duplicate": true` and changes nothing. A different `receipt_ref`
  for an already acknowledged delivery returns **409 already_acknowledged**.
* On success the PO's `qty_received` rises by the accepted quantities and the PO becomes `partially_received` or `completed`.
* Everything happens in one database transaction.

Response: `{"data":{"delivery_id":12,"receipt_ref":"...","status":"acknowledged","po_status":"partially_received","accepted_total":20}}`

### Create a refund request
    POST /refund-requests   Idempotency-Key: RFD-261012093000-ab12cd
    {"request_ref":"RFD-261012093000-ab12cd","supplier_id":2,"supplier_product_id":5,
     "product_name":"Glyphosate Herbicide 1L","quantity":6,"unit_cost":420,"reason":"Leaking bottles on arrival"}

Returns 201 with `status: received`. A repeat of the same `request_ref` returns 200 (duplicate). Supplier staff decide in
**AgriSuppliers > Refund Requests**; statuses: received, under_review, approved, rejected, refunded.

## Error codes
401 `unauthorized` / `invalid_api_key`, 403 `insufficient_scope`, 404 `not_found` / `route_not_found`, 405 `method_not_allowed`,
409 `conflict` / `already_acknowledged` / `not_confirmed` / `request_ref_conflict`, 422 `invalid_*` / `quantity_exceeded` / `exceeds_order`,
429 `rate_limit_exceeded`, 503 `database_unavailable`.

## Try it (Windows, curl)
    curl http://localhost/AgriSuppliers/api/v1/ping
    curl -H "Authorization: Bearer YOUR_KEY" "http://localhost/AgriSuppliers/api/v1/suppliers"
