-- ---------------------------------------------------------------------------
-- 015: the email log (Phase 14.8, spec-v2 §22).
--
-- Every message this application tries to send, whatever became of it: what
-- prompted it, who it was for, and whether it went. The Mailer writes one
-- row per attempt — delivered, filed to var/mail, logged, or blocked by an
-- interlock, with which one — so an Admin can answer "did Bob get the
-- email" from a screen instead of from the server's error log, which on
-- shared hosting is not theirs to read.
--
-- A RECORD, like audit_log: append-only, nothing deletes a row, and it
-- carries no foreign key the recipient's account could take with it
-- (RESTRICT, as every key that points at app_user).
--
-- Schema, so NOT atomic: MySQL commits implicitly on DDL. CREATE TABLE IF
-- NOT EXISTS so a re-run after a half-applied attempt is a no-op.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Why it was sent: the message's own name (Rerm\Mail\EmailLog::PURPOSES)
    -- and the sentence a screen prints beside it — "3 RCF requests
    -- submitted by Erin Delta", "Password reset requested for 1234567".
    -- No member's details beyond what the screen that prompted it showed.
    `purpose`           VARCHAR(32) NOT NULL,
    `reason`            VARCHAR(255) NOT NULL DEFAULT '',

    -- Who it went to: the account, when the recipient has one, and the
    -- address as handed to the Mailer. Both, because an address changes
    -- with every import and the question is "did THIS person get it".
    `recipient_user_id` INT UNSIGNED NULL,
    `recipient`         VARCHAR(255) NOT NULL DEFAULT '',
    `subject`           VARCHAR(255) NOT NULL DEFAULT '',

    -- What became of it: the transport that was in force and the outcome
    -- (Rerm\Mail\EmailLog::OUTCOMES) — sent, failed, filed, logged,
    -- blocked, refused, no_email — with the interlock or the error in
    -- `detail` where there is one.
    `transport`         VARCHAR(8) NOT NULL DEFAULT '',
    `outcome`           VARCHAR(16) NOT NULL,
    `detail`            VARCHAR(255) NOT NULL DEFAULT '',

    -- Whose action prompted it, when somebody was signed in. NULL for a
    -- password reset, which is asked for by somebody who cannot sign in.
    `actor_user_id`     INT UNSIGNED NULL,

    PRIMARY KEY (`id`),
    KEY `ix_email_log_time` (`occurred_at`),
    KEY `ix_email_log_recipient` (`recipient_user_id`, `occurred_at`),
    KEY `ix_email_log_actor` (`actor_user_id`),
    KEY `ix_email_log_outcome` (`outcome`, `occurred_at`),

    CONSTRAINT `fk_email_log_recipient` FOREIGN KEY (`recipient_user_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_email_log_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
