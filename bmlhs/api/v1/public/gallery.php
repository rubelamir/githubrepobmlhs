<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

$albumId = filter_input(INPUT_GET, 'album_id', FILTER_VALIDATE_INT);
$albumId = is_int($albumId) && $albumId > 0 ? $albumId : null;

try {
    $db = getDatabaseConnection();
    $albums = array_map(
        static function (array $album): array {
            $cover = basename((string) ($album['cover_file'] ?? ''));
            return [
                'id' => (int) $album['id'],
                'title' => (string) ($album['title'] ?? ''),
                'event_name' => (string) ($album['event_name'] ?? ''),
                'year' => (string) ($album['album_year'] ?? ''),
                'photo_count' => (int) ($album['photo_count'] ?? 0),
                'cover_url' => $cover !== '' ? publicSiteUrl('image_gallery_file/' . $cover) : null,
            ];
        },
        publicGalleryAlbums($db)
    );

    $selectedAlbum = $albumId !== null ? publicGalleryAlbum($db, $albumId) : null;
    if ($albumId !== null && $selectedAlbum === null) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Gallery album not found.']], 404);
    }
    $photos = [];
    if ($selectedAlbum !== null && tableExists($db, 'image_gallery')) {
        $photos = fetchRows(
            $db,
            'SELECT id, image_gallery_caption AS caption, image_gallery_file AS file,
                    image_gallery_creation_time AS created_at
             FROM image_gallery
             WHERE image_gallery_status = 1 AND album_id = :album_id
             ORDER BY image_gallery_creation_time DESC, id DESC
             LIMIT 100',
            ['album_id' => (int) $selectedAlbum['id']]
        );
    }
    foreach ($photos as &$photo) {
        $file = basename((string) ($photo['file'] ?? ''));
        $photo['image_url'] = $file !== '' ? publicSiteUrl('image_gallery_file/' . $file) : null;
        unset($photo['file']);
    }
    unset($photo);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public gallery');
}

mobileApiJson([
    'data' => [
        'albums' => $albums,
        'selected_album' => $selectedAlbum === null ? null : [
            'id' => (int) $selectedAlbum['id'],
            'title' => (string) ($selectedAlbum['title'] ?? ''),
        ],
        'photos' => $photos,
        'generated_at' => gmdate(DATE_ATOM),
    ],
]);
