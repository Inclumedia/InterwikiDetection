BEGIN;

CREATE TABLE iw_detection(
-- Primary key
iwd_id                     int NOT NULL AUTO_INCREMENT,
-- Target page title (with namespace prefix)
iwd_title                  varchar(256),
-- 0 if doesn't exist; 1 if it does; NULL if we don't know
iwd_exists		   int DEFAULT NULL,
-- Timestamp of polling Wikipedia. 00000000000000 means we don't know whether the target title
-- exists on Wikipedia.
iwd_polled		   binary(14) DEFAULT '00000000000000',
-- Timestamp of when this row was orphaned (i.e. when no pages linked to this target anymore)
-- 99999999999999 if it isn't orphaned.
iwd_orphaned		   binary(14) DEFAULT '99999999999999',

PRIMARY KEY (iwd_id)
)
CHARACTER SET utf8 COLLATE utf8_unicode_ci;

CREATE INDEX iwd_id ON   	       iw_detection (iwd_id);
CREATE INDEX iwd_title ON	       iw_detection (iwd_title);
CREATE INDEX iwd_exists ON             iw_detection (iwd_exists);
CREATE INDEX iwd_polled ON             iw_detection (iwd_polled);

COMMIT;