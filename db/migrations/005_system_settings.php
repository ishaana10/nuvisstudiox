<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasSys = false;
        try {
            $hasSys = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_system_settings'" : "SHOW TABLES LIKE 'nu_system_settings'")->fetch();
        } catch (Exception $e) {}

        if (!$hasSys) {
            $pdo->exec("CREATE TABLE `nu_system_settings` (
                `setting_key` VARCHAR(50) NOT NULL,
                `setting_value` " . ($driver === 'sqlite' ? "TEXT" : "LONGTEXT") . " DEFAULT NULL,
                PRIMARY KEY (`setting_key`)
            )" . ($driver === 'sqlite' ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"));
        }

        // Seed default settings if missing
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `nu_system_settings` WHERE `setting_key` = ?");

        $defaults = [
            'app_name' => 'nuvis',
            'app_logo' => '',
            'system_fields_def' => '[]',
            'forgot_password_enabled' => '1',
            'user_header_format' => '{name} | {location}'
        ];

        foreach ($defaults as $k => $v) {
            $stmt->execute([$k]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                    ->execute([$k, $v]);
            }
        }
    }
};
