<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Core\Config;

final class EmailTemplateRepo
{
    public function __construct(private readonly Database $db, private readonly Config $config) {}

    public function getAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT templateid, templatename, subject, content FROM emailtemplate ORDER BY templatename'
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
