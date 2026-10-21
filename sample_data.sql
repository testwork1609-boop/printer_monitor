-- sample_data.sql - Przykładowe dane testowe
-- Możesz użyć tego pliku do wypełnienia bazy testowymi danymi

-- ============================================================================
-- UWAGA: Ten plik jest opcjonalny i służy tylko do testów/demonstracji
-- Nie uruchamiaj go jeśli masz już dane produkcyjne!
-- ============================================================================

-- Przykładowi użytkownicy (hasła: password123 dla wszystkich)
-- Hasło jest zahashowane przez bcrypt: password123
INSERT INTO users (username, password_hash, full_name, email, role, is_active, created_at, login_count) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator Systemu', 'admin@example.com', 'admin', 1, datetime('now', '-30 days'), 45),
('jkowalski', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jan Kowalski', 'j.kowalski@example.com', 'superuser', 1, datetime('now', '-20 days'), 28),
('anowak', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Anna Nowak', 'a.nowak@example.com', 'user', 1, datetime('now', '-15 days'), 12),
('mwisniewski', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Marek Wiśniewski', 'm.wisniewski@example.com', 'user', 1, datetime('now', '-10 days'), 8),
('kdabrowski', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Katarzyna Dąbrowska', 'k.dabrowska@example.com', 'superuser', 0, datetime('now', '-5 days'), 2);

-- Przykładowe drukarki
INSERT INTO printers (name, ip, community, host_number, location, hall, model, total_prints, color_prints, mono_prints, is_active) VALUES
('HP LaserJet Pro MFP M428', '192.168.152.201', 'public', 'H001', 'Księgowość', 'K3', 'HP M428fdw', 15420, 3250, 12170, 1),
('Brother HL-L2350DW', '192.168.152.202', 'public', 'H002', 'Kadry', 'K3', 'Brother HL-L2350DW', 8930, 0, 8930, 1),
('Canon imageRUNNER C3520i', '192.168.152.203', 'public', 'H003', 'Formy', 'K5', 'Canon iR C3520i', 25670, 18420, 7250, 1),
('Xerox VersaLink C405', '192.168.152.204', 'public', 'H004', 'Marketing', 'K3', 'Xerox VersaLink C405', 12340, 9870, 2470, 1),
('Kyocera ECOSYS M3645idn', '192.168.152.205', 'public', 'H005', 'IT', 'IT', 'Kyocera M3645idn', 6780, 1230, 5550, 1),
('Ricoh MP C3004', '192.168.152.206', 'public', 'H006', 'Recepcja', 'K3', 'Ricoh MP C3004', 19870, 14320, 5550, 1),
('HP Color LaserJet Pro M454', '192.168.152.207', 'public', 'H007', 'Dział Sprzedaży', 'K5', 'HP M454dw', 11230, 8940, 2290, 1),
('Epson WorkForce Pro WF-C879R', '192.168.152.208', 'public', 'H008', 'Produkcja', 'K5', 'Epson WF-C879R', 8450, 2130, 6320, 0);

-- Przykładowe statusy drukarek (symulacja poziomów tonerów)
INSERT INTO printer_status (printer_id, status, materials_json, last_check) VALUES
(1, 'online', '{"black":85,"cyan":72,"magenta":68,"yellow":75,"drum_black":45,"drum_color":50,"waste":30}', datetime('now', '-2 hours')),
(2, 'online', '{"black":28,"drum_black":65,"waste":15}', datetime('now', '-1 hour')),
(3, 'online', '{"black":92,"cyan":88,"magenta":15,"yellow":82,"drum_black":78,"drum_color":75,"waste":45,"fuser":60}', datetime('now', '-30 minutes')),
(4, 'online', '{"black":65,"cyan":55,"magenta":48,"yellow":52,"drum_black":40,"drum_color":38,"waste":62}', datetime('now', '-45 minutes')),
(5, 'online', '{"black":94,"cyan":N/D,"magenta":N/D,"yellow":N/D,"drum_black":88,"waste":22}', datetime('now', '-3 hours')),
(6, 'online', '{"black":42,"cyan":38,"magenta":35,"yellow":40,"drum_black":55,"drum_color":52,"waste":68,"transfer":45}', datetime('now', '-1 hour')),
(7, 'online', '{"black":78,"cyan":25,"magenta":82,"yellow":79,"drum_black":92,"drum_color":90}', datetime('now', '-20 minutes')),
(8, 'offline', '{}', datetime('now', '-2 days'));

-- Przykładowe materiały
INSERT INTO materials (id, type, material_code, printer_name, printer_model, quantity, min_quantity, location, notes, added_date, last_updated) VALUES
('mat_001', 'toner', 'CF410A', 'HP LaserJet Pro', 'M428/M454', 5, 2, 'Magazyn A, Półka 2', 'Czarny', datetime('now', '-60 days'), datetime('now', '-10 days')),
('mat_002', 'toner', 'CF411A', 'HP LaserJet Pro', 'M428/M454', 3, 2, 'Magazyn A, Półka 2', 'Cyan', datetime('now', '-60 days'), datetime('now', '-10 days')),
('mat_003', 'toner', 'CF412A', 'HP LaserJet Pro', 'M428/M454', 1, 2, 'Magazyn A, Półka 2', 'Yellow - NISKI STAN', datetime('now', '-60 days'), datetime('now', '-5 days')),
('mat_004', 'toner', 'CF413A', 'HP LaserJet Pro', 'M428/M454', 4, 2, 'Magazyn A, Półka 2', 'Magenta', datetime('now', '-60 days'), datetime('now', '-10 days')),
('mat_005', 'toner', 'TN-2420', 'Brother HL-L2350DW', 'Brother HL-L2350DW', 8, 3, 'Magazyn A, Półka 1', 'Wysoka pojemność', datetime('now', '-45 days'), datetime('now')),
('mat_006', 'drum', 'DR-2400', 'Brother HL-L2350DW', 'Brother HL-L2350DW', 2, 1, 'Magazyn A, Półka 1', '', datetime('now', '-45 days'), datetime('now', '-30 days')),
('mat_007', 'toner', 'C-EXV51', 'Canon imageRUNNER', 'C3520i', 6, 2, 'Magazyn B, Półka 3', 'Czarny', datetime('now', '-50 days'), datetime('now', '-8 days')),
('mat_008', 'toner', 'C-EXV51C', 'Canon imageRUNNER', 'C3520i', 4, 2, 'Magazyn B, Półka 3', 'Cyan', datetime('now', '-50 days'), datetime('now', '-8 days')),
('mat_009', 'toner', 'C-EXV51M', 'Canon imageRUNNER', 'C3520i', 0, 2, 'Magazyn B, Półka 3', 'BRAK - ZAMÓWIĆ!', datetime('now', '-50 days'), datetime('now', '-2 days')),
('mat_010', 'toner', 'C-EXV51Y', 'Canon imageRUNNER', 'C3520i', 5, 2, 'Magazyn B, Półka 3', 'Yellow', datetime('now', '-50 days'), datetime('now', '-8 days')),
('mat_011', 'fuser', 'RM2-6342', 'HP LaserJet Pro', 'M428', 1, 1, 'Magazyn C, Półka 1', 'Fuser Unit', datetime('now', '-90 days'), datetime('now', '-90 days')),
('mat_012', 'waste', 'B234-4020', 'Ricoh MP', 'C3004', 3, 1, 'Magazyn A, Półka 4', 'Waste Toner Container', datetime('now', '-40 days'), datetime('now', '-15 days')),
('mat_013', 'maintenance', 'MK-3260', 'Kyocera ECOSYS', 'M3645idn', 1, 1, 'Magazyn C, Półka 2', 'Maintenance Kit', datetime('now', '-120 days'), datetime('now', '-120 days')),
('mat_014', 'toner', 'TK-3160', 'Kyocera ECOSYS', 'M3645idn', 6, 2, 'Magazyn B, Półka 1', '', datetime('now', '-35 days'), datetime('now', '-5 days')),
('mat_015', 'belt', 'B223-2076', 'Ricoh MP', 'C3004', 1, 1, 'Magazyn C, Półka 3', 'Transfer Belt', datetime('now', '-180 days'), datetime('now', '-180 days'));

-- Historia materiałów
INSERT INTO material_history (material_id, action, quantity_change, quantity_after, printer_id, user_id, notes, created_at) VALUES
('mat_001', 'added', 10, 10, NULL, 1, 'Dostawa początkowa', datetime('now', '-60 days')),
('mat_001', 'issued', -2, 8, 1, 2, 'Wymiana tonera w drukarce H001', datetime('now', '-30 days')),
('mat_001', 'issued', -3, 5, 1, 2, 'Wymiana tonera w drukarce H001 i zapas', datetime('now', '-10 days')),
('mat_002', 'added', 5, 5, NULL, 1, 'Dostawa początkowa', datetime('now', '-60 days')),
('mat_002', 'issued', -2, 3, 1, 2, 'Wymiana tonera', datetime('now', '-10 days')),
('mat_003', 'added', 5, 5, NULL, 1, 'Dostawa początkowa', datetime('now', '-60 days')),
('mat_003', 'issued', -4, 1, 1, 2, 'Wymiana w kilku drukarkach', datetime('now', '-5 days')),
('mat_005', 'added', 10, 10, NULL, 1, 'Zamówienie Brother', datetime('now', '-45 days')),
('mat_005', 'issued', -2, 8, 2, 2, 'Wymiana w Brother HL-L2350DW', datetime('now', '-15 days')),
('mat_009', 'added', 3, 3, NULL, 1, 'Dostawa Canon', datetime('now', '-50 days')),
('mat_009', 'issued', -3, 0, 3, 2, 'Wymiana w Canon', datetime('now', '-2 days'));

-- Nadchodzące przeglądy i wymiany
INSERT INTO maintenance_schedule (printer_id, type, scheduled_date, completed, notes, created_at) VALUES
(1, 'drum_replacement', date('now', '+5 days'), 0, 'Planowana wymiana bębna czarnego', datetime('now', '-10 days')),
(3, 'toner_replacement', date('now', '+2 days'), 0, 'PILNE: Wymiana magenty - niski poziom', datetime('now', '-1 day')),
(4, 'service', date('now', '+15 days'), 0, 'Przegląd okresowy - 50k wydruków', datetime('now', '-20 days')),
(6, 'waste', date('now', '+3 days'), 0, 'Opróżnienie pojemnika na zużyty toner', datetime('now', '-5 days')),
(7, 'toner_replacement', date('now', '+1 day'), 0, 'Wymiana cyanu - niski poziom', datetime('now')),
(2, 'inspection', date('now', '-5 days'), 1, 'Przegląd wykonany', datetime('now', '-15 days')),
(5, 'service', date('now', '-10 days'), 1, 'Serwis gwarancyjny', datetime('now', '-30 days'));

-- Alerty systemowe
INSERT INTO system_alerts (type, title, message, module, is_active, created_at) VALUES
('critical', 'Brak materiału w magazynie!', 'Magenta C-EXV51M dla Canon imageRUNNER - ZAMÓW NATYCHMIAST', 'materials', 1, datetime('now', '-2 days')),
('warning', 'Niski stan materiałów', 'Yellow CF412A dla HP LaserJet Pro - pozostała tylko 1 sztuka', 'materials', 1, datetime('now', '-5 days')),
('warning', 'Drukarka offline', 'Epson WorkForce Pro WF-C879R (H008) jest offline od 2 dni', 'devices', 1, datetime('now', '-2 days'));

-- Przykładowe logi aktywności
INSERT INTO activity_logs (user_id, action, module, details, ip_address, created_at) VALUES
(1, 'login', 'auth', 'Użytkownik zalogowany', '192.168.152.100', datetime('now', '-8 hours')),
(2, 'login', 'auth', 'Użytkownik zalogowany', '192.168.152.105', datetime('now', '-7 hours')),
(2, 'issue_material', 'materials', 'Wydano materiał: mat_001', '192.168.152.105', datetime('now', '-6 hours')),
(1, 'add_printer', 'devices', 'Dodano drukarkę: Test Printer', '192.168.152.100', datetime('now', '-5 hours')),
(1, 'delete_printer', 'devices', 'Usunięto drukarkę: Test Printer', '192.168.152.100', datetime('now', '-5 hours')),
(2, 'update_printer', 'devices', 'Zaktualizowano drukarkę: HP LaserJet Pro MFP M428', '192.168.152.105', datetime('now', '-4 hours')),
(1, 'add_user', 'users', 'Dodano użytkownika: test_user', '192.168.152.100', datetime('now', '-3 hours')),
(3, 'login', 'auth', 'Użytkownik zalogowany', '192.168.152.110', datetime('now', '-2 hours')),
(1, 'backup_database', 'config', 'Utworzono backup bazy danych', '192.168.152.100', datetime('now', '-1 hour')),
(2, 'logout', 'auth', 'Użytkownik wylogowany', '192.168.152.105', datetime('now', '-30 minutes'));

-- ============================================================================
-- Koniec przykładowych danych
-- ============================================================================

-- Aby użyć tego pliku:
-- 1. UWAGA: To usunie wszystkie istniejące dane!
-- 2. Wykonaj backup swojej bazy przed użyciem!
-- 3. Możesz zaimportować ten plik przez phpMyAdmin lub inny klient SQLite
-- 4. Hasło dla wszystkich użytkowników testowych to: password123