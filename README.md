# ID card registration (`idregister`)

A Nextcloud app that lets visitors create their own account from a phone: they photograph their
Romanian identity card, the server reads the name from it, and they add an e-mail address, a phone
number and a password. Only the e-mail address is verified, with a six digit code and a link.

- Registration happens **on a phone or a tablet**: a computer shows a QR code and follows along while
  the phone does the work.
- After the document, a **selfie** is compared with the photo printed on it (InsightFace); an uncertain
  match goes to an administrator instead of being refused.
- An **identity card or a driving licence** can be used.
- The **picture of the card is never stored** — it is read in memory and dropped.
- The **personal number (CNP) is not stored** either; only a salted hash, so the same card cannot be
  used twice.
- The **name, the e-mail address and the phone number cannot be changed** afterwards.
- Interface in **Romanian and English**.

The card reader is a PHP port of the parser in the [NecMat](https://github.com/CristianCasapu/necmat)
Android app: the machine readable zone, the personal number with its check digit and the printed
labels are combined, and the typical OCR confusions between letters and digits are corrected.

## Requirements

```bash
sudo apt install tesseract-ocr tesseract-ocr-ron
```

PHP needs the `imagick` extension (or GD as a fallback) to normalise the picture.

The selfie check needs InsightFace in a Python environment; the
[Recognize fork](https://github.com/CristianCasapu/recognize) sets one up and this app finds it by
itself. Without it, switch the selfie off in the settings.

## Install

```bash
cd /path/to/nextcloud/apps
tar -xzf idregister-1.0.0.tar.gz
sudo -u www-data php ../occ app:enable idregister
```

Then open Administration settings › ID card registration and switch it on. The registration page is
at `/index.php/apps/idregister/`.

## Commands

```bash
occ idregister:scan <picture>   # try the card reader, stores nothing
occ idregister:cleanup          # drop registrations that were never confirmed
```

## Licence

AGPL-3.0-or-later.
