CREATE TABLE articles (
  id TEXT,
  timestamp INTEGER,
  title TEXT,
  content TEXT,
  allow_comments INTEGER
);

CREATE TABLE comments (
  id INTEGER PRIMARY KEY,
  parent TEXT,
  timestamp INTEGER,
  content TEXT,
  poster_ip TEXT,
  poster_name TEXT,
  poster_email TEXT,
  poster_site TEXT
);

CREATE TABLE remarks (
  id INTEGER PRIMARY KEY,
  timestamp INTEGER,
  content TEXT
);