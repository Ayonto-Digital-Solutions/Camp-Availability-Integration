# Camp Availability Integration

**Version:** 1.3.75  
**Author:** Marc Mirschel  
**Author URI:** https://marc.mirschel.biz  
**Plugin URI:** https://ayon.to

## Beschreibung

Dieses Plugin bietet ein **eigenes Availability-System** für **WooCommerce** mit nahtloser Integration des **Stachethemes Seat Planner**, um eine zeitgesteuerte Verfügbarkeitskontrolle mit professionellem Countdown-Timer und **Warenkorb-Reservierungssystem** zu ermöglichen.

### Hauptfunktionen

1. **Universal Counter Support**: Funktioniert mit ALLEN WooCommerce Produkttypen (simple, variable, grouped, auditorium, etc.)
2. **Warenkorb-Reservierungen**: Automatische Reservierung von Produkten im Warenkorb mit konfigurierbarer Zeitspanne
3. **Globaler Countdown-Timer**: Zeigt einen einheitlichen Timer für alle reservierten Produkte im Warenkorb
4. **Buchungs-Dashboard (NEU v1.3.42)**: Übersicht aller Buchungen sortiert nach Event-Kategorien mit Kunden- und Sitzplatz-Informationen
5. **Order Confirmation Shortcode (NEU v1.3.42)**: Zeigt Bestelldetails inkl. Seat Planner Daten auf der Bestätigungsseite
6. **Professionelle Admin-Oberfläche**: Modernes, einheitliches Card-Design über alle Admin-Seiten
7. **Countdown-Timer-Anzeige**: Zeigt den Availability Scheduler Timer automatisch auf Produktdetailseiten an
8. **Button-Steuerung**: Blendet den "Parzelle auswählen"-Button des Seat Planners aus, bis der Timer abgelaufen ist
9. **Dynamische Aktualisierung**: JavaScript überwacht den Countdown und blendet den Button automatisch ein
10. **Elementor-Kompatibilität**: Funktioniert perfekt mit Elementor Pro Theme Builder
11. **Debug Tools**: Umfassende Debug- und Test-Tools für Entwickler
12. **Dokumentation**: Automatische Anzeige der neuesten Version-Updates

## Installation

1. Laden Sie das Plugin-ZIP hoch oder extrahieren Sie es in `/wp-content/plugins/`
2. Aktivieren Sie das Plugin über das WordPress-Dashboard
3. Stellen Sie sicher, dass folgende Plugins aktiv sind:
   - WooCommerce
   - Stachethemes Seat Planner

## Anforderungen

- **WordPress:** 6.5 oder höher
- **PHP:** 8.0 oder höher
- **WooCommerce:** 9.5 oder höher
- **Required Plugins:**
  - WooCommerce
  - Stachethemes Seat Planner
- **Optional Plugins:**
  - Product Availability Scheduler (Koala Apps) - Kann als Fallback-System verwendet werden

## Admin-Oberfläche

Das Plugin bietet eine moderne Admin-Oberfläche mit folgenden Seiten:

### Dashboard
- Übersicht über aktive Reservierungen
- System-Status und Gesundheitscheck
- Schnellzugriff auf wichtige Funktionen

### Buchungs-Dashboard (NEU v1.3.42)
- **Übersicht aller Buchungen**: Anzeige von Kundenname, E-Mail, Telefon, Produkt, Variation
- **Kategorisiert**: Gruppierung nach Event-Kategorien für bessere Übersicht
- **Seat Planner Integration**: Zeigt gebuchte Sitzplätze/Parzellen aus dem Stachethemes Seat Planner
- **Filterbar**: Nach Kategorie, Bestellstatus und Datum filterbar
- **Statistiken**: Gesamtübersicht mit Buchungszahlen und Status
- **Export**: Druckfunktion für PDF-Export

### Cart Reservations
- Liste aller aktiven Warenkorb-Reservierungen
- Echtzeit-Aktualisierung
- Reservierungs-Details

### Settings
Vier Tab-Bereiche:
1. **General**: Countdown-Timer Einstellungen
2. **Cart Reservation**: Warenkorb-Reservierungen konfigurieren
3. **Debug Settings**: Debug-Modus und Logging aktivieren
4. **Debug Tools**: Erweiterte Debug-Funktionen und System-Status

### Test Suite
- Automatisierte Tests für alle kritischen Funktionen
- Farbcodierte Ergebnisse
- Detaillierte Testberichte

### Documentation
Vier Dokumentations-Bereiche:
1. **README**: Diese Dokumentation
2. **Latest Update (UPDATE.md)**: Automatische Anzeige der neuesten Version-Änderungen aus der UPDATE.md Datei
3. **Changelog**: Vollständige Versionshistorie
4. **Support**: Kontakt und System-Informationen

## Konfiguration

### Warenkorb-Reservierungen
1. Gehen Sie zu **Settings → Cart Reservation**
2. Aktivieren Sie **Enable Cart Reservations**
3. Legen Sie die **Reservation Time** fest (Standard: 5 Minuten)
4. Konfigurieren Sie den **Timer Style** für die Warenkorbseite
5. Speichern Sie die Einstellungen

### Countdown-Timer
1. Gehen Sie zu **Settings → General**
2. Aktivieren Sie **Enable Countdown Timer**
3. Wählen Sie die **Countdown Position**
4. Wählen Sie den **Countdown Style**
5. Speichern Sie die Einstellungen

