# System Monitorowania Drukarek

System do zarządzania drukarkami sieciowymi, monitorowania poziomu tonerów oraz zarządzania magazynem materiałów eksploatacyjnych.

## Wymagania

- PHP 7.0 lub nowszy
- Rozszerzenia PHP:
  - PDO SQLite
  - SNMP (opcjonalne, do komunikacji z drukarkami)
- XAMPP Portable lub inny serwer PHP
- Przeglądarka internetowa

## Instalacja

### Krok 1: Przygotowanie środowiska

1. Pobierz XAMPP Portable i rozpakuj na pendrive lub do wybranego katalogu
2. Skopiuj wszystkie pliki aplikacji do folderu `htdocs/printer_monitor/`

### Krok 2: Struktura katalogów

Upewnij się, że masz następującą strukturę:

```
printer_monitor/
├── ajax/
│   └── dismiss_alert.php
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── scripts.js
├── backups/              (zostanie utworzony automatycznie)
├── content/
│   ├── account.php
│   ├── config_content.php
│   ├── dashboard.php
│   ├── devices.php
│   ├── materials.php
│   └── users.php
├── includes/
│   ├── footer.php
│   ├── header.php
│   └── sidebar.php
├── app.db                (zostanie utworzona automatycznie)
├── auth.php
├── backup_database.php
├── check_printers.php
├── config.php
├── discover_printers.php
├── index.php
├── login.php
├── logout.php
├── no_permission.php
├── scan_printers.php
└── test_snmp.php
```

### Krok 3: Konfiguracja SNMP (opcjonalne)

Aby włączyć komunikację z drukarkami przez SNMP:

1. Otwórz plik `php.ini` w katalogu XAMPP
2. Znajdź linię: `;extension=snmp`
3. Usuń średnik na początku: `extension=snmp`
4. Zapisz plik i zrestartuj Apache

### Krok 4: Uruchomienie

1. Uruchom XAMPP Control Panel
2. Wystartuj Apache
3. Otwórz przeglądarkę i przejdź do: `http://localhost/printer_monitor/`
4. Zaloguj się domyślnymi danymi:
   - **Login:** admin
   - **Hasło:** admin123

## Pierwsza konfiguracja

### 1. Zmiana hasła administratora

Po pierwszym logowaniu:
1. Kliknij na avatar w prawym górnym rogu
2. Wybierz "Moje konto"
3. Zmień hasło na bezpieczne

### 2. Dodanie użytkowników

Jeśli masz uprawnienia administratora:
1. Przejdź do zakładki "Użytkownicy"
2. Kliknij "Dodaj użytkownika"
3. Wypełnij formularz i wybierz odpowiednią rolę

### 3. Konfiguracja sieci

1. Przejdź do zakładki "Konfiguracja"
2. Ustaw parametry sieci:
   - **Subnet:** Twoja podsieć (np. 192.168.1.)
   - **Zakres IP:** Od-Do (np. 200-230)
   - **SNMP Community:** Zazwyczaj "public"
3. Zapisz zmiany

### 4. Skanowanie drukarek

1. Przejdź do zakładki "Urządzenia"
2. Kliknij "Skanuj" - system automatycznie wykryje drukarki w sieci
3. Edytuj wykryte drukarki, dodając nazwę, lokalizację itp.

## Role użytkowników

System obsługuje 3 poziomy uprawnień:

### Administrator
- Pełny dostęp do wszystkich funkcji
- Zarządzanie użytkownikami
- Konfiguracja systemu
- Backup bazy danych

### Super User
- Przeglądanie dashboardu
- Zarządzanie urządzeniami (dodawanie, edycja)
- Zarządzanie materiałami (dodawanie, wydawanie)
- Brak dostępu do konfiguracji i użytkowników

### User (Użytkownik)
- Przeglądanie dashboardu
- Podgląd urządzeń
- Podgląd materiałów
- Brak możliwości edycji

## Funkcje systemu

