CREATE TABLE tx_smartsearch_vector
(
    uid          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection   VARCHAR(255) NOT NULL DEFAULT '',
    identifier   VARCHAR(255) NOT NULL DEFAULT '',
    vector       LONGTEXT     NOT NULL,
    content_hash VARCHAR(32)  NOT NULL DEFAULT '',
    tstamp       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY uq_collection_identifier (collection, identifier(191)),
    KEY idx_collection (collection)
);
