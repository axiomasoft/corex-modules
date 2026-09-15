# corex/modules compatibility passport

This is factual evidence, not a Composer constraint, runtime registry, or release promise.

| Field | Recorded fact |
| --- | --- |
| Package / license / profile | `corex/modules` / MIT / public L0, requires `corex/core` |
| Policy / declared constraint | PHP 8.3–8.5 and Laravel 12–13 / `php:^8.3`, Illuminate `^12.0|^13.0` |
| Public local proof | P4.4: PHP 8.3.33, 8.4.1 and 8.5.10 against Laravel 12 and 13; cold update, platform check and fresh lock install |
| Release-ready status | Not claimed. The P4.4 receipt is local evidence; it is not a current hosted-runner or publication receipt. |
| P4 source and prior candidate | `ea7d0dbdf36b122904abba91e124018c11b939ed`; tree `10f03b48de20ae026c7d52b62c41913dfb18563f`; prior ZIP `3ecbae7f044f2330bd214b13a25f2dc83bab032a35d8b282b0492d802d0845ce` |
| P5 docs candidate | Local-only committed candidate and exact ZIP/metadata digests are recorded in `plans/2026.09.12-№2-COREX-APPROVED-SPECS/findings/P5.1-passport-conformance.md`; the self-altering ZIP digest is deliberately not embedded here. |
| Coverage policy | Root core/modules lane: enforced 80%, target 85%. A package-specific current measurement is not claimed here. |
| Limitations | No publication, current hosted-runner claim, or support beyond the stated policy is implied. PostgreSQL requirements remain as documented in the README. |

The package README documents installation and database constraints.
