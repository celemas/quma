<?php

declare(strict_types=1);

namespace Celemas\Quma;

use RuntimeException;

/** @api */
class Folder
{
	protected Database $db;
	protected string $folder;

	public function __construct(Database $db, string $folder)
	{
		Util::assertPathSegment($folder, 'SQL folder name');

		$this->db = $db;
		$this->folder = $folder;
	}

	public function __get(string $key): Script
	{
		return $this->getScript($key);
	}

	public function __call(string $key, array $args): Query
	{
		$script = $this->getScript($key);

		return $script->invoke(...$args);
	}

	protected function scriptPath(string $key, bool $isTemplate): bool|string
	{
		$ext = $isTemplate ? '.tpql' : '.sql';

		foreach ($this->db->getSqlDirs() as $path) {
			assert(is_string($path), 'SQL directory path must be a string.');
			$result = $path . DIRECTORY_SEPARATOR . $this->folder . DIRECTORY_SEPARATOR . $key . $ext;

			if (is_file($result)) {
				return $result;
			}
		}

		return false;
	}

	protected function getScript(string $key): Script
	{
		Util::assertPathSegment($key, 'SQL script name');

		$script = $this->scriptPath($key, false);

		if (is_string($script)) {
			$loaded = $this->db->loadScript($script, false);

			return new Script($this->db, $loaded, false);
		}

		$dynStmt = $this->scriptPath($key, true);

		if (is_string($dynStmt)) {
			$loaded = $this->db->loadScript($dynStmt, true);

			return new Script($this->db, $loaded, true);
		}

		throw new RuntimeException('SQL script does not exist');
	}
}
