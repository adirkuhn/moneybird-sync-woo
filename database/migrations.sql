CREATE TABLE {prefix}mb_tasks (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    type varchar(50) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    payload longtext NOT NULL,
    attempts int(11) NOT NULL DEFAULT 0,
    max_attempts int(11) NOT NULL DEFAULT 3,
    locked_at datetime DEFAULT NULL,
    completed_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_status (status),
    KEY idx_type_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}mb_logs (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    task_id bigint(20) unsigned DEFAULT NULL,
    level varchar(20) NOT NULL DEFAULT 'info',
    message text NOT NULL,
    context longtext DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_task_id (task_id),
    KEY idx_level (level),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
