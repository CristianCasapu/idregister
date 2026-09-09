# Sign up with ID — `idregister`

A **Nextcloud app** (server side, PHP) that lets a visitor create their own account from a phone by
showing their Romanian identity card to the camera. The server reads the document, checks that it is
a physical card and not a copy on a screen, compares a selfie with the picture on the document and
creates the account — all without ever storing the picture of the card.

It comes with a companion **Android client**:
👉 [CristianCasapu/idregister-android](https://github.com/CristianCasapu/idregister-android) —
same flow with the phone camera, plus NFC reading of the chip in the electronic identity card.
The web page and the app talk to the same endpoints of this app; the app is optional.

- Server app (this repository): <https://github.com/CristianCasapu/idregister>
- Android app: <https://github.com/CristianCasapu/idregister-android>

Interface in **Romanian and English** only. Licence AGPL-3.0-or-later.

---

## How it works

```
  phone (browser or Android app)                 Nextcloud + idregister
  ──────────────────────────────                 ──────────────────────
  1. camera streams guide-cropped frames  ──►  POST /api/scan/frame
                                               RapidOCR reads each frame, fields are
     ◄── "move closer", "hold still",           accumulated in the session, never the frames
         "tilt the document"                    physical-document check (colour, moiré, glare)

  2. auto-capture when the CNP is stable  ──►  name, personal number and expiry date are locked
                                               expired document → refused
  3. selfie (front camera, face oval,     ──►  POST /api/selfie
     small head turn)                          InsightFace compares it with the photo on the
                                               document; the head turn proves a live person
  4. account created on the spot          ──►  POST /api/express
     (express mode, the default)               random user name + password shown once,
                                               the browser is signed in automatically
  5. profile: e-mail (six digit code and  ──►  /profile/…
     a link), phone, nickname                  name stays locked from the document
```

A **computer** cannot register: it shows a QR code and follows the phone through the hand-off
(`/api/handoff/*`); when the phone is done, the computer signs in too.

### What is stored and what is not

| Data | Stored? |
|---|---|
| Picture of the identity card / driving licence | **never** — read in memory and dropped |
| Selfie | **never** — compared and dropped |
| Personal number (CNP) | only as `sha256(cnp + instance secret)`, so one card = one account |
| Name and given names | yes, as the account display name, **locked afterwards** |
| E-mail address, phone number | yes, verified by code, **fixed once confirmed** |

### Reading the document

- **RapidOCR** (ONNX Runtime, in a Python virtual environment) is the reading engine;
  **Tesseract** (`ron+eng`) is only a fallback — on real phone frames it returns noise.
- The parser is a PHP port of `IdCardParser.kt` from the
  [NecMat](https://github.com/CristianCasapu/necmat) Android app: machine readable zone, personal
  number with its check digit, and the printed labels are combined, and the usual OCR confusions
  between letters and digits are corrected.
- Both the **old identity card** (with an MRZ) and the **new electronic card (CEI)** — which has no
  MRZ on the front and uses the labels `Nume/Surname`, `CNP/PIN` — are read, as well as the
  **driving licence**.
- A printed name is only accepted when two frames agree, or when the OCR confidence is ≥ 0.9 and the
  personal number is valid, so a misread name is never locked onto an account.

### Physical-document check (`Service\Liveness`)

Per-frame numbers from `src/ocr_frame.py` decide whether the camera sees a real card:
colour against a black-and-white photocopy, spectral peaks against the moiré of a screen, and glare
that moves when the document is tilted. When it is not sure, the page asks for a tilt for up to eight
frames, then sends the registration to an administrator.

### Selfie

InsightFace embeddings, with thresholds measured on a real 24,747-face library
(same person 0.43–1.13, different people 1.27–1.49): match ≤ 1.15, administrator review ≤ 1.30.
A selfie that is *identical* to the picture on the card is refused (someone photographing the card
again). A small head turn, confirmed by the yaw angle of the 3D landmarks (≥ 18°), proves a live
person. Four failed selfies send the visitor back to the start.

---

## Requirements

- Nextcloud 30 – 34, PHP ≥ 8.1 with `imagick` (or GD as a fallback).
- **RapidOCR**: `occ idregister:install-ocr` sets it up in the Python environment.
- Tesseract fallback: `sudo apt install tesseract-ocr tesseract-ocr-ron`.
- **InsightFace** for the selfie — the
  [Recognize fork](https://github.com/CristianCasapu/recognize) creates the virtual environment and
  this app finds it by itself. Without it, switch the selfie off in the settings.

## Install

```bash
cd /path/to/nextcloud/apps
tar -xzf idregister-<version>.tar.gz
sudo -u www-data php ../occ app:enable idregister
sudo -u www-data php ../occ idregister:install-ocr
```

Then open **Administration settings › Sign up with ID** and switch registration on. The page is at
`/index.php/apps/idregister/`, and a link is added to the login page.

## Settings

Everything is in the admin section; the ones that matter most:

| Setting | Default | What it does |
|---|---|---|
| `registrationOpen` | off | the master switch |
| `expressMode` | on | account created right after document + selfie; e-mail and phone in the profile |
| `mobileOnly` | on | a desktop only gets the QR code |
| `requireSelfie` | on | selfie compared with the document |
| `requireSelfieLiveness` | on | a head turn during the selfie |
| `requirePhysical` | on | refuse photocopies and screens |
| `requireValidDocument` | on | an expired document is refused |
| `requireApproval` | off | every account waits for an administrator |
| `maxAccounts` | 0 | stop after this many accounts (0 = no limit) |
| `oneAccountPerCard` | on | one card, one account (hashed CNP) |
| `chipEnabled` | off | `/api/chip`, the NFC step of the Android app |
| `androidAppUrl` | releases page | suggested when the web scan struggles |

## Commands

```bash
occ idregister:scan <picture>   # try the document reader, stores nothing
occ idregister:install-ocr      # install RapidOCR in the Python environment
occ idregister:cleanup          # drop registrations that were never confirmed
```

## API used by the Android app

| Endpoint | Purpose |
|---|---|
| `POST /api/scan/frame` | one guide-cropped frame; returns guidance, accumulated fields, `can` |
| `POST /api/scan` | a single picture of the document |
| `POST /api/selfie`, `/api/selfie/guide` | selfie comparison, and where the face is in the frame |
| `POST /api/chip` | DG1 fields + DG2 photo read from the chip over NFC (`chipEnabled`) |
| `POST /api/express` | create the account and sign in |
| `/api/handoff/*` | the QR hand-off between a computer and the phone |

See the Android repository for the client side of these calls.

---

## Pe scurt (română)

Aplicație pentru **Nextcloud** prin care un vizitator își face singur cont de pe telefon, arătând
cartea de identitate la cameră: serverul citește actul (RapidOCR), verifică dacă actul e fizic (nu o
copie sau un ecran), compară un selfie cu poza de pe act (InsightFace) și creează contul pe loc.
Poza actului nu se salvează niciodată, CNP-ul se păstrează doar ca hash, iar numele, e-mailul și
telefonul nu mai pot fi schimbate după confirmare. Interfața e doar în română și engleză.

Aplicația Android care face același lucru cu camera telefonului (și citește cipul cărții electronice
prin NFC) este la [CristianCasapu/idregister-android](https://github.com/CristianCasapu/idregister-android).
