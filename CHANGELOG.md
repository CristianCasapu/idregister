# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.14.0] – 2026-09-10

### Added
- **Sign in with Google.** An account that already exists here can be tied to a Google account in
  Personal settings › Personal info, and from then on "Continue with Google" under the sign-in form
  signs it in without a password. The link is keyed on Google's `sub`, so it survives a change of
  address at Google; it is only accepted when Google says it verified the address and that address
  is exactly the one already confirmed here, so a Google account can never be attached to somebody
  else's account. Registration is untouched: an unknown Google account is sent to the identity card,
  never turned into an account.
- Administration settings for it: the switch, the OAuth client ID and secret (written but never read
  back to a browser), and the redirect URI to paste into the Google console.

### Security
- The flow uses `state`, `nonce` and PKCE; the identity token is fetched by the server itself from
  Google's token endpoint over TLS and its issuer, audience, expiry and nonce are checked. An account
  with a second factor still has to pass it — the sign-in hands over to the challenge page.

## [1.13.0] – 2026-09-10

### Added
- The app installs its own reader and face models from the administration page: one button
  builds a Python environment in the data directory with RapidOCR, ONNX Runtime and OpenCV
  (pre-built wheels only, no compiler) and downloads InsightFace's `buffalo_l` model pack —
  about 450 MB, once. `occ idregister:install-ocr` does the same from a terminal.

### Changed
- Face descriptors and the head pose are computed straight on ONNX Runtime (`src/face_embed.py`
  runs the SCRFD, ArcFace and 3D-landmark models itself) instead of through the `insightface`
  package, which needs a C compiler to install. The numbers are identical, so the measured
  thresholds still hold.
- The hand-off from a computer to a phone (QR code) lives in a database table instead of the
  distributed cache: on a server without Redis or Memcached that cache is a per-request array
  and every step of the hand-off was lost at once.
- Setup checks and the administration page point at the "Install the reader" button rather
  than at terminal commands.

## [1.12.2] – 2026-09-09

### Fixed
- Unconfirmed registrations are cleared away every hour again. The cleanup job was marked time
  insensitive, and on an instance with a maintenance window such jobs only run inside it, so an
  abandoned registration — and the identity document it holds — stayed blocked until the next
  night instead of an hour past its expiry.

## [1.12.1] – 2026-09-09

### Changed
- The accepted documents are named the way people call them: identity card (CI) and electronic
  identity card (CEI).

## [1.12.0] – 2026-09-09

### Fixed
- A driving licence is read as a driving licence again: its personal number (field 4d) no longer
  makes it pass for an identity card.

## [1.11.0] – 2026-09-09

### Added
- Consent is asked before the camera starts, and the wizard continues by itself after a successful
  read — one screen and one tap fewer.
- A small head turn during the selfie, confirmed on the server; registrations without it go to
  administrator review (`requireSelfieLiveness`).

### Fixed
- The head turn is judged by the face model's yaw instead of the profile cascade, which fires on
  frontal faces too (1.11.1); the 3D landmark module is loaded for the head pose (1.11.2); the
  selfie guide falls back to the face model when the quick cascade finds no face in poor light
  (1.11.3); four selfies that do not pass end the scan and send back to the start, enforced on the
  server for both the web page and the app (1.11.4).

## [1.10.0] – 2026-09-08

### Added
- The selfie takes itself: a small frame every 600 ms, guidance (come closer, move back, centre,
  hold still), and the picture is taken when two frames in a row are good.
- The document has to be valid — the expiry date is read from the printed label, from the machine
  readable zone of the old card, or as the latest date on it. An expired card is refused; an
  unread expiry sends the registration to review.

### Fixed
- `/api/chip` answers only when switched on, the sign-in hand-off needs a secret that never enters
  the QR code, and a selfie that is the document's own photo is refused (1.10.1).

## [1.9.0] – 2026-09-08

### Added
- Endpoints for the Android client: the chip of the electronic identity card read over NFC gives
  the authoritative name and personal number and the photo used as the selfie reference; the
  six-digit access number is read from the camera frames; the browser signs in when the app
  finishes; the app is suggested when reading in the browser struggles.

### Fixed
- Messages and the spinner stay visible above the full-screen camera (1.9.1); a connection
  indicator, and an existing account or a closed registration goes to the sign-in page (1.9.2);
  the account limit and the minimum age accept 0 (1.9.3); every number setting keeps its own range
  (1.9.4).

## [1.8.0] – 2026-09-08

### Added
- The document has to be physical: black-and-white copies and pictures on a screen are refused from
  the colour and the spectrum of the frames, a small tilt is asked when nothing proves the card
  real (the glare moves on a laminate), and the registration goes to administrator review when it
  is still unsure.
- The camera fills the screen while it runs, for both the document and the selfie.
- After an express registration on the phone, the computer that showed the QR code signs in as the
  new account (1.8.1).

## [1.7.0] – 2026-09-08

### Added
- Express registration: the account is created right after the document and the selfie, with a
  random user name and a password shown once, followed by an automatic sign-in and a profile step
  for the e-mail address (verified by a six-digit code), the phone number and the nickname.

### Fixed
- The QR hand-off on a desktop no longer crashes when the camera is stopped before it starts
  (1.7.1).

## [1.6.0] – 2026-09-08

### Added
- The wizard survives a page reload, so switching to the mail app and back does not lose the
  registration.
- Selfie with the front camera and a face guide; a password generator.

## [1.5.0] – 2026-09-08

### Changed
- Documents are read with RapidOCR (neural text recognition); Tesseract stays as the fallback.
  Printed names on the new electronic card, which has no machine readable zone on the front, are
  confirmed by two consecutive frames.

### Added
- `occ idregister:install-ocr`, and a `?debug=1` readout for the live scan.

## [1.4.0] – 2026-09-08

### Added
- The document is read live from the camera: frames are sent one by one, fields are accumulated,
  guidance is shown, and the picture is taken by itself when the personal number is stable.
- The app is now called "Sign up with ID".

## [1.3.0] – 2026-09-08

### Changed
- No account exists before the e-mail address is confirmed and a password chosen.

### Added
- Step counter, password strength meter, a spinner tied to the pending requests, and signed-in
  visitors are sent away.

### Fixed
- Every wizard step was shown at once, because Nextcloud's CSS reset beats the `hidden` attribute
  on sections (1.3.1); a long mixed password containing a common word was rated weak (1.3.2).

## [1.2.0] – 2026-09-07

### Added
- Registration only from a phone or tablet: a computer shows a QR code and follows the handover.
- A selfie compared with the picture on the document, the driving licence as a second document
  type, a link on the sign-in page, and many more administrator conditions.

## [1.0.0] – 2026-09-07

First release: registration from a photograph of a Romanian identity card, with an e-mail address
verified by both a code and a link. The name comes from the card; name, e-mail address and phone
number cannot be changed afterwards. The picture of the card is never written to disk and the
personal number is stored only as a salted hash.
