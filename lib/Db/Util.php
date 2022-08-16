<?php
declare(strict_types=1);

namespace OCA\Polaroid\Db;

use OCA\Polaroid\AppInfo\Application;
use OCP\Files\File;
use OCP\IDBConnection;

class Util {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

    public static function getDateTaken(File $file) {
        // Attempt to read exif data
        if (in_array($file->getMimeType(), Application::IMAGE_MIMES)) {
            $exif = exif_read_data($file->fopen('rb'));
            $dt = $exif['DateTimeOriginal'];
            if ($dt) {
                $dt = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
                if ($dt) {
                    return $dt->getTimestamp();
                }
            }
        }

        // Fall back to creation time
        $dateTaken = $file->getCreationTime();

        // Fall back to upload time
        if ($dateTaken == 0) {
            $dateTaken = $file->getUploadTime();
        }

        // Fall back to modification time
        if ($dateTaken == 0) {
            $dateTaken = $file->getMtime();
        }
        return $dateTaken;
    }

    public function processFile(string $user, File $file): void {
        $mime = $file->getMimeType();
        $is_image = in_array($mime, Application::IMAGE_MIMES);
        $is_video = in_array($mime, Application::VIDEO_MIMES);
        if (!$is_image && !$is_video) {
            return;
        }

        // Get parameters
        $fileId = $file->getId();
        $dateTaken = $this->getDateTaken($file);
        $dayId = floor($dateTaken / 86400);

        // Get existing entry
        $sql = 'SELECT * FROM oc_polaroid WHERE
                user_id = ? AND file_id = ?';
        $res = $this->connection->executeQuery($sql, [
            $user, $fileId,
		]);
        $erow = $res->fetch();
        $exists = (bool)$erow;

        // Insert or update file
        if ($exists) {
            $sql = 'UPDATE oc_polaroid SET
                    day_id = ?, date_taken = ?, is_video = ?
                    WHERE user_id = ? AND file_id = ?';
        } else {
            $sql = 'INSERT
                    INTO  oc_polaroid (day_id, date_taken, is_video, user_id, file_id)
                    VALUES  (?, ?, ?, ?, ?)';
        }
		$res = $this->connection->executeStatement($sql, [
            $dayId, $dateTaken, $is_video,
            $user, $fileId,
		], [
            \PDO::PARAM_INT, \PDO::PARAM_INT, \PDO::PARAM_BOOL,
            \PDO::PARAM_STR, \PDO::PARAM_INT,
        ]);

        // Change of day
        $dayChange = ($exists && intval($erow['day_id']) != $dayId);

        // Update day table
        if (!$exists || $dayChange) {
            $sql = 'INSERT
                    INTO  oc_polaroid_day (user_id, day_id, count)
                    VALUES  (?, ?, 1)
                    ON DUPLICATE KEY
                    UPDATE  count = count + 1';
            $this->connection->executeStatement($sql, [
                $user, $dayId,
            ]);

            if ($dayChange) {
                $sql = 'UPDATE oc_polaroid_day SET
                        count = count - 1
                        WHERE user_id = ? AND day_id = ?';
                $this->connection->executeStatement($sql, [
                    $user, $erow['day_id'],
                ], [
                    \PDO::PARAM_STR, \PDO::PARAM_INT,
                ]);
            }
        }
    }

    public function deleteFile(File $file) {
        $sql = 'DELETE
                FROM oc_polaroid
                WHERE file_id = ?
                RETURNING *';
        $res = $this->connection->executeQuery($sql, [$file->getId()], [\PDO::PARAM_INT]);
        $rows = $res->fetchAll();

        foreach ($rows as $row) {
            $dayId = $row['day_id'];
            $userId = $row['user_id'];
            $sql = 'UPDATE oc_polaroid_day
                    SET count = count - 1
                    WHERE user_id = ? AND day_id = ?';
            $this->connection->executeStatement($sql, [$userId, $dayId], [
                \PDO::PARAM_STR, \PDO::PARAM_INT,
            ]);
        }
    }

    public function getDays(
        string $user,
    ): array {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('day_id', 'count')
            ->from('polaroid_day')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->orderBy('day_id', 'DESC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }

    public function getDay(
        string $user,
        int $dayId,
    ): array {
        $sql = 'SELECT file_id, oc_filecache.etag, is_video
                FROM oc_polaroid
                LEFT JOIN oc_filecache
                ON oc_filecache.fileid = oc_polaroid.file_id
                WHERE user_id = ? AND day_id = ?
                ORDER BY date_taken DESC';
		$rows = $this->connection->executeQuery($sql, [$user, $dayId], [
            \PDO::PARAM_STR, \PDO::PARAM_INT,
        ])->fetchAll();

        foreach($rows as &$row) {
            $row["file_id"] = intval($row["file_id"]);
            $row["is_video"] = intval($row["is_video"]);
            if (!$row["is_video"]) {
                unset($row["is_video"]);
            }
        }

        return $rows;
    }
}