### Debug-Modus
1. Gehen Sie zu **Settings → Debug Settings**
2. Aktivieren Sie **Enable Debug Mode** (nur für Entwicklung)
3. Optional: Aktivieren Sie **Enable Debug Logging**
4. Nutzen Sie **Settings → Debug Tools** für erweiterte Debug-Funktionen

## Verwendung

### Automatische Integration

Das Plugin arbeitet automatisch, sobald es aktiviert ist:

**Warenkorb-Reservierungen:**
1. Produkte werden beim Hinzufügen zum Warenkorb automatisch reserviert
2. Ein globaler Countdown-Timer zeigt die verbleibende Zeit an
3. Produkte werden nach Ablauf automatisch aus dem Warenkorb entfernt
4. Stock wird automatisch freigegeben

**Bei ALLEN Produkttypen:**
1. Der Countdown-Timer wird automatisch angezeigt, wenn die Produkt-Verfügbarkeit aktiviert ist
2. Timer zeigt Countdown bis zum Verfügbarkeitsbeginn
3. Einheitliches ayonto Custom Design

**Bei "Auditorium" Produkten:**
1. Der "Parzelle auswählen"-Button wird ausgeblendet, wenn das Produkt noch nicht verfügbar ist
2. Der Button wird automatisch eingeblendet, wenn der Timer abläuft
3. Volle Seat Planner Integration

### Order Confirmation Shortcode (NEU v1.3.42)

Zeigt Bestelldetails auf der Bestätigungsseite an, da WooCommerce die Seat Planner Daten nicht verarbeiten kann.

**Verwendung:**
```
[as_cai_order_confirmation]
```

**Parameter:**
- `order_id` (optional): Spezifische Bestell-ID. Wenn nicht angegeben, wird die ID aus der URL gelesen
- `title` (optional): Überschrift (Standard: "Ihre Bestellung")
- `show_customer_details` (optional): Kundendaten anzeigen (Standard: "yes")

**Beispiele:**
```
[as_cai_order_confirmation]
[as_cai_order_confirmation title="Bestellübersicht"]
[as_cai_order_confirmation show_customer_details="no"]
```

**Features:**
- Zeigt alle Bestelldetails inkl. Variationen
- Zeigt gebuchte Sitzplätze/Parzellen aus dem Seat Planner
- Gruppiert Artikel nach Produktkategorien
- Responsive Design mit modernem Styling
- Automatische Sicherheitsprüfung (Order Key)

### Shortcode

Für manuelle Platzierung des Timers:

```
[as_cai_availability_counter]
```

**Verwendung in Elementor:**
1. Fügen Sie ein Shortcode-Widget hinzu
2. Geben Sie `[as_cai_availability_counter]` ein
3. Der Timer wird an dieser Position angezeigt

## Technische Details

### Hooks und Filter

Das Plugin nutzt folgende WordPress/WooCommerce Hooks:

- `wp_enqueue_scripts` - Lädt CSS und JavaScript
- `woocommerce_single_product_summary` - Fügt Timer hinzu
- `woocommerce_before_add_to_cart_button` - Versteckt Button
- `woocommerce_add_to_cart` - Erstellt Reservierung
- `woocommerce_before_cart` - Zeigt globalen Timer
- Cron-Jobs für automatische Cleanup-Prozesse

### Datenbank

Das Plugin erstellt eine eigene Tabelle für Reservierungen:
- `wp_as_cai_reservations` - Speichert Warenkorb-Reservierungen

### Timezone-Sicherheit

Alle Zeitstempel werden timezone-sicher verwaltet:
- UTC in der Datenbank
- Lokale Zeitzone im Frontend
- WordPress-Timezone wird respektiert

## Design-System

Das Plugin verwendet ein einheitliches Design-System:

**Farben:**
- Primary: #667eea (Lila)
- Secondary: #764ba2 (Dunkellila)
- Success: #10b981 (Grün)
- Warning: #f59e0b (Orange)
- Danger: #ef4444 (Rot)

**Components:**
- Modern Card-Design
- Professionelle Icons
- Responsive Layout
- Smooth Animationen

## Support

Für Support und Fragen:
- **Email:** kundensupport@zoobro.de
- **Website:** https://ayon.to
- **Entwickler:** Marc Mirschel (https://marc.mirschel.biz)

## Kompatibilität

### Getestete Themes
- Hello Elementor
- Astra
- Divi
- Twenty Twenty-Five

### Getestete Plugins
- Elementor Pro 3.32.3
- WooCommerce 10.3.3
- Product Availability Scheduler 1.0.2
- Stachethemes Seat Planner 1.0.22

### WordPress/PHP
- WordPress 6.8.3+
- PHP 8.3.26+
- WooCommerce 10.3.3+

## Versionshistorie

Siehe **CHANGELOG.md** für die vollständige Versionshistorie oder besuchen Sie **Documentation → Latest Update (UPDATE.md)** im Admin-Bereich für die neuesten Änderungen.

### Aktuelle Version: 1.3.75
- Koalaapps "Product Availability Scheduler" ist nicht mehr erforderlich
- Eigenes Availability-System mit Hook-Priority 5
- Optimierte Abhängigkeiten
- Aktualisierte Dokumentation

## Lizenz

Dieses Plugin ist unter der GNU General Public License v2 oder höher lizenziert.
http://www.gnu.org/licenses/gpl-2.0.html

## Credits

Entwickelt für professionelle Camp-Buchungssysteme mit Integration von:
- Stachethemes Seat Planner by Stachethemes
- Optional: Product Availability Scheduler by Koala Apps (Fallback-System)

---

**Powered by ayon.to**
