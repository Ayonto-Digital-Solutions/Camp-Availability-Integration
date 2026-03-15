# Camp Availability Integration - Installationsanleitung

## Schnellstart

### Schritt 1: Plugin hochladen und aktivieren

1. Loggen Sie sich in Ihr WordPress-Dashboard ein
2. Navigieren Sie zu **Plugins → Installieren**
3. Klicken Sie auf **Plugin hochladen**
4. Wählen Sie `as-camp-availability-integration-v1.0.0.zip`
5. Klicken Sie auf **Jetzt installieren**
6. Klicken Sie auf **Plugin aktivieren**

### Schritt 2: Abhängigkeiten prüfen

Stellen Sie sicher, dass folgende Plugins installiert und aktiviert sind:
- ✅ **WooCommerce** (10.3.3 oder höher)
- ✅ **Product Availability Scheduler** (Koala Apps)
- ✅ **Stachethemes Seat Planner**

### Schritt 3: Availability Scheduler konfigurieren

#### Option A: Produkt-Ebene (empfohlen für einzelne Produkte)

1. Gehen Sie zu **Produkte** und öffnen Sie ein Auditorium-Produkt (Parzelle)
2. Scrollen Sie zum Tab **Availability Scheduler**
3. Aktivieren Sie **"Enable product level settings"**
4. Konfigurieren Sie:
   - **Start Date:** z.B. 01.11.2025
   - **Start Time:** z.B. 12:00
   - **End Date:** z.B. 03.06.2026
   - **Product Availability Rule:** "Available"
   - **Enable Counter:** ✓ Aktivieren
   - **Counter Availability:** "Before Product Available"
   - **Text Before Counter:** "Verkaufsstart in"
   - **Unavailability Message:** "Verkaufsstart am 01.11.2025 um 12:00 Uhr."
5. Speichern Sie das Produkt

#### Option B: Globale Regeln (für mehrere Produkte)

1. Gehen Sie zu **WooCommerce → Availability Scheduler**
2. Klicken Sie auf **Add New Rule**
3. Erstellen Sie eine Regel mit:
   - **Title:** z.B. "ESG 2025 Verkaufsstart"
   - **Products:** Wählen Sie die betroffenen Produkte
   - **Start Date/Time:** Verkaufsstartzeit
   - **Availability:** "Available"
   - **Enable Counter:** ✓
   - **Counter Display:** "Before Product Available"
4. Veröffentlichen Sie die Regel

### Schritt 4: Globale Counter-Einstellungen

1. Gehen Sie zu **WooCommerce → Einstellungen → Availability Scheduler**
2. Tab: **General**
3. Aktivieren Sie:
   - **Enable Timer on Listing Page** ✓
   - **Enable Timer on Single Page** ✓
4. Wählen Sie **Select Counter Position:** "Before Price"
5. Speichern Sie die Änderungen

### Schritt 5: Testen

1. Öffnen Sie eine Produktseite im Frontend
2. Sie sollten sehen:
   - ✅ Countdown-Timer vor der Preisbox
   - ✅ "Parzelle auswählen"-Button ist ausgeblendet (wenn noch nicht verfügbar)
   - ✅ Unavailability Message wird angezeigt

## Elementor-Integration

### Timer im Elementor Template

Wenn Sie Elementor Pro Theme Builder verwenden:

1. Öffnen Sie Ihr **Product Template** in Elementor
2. Fügen Sie ein **Shortcode Widget** ein
3. Platzieren Sie es zwischen dem **Preis Widget** und dem **Add to Cart Widget**
4. Geben Sie den Shortcode ein: `[as_cai_availability_counter]`
5. Speichern und publizieren Sie das Template

**Empfohlene Reihenfolge der Elementor Widgets:**
1. Produktbild
2. Produkttitel
3. Produktbeschreibung
4. **Shortcode Widget** (Timer) ← NEU
5. Preis
6. Add to Cart (Seat Planner Button)

## Fehlerbehebung

### Problem: Timer wird nicht angezeigt

**Lösung:**
1. Prüfen Sie, ob "Enable Counter" aktiviert ist
2. Stellen Sie sicher, dass "Counter Availability" auf "Before Product Available" gesetzt ist
3. Prüfen Sie, ob Start Date in der Zukunft liegt
4. Leeren Sie den WordPress-Cache
5. Leeren Sie den Browser-Cache

