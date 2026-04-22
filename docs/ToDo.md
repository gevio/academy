# ToDo

<!-- Neueste Eintraege immer oben einfuegen. -->

## 2026-04-21 (v1.6.0)
- [x] `REVIEW_AUTO_FREIGABE` Flag: Review-Submit setzt Status direkt auf "Freigegeben" + Team-Freigabe=true (triggert n8n WF C sofort).
- [x] `REVIEW_PUBLIC_URL` Konstante: Kunden-E-Mails enthalten immer `agenda.adventuresouthside.com`, niemals die Dev-URL.
- [x] `cli/send-reviews.php`: Massenversand Aussteller-Reviews mit `--dry-run`, `--deadline`, `--limit` Flags.
- [x] `NotionClient::setAusstellerStatus()`: Setzt Status-Select eines Aussteller-Eintrags.

## 2026-04-21 (v1.5.1)
- [x] Kontakt-Vorname wird bei Review-Erstellung in der Notion Review-DB gespeichert (`Kontakt-Vorname` Property).
- [x] `getAusstellerReview()` gibt `kontaktVorname` zurück.
- [x] `review.html`: Begrüßung "Hallo [Vorname]," + durchgängiges formelles Sie.

## 2026-04-21 (v1.5.0) – Aussteller-Review-System
- [x] `public/review.html` + `public/css/review.css`: Kunden-Frontend für Aussteller-Review (keine Bootstrap-Abhängigkeit, AS26 Design-System).
- [x] `public/api/get-aussteller-review.php`: Öffentlicher Read-Endpunkt (page_id als Token).
- [x] `public/api/update-aussteller-review.php`: Speichert Beschreibung, Messe-Special, Webseite, Webshop (Status muss "Entwurf" sein).
- [x] `public/api/submit-aussteller-review.php`: Einreichen → Status "Eingereicht" (oder "Freigegeben" bei Auto-Freigabe).
- [x] `public/api/send-aussteller-review.php`: Admin-Endpunkt – erstellt Review-Seite + E-Mail-Draft in Notion.
- [x] `public/api/check-aussteller-review.php`: Admin-Check ob aktive Review existiert.
- [x] `NotionClient`: 3 neue Methoden (`getAusstellerReview`, `updateAusstellerReview`, `submitAusstellerReview`), `createAusstellerReviewPage`, `createAusstellerEmailDraft`.
- [x] Firmenname in Review-Formular: Read-only (kein Update über API möglich).

## 2026-04-21 (offen)
- [ ] Massenversand ausführen: `php cli/send-reviews.php --dry-run` auf Prod prüfen → dann live
- [ ] n8n Workflow C auf Prod aktiv + URL `agenda.adventuresouthside.com` bestätigt?