### Dashboard
- Statystyki użytkowników (aktywni, logowania)
- Statystyki drukarek (online/offline, wydruki)
- Wykres typów wydruków (kolor/mono)
- Top 5 drukarek według liczby wydruków
- Alerty o niskich tonerach
- Nadchodzące przeglądy i wymiany

### Urządzenia
- Lista wszystkich drukarek
- Status online/offline
- Poziomy tonerów i materiałów eksploatacyjnych
- Automatyczne sprawdzanie przez SNMP
- Edycja parametrów drukarek
- Skanowanie sieci

### Magazyn materiałów
- Zarządzanie stanami magazynowymi
- Wydawanie materiałów
- Historia transakcji
- Alerty o niskim stanie
- Eksport do CSV

### Użytkownicy
- Dodawanie i edycja użytkowników
- Przypisywanie ról
- Aktywacja/dezaktywacja kont
- Podgląd sesji i aktywności

### Moje konto
- Edycja profilu
- Zmiana hasła
- Historia aktywności
- Aktywne sesje

## Backup i przywracanie

### Tworzenie backupu
1. Przejdź do zakładki "Konfiguracja"
2. Kliknij "Pobierz backup bazy danych"
3. Zapisz plik `.db` w bezpiecznym miejscu

### Przywracanie z backupu
1. Zatrzymaj Apache w XAMPP
2. Zastąp plik `app.db` swoim backupem
3. Uruchom ponownie Apache

## Rozwiązywanie problemów

### Drukarki nie są wykrywane

**Problem:** Skanowanie nie znajduje drukarek

**Rozwiązanie:**
1. Sprawdź czy moduł SNMP jest włączony w PHP
2. Sprawdź czy drukarki mają włączony SNMP
3. Sprawdź zakres IP w konfiguracji
4. Użyj `test_snmp.php?ip=ADRES_DRUKARKI` do testowania

### Błąd bazy danych

**Problem:** Błędy związane z SQLite

**Rozwiązanie:**
1. Upewnij się że katalog aplikacji ma uprawnienia do zapisu
2. Usuń plik `app.db` - zostanie utworzony ponownie
3. Przywróć backup jeśli masz

### Sesja wygasła

**Problem:** Częste wylogowywanie

**Rozwiązanie:**
- Zaznacz "Zapamiętaj mnie" przy logowaniu (sesja 30 dni zamiast 24h)

### SNMP nie działa

**Problem:** Brak komunikacji z drukarkami

**Rozwiązanie:**
1. Sprawdź `phpinfo()` czy SNMP jest załadowane
2. W `php.ini` odkomentuj `extension=snmp`
3. Zrestartuj Apache
4. Sprawdź czy drukarki odpowiadają na pingi

## Bezpieczeństwo

### Zalecenia
1. **Zmień domyślne hasło** administratora
2. **Ogranicz dostęp** - używaj systemu tylko w sieci lokalnej
3. **Regularne backupy** - twórz kopie bazy co tydzień
4. **Aktualizuj hasła** użytkowników regularnie
5. **Monitoruj logi** aktywności w zakładce "Moje konto"

### Hasła
- Minimum 6 znaków
- Hashowane algorytmem bcrypt
- Możliwość zmiany przez użytkownika

## Wsparcie techniczne

### Logi błędów
Sprawdź logi w:
- PHP error log: `xampp/apache/logs/error.log`
- Logi aplikacji: tabela `activity_logs` w bazie

### Testowanie SNMP
Użyj pliku `test_snmp.php`:
```
http://localhost/printer_monitor/test_snmp.php?ip=192.168.1.100
```

## Aktualizacje

Przed aktualizacją:
1. Utwórz backup bazy danych
2. Skopiuj stare pliki jako backup
3. Skopiuj nowe pliki
4. Sprawdź czy wszystko działa

## Licencja

System jest dostarczany "tak jak jest" bez gwarancji.

## Kontakt

W razie problemów sprawdź:
1. Dokumentację PHP: https://www.php.net/docs.php
2. Dokumentację SNMP: https://www.php.net/manual/en/book.snmp.php
3. Logi systemowe

---

**Wersja:** 2.0  
**Data:** Styczeń 2026  
**Autor:** System Monitor Team