-- Table for records POSTed by the "Google Maps Business Scraper" extension.
-- Run inside the business_data database (or open setup.php once).

CREATE TABLE IF NOT EXISTS businesses (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_key       VARCHAR(191)  NOT NULL,          -- hex_id / place_id / cid / url hash
  zip_id             VARCHAR(32)   NOT NULL DEFAULT '',

  name               VARCHAR(255)  NULL,
  category           VARCHAR(255)  NULL,
  rating             DECIMAL(2,1)  NULL,
  review_count       INT UNSIGNED  NULL,
  price_level        VARCHAR(50)   NULL,
  price_label        VARCHAR(255)  NULL,
  price_per_person   VARCHAR(100)  NULL,

  address            TEXT          NULL,
  street             VARCHAR(255)  NULL,
  city               VARCHAR(120)  NULL,
  state              VARCHAR(120)  NULL,
  postal_code        VARCHAR(20)   NULL,
  country            VARCHAR(120)  NULL,

  phone              VARCHAR(60)   NULL,
  phone_e164         VARCHAR(30)   NULL,
  email              VARCHAR(255)  NULL,              -- best address found on the website
  emails             TEXT          NULL,              -- JSON array, all addresses found
  email_source       VARCHAR(20)   NULL,              -- website | chatgpt | claude
  gpt_status         VARCHAR(10)   NULL,              -- ChatGPT lookup: found | none | error
  gpt_answer         TEXT          NULL,              -- ChatGPT's reply, or the error
  gpt_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  gpt_claimed_at     DATETIME      NULL,              -- UTC, handed out by get_business_without_email.php
  gpt_checked_at     DATETIME      NULL,              -- UTC, lookup finished
  mail_status        VARCHAR(10)   NULL,              -- Gmail sender: sent | error | failed (gave up)
  mail_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  mail_error         TEXT          NULL,              -- last send failure
  mail_claimed_at    DATETIME      NULL,              -- UTC, handed out by get_business_to_email.php
  mail_sent_at       DATETIME      NULL,              -- UTC, last successful send (portal or Gmail)
  claude_status      VARCHAR(10)   NULL,              -- Claude lookup (after ChatGPT found none): found | mentioned | none | error
  claude_answer      TEXT          NULL,              -- Claude's reply, or the error
  claude_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  claude_claimed_at  DATETIME      NULL,              -- UTC, handed out by get_business_for_claude.php
  claude_checked_at  DATETIME      NULL,              -- UTC, lookup finished
  website            TEXT          NULL,
  plus_code          VARCHAR(120)  NULL,
  located_in         VARCHAR(255)  NULL,

  hours_status       VARCHAR(255)  NULL,
  hours              TEXT          NULL,
  hours_weekly       LONGTEXT      NULL,              -- JSON object
  business_status    VARCHAR(60)   NULL,

  latitude           DECIMAL(10,7) NULL,
  longitude          DECIMAL(10,7) NULL,
  google_maps_url    TEXT          NULL,
  place_id           VARCHAR(255)  NULL,
  cid                VARCHAR(30)   NULL,
  hex_id             VARCHAR(64)   NULL,
  cid_url            VARCHAR(255)  NULL,

  service_options    LONGTEXT      NULL,              -- JSON array
  attributes_text    TEXT          NULL,
  attributes         LONGTEXT      NULL,              -- JSON object
  accessibility      TEXT          NULL,
  menu_url           TEXT          NULL,
  reservation_url    TEXT          NULL,
  actions            LONGTEXT      NULL,              -- JSON array
  image_url          TEXT          NULL,
  review_summary     TEXT          NULL,
  rating_breakdown   LONGTEXT      NULL,              -- JSON object
  is_claimed         VARCHAR(10)   NULL,
  is_sponsored       TINYINT(1)    NOT NULL DEFAULT 0,

  zipcode            VARCHAR(20)   NULL,
  zip_lat            DECIMAL(10,7) NULL,
  zip_long           DECIMAL(10,7) NULL,
  search_keyword     VARCHAR(255)  NULL,
  search_query       VARCHAR(255)  NULL,
  scraped_at         DATETIME      NULL,              -- UTC
  extension_version  VARCHAR(20)   NULL,

  raw_data           LONGTEXT      NULL,              -- full record as sent, for fields added later
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_business_zip (business_key, zip_id),
  KEY idx_zipcode (zipcode),
  KEY idx_category (category),
  KEY idx_city_state (city, state),
  KEY idx_scraped_at (scraped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portal settings (settings.php): SMTP and API keys, one row per key.
CREATE TABLE IF NOT EXISTS settings (
  setting_key    VARCHAR(64)   NOT NULL,
  setting_value  TEXT          NULL,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing emails sent from the portal (send_email.php) or Gmail (save_email_result.php), one row per attempt.
CREATE TABLE IF NOT EXISTS email_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  BIGINT UNSIGNED NULL,
  to_email     VARCHAR(255)  NOT NULL,
  subject      VARCHAR(255)  NOT NULL,
  body         TEXT          NULL,
  status       VARCHAR(10)   NOT NULL,              -- sent | failed
  channel      VARCHAR(10)   NULL,                  -- smtp (send_email.php) | gmail (sending_email/ extension)
  from_email   VARCHAR(255)  NULL,                  -- Gmail account the extension sent from
  error        TEXT          NULL,
  sent_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- UTC
  PRIMARY KEY (id),
  KEY idx_business (business_id),
  KEY idx_to_email (to_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing email templates (templates.php). get_random_template.php picks an active one at random.
CREATE TABLE IF NOT EXISTS email_templates (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120)  NOT NULL,
  subject      VARCHAR(255)  NOT NULL,
  body         TEXT          NOT NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,              -- 0 = never picked at random
  source       VARCHAR(10)   NOT NULL DEFAULT 'manual',       -- manual | groq
  times_used   INT UNSIGNED  NOT NULL DEFAULT 0,              -- picks by get_random_template.php
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification (verify_email.php, email_verify.php): one row per address, nothing is ever sent.
CREATE TABLE IF NOT EXISTS email_checks (
  email        VARCHAR(255)  NOT NULL,
  status       VARCHAR(10)   NOT NULL,                     -- valid | invalid | risky | unknown
  reason       VARCHAR(255)  NOT NULL DEFAULT '',          -- plain-English explanation
  mx_host      VARCHAR(255)  NULL,                         -- mail server asked
  smtp_code    SMALLINT      NULL,                         -- its reply code to RCPT TO (0 = no reply)
  smtp_reply   VARCHAR(500)  NULL,                         -- its reply text
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 1,        -- checks in a row that ended `unknown`
  checked_at   DATETIME      NOT NULL,                     -- UTC
  PRIMARY KEY (email),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
