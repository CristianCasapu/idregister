# What this app does with personal data

| Data | Kept? | Why |
|---|---|---|
| Picture of the identity card | **No.** Read in memory, dropped after a few seconds. | Reading the name |
| Name and given names | Yes, as the account's display name | Identifying the account holder |
| Personal number (CNP) | **No.** Only `sha256(CNP + secret)`. | Making sure one card creates one account |
| E-mail address | Yes | Signing in, confirmation, notifications |
| Phone number | Yes, in the account properties | Contact |
| Password | Never in clear: Nextcloud stores its own hash | Signing in |

The salt for the personal number hash is generated once per instance and stored as a sensitive app
value. Without it the hashes cannot be linked back to a personal number.

Registrations whose e-mail address is never confirmed are deleted automatically, together with the
disabled account, after the number of hours set by the administrator (48 by default).
