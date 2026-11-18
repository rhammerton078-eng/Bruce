<?php
declare(strict_types=1);

namespace Atlas;

/** Vector helpers: vectors are L2-normalized and stored as packed float32. */
final class Vector
{
    public static function pack(array $v): string
    {
        $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
        return pack('g*', ...array_map(fn($x) => $x / $n, $v));
    }

    public static function unpack(string $bin): array
    {
        return array_values(unpack('g*', $bin));
    }

    public static function dot(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
        return $s;
    }

    /** Saves a chunk embedding (and mirrors it into the native vector index when enabled). */
    public static function store(int $chunkId, string $model, array $vec): void
    {
        $db = Db::get();
        $db->run('REPLACE INTO chunk_embeddings (chunk_id, model, dims, vec) VALUES (?,?,?,?)', [$chunkId, $model, count($vec), self::pack($vec)]);
        if (self::nativeEnabled()) {
            try { $db->run('REPLACE INTO chunk_vectors (chunk_id, v) VALUES (?, VEC_FromText(?))', [$chunkId, self::toText(self::unpack(self::pack($vec)))]); }
            catch (\Throwable) { /* dimension mismatch: falls back to PHP search for this chunk */ }
        }
    }

    private static function toText(array $v): string
    {
        return '[' . implode(',', array_map(fn($x) => sprintf('%.7F', $x), $v)) . ']';
    }

    // ---------------------------------------------------------- native MariaDB 11.7+ vector index (optional)

    public static function nativeSupported(): array
    {
        $ver = (string)Db::get()->val('SELECT VERSION()');
        preg_match('/^(\d+)\.(\d+)/', $ver, $m);
        $ok = stripos($ver, 'mariadb') !== false && ((int)($m[1] ?? 0) > 11 || ((int)($m[1] ?? 0) === 11 && (int)($m[2] ?? 0) >= 7));
        return ['supported' => $ok, 'version' => $ver, 'enabled' => self::nativeEnabled()];
    }

    public static function nativeEnabled(): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            return $cache = Db::get()->val("SELECT v FROM settings WHERE k = 'vector.native'") === '1'
                && (bool)Db::get()->val("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chunk_vectors'");
        } catch (\Throwable) { return $cache = false; }
    }

    /** Builds the native VECTOR index from existing embeddings. Requires MariaDB 11.7+. */
    public static function enableNative(): int
    {
        $sup = self::nativeSupported();
        if (!$sup['supported']) throw new \RuntimeException('Native vector index needs MariaDB 11.7 or newer (you have ' . $sup['version'] . '). PHP search keeps working.');
        $db = Db::get();
        $dims = $db->all('SELECT dims, COUNT(*) n FROM chunk_embeddings GROUP BY dims ORDER BY n DESC');
        if (!$dims) throw new \RuntimeException('No embeddings yet.');
        if (count($dims) > 1) throw new \RuntimeException('Embeddings have mixed sizes. Run "Re-embed all passages" first.');
        $d = (int)$dims[0]['dims'];
        $db->pdo->exec('DROP TABLE IF EXISTS chunk_vectors');
        $db->pdo->exec("CREATE TABLE chunk_vectors (chunk_id INT UNSIGNED PRIMARY KEY, v VECTOR($d) NOT NULL, VECTOR INDEX (v) DISTANCE=cosine,
                        CONSTRAINT fk_cv_chunk FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE) ENGINE=InnoDB");
        $n = 0;
        $st = $db->pdo->query('SELECT chunk_id, vec FROM chunk_embeddings');
        while ($r = $st->fetch(\PDO::FETCH_NUM)) {
            $db->run('INSERT INTO chunk_vectors (chunk_id, v) VALUES (?, VEC_FromText(?))', [$r[0], self::toText(self::unpack($r[1]))]);
            $n++;
        }
        $db->run("REPLACE INTO settings (k, v) VALUES ('vector.native', '1')");
        return $n;
    }

    public static function disableNative(): void
    {
        $db = Db::get();
        $db->run("REPLACE INTO settings (k, v) VALUES ('vector.native', '0')");
        $db->pdo->exec('DROP TABLE IF EXISTS chunk_vectors');
    }

    /**
     * Brute-force cosine search over all chunk embeddings.
     * Fine for tens of thousands of chunks; results are cached per request.
     */
    public static function searchChunks(array $query, int $k = 8, float $min = 0.0): array
    {
        $q = self::unpack(self::pack($query));
        $db = Db::get();
        if (self::nativeEnabled()) {
            try {
                $out = [];
                foreach ($db->all('SELECT chunk_id, VEC_DISTANCE_COSINE(v, VEC_FromText(?)) AS d FROM chunk_vectors ORDER BY d LIMIT ' . (int)$k, [self::toText($q)]) as $r) {
                    $s = 1 - (float)$r['d'];
                    if ($s >= $min) $out[(int)$r['chunk_id']] = $s;
                }
                return $out;
            } catch (\Throwable) { /* fall back to PHP search */ }
        }
        $scores = [];
        $st = $db->pdo->query('SELECT chunk_id, vec FROM chunk_embeddings');
        while ($row = $st->fetch(\PDO::FETCH_NUM)) {
            $s = self::dot($q, self::unpack($row[1]));
            if ($s >= $min) $scores[(int)$row[0]] = $s;
        }
        arsort($scores);
        return array_slice($scores, 0, $k, true);
    }
}
