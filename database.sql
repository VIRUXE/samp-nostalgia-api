CREATE TABLE news (
    id INTEGER PRIMARY KEY,
    title_en TEXT,
    title_pt TEXT,
    content_en TEXT,
    content_pt TEXT,
    published_at INTEGER
);

CREATE TABLE sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    player_name TEXT,
    auth_code TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_active TEXT DEFAULT CURRENT_TIMESTAMP,
    logged_out TEXT,
    FOREIGN KEY(player_name) REFERENCES players(name)
);

CREATE INDEX idx_sessions_token ON sessions(token);
CREATE INDEX idx_sessions_player_name ON sessions(player_name);

CREATE TABLE messages (
    player_name TEXT PRIMARY KEY,
    message TEXT,
    from_admin_name TEXT,
    timestamp INTEGER
);

CREATE TABLE session_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_name TEXT NOT NULL,
    level TEXT NOT NULL,
    action TEXT NOT NULL,
    timestamp INTEGER NOT NULL
);