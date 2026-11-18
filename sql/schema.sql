-- ATLAS / Mnemo knowledge database
-- Import in phpMyAdmin (or let setup.php do it). MariaDB 10.4+ / MySQL 8+.

CREATE DATABASE IF NOT EXISTS atlas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE atlas;

CREATE TABLE IF NOT EXISTS collections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  color VARCHAR(16) NOT NULL DEFAULT '#38bdf8',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  collection_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  mime VARCHAR(120) NULL,
  kind ENUM('document','image','audio') NOT NULL DEFAULT 'document',
  phash CHAR(16) NULL,
  sha256 CHAR(64) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  page_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary TEXT NULL,
  status ENUM('queued','processing','ready','failed') NOT NULL DEFAULT 'queued',
  error TEXT NULL,
  trust TINYINT UNSIGNED NOT NULL DEFAULT 50,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_sha (sha256),
  KEY idx_status (status),
  CONSTRAINT fk_doc_coll FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chunks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,
  page INT UNSIGNED NULL,
  heading VARCHAR(255) NULL,
  content MEDIUMTEXT NOT NULL,
  token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
  extracted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc (document_id, seq),
  FULLTEXT KEY ft_content (content),
  CONSTRAINT fk_chunk_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Normalized float32 vectors packed as binary (dot product == cosine similarity)
