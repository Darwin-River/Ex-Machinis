-- Code lineage (#616): each company's code revisions (its release, then
-- each push) and the starter and example scripts, their genes (top-level
-- functions and the rest, normalized), and each revision's parents: the
-- earlier revisions whose code it carries, with the share each explains.
-- Worked out on the website's tick (App\Services\CodeLineage::catchUp).
CREATE TABLE IF NOT EXISTS code_revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL COMMENT 'NULL for a starter or example script',
    push_number INT NOT NULL DEFAULT 0 COMMENT '0: its release',
    example VARCHAR(64) NULL COMMENT 'examples/<dir>/<name> for a starter or example script',
    game_t BIGINT NULL COMMENT 'Game time: the company founded or the push sent; NULL for examples (undated roots)',
    source_hash CHAR(40) NOT NULL,
    size INT NOT NULL DEFAULT 0 COMMENT 'Normalized tokens',
    new_share DOUBLE NULL COMMENT 'Share of its code no earlier revision explains; NULL until worked out',
    UNIQUE KEY code_revisions_company (company_id, push_number),
    UNIQUE KEY code_revisions_example (example),
    INDEX code_revisions_time (game_t)
);

CREATE TABLE IF NOT EXISTS code_genes (
    revision_id INT UNSIGNED NOT NULL,
    seq INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    hash CHAR(40) NOT NULL,
    size INT NOT NULL,
    tokens MEDIUMTEXT NOT NULL COMMENT 'Normalized tokens, space-separated',
    PRIMARY KEY (revision_id, seq),
    INDEX code_genes_hash (hash)
);

CREATE TABLE IF NOT EXISTS code_parents (
    revision_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NOT NULL,
    rank_no INT NOT NULL COMMENT '1: the parent chosen first, which explains the most',
    share DOUBLE NOT NULL COMMENT 'Share of the revision''s code it explains (beyond earlier-ranked parents)',
    PRIMARY KEY (revision_id, parent_id),
    INDEX code_parents_parent (parent_id)
);
