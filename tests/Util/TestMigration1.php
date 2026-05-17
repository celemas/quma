<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests\Util;

use Celemas\Quma\Contract\Migration;
use Celemas\Quma\Environment;
use PDO;

final class TestMigration1 implements Migration
{
	public function run(Environment $env): void
	{
		$db = $env->db;
		$driver = $env->driver;

		switch ($driver) {
			case 'sqlite':
				$db->execute('ALTER TABLE genres ADD COLUMN name_sqlite TEXT;')->run();
				$db->execute("INSERT INTO genres (id, name_sqlite) VALUES (1, 'Death Metal');")->run();

				break;
			case 'pgsql':
				$db->execute('ALTER TABLE genres ADD COLUMN name_pgsql TEXT;')->run();
				$db->execute("INSERT INTO genres (id, name_pgsql) VALUES (1, 'Death Metal');")->run();

				break;
			case 'mysql':
				$db->execute('ALTER TABLE genres ADD COLUMN name_mysql TEXT;')->run();
				$db->execute("INSERT INTO genres (id, name_mysql) VALUES (1, 'Death Metal');")->run();

				break;
		}

		$result = $db->execute(
			"SELECT id, name_{$driver} FROM genres WHERE id = 1",
		)->all(fetchMode: PDO::FETCH_ASSOC);

		assert(count($result) === 1, 'The migration should insert one genre row.');

		switch ($driver) {
			case 'sqlite':
				$result = $db->execute("PRAGMA table_info('genres')")->all();
				assert($result[1]['name'] === 'name_sqlite', 'The SQLite migration should add name_sqlite.');

				break;
			case 'pgsql':
				$result = $db->execute(
					'SELECT count(*) AS exists FROM information_schema.columns '
					. "WHERE table_schema='public' "
					. "AND table_name='genres' "
					. "AND column_name='name_pgsql'",
				)->one();

				assert($result['exists'] === 1, 'The PostgreSQL migration should add name_pgsql.');

				break;
			case 'mysql':
				$result = $db->execute(
					"SHOW COLUMNS FROM genres WHERE Field = 'name_mysql'",
				)->one();

				assert(
					$result['Field'] ?? false === 'name_mysql',
					'The MySQL migration should add name_mysql.',
				);

				break;
		}
	}
}