CREATE TABLE IF NOT EXISTS chunk_embeddings (
  chunk_id INT UNSIGNED PRIMARY KEY,
  model VARCHAR(120) NOT NULL,
  dims SMALLINT UNSIGNED NOT NULL,
  vec MEDIUMBLOB NOT NULL,
  CONSTRAINT fk_emb_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  norm_key VARCHAR(255) NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'concept',
  description TEXT NULL,
  mention_count INT UNSIGNED NOT NULL DEFAULT 0,
  cluster_id INT UNSIGNED NULL,
  confidence DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  vec MEDIUMBLOB NULL,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_norm (norm_key),
  KEY idx_type (type),
  FULLTEXT KEY ft_ent (name, description)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entity_aliases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_id INT UNSIGNED NOT NULL,
  alias VARCHAR(255) NOT NULL,
  norm_key VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_alias (norm_key),
  CONSTRAINT fk_alias_ent FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entity_mentions (
  entity_id INT UNSIGNED NOT NULL,
  chunk_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entity_id, chunk_id),
  KEY idx_chunk (chunk_id),
  CONSTRAINT fk_men_ent FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_men_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  type VARCHAR(80) NOT NULL,
  origin ENUM('extracted','inferred','user') NOT NULL DEFAULT 'extracted',
  weight INT UNSIGNED NOT NULL DEFAULT 1,
  confidence DECIMAL(4,3) NOT NULL DEFAULT 0.600,
  evidence TEXT NULL,
  chunk_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rel (source_id, target_id, type),
  KEY idx_target (target_id),
  CONSTRAINT fk_rel_src FOREIGN KEY (source_id) REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rel_tgt FOREIGN KEY (target_id) REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rel_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ingest_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(60) NOT NULL,
  document_id INT UNSIGNED NULL,
  entity_id INT UNSIGNED NULL,
  detail VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_event (event)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  sources JSON NULL,
  feedback TINYINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conv (conversation_id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Facts taught directly by the user ("remember:", "define:", "correct:")
CREATE TABLE IF NOT EXISTS user_facts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('fact','definition','correction') NOT NULL DEFAULT 'fact',
  content TEXT NOT NULL,
  supersedes_id INT UNSIGNED NULL,
  chunk_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_digest (
  day DATE PRIMARY KEY,
  documents INT UNSIGNED NOT NULL DEFAULT 0,
  chunks INT UNSIGNED NOT NULL DEFAULT 0,
  entities INT UNSIGNED NOT NULL DEFAULT 0,
  relations INT UNSIGNED NOT NULL DEFAULT 0,
  inferred_links INT UNSIGNED NOT NULL DEFAULT 0,
  clusters INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(80) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO collections (name, color) VALUES ('General', '#38bdf8');

-- =====================================================================
-- Phase 5: Truth engine
-- =====================================================================
CREATE TABLE IF NOT EXISTS claims (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chunk_id INT UNSIGNED NULL,
  document_id INT UNSIGNED NULL,
  subject_id INT UNSIGNED NULL,
  subject VARCHAR(255) NOT NULL,
  predicate VARCHAR(120) NOT NULL,
  object TEXT NOT NULL,
  statement TEXT NOT NULL,
  confidence DECIMAL(4,3) NOT NULL DEFAULT 0.600,
  support INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('active','disputed','rejected','superseded') NOT NULL DEFAULT 'active',
  origin ENUM('extracted','user') NOT NULL DEFAULT 'extracted',
  checked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_subject (subject_id),
  KEY idx_chunk (chunk_id),
  KEY idx_checked (checked),
  FULLTEXT KEY ft_claim (statement),
  CONSTRAINT fk_claim_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_ent FOREIGN KEY (subject_id) REFERENCES entities(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contradictions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_a INT UNSIGNED NOT NULL,
  claim_b INT UNSIGNED NOT NULL,
  reason TEXT NULL,
  score DECIMAL(4,3) NOT NULL DEFAULT 0.700,
  status ENUM('open','a_correct','b_correct','both_valid','ignored') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_pair (claim_a, claim_b),
  KEY idx_status (status),
  CONSTRAINT fk_con_a FOREIGN KEY (claim_a) REFERENCES claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_con_b FOREIGN KEY (claim_b) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Full history of every change to the knowledge (never lose anything)
CREATE TABLE IF NOT EXISTS fact_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_type ENUM('entity','relation','claim','document') NOT NULL,
  object_id INT UNSIGNED NOT NULL,
  field VARCHAR(60) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_obj (object_type, object_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS review_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('relation','merge','claim') NOT NULL,
  ref_a INT UNSIGNED NOT NULL,
  ref_b INT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  score DECIMAL(4,3) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  UNIQUE KEY uq_item (kind, ref_a, ref_b),
  KEY idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entity_merges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kept_id INT UNSIGNED NOT NULL,
  merged_id INT UNSIGNED NOT NULL,
  merged_name VARCHAR(255) NOT NULL,
  snapshot LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at DATETIME NULL
) ENGINE=InnoDB;

-- =====================================================================
-- Phase 6: Self-improvement
-- =====================================================================
CREATE TABLE IF NOT EXISTS qa_memory (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NULL,
  question TEXT NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  sources JSON NULL,
  vec MEDIUMBLOB NULL,
  uses INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_msg (message_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS golden_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  expected TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS golden_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  passed INT UNSIGNED NOT NULL DEFAULT 0,
  avg_score DECIMAL(5,4) NOT NULL DEFAULT 0,
  knowledge_size INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS golden_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  test_id INT UNSIGNED NOT NULL,
  answer MEDIUMTEXT NULL,
  score DECIMAL(4,3) NOT NULL DEFAULT 0,
  reason TEXT NULL,
  KEY idx_run (run_id),
  CONSTRAINT fk_gr_run FOREIGN KEY (run_id) REFERENCES golden_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_gr_test FOREIGN KEY (test_id) REFERENCES golden_tests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS self_quiz (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chunk_id INT UNSIGNED NULL,
  question TEXT NOT NULL,
  expected TEXT NOT NULL,
  answer MEDIUMTEXT NULL,
  score DECIMAL(4,3) NOT NULL DEFAULT 0,
  retrieved TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chunk (chunk_id),
  CONSTRAINT fk_sq_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- Phase 7: Assistant features
-- =====================================================================
CREATE TABLE IF NOT EXISTS standing_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  answer MEDIUMTEXT NULL,
  sources JSON NULL,
  source_hash CHAR(40) NULL,
  unread TINYINT(1) NOT NULL DEFAULT 0,
  versions INT UNSIGNED NOT NULL DEFAULT 0,
  checked_at DATETIME NULL,
  changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flashcards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_id INT UNSIGNED NULL,
  chunk_id INT UNSIGNED NULL,
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  box TINYINT UNSIGNED NOT NULL DEFAULT 1,
  due_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviews INT UNSIGNED NOT NULL DEFAULT 0,
  lapses INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entity (entity_id),
  KEY idx_due (due_at),
  CONSTRAINT fk_fc_ent FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_fc_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hypotheses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_a INT UNSIGNED NOT NULL,
  entity_b INT UNSIGNED NOT NULL,
  score DECIMAL(6,3) NOT NULL DEFAULT 0,
  shared JSON NULL,
  status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pair (entity_a, entity_b),
  KEY idx_status (status),
  CONSTRAINT fk_hy_a FOREIGN KEY (entity_a) REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_hy_b FOREIGN KEY (entity_b) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- Phase 8: Scale (image similarity)
-- =====================================================================
CREATE TABLE IF NOT EXISTS image_links (
  image_a INT UNSIGNED NOT NULL,
  image_b INT UNSIGNED NOT NULL,
  score DECIMAL(4,3) NOT NULL,
  method VARCHAR(30) NOT NULL,
  PRIMARY KEY (image_a, image_b),
  CONSTRAINT fk_il_a FOREIGN KEY (image_a) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_il_b FOREIGN KEY (image_b) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- Baby mode: growth, curiosity, lessons
-- =====================================================================
CREATE TABLE IF NOT EXISTS curiosity_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_id INT UNSIGNED NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'what_is',
  question TEXT NOT NULL,
  context TEXT NULL,
  answer TEXT NULL,
  status ENUM('pending','answered','skipped') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at DATETIME NULL,
  UNIQUE KEY uq_ent_kind (entity_id, kind),
  KEY idx_status (status),
  CONSTRAINT fk_cq_ent FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  items INT UNSIGNED NOT NULL DEFAULT 0,
  last_score DECIMAL(4,3) NULL,
  exams INT UNSIGNED NOT NULL DEFAULT 0,
  last_exam JSON NULL,
  last_exam_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS growth_diary (
  day DATE PRIMARY KEY,
  level INT UNSIGNED NOT NULL DEFAULT 0,
  stage VARCHAR(60) NOT NULL,
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  entry TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
