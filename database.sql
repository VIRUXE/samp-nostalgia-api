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
    hwid TEXT,
    auth_code TEXT,
    created_at INTEGER DEFAULT (strftime('%s', 'now')),
    last_active INTEGER DEFAULT (strftime('%s', 'now')),
    logged_out INTEGER,
    FOREIGN KEY(player_name) REFERENCES players(name)
);

CREATE INDEX idx_sessions_token ON sessions(token);
CREATE INDEX idx_sessions_player_name ON sessions(player_name);

CREATE TABLE banned_hwids (
    hwid TEXT PRIMARY KEY,
    banned_at INTEGER DEFAULT (strftime('%s', 'now')),
    reason TEXT
);

CREATE TABLE messages (
    player_name TEXT PRIMARY KEY,
    message TEXT,
    from_admin_name TEXT,
    timestamp INTEGER
);

CREATE TABLE session_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session TEXT NOT NULL,
    level TEXT NOT NULL,
    action TEXT NOT NULL,
    timestamp INTEGER NOT NULL
);

CREATE TABLE expected_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session TEXT NOT NULL,
    actionType TEXT NOT NULL,
    issuedAt INTEGER,
    respondedAt INTEGER
);

INSERT INTO expected_responses (session, actionType, issuedAt) VALUES (:token, :actionType, strftime('%s', 'now'));