### Problem: Button wird nicht ausgeblendet

**Lösung:**
1. Prüfen Sie, ob der Product Type "Auditorium" ist
2. Öffnen Sie die Browser-Entwicklerkonsole (F12) und suchen Sie nach JavaScript-Fehlern
3. Deaktivieren Sie andere Plugins, die möglicherweise Konflikte verursachen
4. Prüfen Sie, ob WooCommerce JavaScript korrekt geladen wird

### Problem: Button bleibt versteckt nach Timer-Ablauf

**Lösung:**
1. Laden Sie die Seite neu (F5)
2. Prüfen Sie die Browser-Konsole auf JavaScript-Fehler
3. Stellen Sie sicher, dass jQuery korrekt geladen wird
4. Deaktivieren Sie JavaScript-Minifizierung temporär

### Problem: Abhängigkeits-Warnung

**Lösung:**
Wenn Sie eine Warnung sehen "Folgende Plugins werden benötigt", dann:
1. Gehen Sie zu **Plugins → Installiert**
2. Aktivieren Sie alle erforderlichen Plugins:
   - WooCommerce
   - Product Availability Scheduler
   - Stachethemes Seat Planner

## Erweiterte Konfiguration

### Custom CSS

Fügen Sie in **Customizer → Zusätzliches CSS** hinzu:

```css
/* Timer-Position anpassen */
.as-cai-availability-counter-wrapper {
    margin: 25px 0;
    text-align: center;
}

/* Timer-Text-Styling */
.af-aps-before-txt {
    font-size: 16px;
    font-weight: bold;
    color: #e74c3c;
}

/* Button-Animation anpassen */
.stachesepl-single-add-to-cart-button-wrapper {
    transition: all 0.5s ease-in-out;
}
```

### Custom JavaScript

Falls Sie zusätzliche Funktionen benötigen:

```javascript
jQuery(document).on('as-cai-product-available', function() {
    console.log('Produkt ist jetzt verfügbar!');
    // Ihre custom Aktionen hier
});
```

## Support-Checkliste

Wenn Sie Probleme haben, sammeln Sie folgende Informationen:

- [ ] WordPress-Version
- [ ] PHP-Version
- [ ] WooCommerce-Version
- [ ] Availability Scheduler Version
- [ ] Seat Planner Version
- [ ] Aktives Theme
- [ ] Liste aktiver Plugins
- [ ] JavaScript-Fehler aus Browser-Konsole
- [ ] Screenshots des Problems

Senden Sie diese Informationen an: support@akkusys.de

## Tipps und Best Practices

1. **Testen Sie zuerst auf einer Staging-Umgebung**
2. **Erstellen Sie ein Backup vor der Installation**
3. **Verwenden Sie Produkt-Level-Einstellungen für einmalige Events**
4. **Verwenden Sie globale Regeln für wiederkehrende Verkaufszeiten**
5. **Setzen Sie den Counter-Text klar und deutlich**
6. **Testen Sie auf verschiedenen Geräten (Desktop, Tablet, Mobile)**

## Häufig gestellte Fragen (FAQ)

### Funktioniert das Plugin mit anderen Themes?

Ja, das Plugin ist Theme-unabhängig und funktioniert mit allen WooCommerce-kompatiblen Themes.

### Kann ich mehrere Produkte gleichzeitig konfigurieren?

Ja, verwenden Sie globale Availability Scheduler Regeln für mehrere Produkte.

### Wird der Timer automatisch aktualisiert?

Ja, der Countdown läuft clientseitig und aktualisiert sich sekündlich.

### Funktioniert es mit Caching-Plugins?

Ja, aber der JavaScript-Teil sollte nicht gecacht werden. Schließen Sie `/wp-content/plugins/as-camp-availability-integration/assets/js/` vom Caching aus.

### Ist das Plugin DSGVO-konform?

Ja, das Plugin speichert keine personenbezogenen Daten.

---

**Version:** 1.0.0  
**Datum:** 27. Oktober 2025  
**Support:** support@akkusys.de
