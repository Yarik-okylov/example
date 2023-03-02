<?php


namespace App\Service\Video;


use App\Entity\Ads;
use App\Entity\User;
use App\Entity\UserImage;
use App\Entity\Video;
use App\Enum\Video\Server;
use App\Library\Utils\Convert;
use App\Library\Utils\URI;
use App\Service\Data\ApiExchanger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class VideoService
{
    /**
     * @var ApiExchanger
     */
    private $apiExchanger;

    public function __construct(ApiExchanger $apiExchanger)
    {
        $this->apiExchanger = $apiExchanger;
    }

    /**
     * Сохраняется как само видео, так и один скриншот к нему
     * ВАЖНО! Учитывается, что скриншот будет в формате jpeg @see VideoService::videoScreen()
     *
     * @param string $tempDir
     * @param int $userId
     * @param string|null $email
     * @param string|null $ip
     * @return int[]|null
     */
    public function save(string $tempDir, int $userId)
    {
        $filePaths = $this->getDirectoryFilePaths($tempDir);

        $imagePath = '';
        $imageFilename = '';
        $originalImageFilename = '';

        $videoPath = '';
        $videoFilename = '';
        $originalVideoFilename = '';

        foreach ($filePaths as $filePath) {
            switch ($filePath['type']) {
                case 'image':
                    $imagePath = $filePath['path'];
                    break;
                case 'video':
                    $videoPath = $filePath['path'];
                    break;
                default:
                    break;
            }
        }

        if($videoPath) {
            $videoFilename = $this->getFileName($videoPath, 'video');
            $originalVideoFilename = explode('/', $videoPath);
            $originalVideoFilename = $originalVideoFilename[count($originalVideoFilename) - 1];
        }

        if($imagePath) {
            $imageFilename = $this->getFileName($imagePath, 'image');
            $originalImageFilename = explode('/', $imagePath);
            $originalImageFilename = $originalImageFilename[count($originalImageFilename) - 1];
        }

        if(!$videoPath || !$imagePath) {
            return null;
        }

        $data = $this->apiExchanger->addVideo(
            $originalVideoFilename,
            $videoFilename,
            $originalImageFilename,
            $imageFilename,
            $userId
        );

        $id = $data['id'] ?? 0;
        if ($id && $id > 0) {
            $saveDir = getenv('IMG_STORE') . '/' . $this->getSubPath($id) . '/';
            try {
                mkdir($saveDir, 0777, true);
            } catch (\Exception $e) {

            }
            rename($videoPath, $saveDir . $videoFilename);
            rename($imagePath, $saveDir . $imageFilename);
        }

        return ['id' => $id];
    }

    private function getDirectoryFilePaths($tempDir): array
    {
        if (!is_dir($tempDir)) {
            return [];
        }

        $dir = dir($tempDir);
        $filePaths = [];

        while (false !== ($entry = $dir->read())) {
            if(count($filePaths) >= 2) {
                break;
            }

            $filePath = $tempDir . $entry;
            $fileName = explode('.', $entry);
            $extKey = array_key_last($fileName);
            $fileType = ($fileName[$extKey] === 'jpeg') ? 'image' : 'video';
            if (is_file($filePath)) {
                $filePaths[] = [
                    'path' => $filePath,
                    'type' => $fileType
                ];
            }
        }

        $dir->close();

        return $filePaths;
    }

    public function getFileName(string $originalFilename, string $type)
    {
        $text = "{$type}".time();

        $text = preg_replace(
            '/[^a-zA-Z0-9-]/',
            '',
            str_replace(
                ' ',
                '-',
                preg_replace(
                    '/\s/',
                    ' ',
                    Convert::toEnglish(mb_strtolower($text)
                    )
                )
            )
        );
        $text = explode('-', $text);
        $texts = [];
        for ($i = 0; $i < 3; $i++) {
            if (isset($text[$i])) {
                $texts[] = $text[$i];
            }
        }
        $originalFilename = explode('.', $originalFilename);
        $fname = str_replace(' ', '_' ,implode('-', $texts));
        if (strlen($fname)>90) {
            $fname = substr($fname, 0, 90);
        }
        if ($fname == '') {
            $fname = 'image_'.time();
        }
        return $fname . '.' . $originalFilename[count($originalFilename) - 1];
    }

    public function getSubPath(int $id)
    {
        $tmp = md5($id);
        return $tmp[4] . $tmp[16] . '/' . $tmp[11] . $tmp[23];
    }

    public function videoScreen(string $dir, string $videoFilePath, string $originalFileName)
    {
        $dir = realpath($dir);
        $videoFilePath = realpath($videoFilePath);
        $screenFileName = explode('.', $originalFileName);
        $extKey = array_key_last($screenFileName);
        $screenFileName[$extKey] = '.jpeg';
        $screenFileName = implode($screenFileName);
        $screenFilePath = $dir.'/'.$screenFileName;
        exec("/usr/bin/ffmpeg -ss 00:00:00 -i {$videoFilePath} -vframes 1 -q:v 2 {$screenFilePath}");

        return $screenFileName;
    }

    public function getVideoDuration(string $filePath)
    {
        $result = shell_exec("/usr/bin/ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$filePath}");
        return $result ? (int)ceil(trim($result)) : null;
    }

    public function getVideos(?string $gender, string $sortKey, int $page = 1, int $perPage = 99, ?int $userId = null, ?string $start = null)
    {
        $gallery = $this->apiExchanger->getVideoGallery($gender, $sortKey, $page, $perPage, $userId, $start);
        $gallery['list'] = array_map(static function (array $videoItem) {
            return (new Video())->fromArray($videoItem);
        }, $gallery['list'] ?? []);

        return $gallery;
    }

    public function getUserVideos(int $userId, int $currentUserId, int $page = 1, int $perPage = 99, bool $all = true)
    {
        $rawVideos = $this->apiExchanger->getUserVideos($page, $perPage, $all, $userId, $currentUserId);
        $rawVideos['list'] = array_map(static function (array $dataItem) {
            return (new Video())->fromArray($dataItem);
        }, $rawVideos['list']);

        return $rawVideos;
    }

    public static function buildVideoId(Video $video): string
    {
        $videoId = $video->getId();
        $name = ($user = $video->getUser()) ? mb_strtolower($user->getUsername()) : '';
        if($name) {
            $convertedName = Convert::toEnglish( preg_replace('/[^a-zA-Z0-9-]/', '', str_replace(' ', '-', $name)));
        }

        return "{$convertedName}-{$videoId}";
    }

    public function fillQualitiesPath(Video $video)
    {
        $result = [];

        foreach ($video->getQuality() as $key => $quality) {
            $result[$quality] = $this->buildVideoPathForQuality($video, $quality);
        }

        $video->setQuality($result);

        return $video;
    }

    public function buildVideoPathForQuality(Video $video, $quality)
    {
        $videoPath = $this->getVideoPath($video->getId(), $video->getVideoFileName(), $video->getServer());
        $arrayPath = explode('/', $videoPath);

        $videoName = $arrayPath[count($arrayPath) - 1];
        $videoNameArray = explode('.', $videoName);
        $ext = $videoNameArray[count($videoNameArray) - 1];
        unset($videoNameArray[count($videoNameArray) - 1]);

        $videoNameWithoutExt = implode('.', $videoNameArray);

        return "{$videoNameWithoutExt}_{$quality}.{$ext}";
    }

    public function getVideoPath($id, $filename, $server = Server::SERVER_WWW)
    {
        return ($_SERVER['HTTPS'] == 'on' ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'https')) . '://' . URI::getVideoServer($server) . getenv('IMG_STORE_URL') . '/' . $this->getSubPath((int)$id) . '/' . $filename;
    }

    public function getOptimalQualityVideoPath(Video $video)
    {
        $video = $this->fillQualitiesPath($video);

        $result = null;

        if($video->getQuality()) {
            foreach ($video->getQuality() as $key => $quality) {
                if(!$result || ($result <= 720)) {
                    $result = (int)$key;
                }
            }
        }

        return $result;
    }

    public function getRealVideoPath(Video $video)
    {
        return $this->getVideoPath($video->getId(), $video->getVideoFileName(), $video->getServer());
    }
}
