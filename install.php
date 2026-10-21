-- Tabela alertów o niskim poziomie materiałów
CREATE TABLE IF NOT EXISTS material_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    printer_id INTEGER NOT NULL,
    material_type TEXT NOT NULL,   -- np. 'black', 'cyan', 'drum_black' itp.
    alert_level INTEGER NOT NULL,  -- próg, który spowodował alert (np. 20)
    current_level INTEGER NOT NULL, -- poziom w momencie alertu
    alert_date DATETIME NOT NULL,
    resolved_date DATETIME,        -- NULL jeśli nierozwiązane
    resolved_by_material_id TEXT,  -- ID materiału z magazynu, który został użyty
    FOREIGN KEY (printer_id) REFERENCES printers(id),
    FOREIGN KEY (resolved_by_material_id) REFERENCES materials(id)
);

-- Tabela historii wymian
CREATE TABLE IF NOT EXISTS material_replacements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    printer_id INTEGER NOT NULL,
    material_type TEXT NOT NULL,
    old_material_id TEXT,          -- ID materiału, który został zużyty (jeśli znany)
    new_material_id TEXT,          -- ID nowego materiału z magazynu
    alert_date DATETIME NOT NULL,  -- data alertu
    replacement_date DATETIME NOT NULL, -- data wymiany
    days_waiting INTEGER,          -- liczba dni oczekiwania
    FOREIGN KEY (printer_id) REFERENCES printers(id),
    FOREIGN KEY (old_material_id) REFERENCES materials(id),
    FOREIGN KEY (new_material_id) REFERENCES materials(id)
);

-- Dodanie kolumny color do materials (jeśli nie istnieje)
ALTER TABLE materials ADD COLUMN color TEXT;